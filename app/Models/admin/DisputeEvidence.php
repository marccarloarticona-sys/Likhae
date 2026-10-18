<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisputeEvidence extends Model
{
    protected $table = 'dispute_evidence';

    protected $fillable = [
        'dispute_id',
        'uploaded_by_user_id',
        'file_path',
        'disk',
        'encrypted',
        'original_name',
        'mime_type',
        'notes',
    ];

    protected function casts(): array
    {
        return ['encrypted' => 'boolean'];
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
