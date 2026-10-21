<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    protected $table = 'announcements';

    protected $fillable = [
        'created_by_user_id',
        'title',
        'body',
        'target_audience',
        'image_path',
        'image_alt',
        'theme_color',
        'cta_label',
        'cta_url',
        'show_popup',
        'published_at',
        'expires_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'show_popup' => 'boolean',
        ];
    }

    public function getSafeThemeColorAttribute(): ?string
    {
        $color = (string) $this->theme_color;

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1
            ? strtoupper($color)
            : null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function publish(): void
    {
        $this->forceFill([
            'published_at' => $this->published_at ?? now(),
            'is_active' => true,
        ])->save();
    }
}
