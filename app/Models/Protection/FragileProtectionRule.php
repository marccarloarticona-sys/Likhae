<?php

namespace App\Models\Protection;

use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FragileProtectionRule extends Model
{
    use HasFactory;

    public const TYPE_PERCENTAGE = 'PERCENTAGE';
    public const TYPE_FLAT = 'FLAT';
    public const TYPE_HYBRID = 'HYBRID';
    public const SCOPE_PER_UNIT = 'PER_UNIT';
    public const SCOPE_PER_LINE = 'PER_LINE';

    protected $fillable = [
        'category_id',
        'subcategory_id',
        'product_id',
        'risk_level',
        'calculation_type',
        'scope',
        'percentage_rate',
        'flat_amount',
        'minimum_fee',
        'maximum_fee',
        'minimum_product_price',
        'maximum_product_price',
        'active',
        'effective_from',
        'effective_until',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'percentage_rate' => 'decimal:4',
            'flat_amount' => 'decimal:2',
            'minimum_fee' => 'decimal:2',
            'maximum_fee' => 'decimal:2',
            'minimum_product_price' => 'decimal:2',
            'maximum_product_price' => 'decimal:2',
            'active' => 'boolean',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'subcategory_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
