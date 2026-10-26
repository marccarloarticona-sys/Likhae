<?php

namespace App\Models\Communication;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationSupportInteraction extends Model
{
    protected $fillable = [
        'conversation_id', 'conversation_support_session_id', 'support_request_id', 'requester_user_id',
        'selected_faq_id', 'question_key', 'category', 'question_snapshot', 'answer_snapshot', 'answer_source',
        'feedback', 'context_snapshot',
    ];

    protected function casts(): array { return ['context_snapshot' => 'array']; }

    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    public function supportSession(): BelongsTo { return $this->belongsTo(ConversationSupportSession::class, 'conversation_support_session_id'); }
    public function supportRequest(): BelongsTo { return $this->belongsTo(ConversationSupportRequest::class, 'support_request_id'); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requester_user_id'); }
    public function selectedFaq(): BelongsTo { return $this->belongsTo(MessagingFaq::class, 'selected_faq_id'); }
}
