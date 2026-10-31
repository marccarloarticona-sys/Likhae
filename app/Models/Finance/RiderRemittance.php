<?php

namespace App\Models\Finance;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Support\FinancialRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiderRemittance extends Model
{
    public const DRAFT = 'DRAFT';
    public const SUBMITTED = 'SUBMITTED';
    public const VERIFIED = 'VERIFIED';
    public const RECONCILED = 'RECONCILED';
    public const REJECTED = 'REJECTED';
    public const DISCREPANCY = 'DISCREPANCY';
    public const CANCELLED = 'CANCELLED';

    protected $fillable = [
        'rider_profile_id', 'logistics_center_id', 'remittance_reference', 'expected_amount',
        'submitted_amount', 'verified_amount', 'discrepancy_amount', 'status', 'submitted_at',
        'verified_at', 'submitted_by_user_id', 'verified_by_user_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'expected_amount' => 'decimal:2',
            'submitted_amount' => 'decimal:2',
            'verified_amount' => 'decimal:2',
            'discrepancy_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function riderProfile(): BelongsTo { return $this->belongsTo(RiderProfile::class); }
    public function logisticsCenter(): BelongsTo { return $this->belongsTo(LogisticsCenter::class); }
    public function submittedBy(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by_user_id'); }
    public function verifiedBy(): BelongsTo { return $this->belongsTo(User::class, 'verified_by_user_id'); }
    public function items(): HasMany { return $this->hasMany(RiderRemittanceItem::class); }

    public function coversCollectionWithVerifiedFullCash(CodCollection $collection): bool
    {
        if ($this->status !== self::RECONCILED
            || $this->verified_amount === null
            || (int) $this->logistics_center_id !== (int) $collection->logistics_center_id) {
            return false;
        }

        $this->loadMissing('items.codCollection');
        $items = $this->items;
        if ($items->isEmpty() || $items->contains(fn (RiderRemittanceItem $item): bool => ! $item->codCollection)) {
            return false;
        }

        $batchDueCents = $items->sum(fn (RiderRemittanceItem $item): int =>
            FinancialRules::cents($item->codCollection->expected_amount)
        );
        if ($batchDueCents <= 0
            || $batchDueCents !== FinancialRules::cents($this->expected_amount)
            || $batchDueCents !== FinancialRules::cents($this->verified_amount)) {
            return false;
        }

        $included = $items->contains(fn (RiderRemittanceItem $item): bool =>
            (int) $item->cod_collection_id === (int) $collection->id
        );
        if (! $included || $collection->collected_amount === null) {
            return false;
        }

        $buyerCollectedCents = FinancialRules::cents($collection->collected_amount);
        $collectionDueCents = FinancialRules::cents($collection->expected_amount);

        return match ($collection->status) {
            CodCollection::REMITTED => $buyerCollectedCents === $collectionDueCents,
            CodCollection::REMITTED_RIDER_TOPUP => $buyerCollectedCents < $collectionDueCents,
            // Recognize old reconciled receipts created before Rider top-ups
            // were represented as their own collection status.
            CodCollection::REMITTED_DISCREPANCY => $buyerCollectedCents < $collectionDueCents,
            default => false,
        };
    }
}
