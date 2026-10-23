<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformPolicy extends Model
{
    protected $table = 'platform_policies';

    protected $fillable = [
        'published_by_user_id',
        'title',
        'version',
        'body',
        'policy_type',
        'audience',
        'logistics_center_id',
        'scope_key',
        'effective_at',
        'published_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'published_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
