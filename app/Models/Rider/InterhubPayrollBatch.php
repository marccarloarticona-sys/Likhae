<?php

namespace App\Models\Rider;

use App\Models\Logistics\LogisticsCenter;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InterhubPayrollBatch extends Model
{
    protected $fillable = ['logistics_center_id', 'period_start', 'period_end', 'created_by_user_id'];
    protected function casts(): array { return ['period_start' => 'date', 'period_end' => 'date']; }
    public function logisticsCenter(): BelongsTo { return $this->belongsTo(LogisticsCenter::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function items(): HasMany { return $this->hasMany(InterhubPayrollItem::class, 'payroll_batch_id'); }
}
