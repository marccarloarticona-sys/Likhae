<?php

namespace App\Models\Rider;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiderProfileUpdateDocument extends Model
{
    protected $fillable = [
        'rider_profile_update_request_id',
        'document_type',
        'file_path',
        'original_name',
        'mime_type',
        'verification_status',
        'verified_by_user_id',
        'verified_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function updateRequest(): BelongsTo
    {
        return $this->belongsTo(RiderProfileUpdateRequest::class, 'rider_profile_update_request_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
