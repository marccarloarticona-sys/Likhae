<?php

namespace App\Models\Returns;

use App\Models\Buyer\ReturnRefundRequestItem;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnInspection extends Model
{
    use HasFactory;

    public const RESTOCKABLE = 'RESTOCKABLE';
    public const DAMAGED = 'DAMAGED';
    public const QUARANTINED = 'QUARANTINED';
    public const NON_RESELLABLE = 'NON_RESELLABLE';

    protected $fillable = [
        'seller_return_refund_request_id', 'return_refund_request_item_id', 'inspector_user_id', 'inspection_status',
        'received_condition', 'completeness_status', 'damage_notes', 'missing_accessories', 'identifier_result',
        'evidence_metadata', 'inventory_disposition', 'received_at', 'inspected_at', 'notes',
        'restocked_at', 'restocked_by_user_id',
    ];

    protected function casts(): array { return ['evidence_metadata' => 'array', 'received_at' => 'datetime', 'inspected_at' => 'datetime', 'restocked_at' => 'datetime']; }
    public function sellerRequest(): BelongsTo { return $this->belongsTo(SellerReturnRefundRequest::class, 'seller_return_refund_request_id'); }
    public function requestItem(): BelongsTo { return $this->belongsTo(ReturnRefundRequestItem::class, 'return_refund_request_item_id'); }
    public function inspector(): BelongsTo { return $this->belongsTo(User::class, 'inspector_user_id'); }
    public function restockedBy(): BelongsTo { return $this->belongsTo(User::class, 'restocked_by_user_id'); }
}
