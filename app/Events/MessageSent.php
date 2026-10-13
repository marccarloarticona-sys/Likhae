<?php

namespace App\Events;

use App\Models\Communication\Message;
use App\Models\Communication\ConversationParticipant;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * Pass the known participants with the event so broadcasting does not have
     * to query the participant table again after the message is committed.
     * The fallback query keeps manually dispatched events backwards compatible.
     *
     * @param array<int, int|string> $participantIds
     */
    public function __construct(
        public Message $message,
        public array $participantIds = [],
        public ?string $senderName = null,
    )
    {
    }

    public function broadcastOn(): array
    {
        $participantIds = $this->participantIds;
        if ($participantIds === []) {
            $participantIds = ConversationParticipant::query()
                ->where('conversation_id', $this->message->conversation_id)
                ->pluck('user_id')
                ->all();
        }

        $userChannels = collect($participantIds)
            ->map(fn ($userId) => new PrivateChannel('App.Models.User.'.$userId))
            ->all();

        return array_merge(
            [new PrivateChannel('conversations.'.$this->message->conversation_id)],
            $userChannels,
        );
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        $message = $this->message;
        $sentAt = $message->sent_at ?? $message->created_at;

        return [
            'id' => (string) $message->id,
            'conversation_id' => (string) $message->conversation_id,
            'sender_id' => (string) $message->sender_user_id,
            'sender_name' => $this->senderName ?? $message->sender?->name ?? 'User',
            'body' => $message->body,
            'time' => $sentAt?->diffForHumans() ?? 'Just now',
            'sent_at' => $sentAt?->toIso8601String(),
        ];
    }
}
