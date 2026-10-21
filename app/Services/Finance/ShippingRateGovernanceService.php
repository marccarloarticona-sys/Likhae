<?php

namespace App\Services\Finance;

use App\Models\Admin\Notification;
use App\Models\Finance\FinanceAuditEvent;
use App\Models\Finance\ShippingRateChangeRequest;
use App\Models\Finance\ShippingRateVersion;
use App\Models\User;
use App\Support\FinancialRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
        $isCommissionRequest = array_key_exists('requested_rider_pool_percentage', $data)
            && $data['requested_rider_pool_percentage'] !== null
            && $data['requested_rider_pool_percentage'] !== '';
        if ($isCommissionRequest && (! Schema::hasColumn('shipping_rate_change_requests', 'current_rider_pool_percentage')
            || ! Schema::hasColumn('shipping_rate_change_requests', 'requested_rider_pool_percentage'))) {
            throw new RuntimeException('The reviewed commission-request migration must be applied before Logistics can request a Rider share change.');
        }
        if ($isCommissionRequest && (! is_numeric($data['requested_rider_pool_percentage'])
            || (float) $data['requested_rider_pool_percentage'] < 0
            || (float) $data['requested_rider_pool_percentage'] > 100)) {
            throw ValidationException::withMessages(['requested_rider_pool_percentage' => 'Enter a Rider share from 0 to 100%.']);
        }
        if (preg_match('/<[^>]*>/', $reasonDetails)) {
            throw ValidationException::withMessages(['reason_details' => 'HTML is not allowed in the request note.']);
        }

        return DB::transaction(function () use ($requester, $data, $reasonDetails, $isCommissionRequest): ShippingRateChangeRequest {
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
            $requestedCents = $isCommissionRequest
                ? $currentCents
                : FinancialRules::cents((string) $data['requested_shipping_fee']);
            if (! $isCommissionRequest && $requestedCents <= 0) {
                throw ValidationException::withMessages(['requested_shipping_fee' => 'Enter a shipping fee greater than zero.']);
            }
            if (! $isCommissionRequest && $requestedCents === $currentCents) {
                throw ValidationException::withMessages(['requested_shipping_fee' => 'The requested fee must differ from the current approved fee.']);
            }

            $currentRiderPercentage = (string) $currentVersion->rider_pool_percentage;
            $requestedRiderPercentage = $isCommissionRequest
                ? number_format((float) $data['requested_rider_pool_percentage'], 4, '.', '')
                : null;
            if ($isCommissionRequest && (float) $requestedRiderPercentage === (float) $currentRiderPercentage) {
                throw ValidationException::withMessages(['requested_rider_pool_percentage' => 'The requested Rider share must differ from the current approved share.']);
            }

            $difference = $requestedCents - $currentCents;
            $percentageUnits = $difference === 0
                ? 0
                : intdiv((abs($difference) * 1_000_000) + intdiv($currentCents, 2), $currentCents);
            $attributes = [
                'requesting_logistics_user_id' => $lockedRequester->id,
                'current_shipping_fee' => FinancialRules::amount($currentCents),
                'requested_shipping_fee' => FinancialRules::amount($requestedCents),
                'change_type' => $isCommissionRequest ? 'RIDER_SHARE' : ($difference > 0 ? 'INCREASE' : 'DECREASE'),
                'change_amount' => FinancialRules::amount($difference),
                'change_percentage' => self::decimalFromUnits($percentageUnits, 4, $difference < 0),
                'reason_code' => $data['reason_code'],
                'reason_details' => $reasonDetails !== '' ? $reasonDetails : null,
                'requested_effective_at' => null,
                'status' => ShippingRateChangeRequest::STATUS_PENDING,
            ];
            if (Schema::hasColumn('shipping_rate_change_requests', 'idempotency_key')) {
                $attributes['idempotency_key'] = (string) Str::uuid();
            } else {
                unset($attributes['idempotency_key']);
            }
            if ($isCommissionRequest) {
                $attributes['current_rider_pool_percentage'] = $currentRiderPercentage;
                $attributes['requested_rider_pool_percentage'] = $requestedRiderPercentage;
            }
            $request = ShippingRateChangeRequest::query()->create($attributes);

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
            $commissionRequest = $lockedRequest->requested_rider_pool_percentage !== null;
            $baselineIsStale = FinancialRules::cents((string) $current->shipping_fee)
                !== FinancialRules::cents((string) $lockedRequest->current_shipping_fee)
                || ($commissionRequest && (float) $current->rider_pool_percentage
                    !== (float) $lockedRequest->current_rider_pool_percentage);
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
                'shipping_fee' => $commissionRequest
                    ? (string) $current->shipping_fee
                    : FinancialRules::amount(FinancialRules::cents((string) $lockedRequest->requested_shipping_fee)),
                'rider_pool_percentage' => $commissionRequest
                    ? (string) $lockedRequest->requested_rider_pool_percentage
                    : $current->rider_pool_percentage,
                'logistics_percentage' => $commissionRequest
                    ? number_format(100 - (float) $lockedRequest->requested_rider_pool_percentage, 4, '.', '')
                    : $current->logistics_percentage,
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
                    : ($commissionRequest
                        ? 'Admin approved the requested Rider/Logistics shipping share; activation was immediate.'
                        : 'Admin approved the exact amount requested; activation was immediate.'),
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

    public function updateCommissionSplit(User $admin, string|int|float $logisticsPercentage): ShippingRateVersion
    {
        $this->requireAdmin($admin);
        $this->requireSchema();
        if (! is_numeric($logisticsPercentage) || (float) $logisticsPercentage < 0 || (float) $logisticsPercentage > 100) {
            throw ValidationException::withMessages(['logistics_percentage' => 'Enter a Logistics shipping share from 0 to 100%.']);
        }

        $logisticsPercentage = number_format((float) $logisticsPercentage, 4, '.', '');
        $riderPercentage = number_format(100 - (float) $logisticsPercentage, 4, '.', '');

        return DB::transaction(function () use ($admin, $logisticsPercentage, $riderPercentage): ShippingRateVersion {
            $this->lockGlobalWriter();
            $current = $this->rates->currentVersion(lockForUpdate: true);
            if (! $current) {
                throw ValidationException::withMessages(['shipping_rate' => 'Initialize the current global shipping policy before changing its split.']);
            }
            if ((float) $current->logistics_percentage === (float) $logisticsPercentage) {
                throw ValidationException::withMessages(['logistics_percentage' => 'The Logistics share already matches the active policy.']);
            }

            $now = now();
            $before = $this->versionState($current);
            $current->forceFill(['effective_until' => $now, 'status' => ShippingRateVersion::STATUS_SUPERSEDED])->save();
            $version = ShippingRateVersion::query()->create([
                'scope' => ShippingRateVersion::SCOPE_GLOBAL,
                'shipping_fee' => $current->shipping_fee,
                'rider_pool_percentage' => $riderPercentage,
                'logistics_percentage' => $logisticsPercentage,
                'pickup_rider_weight' => $current->pickup_rider_weight,
                'hub_to_hub_rider_weight' => $current->hub_to_hub_rider_weight,
                'last_mile_rider_weight' => $current->last_mile_rider_weight,
                'effective_from' => $now,
                'effective_until' => null,
                'status' => ShippingRateVersion::STATUS_ACTIVE,
                'approved_by_admin_user_id' => $admin->id,
                'source_request_id' => null,
            ]);
            $this->audit(
                'SHIPPING_COMMISSION_SPLIT_UPDATED',
                $version,
                $admin,
                $before,
                $this->versionState($version),
                'Admin changed the active shipping revenue split. Existing SellerOrder rate snapshots remain unchanged.',
            );

            return $version;
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
        $message = ($request->requester?->name ?: 'A Logistics operator').' requested '
            .($request->requested_rider_pool_percentage !== null
                ? 'a Rider shipping share change from '.$request->current_rider_pool_percentage.'% to '.$request->requested_rider_pool_percentage.'%.'
                : 'a shipping fee change from PHP '.self::money(FinancialRules::cents((string) $request->current_shipping_fee))
                    .' to PHP '.self::money(FinancialRules::cents((string) $request->requested_shipping_fee)).'.');

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
        $commissionRequest = $request->requested_rider_pool_percentage !== null;
        $title = $approved
            ? ($commissionRequest ? 'Shipping Commission Request Approved' : 'Shipping Fee Request Approved')
            : ($commissionRequest ? 'Shipping Commission Request Rejected' : 'Shipping Fee Request Rejected');
        $message = $commissionRequest
            ? ($approved
                ? 'Your requested Rider shipping share of '.$request->requested_rider_pool_percentage.'% was approved. The Logistics share is now '.number_format(100 - (float) $request->requested_rider_pool_percentage, 4).'%.'
                : 'Your requested Rider shipping share of '.$request->requested_rider_pool_percentage.'% was rejected. The active split remains Rider '.$request->current_rider_pool_percentage.'% / Logistics '.number_format(100 - (float) $request->current_rider_pool_percentage, 4).'%. Reason: '.(string) $request->admin_decision_reason)
            : ($approved
                ? 'Your request to change the global shipping fee from PHP '.self::money(FinancialRules::cents((string) $request->current_shipping_fee))
                    .' to PHP '.self::money(FinancialRules::cents((string) $request->requested_shipping_fee)).' was approved and is effective now.'
                : 'Your request for PHP '.self::money(FinancialRules::cents((string) $request->requested_shipping_fee))
                    .' was rejected. The current rate remains PHP '.self::money(FinancialRules::cents((string) $request->current_shipping_fee))
                    .'. Reason: '.(string) $request->admin_decision_reason);

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
            'current_rider_pool_percentage' => $request->current_rider_pool_percentage === null ? null : (string) $request->current_rider_pool_percentage,
            'requested_rider_pool_percentage' => $request->requested_rider_pool_percentage === null ? null : (string) $request->requested_rider_pool_percentage,
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
