<?php

namespace App\Models\Finance;

use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiderEarningPoolParticipant extends Model
{
    protected $fillable = ['rider_earning_pool_id', 'rider_assignment_id', 'rider_profile_id', 'participant_role', 'share_amount', 'status', 'approved_by_user_id', 'approved_at'];

    protected function casts(): array { return ['share_amount' => 'decimal:2', 'approved_at' => 'datetime']; }

    public function pool(): BelongsTo { return $this->belongsTo(RiderEarningPool::class, 'rider_earning_pool_id'); }
    public function assignment(): BelongsTo { return $this->belongsTo(RiderAssignment::class, 'rider_assignment_id'); }
    public function riderProfile(): BelongsTo { return $this->belongsTo(RiderProfile::class); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by_user_id'); }
}
