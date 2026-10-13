<?php

namespace App\Models\Finance;

use App\Models\Logistics\Shipment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiderEarningPool extends Model
{
    protected $fillable = [
        'shipment_financial_allocation_id', 'shipment_id', 'total_amount', 'approved_amount',
        'paid_amount', 'unresolved_share_amount', 'status', 'settlement_reference',
        'approved_by_user_id', 'approved_at', 'paid_at', 'notes',
    ];

    protected function casts(): array
    {
        return ['total_amount' => 'decimal:2', 'approved_amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'unresolved_share_amount' => 'decimal:2', 'approved_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function allocation(): BelongsTo { return $this->belongsTo(ShipmentFinancialAllocation::class, 'shipment_financial_allocation_id'); }
    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function participants(): HasMany { return $this->hasMany(RiderEarningPoolParticipant::class); }
}
