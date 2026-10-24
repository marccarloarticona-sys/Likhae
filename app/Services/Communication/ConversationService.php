<?php

namespace App\Services\Communication;

use App\Events\MessageSent;
use App\Events\ConversationRead;
use App\Models\Communication\Conversation;
use App\Models\Communication\ConversationParticipant;
use App\Models\Communication\Message;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;
use RuntimeException;

class ConversationService
{
    /** @var array<string, true>|null */
    private ?array $messageColumnNames = null;

    public function listFor(User $user, bool $includeMessages = true, ?int $limit = null, bool $includeSellerProfiles = false): Collection
    {
        $relations = ['participants', 'latestMessage'];
        if ($includeSellerProfiles) {
            $relations[] = 'participants.sellerProfile';
        }

        $query = Conversation::query()
            ->whereHas('participantRecords', fn ($query) => $query
                ->where('user_id', $user->id)
                ->whereNull('left_at'))
            ->with($relations)
            // Persisted message IDs make activity ordering deterministic even
            // when separate messages share the same timestamp second.
            ->withMax('messages as latest_message_id', 'id')
            ->orderByDesc('latest_message_id')
            ->latest('updated_at')
            ->latest('id');

        if ($limit !== null) {
            $query->limit(max(1, min($limit, 100)));
        }

        $conversations = $query->get();
        $authorization = app(ConversationAuthorizationService::class);
        $conversations = $conversations
            ->filter(fn (Conversation $conversation): bool => $authorization->canAccess($user, $conversation))
            ->values();

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
                    ->where('unread_participants.user_id', $user->id)
                    ->whereNull('unread_participants.left_at');
            })
            ->whereIn('messages.conversation_id', $conversationIds)
            ->where(function ($query) use ($user): void {
                $query->whereNull('messages.sender_user_id')
                    ->orWhere('messages.sender_user_id', '<>', $user->id);
            })
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
        $conversations = $this->listFor($user, false, 100, true);
        $active = $selectedConversationId
            ? $conversations->firstWhere('id', $selectedConversationId)
            : null;

        if ($active) {
            $this->markRead($active, $user);
            $active->setRelation('messages', $this->messagesFor($user, (int) $active->id));
        }

        $unreadCounts = $this->unreadCountsFor($user, $conversations);
        $activeConversationId = (int) ($active?->id ?? 0);
        $conversations->each(function (Conversation $conversation) use ($unreadCounts, $activeConversationId): void {
            $unreadCount = (int) $unreadCounts->get($conversation->id, 0);
            if ($activeConversationId > 0 && (int) $conversation->id === $activeConversationId) {
                $unreadCount = 0;
            }
            $conversation->setAttribute('unread_count', $unreadCount);
        });

        return $conversations;
    }

    /** Return an authorized message window for the fallback connection. */
    public function messagesFor(User $user, int $conversationId, int $limit = 50, ?int $afterId = null): Collection
    {
        $conversation = Conversation::query()
            ->whereKey($conversationId)
            ->whereHas('participantRecords', fn ($query) => $query
                ->where('user_id', $user->id)
                ->whereNull('left_at'))
            ->firstOrFail();
        app(ConversationAuthorizationService::class)->assertCanAccess($user, $conversation);

        $messageColumns = $this->messageColumnNames ??= array_fill_keys(Schema::getColumnListing('messages'), true);
        $columns = ['id', 'conversation_id', 'sender_user_id', 'body', 'sent_at', 'created_at'];
        if (isset($messageColumns['attachment_path'])) {
            $columns[] = 'attachment_path';
        }
        if (isset($messageColumns['attachment_original_name'])) {
            $columns[] = 'attachment_original_name';
        }
        if (isset($messageColumns['attachment_mime'])) {
            $columns[] = 'attachment_mime';
        }

        $query = Message::query()
            ->where('conversation_id', $conversation->id)
            ->when($afterId, fn ($query) => $query->where('id', '>', $afterId)->oldest('id'), fn ($query) => $query->latest('sent_at')->latest('id'))
            ->limit(max(1, min($limit, 50)))
            ->get($columns);

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
            'body' => $this->messageBodyForDisplay($message),
            'time' => $sentAt?->diffForHumans() ?? 'Just now',
            'sent_at' => $sentAt?->toIso8601String(),
            'attachment_url' => app(MessageAttachmentService::class)->privateUrl($message),
            'attachment_name' => app(MessageAttachmentService::class)->originalNameFor($message),
            'attachment_mime' => app(MessageAttachmentService::class)->mimeFor($message),
        ];
    }

    public function messageBodyForDisplay(Message $message): string
    {
        $body = (string) $message->body;
        $hasAttachment = filled($message->attachment_path);

        return $hasAttachment && in_array(trim($body), ['[Photo]', '[File]'], true)
            ? ''
            : $body;
    }

    public function messagePreviewFor(?Message $message): string
    {
        if (! $message) {
            return 'Start a conversation.';
        }

        $body = trim($this->messageBodyForDisplay($message));
        if ($body !== '') {
            return $body;
        }

        if (! filled($message->attachment_path)) {
            return 'Start a conversation.';
        }

        return str_starts_with(app(MessageAttachmentService::class)->mimeFor($message), 'image/')
            ? 'Photo'
            : 'File';
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
        ?string $attachmentPath = null,
        ?string $attachmentName = null,
        ?string $attachmentMime = null,
    ): Message
    {
        $message = DB::transaction(function () use ($sender, $recipientUserId, $body, $context, $conversation, $attachmentPath, $attachmentName, $attachmentMime): Message {
            $conversation ??= $this->findOrCreate($sender, $recipientUserId, $context);
            app(ConversationAuthorizationService::class)->assertCanAccess($sender, $conversation);
            $now = now();

            $attributes = [
                'conversation_id' => $conversation->id,
                'sender_user_id' => $sender->id,
                'body' => $body,
                'sent_at' => $now,
            ];
            if ($attachmentPath !== null) {
                if (! Schema::hasColumn('messages', 'attachment_path')) {
                    throw new RuntimeException('The message attachment schema has not been deployed.');
                }
                $attributes['attachment_path'] = $attachmentPath;
                if ($attachmentName !== null && Schema::hasColumn('messages', 'attachment_original_name')) {
                    $attributes['attachment_original_name'] = $attachmentName;
                }
                if ($attachmentMime !== null && Schema::hasColumn('messages', 'attachment_mime')) {
                    $attributes['attachment_mime'] = $attachmentMime;
                }
            }
            $message = Message::create($attributes);

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
        return DB::transaction(function () use ($creator, $recipientUserId, $context): Conversation {
            $conversation = $this->findOrCreate($creator, $recipientUserId, $context);
            app(ConversationAuthorizationService::class)->assertCanAccess($creator, $conversation);

            return $conversation;
        });
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

    public function markRead(Conversation $conversation, User $user): string
    {
        $readAt = now();
        ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->update(['last_read_at' => $readAt]);

        $readAtIso = $readAt->toIso8601String();
        $broadcast = function () use ($conversation, $user, $readAtIso): void {
            try {
                if (! Cache::get('reverb:broadcast-unavailable')) {
                    ConversationRead::dispatch((int) $conversation->id, (int) $user->id, $readAtIso);
                }
            } catch (Throwable $exception) {
                try {
                    Cache::put('reverb:broadcast-unavailable', true, now()->addSeconds(30));
                } catch (Throwable) {
                    // A cache outage must not affect the saved read receipt.
                }

                report($exception);
            }
        };

        if (app()->runningInConsole() || app()->runningUnitTests()) {
            $broadcast();
        } else {
            app()->terminating($broadcast);
        }

        return $readAtIso;
    }

    public function avatarUrlFor(User $user): string
    {
        $user->loadMissing('sellerProfile');
        $path = trim((string) ($user->profile_photo_path ?: $user->sellerProfile?->avatar_path));
        $fallback = asset('images/defaults/profile-avatar.svg');
        if ($path === '' || preg_match('~^[a-z][a-z0-9+.-]*:|^[/\\\\]|[?#]|(^|[/\\\\])\\.\\.?([/\\\\]|$)~i', $path)) {
            return $fallback;
        }

        $path = str_replace(chr(92), '/', $path);

        return Storage::disk('public')->exists($path)
            ? Storage::disk('public')->url($path)
            : $fallback;
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
            return $this->preserveMissingContext($conversation, $context);
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
                return $this->preserveMissingContext($conversation, $context);
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

    /** Add verified order/shipment metadata to an existing plain direct thread. */
    private function preserveMissingContext(Conversation $conversation, array $context): Conversation
    {
        $keys = ['order_id', 'seller_order_id', 'shipment_id'];
        $hasContext = collect($keys)->contains(fn (string $key): bool => filled($conversation->{$key}));
        if ($hasContext) {
            return $conversation;
        }

        $updates = collect($keys)
            ->mapWithKeys(fn (string $key): array => [$key => $context[$key] ?? null])
            ->filter(fn ($value): bool => filled($value))
            ->all();
        if ($updates === []) {
            return $conversation;
        }

        $conversation->forceFill($updates + ['type' => $context['type'] ?? $conversation->type])->saveQuietly();

        return $conversation->refresh();
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
