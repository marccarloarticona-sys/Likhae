<?php

namespace App\Models\Rider;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterhubPayrollItem extends Model
{
    protected $fillable = [
        'payroll_batch_id', 'attendance_session_id', 'rider_profile_id', 'daily_rate_snapshot', 'worked_minutes',
        'amount', 'status', 'payment_reference', 'proof_path', 'payment_notes', 'recorded_by_user_id', 'paid_at',
    ];
    protected function casts(): array
    {
        return ['daily_rate_snapshot' => 'decimal:4', 'amount' => 'decimal:2', 'worked_minutes' => 'integer', 'paid_at' => 'datetime'];
    }
    public function batch(): BelongsTo { return $this->belongsTo(InterhubPayrollBatch::class, 'payroll_batch_id'); }
    public function session(): BelongsTo { return $this->belongsTo(InterhubAttendanceSession::class, 'attendance_session_id'); }
    public function riderProfile(): BelongsTo { return $this->belongsTo(RiderProfile::class); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by_user_id'); }
}
