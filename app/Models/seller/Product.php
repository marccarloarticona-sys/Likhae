<?php

namespace App\Models\Seller;

use App\Models\Admin\SellerComplianceCase;
use App\Models\Admin\ProductModerationScan;
use App\Models\Buyer\OrderItem;
use App\Models\Buyer\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'seller_profile_id',
        'category_id',
        'name',
        'slug',
        'description',
        'status',
        'purchase_limit_per_buyer',
        'published_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            'purchase_limit_per_buyer' => 'integer',
        ];
    }

    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class);
    }

    /** Transitional alias. */
    public function seller(): BelongsTo
    {
        return $this->sellerProfile();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('sort_order');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function reviews(): HasManyThrough
    {
        return $this->hasManyThrough(Review::class, OrderItem::class)
            ->where('reviews.status', 'PUBLISHED')
            ->whereNotNull('reviews.rating');
    }

    public function complianceCases(): HasMany
    {
        return $this->hasMany(SellerComplianceCase::class);
    }

    public function moderationScans(): HasMany
    {
        return $this->hasMany(ProductModerationScan::class);
    }

    public function latestModerationScan(): HasOne
    {
        return $this->hasOne(ProductModerationScan::class)->latestOfMany();
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->where('status', 'ACTIVE')
            ->whereHas('sellerProfile', function (Builder $seller): void {
                $seller->where('status', 'ACTIVE')
                    ->whereHas('user', fn (Builder $user) => $user->where('status', 'ACTIVE'));
            })
            ->whereHas('variants', fn (Builder $variant) => $variant->where('is_active', true));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'ACTIVE');
    }

    public function getMinPriceAttribute(): ?string
    {
        $value = $this->relationLoaded('variants')
            ? $this->variants->where('is_active', true)->min('price')
            : $this->variants()->where('is_active', true)->min('price');

        return $value === null ? null : number_format((float) $value, 2, '.', '');
    }
}
