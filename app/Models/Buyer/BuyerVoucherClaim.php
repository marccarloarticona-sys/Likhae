<?php

namespace App\Models\Buyer;

use App\Models\Seller\Voucher;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuyerVoucherClaim extends Model
{
    protected $fillable = [
        'buyer_user_id',
        'voucher_id',
    ];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
