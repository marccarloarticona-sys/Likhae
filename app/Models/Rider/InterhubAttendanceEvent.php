<?php

namespace App\Models\Rider;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterhubAttendanceEvent extends Model
{
    public $timestamps = false;
    protected $fillable = ['attendance_session_id', 'actor_user_id', 'event_type', 'old_values', 'new_values', 'reason', 'ip_address', 'created_at'];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }

    public function session(): BelongsTo { return $this->belongsTo(InterhubAttendanceSession::class, 'attendance_session_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
