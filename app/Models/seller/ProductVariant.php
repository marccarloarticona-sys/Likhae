<?php

namespace App\Models\Seller;

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
        $price = (float) $this->price;
        $value = max(0, (float) ($this->discount_value ?? 0));

        return match ($this->resolved_discount_type) {
            'fixed' => round(min($price, $value), 2),
            'percentage' => round(min($price, $price * min(100, $value) / 100), 2),
            default => 0.0,
        };
    }

    public function getResolvedDiscountTypeAttribute(): string
    {
        $type = strtolower(trim((string) ($this->getRawOriginal('discount_type') ?? '')));

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
        return round(max(0, (float) $this->price - $this->discount_amount), 2);
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
