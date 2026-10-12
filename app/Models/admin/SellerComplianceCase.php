<?php

namespace App\Models\Admin;

use App\Models\Seller\Product;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerComplianceCase extends Model
{
    protected $fillable = [
        'case_number',
        'seller_profile_id',
        'product_id',
        'opened_by_admin_user_id',
        'violation_type',
        'description',
        'status',
        'opened_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function openedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_admin_user_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(SellerComplianceAction::class)->orderBy('performed_at');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SellerComplianceResponse::class)->latest('submitted_at');
    }
}
