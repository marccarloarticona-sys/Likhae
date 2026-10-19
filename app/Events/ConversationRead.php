<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationRead implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $conversationId,
        public int $readerUserId,
        public string $readAt,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversations.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'conversation.read';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => (string) $this->conversationId,
            'reader_user_id' => (string) $this->readerUserId,
            'read_at' => $this->readAt,
        ];
    }
}
