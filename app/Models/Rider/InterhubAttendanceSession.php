<?php

namespace App\Models\Rider;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\TransferManifest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InterhubAttendanceSession extends Model
{
    protected $fillable = [
        'rider_profile_id', 'active_driver_id', 'logistics_center_id', 'manifest_id', 'rate_id', 'region_type', 'daily_rate_snapshot',
        'clock_in_at', 'clock_out_at', 'worked_minutes', 'gross_pay', 'overtime_review',
        'clock_in_by_user_id', 'clock_out_by_user_id', 'clock_in_source', 'clock_out_source',
    ];

    protected function casts(): array
    {
        return [
            'clock_in_at' => 'datetime', 'clock_out_at' => 'datetime',
            'daily_rate_snapshot' => 'decimal:4', 'gross_pay' => 'decimal:2',
            'overtime_review' => 'boolean', 'worked_minutes' => 'integer',
        ];
    }

    public function riderProfile(): BelongsTo { return $this->belongsTo(RiderProfile::class); }
    public function logisticsCenter(): BelongsTo { return $this->belongsTo(LogisticsCenter::class); }
    public function manifest(): BelongsTo { return $this->belongsTo(TransferManifest::class, 'manifest_id'); }
    public function rate(): BelongsTo { return $this->belongsTo(InterhubDriverRate::class, 'rate_id'); }
    public function events(): HasMany { return $this->hasMany(InterhubAttendanceEvent::class, 'attendance_session_id')->orderBy('created_at'); }
    public function payrollItem(): HasOne { return $this->hasOne(InterhubPayrollItem::class, 'attendance_session_id'); }
}
