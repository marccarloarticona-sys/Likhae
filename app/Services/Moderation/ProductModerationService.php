<?php

namespace App\Services\Moderation;

use App\Jobs\ScanProductModeration;
use App\Models\Admin\AuditLog;
use App\Models\Admin\Notification;
use App\Models\Admin\PlatformSetting;
use App\Models\Admin\ProductModerationScan;
use App\Models\Admin\SellerComplianceAction;
use App\Models\Admin\SellerComplianceCase;
use App\Models\Seller\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ProductModerationService
{
    public function __construct(
        private readonly ProductModerationRevisionHasher $hasher,
        private readonly ProductModerationProvider $provider,
        private readonly ProductModerationImagePreprocessor $images,
        private readonly ProductModerationRiskEngine $riskEngine,
    ) {}

    public function enabled(): bool
    {
        return $this->featureEnabled() && $this->provider->configured();
    }

    public function featureEnabled(): bool
    {
        try {
            if (Schema::hasTable('platform_settings')) {
                return (bool) PlatformSetting::valueOf(
                    'product_moderation_enabled',
                    config('product_moderation.enabled'),
                );
            }
        } catch (Throwable) {
            // Fall back to the deployment setting while platform settings are unavailable.
        }

        return (bool) config('product_moderation.enabled');
    }

    public function hasConfiguredCredentials(): bool
    {
        return $this->provider->configured();
    }

    public function revisionFingerprint(Product $product): string
    {
        return $this->hasher->hash($product);
    }

    public function queueAutomatic(Product $product): ?ProductModerationScan
    {
        if (! $this->enabled() || ! Schema::hasTable('product_moderation_scans')
            || ! in_array($product->status, ['DRAFT', 'ACTIVE', 'SUSPENDED'], true)) {
            return null;
        }

        return $this->queue($product, null, true, false);
    }

    public function queueManual(Product $product, User $admin): ProductModerationScan
    {
        if (! $this->enabled()) {
            throw new ProductModerationProviderException('AI_NOT_CONFIGURED', false);
        }

        if (! Schema::hasTable('product_moderation_scans')) {
            throw new ProductModerationProviderException('AI_SCAN_STORAGE_NOT_READY', false);
        }

        $scan = $this->queue($product, $admin, false, true);
        if (! $scan) {
            throw new ProductModerationProviderException('AI_PRODUCT_UNAVAILABLE', false);
        }

        return $scan;
    }

    private function queue(Product $product, ?User $admin, bool $automatic, bool $force): ?ProductModerationScan
    {
        return DB::transaction(function () use ($product, $admin, $automatic, $force): ?ProductModerationScan {
            $freshProduct = Product::query()
                ->with(['category.parent', 'images', 'options.values', 'variants.optionValues.option'])
                ->whereKey($product->id)
                ->lockForUpdate()
                ->first();
            if (! $freshProduct) {
                return null;
            }
            if ($automatic && ! in_array($freshProduct->status, ['DRAFT', 'ACTIVE', 'SUSPENDED'], true)) {
                return null;
            }

            $revision = $this->hasher->hash($freshProduct);
            if (! $force) {
                $existing = ProductModerationScan::query()
                    ->where('product_id', $freshProduct->id)
                    ->where('revision_hash', $revision)
                    ->where('policy_version', config('product_moderation.policy_version'))
                    ->whereIn('scan_status', ['QUEUED', 'SCANNING', 'COMPLETE', 'AI_SCAN_DEFERRED'])
                    ->latest('id')
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $scan = ProductModerationScan::create([
                'product_id' => $freshProduct->id,
                'seller_profile_id' => $freshProduct->seller_profile_id,
                'requested_by_admin_user_id' => $admin?->id,
                'revision_hash' => $revision,
                'policy_version' => (string) config('product_moderation.policy_version'),
                'model_versions' => $this->modelVersions(),
                'is_automatic' => $automatic,
                'scan_status' => 'QUEUED',
                'decision_status' => 'PENDING',
                'appeal_status' => 'NOT_APPEALED',
            ]);

            ScanProductModeration::dispatch($scan->id)->afterCommit();

            return $scan;
        });
    }

    public function processScan(int $scanId, int $attempt = 1): void
    {
        $scan = ProductModerationScan::query()->find($scanId);
        if (! $scan || in_array($scan->scan_status, ['COMPLETE', 'SUPERSEDED'], true)) {
            return;
        }
        if (! $this->enabled()) {
            $this->markFailure($scan, 'AI_NOT_CONFIGURED', false, $attempt);

            return;
        }

        $scan->update([
            'scan_status' => 'SCANNING',
            'failure_code' => null,
            'retry_count' => max(0, $attempt - 1),
            'started_at' => $scan->started_at ?: now(),
        ]);

        try {
            $product = Product::query()
                ->with([
                    'category.parent', 'images', 'options.values', 'variants.optionValues.option',
                ])
                ->find($scan->product_id);
            if (! $product || ! hash_equals((string) $scan->revision_hash, $this->hasher->hash($product))) {
                $scan->update(['scan_status' => 'SUPERSEDED', 'failure_code' => null, 'scanned_at' => now()]);

                return;
            }

            $productState = $this->productState($product);
            $productImages = $product->images->values();
            $maxImages = (int) config('product_moderation.images.max_per_scan', 10);
            $completeImages = $productImages->count() <= $maxImages;
            $preparedImages = [];
            $unavailableImages = [];
            foreach ($productImages->take($maxImages) as $image) {
                try {
                    $preparedImages[] = $this->images->prepare($image);
                } catch (ProductModerationProviderException $exception) {
                    $completeImages = false;
                    $unavailableImages[] = [
                        'image_id' => (int) $image->id,
                        'failure_code' => $exception->failureCode,
                    ];
                }
            }

            $textResult = $this->provider->classifyText($productState);
            $imageResult = $this->provider->classifyImages($productState, $preparedImages);
            $objectDetections = [];
            foreach ($preparedImages as $image) {
                try {
                    $detections = $this->provider->detectObjects($image);
                } catch (Throwable $exception) {
                    // The object detector is supporting evidence only. Its
                    // outage cannot override a valid primary Clef decision.
                    $detections = [];
                    $objectDetections[] = [
                        'image_id' => $image['image_id'],
                        'status' => 'UNAVAILABLE',
                        'failure_code' => $exception instanceof ProductModerationProviderException
                            ? $exception->failureCode
                            : 'AI_INVALID_RESPONSE',
                    ];
                }
                if ($detections !== []) {
                    $objectDetections[] = [
                        'image_id' => $image['image_id'],
                        'status' => 'COMPLETE',
                        'detections' => $detections,
                    ];
                }
            }

            $decision = $this->riskEngine->decide(
                $textResult,
                $imageResult,
                $preparedImages !== [],
                $completeImages,
            );
            $imageHashes = collect($preparedImages)->map(fn (array $image): array => [
                'image_id' => $image['image_id'],
                'sha256' => $image['sha256'],
            ])->all();

            DB::transaction(function () use ($scan, $product, $decision, $textResult, $imageResult, $objectDetections, $imageHashes, $unavailableImages): void {
                $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->first();
                if (! $lockedProduct || ! hash_equals((string) $scan->revision_hash, $this->hasher->hash($lockedProduct))) {
                    $scan->update(['scan_status' => 'SUPERSEDED', 'failure_code' => null, 'scanned_at' => now()]);

                    return;
                }

                $risk = $decision['overall_risk'];
                $hold = in_array($risk, ['HIGH', 'CRITICAL'], true) && $lockedProduct->status === 'ACTIVE';
                $priorStatus = $hold ? (string) $lockedProduct->status : null;
                if ($hold) {
                    $lockedProduct->update(['status' => 'SUSPENDED']);
                }

                $scan->update([
                    'scan_status' => 'COMPLETE',
                    'decision_status' => $risk === 'LOW' ? 'NOT_REQUIRED' : 'PENDING',
                    'overall_risk' => $risk,
                    'text_risk' => $decision['text_risk'],
                    'image_risk' => $decision['image_risk'],
                    'detected_categories' => $decision['detected_categories'],
                    'text_findings' => $this->safeEvaluation($textResult),
                    'image_findings' => $this->safeEvaluation($imageResult) + ['unavailable_images' => $unavailableImages],
                    'object_detections' => $objectDetections,
                    'model_results' => [
                        'image_hashes' => $imageHashes,
                        'classification_sources' => [
                            'text' => [
                                'provider' => $textResult['provider_source'] ?? null,
                                'model' => $textResult['model_source'] ?? null,
                                'used_fallback' => $textResult['used_fallback'] ?? false,
                            ],
                            'image' => [
                                'provider' => $imageResult['provider_source'] ?? null,
                                'model' => $imageResult['model_source'] ?? null,
                                'used_fallback' => $imageResult['used_fallback'] ?? false,
                            ],
                        ],
                    ],
                    'evidence_summary' => $decision['evidence_summary'],
                    'recommended_action' => $decision['recommended_action'],
                    'listing_status_before_hold' => $priorStatus,
                    'held_at' => $hold ? now() : null,
                    'failure_code' => null,
                    'scanned_at' => now(),
                ]);
            });

            $scan->refresh();
            if ($scan->scan_status !== 'COMPLETE') {
                return;
            }

            if (in_array($scan->overall_risk, ['MEDIUM', 'HIGH', 'CRITICAL', 'UNKNOWN'], true)) {
                $this->notifyProductReview($scan, $product);
            }
            $obfuscationSignal = (float) data_get($scan->text_findings, 'disguised_probability', 0)
                >= (float) config('product_moderation.risk.medium', 0.30);
            if (in_array($scan->overall_risk, ['HIGH', 'CRITICAL'], true) || $obfuscationSignal) {
                $this->notifyAdmins($scan, $product);
            }

            AuditLog::create([
                'actor_user_id' => null,
                'event' => 'PRODUCT_AI_SCAN_COMPLETED',
                'auditable_type' => ProductModerationScan::class,
                'auditable_id' => $scan->id,
                'old_values' => null,
                'new_values' => [
                    'product_id' => $product->id,
                    'risk' => $scan->overall_risk,
                    'categories' => $scan->detected_categories,
                    'scan_status' => $scan->scan_status,
                ],
                'created_at' => now(),
            ]);
        } catch (ProductModerationProviderException $exception) {
            $this->markFailure($scan, $exception->failureCode, $exception->retryable, $attempt);
            if ($exception->retryable && $attempt < (int) config('product_moderation.queue.max_attempts', 3)) {
                throw $exception;
            }
        } catch (Throwable $exception) {
            $this->markFailure($scan, 'AI_INVALID_RESPONSE', false, $attempt);
            Log::warning('Product moderation scan failed: AI_INVALID_RESPONSE.', [
                'scan_id' => $scan->id,
                'product_id' => $scan->product_id,
                'failure_code' => 'AI_INVALID_RESPONSE',
                'exception_type' => class_basename($exception),
            ]);
        }
    }

    public function markFailure(ProductModerationScan $scan, string $failureCode, bool $retryable, int $attempt): void
    {
        $finalAttempt = $attempt >= (int) config('product_moderation.queue.max_attempts', 3);
        $status = $retryable && ! $finalAttempt
            ? 'RETRYING'
            : ($failureCode === 'AI_QUOTA_LIMIT' ? 'AI_SCAN_DEFERRED' : 'SCAN_FAILED');
        $scan->update([
            'scan_status' => $status,
            'failure_code' => $failureCode,
            'retry_count' => max(0, $attempt - 1),
            'scanned_at' => $finalAttempt || ! $retryable ? now() : null,
        ]);
        Log::warning('Product moderation scan failure: '.$failureCode.'.', [
            'scan_id' => $scan->id,
            'product_id' => $scan->product_id,
            'failure_code' => $failureCode,
            'retryable' => $retryable,
            'attempt' => $attempt,
        ]);

        if ($failureCode === 'AI_QUOTA_LIMIT' && $attempt === (int) config('product_moderation.queue.max_attempts', 3)) {
            ScanProductModeration::dispatch($scan->id, true)
                ->delay(now()->addMinutes(max(1, (int) config('product_moderation.queue.quota_deferred_retry_minutes', 60))))
                ->afterCommit();
        }

        if ($finalAttempt || ! $retryable) {
            $this->notifyAdminsOfRepeatedFailures($scan, $failureCode);
        }
    }

    private function notifyAdminsOfRepeatedFailures(ProductModerationScan $scan, string $failureCode): void
    {
        try {
            $recentFailures = ProductModerationScan::query()
                ->whereIn('scan_status', ['SCAN_FAILED', 'AI_SCAN_DEFERRED'])
                ->where('updated_at', '>=', now()->subDay())
                ->count();
            if ($recentFailures < 3 || ! Cache::add('product-moderation-repeated-provider-failure-alert', true, now()->addMinutes(30))) {
                return;
            }

            $admins = User::query()->where('account_type', User::TYPE_ADMIN)->where('status', User::STATUS_ACTIVE)->get(['id']);
            foreach ($admins as $admin) {
                if (Notification::query()
                    ->where('user_id', $admin->id)
                    ->where('type', 'PRODUCT_AI_PROVIDER_FAILURE')
                    ->where('reference_type', ProductModerationScan::class)
                    ->where('reference_id', $scan->id)
                    ->exists()) {
                    continue;
                }

                Notification::create([
                    'user_id' => $admin->id,
                    'type' => 'PRODUCT_AI_PROVIDER_FAILURE',
                    'title' => 'Product moderation scans are failing',
                    'message' => 'At least three product moderation scans failed or were deferred in the last 24 hours. Latest failure: '.$failureCode.'.',
                    'reference_type' => ProductModerationScan::class,
                    'reference_id' => $scan->id,
                    'action_url' => $scan->product_id
                        ? route('admin.products.show', ['product' => $scan->product_id])
                        : route('admin.products'),
                ]);
            }
        } catch (Throwable) {
            // Provider outage reporting must not create a retry loop.
        }
    }

    public function recordHumanReview(
        ProductModerationScan $scan,
        User $admin,
        string $decision,
        string $reason,
        bool $appealReview = false,
    ): ProductModerationScan {
        $decision = strtoupper($decision);
        $allowed = $appealReview ? ['APPROVED', 'DENIED', 'REQUEST_CLARIFICATION'] : [
            'FALSE_POSITIVE',
            'CONFIRMED_POLICY_VIOLATION',
            'REQUEST_SELLER_EDIT',
        ];
        if (! in_array($decision, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported moderation review decision.');
        }

        DB::transaction(function () use ($scan, $admin, $decision, $reason, $appealReview): void {
            $lockedScan = ProductModerationScan::query()->whereKey($scan->id)->lockForUpdate()->firstOrFail();
            if ($lockedScan->scan_status !== 'COMPLETE') {
                abort(409, 'Only completed scans can receive a human decision.');
            }
            if ($appealReview) {
                if ($lockedScan->appeal_status !== 'APPEAL_SUBMITTED') {
                    abort(409, 'This appeal is no longer awaiting review.');
                }
            } elseif ($lockedScan->decision_status !== 'PENDING') {
                abort(409, 'This scan already has a human decision.');
            }

            $oldDecision = $lockedScan->decision_status;
            if ($appealReview) {
                $appealStatus = match ($decision) {
                    'APPROVED' => 'APPEAL_APPROVED',
                    'DENIED' => 'APPEAL_DENIED',
                    default => 'APPEAL_UNDER_REVIEW',
                };
                $updates = [
                    'appeal_status' => $appealStatus,
                    'appeal_decided_by_admin_user_id' => $admin->id,
                    'appeal_decision_reason' => $reason,
                    'appeal_decided_at' => now(),
                ];
                if ($decision !== 'REQUEST_CLARIFICATION') {
                    $updates += [
                        'decision_status' => $decision === 'APPROVED' ? 'FALSE_POSITIVE' : 'APPEAL_DENIED',
                        'decision_reason' => $reason,
                        'decided_by_admin_user_id' => $admin->id,
                        'decided_at' => now(),
                    ];
                }
                $lockedScan->update($updates);
            } else {
                $lockedScan->update([
                    'decision_status' => $decision === 'REQUEST_SELLER_EDIT' ? 'SELLER_EDIT_REQUESTED' : $decision,
                    'decision_reason' => $reason,
                    'decided_by_admin_user_id' => $admin->id,
                    'decided_at' => now(),
                ]);
            }

            if ((! $appealReview && $decision === 'FALSE_POSITIVE') || ($appealReview && $decision === 'APPROVED')) {
                if ($this->hasOtherPendingAiHold($lockedScan)) {
                    $this->recordComplianceReviewNote($lockedScan, $admin, $reason, 'False positive finding cleared; another moderation hold remains open');
                } else {
                    $this->restoreAiHeldListing($lockedScan);
                    $this->resolveAiComplianceCase($lockedScan, $admin, $reason);
                }
            } else {
                $this->recordComplianceReviewNote($lockedScan, $admin, $reason, $appealReview ? 'Appeal reviewed' : 'Human review completed');
            }

            $lockedScan->loadMissing('product.sellerProfile.user');
            if ($appealReview) {
                $this->notifyAppealDecision($lockedScan, $decision, $reason);
            } else {
                $this->notifyHumanDecision($lockedScan, $decision, $reason);
            }

            AuditLog::create([
                'actor_user_id' => $admin->id,
                'event' => $appealReview ? 'PRODUCT_AI_APPEAL_REVIEWED' : 'PRODUCT_AI_HUMAN_REVIEWED',
                'auditable_type' => ProductModerationScan::class,
                'auditable_id' => $lockedScan->id,
                'old_values' => ['decision_status' => $oldDecision, 'appeal_status' => $scan->appeal_status],
                'new_values' => [
                    'decision_status' => $lockedScan->decision_status,
                    'appeal_status' => $lockedScan->appeal_status,
                    'product_id' => $lockedScan->product_id,
                ],
                'created_at' => now(),
            ]);
        });

        return $scan->fresh();
    }

    private function restoreAiHeldListing(ProductModerationScan $scan): void
    {
        if (! $scan->product_id || ! $scan->held_at || ! $scan->listing_status_before_hold) {
            return;
        }

        $product = Product::query()->whereKey($scan->product_id)->lockForUpdate()->first();
        if (! $product || $product->status !== 'SUSPENDED'
            || ! hash_equals((string) $scan->revision_hash, $this->hasher->hash($product))) {
            return;
        }

        $otherAiHold = $this->hasOtherPendingAiHold($scan);
        $otherOpenCase = SellerComplianceCase::query()
            ->where('product_id', $product->id)
            ->whereIn('status', ['OPEN', 'UNDER_REVIEW'])
            ->when($scan->seller_compliance_case_id, fn ($query) => $query->where('id', '!=', $scan->seller_compliance_case_id))
            ->exists();

        if (! $otherAiHold && ! $otherOpenCase) {
            $product->update(['status' => $scan->listing_status_before_hold]);
        }
    }

    private function hasOtherPendingAiHold(ProductModerationScan $scan): bool
    {
        return ProductModerationScan::query()
            ->where('product_id', $scan->product_id)
            ->where('id', '!=', $scan->id)
            ->whereNotNull('held_at')
            ->where('scan_status', 'COMPLETE')
            ->where('decision_status', 'PENDING')
            ->exists();
    }

    private function resolveAiComplianceCase(ProductModerationScan $scan, User $admin, string $reason): void
    {
        $case = $scan->seller_compliance_case_id
            ? SellerComplianceCase::query()->whereKey($scan->seller_compliance_case_id)->lockForUpdate()->first()
            : null;
        if (! $case) {
            return;
        }

        if (in_array($case->status, ['OPEN', 'UNDER_REVIEW'], true)) {
            $case->update(['status' => 'RESOLVED', 'resolved_at' => now()]);
            SellerComplianceAction::create([
                'seller_compliance_case_id' => $case->id,
                'performed_by_user_id' => $admin->id,
                'action_type' => 'NOTE',
                'reason' => 'AI moderation false positive; listing hold reviewed by Admin. '.$reason,
                'performed_at' => now(),
            ]);
        }
    }

    private function recordComplianceReviewNote(ProductModerationScan $scan, User $admin, string $reason, string $label): void
    {
        $case = $scan->seller_compliance_case_id
            ? SellerComplianceCase::query()->whereKey($scan->seller_compliance_case_id)->lockForUpdate()->first()
            : null;
        if (! $case) {
            return;
        }

        SellerComplianceAction::create([
            'seller_compliance_case_id' => $case->id,
            'performed_by_user_id' => $admin->id,
            'action_type' => 'NOTE',
            'reason' => $label.': '.$reason,
            'performed_at' => now(),
        ]);
    }

    private function notifyHumanDecision(ProductModerationScan $scan, string $decision, string $reason): void
    {
        $this->notifySellerOfReview($scan, 'PRODUCT_AI_REVIEW_DECISION', match ($decision) {
            'FALSE_POSITIVE' => 'Admin review cleared your product',
            'REQUEST_SELLER_EDIT' => 'Admin requested a product edit',
            default => 'Admin reviewed your product listing',
        }, match ($decision) {
            'FALSE_POSITIVE' => 'Admin confirmed the automated flag was incorrect. Any AI-only listing hold has been removed when the current listing still matches the reviewed version.',
            'REQUEST_SELLER_EDIT' => 'Admin reviewed the automated signal and requested a product listing edit. Review the compliance notice for details.',
            default => 'Admin completed a human review of the product listing. Review the compliance notice for the next step. Admin note: '.$reason,
        });
    }

    private function notifyAppealDecision(ProductModerationScan $scan, string $decision, string $reason): void
    {
        $this->notifySellerOfReview($scan, match ($decision) {
            'REQUEST_CLARIFICATION' => 'PRODUCT_AI_APPEAL_CLARIFICATION',
            default => 'PRODUCT_AI_APPEAL_DECISION',
        }, match ($decision) {
            'APPROVED' => 'Product moderation appeal approved',
            'REQUEST_CLARIFICATION' => 'Admin requested more appeal information',
            default => 'Product moderation appeal denied',
        }, match ($decision) {
            'APPROVED' => 'Admin approved your appeal and cleared the AI moderation decision. Any AI-only listing hold has been removed when the current listing still matches the reviewed version. Admin note: '.$reason,
            'REQUEST_CLARIFICATION' => 'Admin needs more information before deciding your appeal. Add a clarification from your compliance notice. Admin request: '.$reason,
            default => 'Admin denied your appeal. The listing decision remains in place. Review your compliance notice for the reason and next steps. Admin note: '.$reason,
        });
    }

    private function notifySellerOfReview(ProductModerationScan $scan, string $type, string $title, string $message): void
    {
        $seller = $scan->product?->sellerProfile?->user;
        if (! $seller || Notification::query()
            ->where('user_id', $seller->id)
            ->where('type', $type)
            ->where('reference_type', ProductModerationScan::class)
            ->where('reference_id', $scan->id)
            ->exists()) {
            return;
        }

        Notification::create([
            'user_id' => $seller->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'reference_type' => ProductModerationScan::class,
            'reference_id' => $scan->id,
            'action_url' => $scan->seller_compliance_case_id
                ? route('seller.compliance.show', ['case' => $scan->seller_compliance_case_id])
                : route('seller.products'),
        ]);
    }

    public function notifyAdminsOfAppeal(ProductModerationScan $scan, bool $clarification = false): void
    {
        $type = $clarification ? 'PRODUCT_AI_APPEAL_CLARIFICATION' : 'PRODUCT_AI_APPEAL';
        $admins = User::query()->where('account_type', User::TYPE_ADMIN)->where('status', User::STATUS_ACTIVE)->get(['id']);
        foreach ($admins as $admin) {
            if (Notification::query()
                ->where('user_id', $admin->id)
                ->where('type', $type)
                ->where('reference_type', ProductModerationScan::class)
                ->where('reference_id', $scan->id)
                ->exists()) {
                continue;
            }
            Notification::create([
                'user_id' => $admin->id,
                'type' => $type,
                'title' => $clarification ? 'Seller added appeal clarification' : 'Product moderation appeal submitted',
                'message' => $clarification
                    ? 'A Seller added clarification to the appeal for product #'.$scan->product_id.'. Review the updated appeal record.'
                    : 'A Seller submitted an appeal for product #'.$scan->product_id.'. Review the appeal and private evidence before deciding.',
                'reference_type' => ProductModerationScan::class,
                'reference_id' => $scan->id,
                'action_url' => route('admin.products.show', ['product' => $scan->product_id]),
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function healthSnapshot(): array
    {
        $configured = $this->provider->configured();
        $enabled = $this->featureEnabled();
        $tableReady = false;
        $lastSuccess = null;
        $recentFailures = null;
        $totalScans = null;
        try {
            $tableReady = Schema::hasTable('product_moderation_scans');
            if ($tableReady) {
                $lastSuccess = ProductModerationScan::query()->where('scan_status', 'COMPLETE')->max('scanned_at');
                $totalScans = ProductModerationScan::query()->count();
                $recentFailures = ProductModerationScan::query()
                    ->whereIn('scan_status', ['SCAN_FAILED', 'AI_SCAN_DEFERRED'])
                    ->where('updated_at', '>=', now()->subDay())
                    ->count();
            }
        } catch (Throwable) {
            $tableReady = false;
        }

        $status = ! $enabled
            ? 'DISABLED'
            : (! $configured
                ? 'NOT CONFIGURED'
            : (! $tableReady || (int) $totalScans === 0
                ? 'UNKNOWN'
                : (((int) $recentFailures > 0) ? 'DEGRADED' : 'HEALTHY')));

        return [
            'status' => $status,
            'provider' => $this->provider->providerLabel(),
            'primary_model' => $this->provider->primaryModel(),
            'object_model' => $this->provider->objectModel(),
            'secondary_model' => config('product_moderation.models.secondary'),
            'enabled' => $enabled,
            'configured' => $configured,
            'primary_configured' => strtolower((string) config('product_moderation.provider')) === 'gemini'
                ? filled(config('services.gemini.key'))
                : (filled(config('services.cloudflare.account_id')) && filled(config('services.cloudflare.ai_token'))),
            'fallback_configured' => strtolower((string) config('product_moderation.fallback.provider')) === 'gemini'
                ? filled(config('services.gemini.key'))
                : (filled(config('services.cloudflare.account_id')) && filled(config('services.cloudflare.ai_token'))),
            'table_ready' => $tableReady,
            'last_successful_scan' => $lastSuccess,
            'recent_failures_24h' => $recentFailures,
            'total_scans' => $totalScans,
            'quota_status' => 'UNKNOWN',
        ];
    }

    private function notifyProductReview(ProductModerationScan $scan, Product $product): void
    {
        $case = $this->openComplianceCase($scan, $product);
        if ($case) {
            $scan->update(['seller_compliance_case_id' => $case->id]);
        }
        $seller = $product->sellerProfile?->user;
        if (! $seller || Notification::query()
            ->where('user_id', $seller->id)
            ->where('type', 'PRODUCT_AI_MODERATION')
            ->where('reference_type', ProductModerationScan::class)
            ->where('reference_id', $scan->id)
            ->exists()) {
            return;
        }

        $high = in_array($scan->overall_risk, ['HIGH', 'CRITICAL'], true);
        Notification::create([
            'user_id' => $seller->id,
            'type' => 'PRODUCT_AI_MODERATION',
            'title' => $high ? 'Product temporarily held for review' : 'Product requires review',
            'message' => $high
                ? 'LIKHAE automated screening identified a possible restricted item. This listing is temporarily unavailable while Admin reviews it. You may submit an appeal or update the listing if requested.'
                : 'LIKHAE automated screening identified a possible policy concern. Please review your listing details and update any unclear or inaccurate information. An Admin may review the listing.',
            'reference_type' => ProductModerationScan::class,
            'reference_id' => $scan->id,
            'action_url' => $case ? route('seller.compliance.show', ['case' => $case->id]) : route('seller.products'),
        ]);
    }

    private function notifyAdmins(ProductModerationScan $scan, Product $product): void
    {
        $obfuscationSignal = (float) data_get($scan->text_findings, 'disguised_probability', 0)
            >= (float) config('product_moderation.risk.medium', 0.30);
        $highRisk = in_array($scan->overall_risk, ['HIGH', 'CRITICAL'], true);
        $admins = User::query()->where('account_type', User::TYPE_ADMIN)->where('status', User::STATUS_ACTIVE)->get(['id']);
        foreach ($admins as $admin) {
            if (Notification::query()
                ->where('user_id', $admin->id)
                ->where('type', 'PRODUCT_AI_MODERATION_ALERT')
                ->where('reference_type', ProductModerationScan::class)
                ->where('reference_id', $scan->id)
                ->exists()) {
                continue;
            }
            Notification::create([
                'user_id' => $admin->id,
                'type' => 'PRODUCT_AI_MODERATION_ALERT',
                'title' => $scan->overall_risk === 'CRITICAL'
                    ? 'Urgent AI product review'
                    : ($obfuscationSignal && ! $highRisk ? 'Possible listing evasion needs review' : 'AI product review required'),
                'message' => $obfuscationSignal && ! $highRisk
                    ? 'Product #'.$product->id.' has an AI signal of possible evasive or unusually unclear listing text. Verify the listing context before deciding; the signal is advisory.'
                    : 'Product #'.$product->id.' requires human review for a possible prohibited item ('.$scan->overall_risk.').',
                'reference_type' => ProductModerationScan::class,
                'reference_id' => $scan->id,
                'action_url' => route('admin.products.show', ['product' => $product->id]),
            ]);
        }
    }

    private function openComplianceCase(ProductModerationScan $scan, Product $product): ?SellerComplianceCase
    {
        $case = SellerComplianceCase::query()
            ->where('product_id', $product->id)
            ->where('violation_type', 'PRODUCT_MODERATION')
            ->whereIn('status', ['OPEN', 'UNDER_REVIEW'])
            ->latest('id')
            ->first();
        if ($case) {
            return $case;
        }

        $categories = collect($scan->detected_categories ?? [])->map(fn ($category): string => str($category)->replace('_', ' ')->lower()->toString())->implode(', ');
        $description = $scan->overall_risk === 'UNKNOWN'
            ? 'AI moderation could not confidently classify this listing. Please review or clarify the product details.'
            : 'Automated screening identified a possible marketplace policy concern'.($categories !== '' ? ' (' . $categories . ')' : '').'. This is not a legal conclusion. Admin review is required.';

        return SellerComplianceCase::create([
            'case_number' => 'AI-'.Str::upper(Str::random(14)),
            'seller_profile_id' => $product->seller_profile_id,
            'product_id' => $product->id,
            'opened_by_admin_user_id' => null,
            'violation_type' => 'PRODUCT_MODERATION',
            'description' => $description,
            'status' => 'OPEN',
            'opened_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function productState(Product $product): array
    {
        $category = $product->category;
        $description = $this->cleanText((string) ($product->description ?? ''));
        $title = $this->cleanText((string) $product->name);
        $variantNames = $product->variants
            ->map(fn ($variant): string => $this->cleanText((string) $variant->description))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $attributes = $product->options
            ->map(fn ($option): array => [
                'name' => $this->cleanText((string) $option->name),
                'values' => $option->values->pluck('value')->map(fn ($value): string => $this->cleanText((string) $value))->values()->all(),
            ])
            ->values()
            ->all();

        return [
            'title' => $title,
            'description' => mb_substr($description, 0, 10_000),
            'category' => $this->cleanText((string) ($category?->parent?->name ?? '')),
            'subcategory' => $this->cleanText((string) ($category?->name ?? '')),
            'variant_names' => $variantNames,
            'seller_entered_attributes' => $attributes,
            'obfuscation_hints' => $this->obfuscationHints($title.' '.$description.' '.implode(' ', $variantNames)),
        ];
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;

        return trim(mb_substr($text, 0, 2_000));
    }

    /** @return list<string> */
    private function obfuscationHints(string $text): array
    {
        $hints = [];
        if (preg_match_all('/(?<![\pL\pN])(?:[\pL][\s.*_-]){1,}[\pL](?![\pL\pN])/u', $text, $matches)) {
            foreach ($matches[0] as $match) {
                $collapsed = preg_replace('/[^\pL]+/u', '', $match);
                if (is_string($collapsed) && mb_strlen($collapsed) >= 3) {
                    $hints[] = mb_strtolower($collapsed);
                }
            }
        }
        if (preg_match_all('/(?<!\pL)[\pL\pN*._-]{3,}(?!\pL)/u', $text, $tokens)) {
            foreach ($tokens[0] as $token) {
                if (preg_match('/\d|[*.]/u', $token)) {
                    $normalized = strtr(mb_strtolower($token), ['3' => 'e', '0' => 'o', '1' => 'i', '4' => 'a', '5' => 's', '*' => '', '.' => '', '-' => '_']);
                    $hints[] = $normalized;
                }
            }
        }

        return array_values(array_unique(array_slice($hints, 0, 12)));
    }

    /** @return array<string, mixed> */
    private function safeEvaluation(array $evaluation): array
    {
        return [
            'available' => (bool) ($evaluation['available'] ?? false),
            'category_probabilities' => $evaluation['category_probabilities'] ?? [],
            'top_category' => $evaluation['top_category'] ?? null,
            'confidence' => $evaluation['confidence'] ?? null,
            'provider_source' => $evaluation['provider_source'] ?? null,
            'model_source' => $evaluation['model_source'] ?? null,
            'used_fallback' => (bool) ($evaluation['used_fallback'] ?? false),
            'mismatch_probability' => $evaluation['mismatch_probability'] ?? null,
            'disguised_probability' => $evaluation['disguised_probability'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function modelVersions(): array
    {
        return [
            'provider' => $this->provider->providerLabel(),
            'primary' => $this->provider->primaryModel(),
            'object' => $this->provider->objectModel(),
            'fallback_provider' => config('product_moderation.fallback.provider'),
            'fallback_model' => strtolower((string) config('product_moderation.fallback.provider')) === 'gemini'
                ? config('services.gemini.model')
                : config('product_moderation.models.primary'),
            'secondary' => null,
        ];
    }
}
