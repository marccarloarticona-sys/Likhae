<?php

namespace App\Services\Communication;

use App\Events\MessageSent;
use App\Models\Communication\Conversation;
use App\Models\Communication\ConversationParticipant;
use App\Models\Communication\Message;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class ConversationService
{
    public function listFor(User $user, bool $includeMessages = true, ?int $limit = null, bool $includeSellerProfiles = false): Collection
    {
        $relations = ['participants', 'latestMessage.sender'];
        if ($includeSellerProfiles) {
            $relations[] = 'participants.sellerProfile';
        }

        $query = Conversation::query()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $user->id))
            ->with($relations)
            ->latest('updated_at');

        if ($limit !== null) {
            $query->limit(max(1, min($limit, 100)));
        }

        $conversations = $query->get();

        if ($includeMessages) {
            $conversations->load('messages.sender');
        }

        return $conversations;
    }

    /** Return unread counts without hydrating every message in each thread. */
    public function unreadCountsFor(User $user, Collection $conversations): Collection
    {
        $conversationIds = $conversations->pluck('id')->map(fn ($id): int => (int) $id)->values();
        if ($conversationIds->isEmpty()) {
            return collect();
        }

        $query = Message::query()
            ->join('conversation_participants as unread_participants', function ($join) use ($user): void {
                $join->on('unread_participants.conversation_id', '=', 'messages.conversation_id')
                    ->where('unread_participants.user_id', $user->id);
            })
            ->whereIn('messages.conversation_id', $conversationIds)
            ->where('messages.sender_user_id', '!=', $user->id)
            ->where(function ($query): void {
                $query->whereNull('unread_participants.last_read_at')
                    ->orWhereColumn('messages.sent_at', '>', 'unread_participants.last_read_at');
            });

        return $query
            ->select('messages.conversation_id')
            ->selectRaw('COUNT(messages.id) as aggregate')
            ->groupBy('messages.conversation_id')
            ->pluck('aggregate', 'messages.conversation_id')
            ->map(fn ($count): int => (int) $count);
    }

    /** Load conversation summaries plus only the selected thread's recent messages. */
    public function workspaceThreads(User $user, ?int $selectedConversationId = null): Collection
    {
        $conversations = $this->listFor($user, false, 100);
        $active = $selectedConversationId
            ? $conversations->firstWhere('id', $selectedConversationId)
            : $conversations->first();

        if ($active) {
            $active->setRelation('messages', $this->messagesFor($user, (int) $active->id));
            $this->markRead($active, $user);
        }

        return $conversations;
    }

    /** Return an authorized message window for the fallback connection. */
    public function messagesFor(User $user, int $conversationId, int $limit = 50, ?int $afterId = null): Collection
    {
        $conversation = Conversation::query()
            ->select('conversations.id')
            ->whereKey($conversationId)
            ->whereHas('participants', fn ($query) => $query->where('users.id', $user->id))
            ->firstOrFail();

        $query = Message::query()
            ->where('conversation_id', $conversation->id)
            ->when($afterId, fn ($query) => $query->where('id', '>', $afterId)->oldest('id'), fn ($query) => $query->latest('sent_at')->latest('id'))
            ->limit(max(1, min($limit, 50)))
            ->get(['id', 'conversation_id', 'sender_user_id', 'body', 'sent_at', 'created_at']);

        return $afterId ? $query->values() : $query->reverse()->values();
    }

    public function messagePayload(Message $message): array
    {
        $sentAt = $message->sent_at ?? $message->created_at;

        return [
            'id' => (string) $message->id,
            'conversation_id' => (string) $message->conversation_id,
            'sender_id' => (string) $message->sender_user_id,
            'sender_user_id' => (string) $message->sender_user_id,
            'body' => $message->body,
            'time' => $sentAt?->diffForHumans() ?? 'Just now',
            'sent_at' => $sentAt?->toIso8601String(),
        ];
    }

    public function streamPayload(User $user, int $conversationId, ?int $afterId = null): Collection
    {
        return $this->messagesFor($user, $conversationId, 50, $afterId)
            ->map(fn (Message $message): array => $this->messagePayload($message));
    }

    public function send(
        User $sender,
        int $recipientUserId,
        string $body,
        array $context = [],
        ?Conversation $conversation = null,
    ): Message
    {
        $message = DB::transaction(function () use ($sender, $recipientUserId, $body, $context, $conversation): Message {
            $conversation ??= $this->findOrCreate($sender, $recipientUserId, $context);
            $now = now();

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_user_id' => $sender->id,
                'body' => $body,
                'sent_at' => $now,
            ]);

            $conversation->updated_at = $now;
            $conversation->saveQuietly();

            ConversationParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $sender->id)
                ->update(['last_read_at' => $now]);

            return $message;
        });

        $broadcast = function () use ($message, $sender, $recipientUserId): void {
            try {
                // Stop repeated WebSocket failures from making every message
                // request pay the same network timeout. Polling remains active
                // while the short circuit is open and broadcasting retries
                // automatically after the cooldown.
                if (Cache::get('reverb:broadcast-unavailable')) {
                    return;
                }

                MessageSent::dispatch(
                    $message,
                    [(int) $sender->id, (int) $recipientUserId],
                    $sender->name,
                );

                Cache::forget('reverb:broadcast-unavailable');
            } catch (Throwable $exception) {
                // A WebSocket outage must not make a committed web or mobile message fail.
                try {
                    Cache::put('reverb:broadcast-unavailable', true, now()->addSeconds(30));
                } catch (Throwable) {
                    // A cache outage must not affect message delivery either.
                }

                report($exception);
            }
        };

        // Return the committed message first. Reverb is notified during the
        // HTTP termination phase so a slow/unavailable WebSocket endpoint can
        // never hold the send request open.
        if (app()->runningInConsole() || app()->runningUnitTests()) {
            $broadcast();
        } else {
            app()->terminating($broadcast);
        }

        return $message;
    }

    /** Start a thread before either participant has written a message. */
    public function start(User $creator, int $recipientUserId, array $context = []): Conversation
    {
        return DB::transaction(fn (): Conversation => $this->findOrCreate($creator, $recipientUserId, $context));
    }

    /** Preserve a thread's order metadata when a participant replies. */
    public function contextFor(Conversation $conversation): array
    {
        return [
            'type' => $conversation->type,
            'order_id' => $conversation->order_id,
            'seller_order_id' => $conversation->seller_order_id,
            'shipment_id' => $conversation->shipment_id,
        ];
    }

    public function markRead(Conversation $conversation, User $user): void
    {
        ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->update(['last_read_at' => now()]);
    }

    private function findOrCreate(User $creator, int $recipientUserId, array $context): Conversation
    {
        $ids = collect([$creator->id, $recipientUserId])->sort()->values();
        if ((int) $ids[0] === (int) $ids[1]) {
            throw new \InvalidArgumentException('A conversation requires two different accounts.');
        }

        $pairKey = $ids->implode(':');
        $conversation = Conversation::query()
            ->where('direct_pair_key', $pairKey)
            ->first()
            ?: $this->findPairConversation($ids);

        if ($conversation) {
            return $conversation;
        }

        try {
            $conversation = Conversation::create([
                'type' => $context['type'] ?? 'DIRECT',
                'direct_pair_key' => $pairKey,
                'order_id' => $context['order_id'] ?? null,
                'seller_order_id' => $context['seller_order_id'] ?? null,
                'shipment_id' => $context['shipment_id'] ?? null,
                'created_by_user_id' => $creator->id,
            ]);
        } catch (QueryException $exception) {
            // A concurrent first message may win the unique pair key. Reuse
            // that committed conversation instead of creating a second one.
            if (! str_contains($exception->getMessage(), 'direct_pair_key')) {
                throw $exception;
            }

            $conversation = Conversation::query()
                ->where('direct_pair_key', $pairKey)
                ->first()
                ?: $this->findPairConversation($ids);
            if ($conversation) {
                return $conversation;
            }

            throw $exception;
        }

        $now = now();
        ConversationParticipant::query()->insert([
            [
                'conversation_id' => $conversation->id,
                'user_id' => (int) $ids[0],
                'joined_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'conversation_id' => $conversation->id,
                'user_id' => (int) $ids[1],
                'joined_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        return $conversation;
    }

    private function findPairConversation(Collection $ids): ?Conversation
    {
        return Conversation::query()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $ids[0]))
            ->whereHas('participants', fn ($query) => $query->where('users.id', $ids[1]))
            ->whereDoesntHave('participants', fn ($query) => $query->whereNotIn('users.id', $ids->all()))
            ->oldest('id')
            ->first();
    }
}
