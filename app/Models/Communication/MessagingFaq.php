<?php

namespace App\Models\Communication;

use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessagingFaq extends Model
{
    public const OWNER_SELLER = 'SELLER';
    public const OWNER_ADMIN = 'ADMIN';

    protected $fillable = [
        'owner_type', 'seller_profile_id', 'created_by_user_id', 'category', 'question', 'answer',
        'product_id', 'product_variant_id', 'is_active', 'sort_order', 'selection_count', 'last_selected_at',
        'target_role', 'entry_type', 'keywords', 'alternate_questions', 'is_automatic',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'selection_count' => 'integer',
            'last_selected_at' => 'datetime',
            'keywords' => 'array',
            'alternate_questions' => 'array',
            'is_automatic' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
