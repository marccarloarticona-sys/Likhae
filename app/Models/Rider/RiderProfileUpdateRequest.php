<?php

namespace App\Models\Rider;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiderProfileUpdateRequest extends Model
{
    protected $fillable = [
        'rider_profile_id',
        'submitted_by_user_id',
        'vehicle_type',
        'plate_number',
        'drivers_license_number',
        'status',
        'review_notes',
        'reviewed_by_user_id',
        'submitted_at',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(RiderProfile::class, 'rider_profile_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RiderProfileUpdateDocument::class);
    }
}
