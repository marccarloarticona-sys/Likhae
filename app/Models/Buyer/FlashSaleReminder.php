<?php

namespace App\Models\Buyer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlashSaleReminder extends Model
{
    protected $fillable = [
        'buyer_user_id',
        'product_id',
        'product_name',
        'product_slug',
        'sale_starts_at',
        'scheduled_at',
        'status',
        'sent_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'sale_starts_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'buyer_user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Seller\Product::class);
    }
}
