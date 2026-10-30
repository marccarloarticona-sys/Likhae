<?php

namespace App\Models\Communication;

use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConversationSupportRequest extends Model
{
    public const NEW = 'NEW';
    public const AWAITING_RESPONSE = 'AWAITING_RESPONSE';
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const RESOLVED = 'RESOLVED';

    public const ACTIVE_STATUSES = [self::NEW, self::AWAITING_RESPONSE, self::IN_PROGRESS];

    protected $fillable = [
        'conversation_id', 'requester_user_id', 'recipient_user_id', 'seller_profile_id', 'selected_faq_id',
        'category', 'selected_question', 'answer_snapshot', 'answer_source', 'last_feedback', 'unresolved_concern',
        'order_reference', 'context_snapshot', 'status', 'requested_at', 'acknowledged_at', 'acknowledged_by_user_id',
        'accepted_at', 'accepted_by_user_id', 'resolved_at', 'resolved_by_user_id', 'internal_notes',
    ];

    protected function casts(): array
    {
        return ['context_snapshot' => 'array', 'internal_notes' => 'array', 'requested_at' => 'datetime', 'acknowledged_at' => 'datetime', 'accepted_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requester_user_id'); }
    public function recipient(): BelongsTo { return $this->belongsTo(User::class, 'recipient_user_id'); }
    public function sellerProfile(): BelongsTo { return $this->belongsTo(SellerProfile::class); }
    public function selectedFaq(): BelongsTo { return $this->belongsTo(MessagingFaq::class, 'selected_faq_id'); }
    public function acknowledgedBy(): BelongsTo { return $this->belongsTo(User::class, 'acknowledged_by_user_id'); }
    public function acceptedBy(): BelongsTo { return $this->belongsTo(User::class, 'accepted_by_user_id'); }
    public function resolvedBy(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by_user_id'); }
    public function interactions(): HasMany { return $this->hasMany(ConversationSupportInteraction::class, 'support_request_id')->orderBy('created_at')->orderBy('id'); }
}
