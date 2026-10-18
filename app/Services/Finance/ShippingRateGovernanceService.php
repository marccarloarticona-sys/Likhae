<?php

namespace App\Services\Finance;

use App\Models\Admin\Notification;
use App\Models\Finance\FinanceAuditEvent;
use App\Models\Finance\ShippingRateChangeRequest;
use App\Models\Finance\ShippingRateVersion;
use App\Models\User;
use App\Support\FinancialRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ShippingRateGovernanceService
{
    public function __construct(private readonly ShippingRatePolicyService $rates) {}

    public function submitRequest(User $requester, array $data): ShippingRateChangeRequest
    {
        $this->requireLogisticsOwner($requester);
        $this->requireSchema();
        if (! array_key_exists((string) ($data['reason_code'] ?? ''), ShippingRateChangeRequest::REASONS)) {
            throw ValidationException::withMessages(['reason_code' => 'Select a supported shipping-rate request reason.']);
        }
        $reasonDetails = trim((string) ($data['reason_details'] ?? ''));
        if (($data['reason_code'] ?? null) === 'OTHER' && $reasonDetails === '') {
            throw ValidationException::withMessages(['reason_details' => 'Explain the reason when you select Other.']);
        }
        if (preg_match('/<[^>]*>/', $reasonDetails)) {
            throw ValidationException::withMessages(['reason_details' => 'HTML is not allowed in the request note.']);
        }

        return DB::transaction(function () use ($requester, $data, $reasonDetails): ShippingRateChangeRequest {
            // Locking the requester's stable users row serializes duplicate
            // checks for that operator under MySQL/InnoDB transactions.
            $lockedRequester = User::query()->whereKey($requester->id)->lockForUpdate()->firstOrFail();
            $this->requireLogisticsOwner($lockedRequester);

            $pending = ShippingRateChangeRequest::query()
                ->where('requesting_logistics_user_id', $lockedRequester->id)
                ->pending()
                ->lockForUpdate()
                ->exists();
            if ($pending) {
                throw ValidationException::withMessages([
                    'requested_shipping_fee' => 'You already have a pending shipping-rate request. Cancel it or wait for Admin review.',
                ]);
            }

            $currentVersion = $this->rates->currentVersion(lockForUpdate: true);
            if (! $currentVersion) {
                throw ValidationException::withMessages([
                    'shipping_rate' => 'An Admin must initialize the current global rate before Logistics can submit a request.',
                ]);
            }

            $currentCents = FinancialRules::cents((string) $currentVersion->shipping_fee);
            $requestedCents = FinancialRules::cents((string) $data['requested_shipping_fee']);
            if ($requestedCents <= 0) {
                throw ValidationException::withMessages(['requested_shipping_fee' => 'Enter a shipping fee greater than zero.']);
            }
            if ($requestedCents === $currentCents) {
                throw ValidationException::withMessages(['requested_shipping_fee' => 'The requested fee must differ from the current approved fee.']);
            }

            $difference = $requestedCents - $currentCents;
            $percentageUnits = intdiv((abs($difference) * 1_000_000) + intdiv($currentCents, 2), $currentCents);
            $request = ShippingRateChangeRequest::query()->create([
                'requesting_logistics_user_id' => $lockedRequester->id,
                'current_shipping_fee' => FinancialRules::amount($currentCents),
                'requested_shipping_fee' => FinancialRules::amount($requestedCents),
                'change_type' => $difference > 0 ? 'INCREASE' : 'DECREASE',
                'change_amount' => FinancialRules::amount($difference),
                'change_percentage' => self::decimalFromUnits($percentageUnits, 4, $difference < 0),
                'reason_code' => $data['reason_code'],
                'reason_details' => $reasonDetails !== '' ? $reasonDetails : null,
                'requested_effective_at' => null,
                'status' => ShippingRateChangeRequest::STATUS_PENDING,
            ]);

            $this->audit(
                'LOGISTICS_SHIPPING_RATE_REQUESTED',
                $request,
                $lockedRequester,
                null,
                $this->requestState($request),
                'Logistics submitted a global shipping-rate proposal.',
            );
            $this->notifyAdminsOfRequest($request);

            return $request;
        }, 3);
    }

    public function cancelRequest(User $requester, ShippingRateChangeRequest $request): ShippingRateChangeRequest
    {
        $this->requireLogisticsOwner($requester);
        $this->requireSchema();

        return DB::transaction(function () use ($requester, $request): ShippingRateChangeRequest {
            $locked = ShippingRateChangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->requesting_logistics_user_id === (int) $requester->id, 403);
            if ($locked->status !== ShippingRateChangeRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['request' => 'Only your pending request can be cancelled.']);
            }

            $before = $this->requestState($locked);
            $locked->forceFill(['status' => ShippingRateChangeRequest::STATUS_CANCELLED])->save();
            $this->audit(
                'LOGISTICS_SHIPPING_RATE_REQUEST_CANCELLED',
                $locked,
                $requester,
                $before,
                $this->requestState($locked),
                'Logistics cancelled its own pending global shipping-rate request.',
            );

            return $locked->refresh();
        }, 3);
    }

    public function initializeGlobalRate(User $admin): ShippingRateVersion
    {
        $this->requireAdmin($admin);
        $this->requireSchema();

        return DB::transaction(function () use ($admin): ShippingRateVersion {
            $this->lockGlobalWriter();
            $existing = $this->rates->currentVersion(lockForUpdate: true);
            if ($existing) {
                return $existing;
            }

            $feeCents = FinancialRules::cents((string) config('likhae.standard_shipping_fee', 50));
            if ($feeCents <= 0) {
                throw new RuntimeException('The configured bootstrap shipping fee must be greater than zero.');
            }

            $version = ShippingRateVersion::query()->create([
                'scope' => ShippingRateVersion::SCOPE_GLOBAL,
                'shipping_fee' => FinancialRules::amount($feeCents),
                'rider_pool_percentage' => ShippingRatePolicyService::DEFAULT_RIDER_PERCENTAGE,
                'logistics_percentage' => ShippingRatePolicyService::DEFAULT_LOGISTICS_PERCENTAGE,
                'pickup_rider_weight' => ShippingRatePolicyService::DEFAULT_PICKUP_WEIGHT,
                'hub_to_hub_rider_weight' => ShippingRatePolicyService::DEFAULT_HUB_WEIGHT,
                'last_mile_rider_weight' => ShippingRatePolicyService::DEFAULT_LAST_MILE_WEIGHT,
                'effective_from' => now(),
                'effective_until' => null,
                'status' => ShippingRateVersion::STATUS_ACTIVE,
                'approved_by_admin_user_id' => $admin->id,
                'source_request_id' => null,
            ]);

            $this->audit(
                'SHIPPING_RATE_ACTIVATED',
                $version,
                $admin,
                null,
                $this->versionState($version),
                'Admin initialized the existing global shipping policy from the documented config fallback.',
            );

            return $version;
        }, 3);
    }

    public function approve(
        User $admin,
        ShippingRateChangeRequest $request,
        ?string $decisionReason = null,
        bool $confirmStaleBaseline = false,
        ?int $expectedActiveVersionId = null,
    ): ShippingRateVersion
    {
        $this->requireAdmin($admin);
        $this->requireSchema();

        return DB::transaction(function () use ($admin, $request, $decisionReason, $confirmStaleBaseline, $expectedActiveVersionId): ShippingRateVersion {
            $this->lockGlobalWriter();
            $lockedRequest = ShippingRateChangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($lockedRequest->status !== ShippingRateChangeRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['request' => 'This request is no longer pending review.']);
            }

            $current = $this->rates->currentVersion(lockForUpdate: true);
            if (! $current) {
                throw ValidationException::withMessages(['request' => 'Initialize the current global rate before approving change requests.']);
            }
            if ($expectedActiveVersionId === null || (int) $current->id !== $expectedActiveVersionId) {
                throw ValidationException::withMessages([
                    'expected_active_version_id' => 'The active GLOBAL rate changed after this review page was loaded. Refresh the page and review the current predecessor before approving.',
                ]);
            }
            $baselineIsStale = FinancialRules::cents((string) $current->shipping_fee)
                !== FinancialRules::cents((string) $lockedRequest->current_shipping_fee);
            if ($baselineIsStale && ! $confirmStaleBaseline) {
                throw ValidationException::withMessages([
                    'confirm_stale_baseline' => 'The global rate changed after this request was submitted. Refresh the review page, then explicitly confirm the original baseline and add an Admin note before approving the exact requested target.',
                ]);
            }
            if ($baselineIsStale && blank($decisionReason)) {
                throw ValidationException::withMessages([
                    'admin_decision_reason' => 'Record why Admin is approving the exact target despite the changed request baseline.',
                ]);
            }

            $now = now();
            $versionBefore = $this->versionState($current);
            $requestBefore = $this->requestState($lockedRequest);
            $current->forceFill([
                'effective_until' => $now,
                'status' => ShippingRateVersion::STATUS_SUPERSEDED,
            ])->save();

            $version = ShippingRateVersion::query()->create([
                'scope' => ShippingRateVersion::SCOPE_GLOBAL,
                'shipping_fee' => FinancialRules::amount(FinancialRules::cents((string) $lockedRequest->requested_shipping_fee)),
                'rider_pool_percentage' => $current->rider_pool_percentage,
                'logistics_percentage' => $current->logistics_percentage,
                'pickup_rider_weight' => $current->pickup_rider_weight,
                'hub_to_hub_rider_weight' => $current->hub_to_hub_rider_weight,
                'last_mile_rider_weight' => $current->last_mile_rider_weight,
                'effective_from' => $now,
                'effective_until' => null,
                'status' => ShippingRateVersion::STATUS_ACTIVE,
                'approved_by_admin_user_id' => $admin->id,
                'source_request_id' => $lockedRequest->id,
            ]);

            $lockedRequest->forceFill([
                'status' => ShippingRateChangeRequest::STATUS_APPROVED,
                'reviewed_by_admin_user_id' => $admin->id,
                'reviewed_at' => $now,
                'admin_decision_reason' => filled($decisionReason) ? trim((string) $decisionReason) : null,
                'approved_effective_at' => $now,
            ])->save();

            $this->audit(
                'SHIPPING_RATE_REQUEST_APPROVED',
                $lockedRequest,
                $admin,
                ['request' => $requestBefore, 'previous_rate' => $versionBefore],
                ['request' => $this->requestState($lockedRequest), 'approved_rate' => $this->versionState($version)],
                $baselineIsStale
                    ? 'Admin confirmed the original request baseline was stale and approved the exact requested target against the current predecessor; activation was immediate.'
                    : 'Admin approved the exact amount requested; activation was immediate.',
            );
            $this->audit(
                'SHIPPING_RATE_ACTIVATED',
                $version,
                $admin,
                $versionBefore,
                $this->versionState($version),
                'New GLOBAL shipping-rate version became effective immediately.',
            );
            $this->notifyRequesterOfDecision($lockedRequest, approved: true);

            return $version;
        }, 3);
    }

    public function reject(User $admin, ShippingRateChangeRequest $request, string $reason): ShippingRateChangeRequest
    {
        $this->requireAdmin($admin);
        $this->requireSchema();
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['admin_decision_reason' => 'Provide a reason for rejecting the request.']);
        }

        return DB::transaction(function () use ($admin, $request, $reason): ShippingRateChangeRequest {
            $locked = ShippingRateChangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ShippingRateChangeRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['request' => 'This request is no longer pending review.']);
            }

            $before = $this->requestState($locked);
            $locked->forceFill([
                'status' => ShippingRateChangeRequest::STATUS_REJECTED,
                'reviewed_by_admin_user_id' => $admin->id,
                'reviewed_at' => now(),
                'admin_decision_reason' => $reason,
                'approved_effective_at' => null,
            ])->save();

            $this->audit(
                'SHIPPING_RATE_REQUEST_REJECTED',
                $locked,
                $admin,
                $before,
                $this->requestState($locked),
                'Admin rejected the request; the active global rate was not changed.',
            );
            $this->notifyRequesterOfDecision($locked, approved: false);

            return $locked->refresh();
        }, 3);
    }

    private function lockGlobalWriter(): void
    {
        // Every Admin rate write locks the same existing Admin user row. This
        // serializes bootstrap and approvals even when no rate row exists yet.
        $anchor = User::query()
            ->where('account_type', User::TYPE_ADMIN)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (! $anchor) {
            throw new RuntimeException('A persisted Admin account is required to change the global shipping rate.');
        }
    }

    private function notifyAdminsOfRequest(ShippingRateChangeRequest $request): void
    {
        $request->loadMissing('requester');
        $message = ($request->requester?->name ?: 'A Logistics operator')
            .' requested PHP '.self::money(FinancialRules::cents((string) $request->requested_shipping_fee))
            .' from PHP '.self::money(FinancialRules::cents((string) $request->current_shipping_fee))
            .' ('.strtolower($request->change_type).').';

        User::query()->where('account_type', User::TYPE_ADMIN)
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('id')
            ->get()
            ->each(function (User $admin) use ($request, $message): void {
                Notification::query()->firstOrCreate(
                    [
                        'user_id' => $admin->id,
                        'type' => 'SHIPPING_RATE_REQUESTED',
                        'reference_type' => 'SHIPPING_RATE_REQUEST',
                        'reference_id' => $request->id,
                    ],
                    [
                        'title' => 'Shipping Rate Change Request',
                        'message' => $message,
                        'action_url' => '/admin/shipping-rate-requests?status=PENDING',
                    ],
                );
            });
    }

    private function notifyRequesterOfDecision(ShippingRateChangeRequest $request, bool $approved): void
    {
        $currentFee = $approved
            ? $request->current_shipping_fee
            : $request->current_shipping_fee;
        $title = $approved ? 'Shipping Fee Request Approved' : 'Shipping Fee Request Rejected';
        $message = $approved
            ? 'Your request to change the global shipping fee from PHP '.self::money(FinancialRules::cents((string) $currentFee))
                .' to PHP '.self::money(FinancialRules::cents((string) $request->requested_shipping_fee))
                .' was approved and is effective now.'
            : 'Your request for PHP '.self::money(FinancialRules::cents((string) $request->requested_shipping_fee))
                .' was rejected. The current rate remains PHP '.self::money(FinancialRules::cents((string) $currentFee))
                .'. Reason: '.(string) $request->admin_decision_reason;

        Notification::query()->firstOrCreate(
            [
                'user_id' => $request->requesting_logistics_user_id,
                'type' => $approved ? 'SHIPPING_RATE_REQUEST_APPROVED' : 'SHIPPING_RATE_REQUEST_REJECTED',
                'reference_type' => 'SHIPPING_RATE_REQUEST',
                'reference_id' => $request->id,
            ],
            [
                'title' => $title,
                'message' => $message,
                'action_url' => '/logistics/shipping-rate',
            ],
        );
    }

    private function audit(string $event, ShippingRateChangeRequest|ShippingRateVersion $record, User $actor, ?array $before, ?array $after, ?string $notes): void
    {
        FinanceAuditEvent::query()->create([
            'event_type' => $event,
            'auditable_type' => $record::class,
            'auditable_id' => $record->id,
            'actor_user_id' => $actor->id,
            'before_state' => $before,
            'after_state' => $after,
            'notes' => $notes,
        ]);
    }

    private function requestState(ShippingRateChangeRequest $request): array
    {
        return [
            'request_id' => $request->id,
            'requesting_logistics_user_id' => $request->requesting_logistics_user_id,
            'current_shipping_fee' => (string) $request->current_shipping_fee,
            'requested_shipping_fee' => (string) $request->requested_shipping_fee,
            'change_type' => $request->change_type,
            'change_amount' => (string) $request->change_amount,
            'change_percentage' => (string) $request->change_percentage,
            'reason_code' => $request->reason_code,
            'status' => $request->status,
            'reviewed_by_admin_user_id' => $request->reviewed_by_admin_user_id,
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'admin_decision_reason' => $request->admin_decision_reason,
            'approved_effective_at' => $request->approved_effective_at?->toIso8601String(),
        ];
    }

    private function versionState(ShippingRateVersion $version): array
    {
        return [
            'version_id' => $version->id,
            'scope' => $version->scope,
            'shipping_fee' => (string) $version->shipping_fee,
            'rider_pool_percentage' => (string) $version->rider_pool_percentage,
            'logistics_percentage' => (string) $version->logistics_percentage,
            'pickup_rider_weight' => (int) $version->pickup_rider_weight,
            'hub_to_hub_rider_weight' => (int) $version->hub_to_hub_rider_weight,
            'last_mile_rider_weight' => (int) $version->last_mile_rider_weight,
            'effective_from' => $version->effective_from?->toIso8601String(),
            'effective_until' => $version->effective_until?->toIso8601String(),
            'status' => $version->status,
            'approved_by_admin_user_id' => $version->approved_by_admin_user_id,
            'source_request_id' => $version->source_request_id,
        ];
    }

    private function requireSchema(): void
    {
        if (! $this->rates->schemaReady()) {
            throw new RuntimeException('Shipping rate governance is unavailable until its reviewed migration has been executed.');
        }
    }

    private function requireLogisticsOwner(User $user): void
    {
        abort_unless($user->isAccountType(User::TYPE_LOGISTICS) && $user->logisticsCenter()->exists(), 403);
    }

    private function requireAdmin(User $user): void
    {
        abort_unless($user->isAccountType(User::TYPE_ADMIN) && $user->isActive(), 403);
    }

    private static function money(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.number_format(intdiv($absolute, 100), 0, '.', ',').'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function decimalFromUnits(int $units, int $places, bool $negative = false): string
    {
        $factor = 10 ** $places;
        $sign = $negative && $units > 0 ? '-' : '';

        return $sign.intdiv($units, $factor).'.'.str_pad((string) ($units % $factor), $places, '0', STR_PAD_LEFT);
    }
}
