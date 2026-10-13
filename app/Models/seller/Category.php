<?php

namespace App\Models\Seller;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'is_active',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function sellerProfiles(): HasMany
    {
        return $this->hasMany(SellerProfile::class, 'primary_category_id');
    }

    public function sellerApplications(): HasMany
    {
        return $this->hasMany(SellerApplicationData::class);
    }
}
