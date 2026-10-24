<?php

namespace App\Models\Seller;

use App\Models\Admin\SellerComplianceCase;
use App\Models\Buyer\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerProfile extends Model
{
    protected $table = 'seller_profiles';

    protected $hidden = ['payout_method', 'payout_account_type', 'payout_bank_name', 'payout_account_name', 'payout_account_number', 'payout_destinations'];

    protected $fillable = [
        'user_id',
        'primary_category_id',
        'business_address_id',
        'business_name',
        'business_registration_number',
        'seller_type',
        'tin',
        'avatar_path',
        'banner_path',
        'tagline',
        'description',
        'location',
        'business_days',
        'business_hours',
        'processing_days',
        'order_cutoff',
        'vacation_mode',
        'auto_accept_orders',
        'store_visibility',
        'status',
        'approved_by_user_id',
        'approved_at',
        'payout_method',
        'payout_account_type',
        'payout_bank_name',
        'payout_account_name',
        'payout_account_number',
        'payout_destinations',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'processing_days' => 'integer',
            'vacation_mode' => 'boolean',
            'auto_accept_orders' => 'boolean',
            'store_visibility' => 'boolean',
            'payout_account_name' => 'encrypted',
            'payout_account_number' => 'encrypted',
            'payout_destinations' => 'encrypted:array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Transitional alias. */
    public function owner(): BelongsTo
    {
        return $this->user();
    }

    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'primary_category_id');
    }

    public function businessAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'business_address_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(SellerPromotion::class);
    }

    public function sellerOrders(): HasMany
    {
        return $this->hasMany(SellerOrder::class);
    }

    public function pickupRequests(): HasMany
    {
        return $this->hasMany(SellerPickupRequest::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function complianceCases(): HasMany
    {
        return $this->hasMany(SellerComplianceCase::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'ACTIVE');
    }
}
