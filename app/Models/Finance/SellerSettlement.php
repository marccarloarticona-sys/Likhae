<?php

namespace App\Models\Finance;

use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\Buyer\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerSettlement extends Model
{
    protected $fillable = [
        'shipment_financial_allocation_id', 'seller_order_id', 'order_id', 'seller_profile_id',
        'original_product_amount', 'discount_amount', 'platform_commission', 'seller_payable',
        'payout_amount', 'outstanding_balance', 'payout_status', 'settlement_reference',
        'authorized_by_user_id', 'authorized_at', 'paid_at', 'notes', 'payout_method',
        'payout_external_reference', 'payout_failure_reason', 'payout_failed_at',
        'payout_failed_by_user_id', 'seller_acknowledged_at', 'seller_acknowledged_by_user_id',
    ];

    protected function casts(): array
    {
        return ['original_product_amount' => 'decimal:2', 'discount_amount' => 'decimal:2', 'platform_commission' => 'decimal:2', 'seller_payable' => 'decimal:2', 'payout_amount' => 'decimal:2', 'outstanding_balance' => 'decimal:2', 'authorized_at' => 'datetime', 'paid_at' => 'datetime', 'payout_failed_at' => 'datetime', 'seller_acknowledged_at' => 'datetime'];
    }

    public function allocation(): BelongsTo { return $this->belongsTo(ShipmentFinancialAllocation::class, 'shipment_financial_allocation_id'); }
    public function sellerOrder(): BelongsTo { return $this->belongsTo(SellerOrder::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function sellerProfile(): BelongsTo { return $this->belongsTo(SellerProfile::class); }
    public function authorizedBy(): BelongsTo { return $this->belongsTo(User::class, 'authorized_by_user_id'); }
    public function payoutFailedBy(): BelongsTo { return $this->belongsTo(User::class, 'payout_failed_by_user_id'); }
    public function sellerAcknowledgedBy(): BelongsTo { return $this->belongsTo(User::class, 'seller_acknowledged_by_user_id'); }
}
