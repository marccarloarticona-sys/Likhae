<?php

namespace App\Models\Logistics;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferManifestItem extends Model
{
    public const EXPECTED = 'EXPECTED';
    public const RECEIVED = 'RECEIVED';
    public const MISSING = 'MISSING';
    public const DAMAGED = 'DAMAGED';
    public const REJECTED = 'REJECTED';

    protected $fillable = [
        'manifest_id', 'transfer_leg_id', 'added_by_user_id', 'added_at', 'status',
        'received_by_user_id', 'received_at', 'exception_type', 'exception_notes',
    ];

    protected function casts(): array
    {
        return ['added_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function manifest(): BelongsTo { return $this->belongsTo(TransferManifest::class, 'manifest_id'); }
    public function transferLeg(): BelongsTo { return $this->belongsTo(ShipmentTransferLeg::class, 'transfer_leg_id'); }
    public function addedBy(): BelongsTo { return $this->belongsTo(User::class, 'added_by_user_id'); }
    public function receivedBy(): BelongsTo { return $this->belongsTo(User::class, 'received_by_user_id'); }
}
