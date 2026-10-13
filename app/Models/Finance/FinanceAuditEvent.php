<?php

namespace App\Models\Finance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceAuditEvent extends Model
{
    protected $fillable = ['event_type', 'auditable_type', 'auditable_id', 'actor_user_id', 'before_state', 'after_state', 'notes'];

    protected function casts(): array { return ['before_state' => 'array', 'after_state' => 'array']; }

    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
