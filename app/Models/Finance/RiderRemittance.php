<?php

namespace App\Models\Finance;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Rider\RiderProfile;
use App\Models\User;
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
}
