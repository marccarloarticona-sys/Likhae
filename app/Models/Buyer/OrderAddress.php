<?php

namespace App\Models\Buyer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAddress extends Model
{
    protected $fillable = [
        'order_id',
        'recipient_name',
        'contact_number',
        'province_code',
        'province_name',
        'region_code',
        'region_name',
        'municipality_code',
        'municipality_name',
        'barangay_code',
        'barangay_name',
        'postal_code',
        'house_number',
        'street_address',
        'landmark',
        'latitude',
        'longitude',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function formatted(): string
    {
        return collect([
            $this->house_number,
            $this->street_address,
            $this->barangay_name,
            $this->municipality_name,
            $this->province_name,
            $this->postal_code,
        ])->filter(fn ($value) => filled($value))->implode(', ');
    }
}
