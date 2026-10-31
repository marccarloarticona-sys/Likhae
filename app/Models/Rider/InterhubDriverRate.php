<?php

namespace App\Models\Rider;

use App\Models\Logistics\LogisticsCenter;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterhubDriverRate extends Model
{
    protected $fillable = ['rider_profile_id', 'logistics_center_id', 'region_type', 'daily_rate', 'effective_from', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['daily_rate' => 'decimal:4', 'effective_from' => 'date'];
    }

    public function riderProfile(): BelongsTo { return $this->belongsTo(RiderProfile::class); }
    public function logisticsCenter(): BelongsTo { return $this->belongsTo(LogisticsCenter::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
}
