<?php

namespace App\Models\Logistics;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShipmentTransferLeg extends Model
{
    public const PLANNED = 'PLANNED';
    public const READY = 'READY';
    public const RELEASED = 'RELEASED';
    public const IN_TRANSIT = 'IN_TRANSIT';
    public const RECEIVED = 'RECEIVED';
    public const CANCELLED = 'CANCELLED';

    protected $fillable = [
        'shipment_id', 'sequence_no', 'origin_logistics_center_id', 'destination_logistics_center_id',
        'origin_province_code', 'origin_municipality_code', 'destination_province_code',
        'destination_municipality_code', 'status', 'released_by_user_id', 'released_at',
        'received_by_user_id', 'received_at',
    ];

    protected function casts(): array
    {
        return ['sequence_no' => 'integer', 'released_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function originLogisticsCenter(): BelongsTo { return $this->belongsTo(LogisticsCenter::class, 'origin_logistics_center_id'); }
    public function destinationLogisticsCenter(): BelongsTo { return $this->belongsTo(LogisticsCenter::class, 'destination_logistics_center_id'); }
    public function releasedBy(): BelongsTo { return $this->belongsTo(User::class, 'released_by_user_id'); }
    public function receivedBy(): BelongsTo { return $this->belongsTo(User::class, 'received_by_user_id'); }
    public function manifestItems(): HasMany { return $this->hasMany(TransferManifestItem::class, 'transfer_leg_id'); }
    public function scans(): HasMany { return $this->hasMany(ParcelScan::class, 'transfer_leg_id'); }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::RECEIVED, self::CANCELLED]);
    }
}
