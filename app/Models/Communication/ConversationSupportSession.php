<?php

namespace App\Models\Communication;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationSupportSession extends Model
{
    public const AUTOMATED_HELP = 'AUTOMATED_HELP';
    public const HUMAN_ASSISTANCE_REQUESTED = 'HUMAN_ASSISTANCE_REQUESTED';
    public const HUMAN_CONVERSATION = 'HUMAN_CONVERSATION';
    public const RESOLVED = 'RESOLVED';

    protected $fillable = [
        'conversation_id', 'requester_user_id', 'recipient_user_id', 'status', 'selected_faq_id',
        'selected_question_key', 'selected_question_snapshot', 'selected_category_snapshot', 'answer_snapshot', 'answer_source_snapshot', 'last_feedback', 'context_snapshot',
        'current_request_id',
        'selected_at', 'requested_at', 'resolved_at', 'resolved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'context_snapshot' => 'array',
            'selected_at' => 'datetime',
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function selectedFaq(): BelongsTo
    {
        return $this->belongsTo(MessagingFaq::class, 'selected_faq_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function currentRequest(): BelongsTo
    {
        return $this->belongsTo(ConversationSupportRequest::class, 'current_request_id');
    }
}
