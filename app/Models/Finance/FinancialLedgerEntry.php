<?php

namespace App\Models\Finance;

use App\Models\Buyer\Order;
use App\Models\Logistics\Shipment;
use App\Models\Seller\SellerOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialLedgerEntry extends Model
{
    protected $fillable = ['shipment_financial_allocation_id', 'shipment_id', 'seller_order_id', 'order_id', 'account_type', 'entry_type', 'direction', 'amount', 'status', 'idempotency_key', 'actor_user_id', 'posted_at', 'paid_at', 'metadata'];

    protected function casts(): array { return ['amount' => 'decimal:2', 'posted_at' => 'datetime', 'paid_at' => 'datetime', 'metadata' => 'array']; }

    public function allocation(): BelongsTo { return $this->belongsTo(ShipmentFinancialAllocation::class, 'shipment_financial_allocation_id'); }
    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function sellerOrder(): BelongsTo { return $this->belongsTo(SellerOrder::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
