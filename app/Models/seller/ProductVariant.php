<?php

namespace App\Models\Seller;

use App\Services\Marketplace\ProductPriceResolver;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'product_image_id',
        'sku',
        'price',
        'discount_type',
        'discount_value',
        'flash_sale_price',
        'flash_sale_allocation',
        'flash_sale_sold',
        'payment_method',
        'stock',
        'weight_kg',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'flash_sale_price' => 'decimal:2',
            'flash_sale_allocation' => 'integer',
            'flash_sale_sold' => 'integer',
            'stock' => 'integer',
            'weight_kg' => 'decimal:3',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productImage(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class, 'product_image_id');
    }

    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductOptionValue::class,
            'product_variant_option_values',
            'product_variant_id',
            'product_option_value_id',
        )->withTimestamps();
    }

    public function optionValueLinks(): HasMany
    {
        return $this->hasMany(ProductVariantOptionValue::class);
    }

    public function getDiscountAmountAttribute(): float
    {
        return app(ProductPriceResolver::class)->resolve($this)['discount_amount'];
    }

    public function getResolvedDiscountTypeAttribute(): string
    {
        $type = strtolower(trim((string) ($this->getAttribute('discount_type') ?? '')));

        if (in_array($type, ['none', 'percentage', 'fixed'], true)) {
            return $type;
        }

        return (float) ($this->discount_value ?? 0) > 0 ? 'percentage' : 'none';
    }

    public function getDiscountPercentageAttribute(): float
    {
        return $this->resolved_discount_type === 'percentage'
            ? round(min(100, max(0, (float) ($this->discount_value ?? 0))), 2)
            : 0.0;
    }

    public function getFinalPriceAttribute(): float
    {
        return app(ProductPriceResolver::class)->resolve($this)['price'];
    }

    public function getFlashSaleRemainingAttribute(): int
    {
        return app(ProductPriceResolver::class)->remainingFlashSaleUnits($this);
    }

    public function getActiveFlashSalePriceAttribute(): ?float
    {
        return app(ProductPriceResolver::class)->activeFlashSalePrice($this);
    }

    public function getFlashSaleStatusAttribute(): string
    {
        if ($this->flash_sale_price === null || $this->flash_sale_allocation === null) {
            return 'NOT_CONFIGURED';
        }

        $product = $this->relationLoaded('product') ? $this->getRelation('product') : $this->product;
        if ($product?->flash_sale_ends_at?->lessThanOrEqualTo(now())) {
            return 'EXPIRED';
        }
        if ((bool) ($product?->flash_sale_is_paused ?? false)) {
            return 'PAUSED';
        }
        if ($this->flash_sale_remaining <= 0 || (int) $this->stock <= 0) {
            return 'SOLD_OUT';
        }
        if ($product?->flash_sale_starts_at?->isFuture()) {
            return 'SCHEDULED';
        }

        return 'ACTIVE';
    }

    private function flashSaleWindowIsActive(): bool
    {
        $product = $this->relationLoaded('product') ? $this->getRelation('product') : $this->product;
        $startsAt = $product?->flash_sale_starts_at;
        $endsAt = $product?->flash_sale_ends_at;

        return (! $startsAt || ! $startsAt->isFuture())
            && (! $endsAt || $endsAt->isFuture());
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('stock', '>', 0);
    }

    public function getDescriptionAttribute(): string
    {
        $values = $this->relationLoaded('optionValues')
            ? $this->optionValues
            : $this->optionValues()->with('option')->get();

        return $values
            ->sortBy(fn (ProductOptionValue $value) => $value->option?->sort_order ?? 0)
            ->map(fn (ProductOptionValue $value) => trim(($value->option?->name ? $value->option->name.': ' : '').$value->value))
            ->implode(' / ');
    }
}
