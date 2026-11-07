<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceAreaLocation extends Model
{
    protected $fillable = [
        'service_area_id',
        'province_code',
        'province_name',
        'municipality_code',
        'municipality_name',
        'barangay_code',
        'barangay_name',
        'coverage_type',
    ];

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }
}
