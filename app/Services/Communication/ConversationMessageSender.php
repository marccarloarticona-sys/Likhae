<?php

namespace App\Services\Communication;

use App\Models\Communication\Conversation;
use App\Models\Communication\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Throwable;
use Illuminate\Validation\ValidationException;

class ConversationMessageSender
{
    public function __construct(private readonly MessageAttachmentService $attachments) {}

    /** @return array<string, mixed> */
    public function validate(Request $request): array
    {
        if (is_string($request->input('body'))) {
            $request->merge(['body' => $this->trimWhitespace($request->input('body'))]);
        }

        return $request->validate([
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
            'shipment_id' => ['nullable', 'integer', 'exists:shipments,id'],
            'body' => ['nullable', 'string', 'max:1000'],
            'attachment' => $this->attachments->validationRules(),
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => $this->attachments->validationRules(),
        ]);
    }

    /** @param array<string, mixed> $data
     *  @param array<string, mixed> $context
     *  @return array<int, Message>
     */
    public function sendMany(
        Request $request,
        array $data,
        User $recipient,
        ConversationService $conversations,
        array $context = [],
    ): array {
        abort_unless($recipient->isActive(), 404);

        $files = array_values(array_filter(
            (array) ($data['attachments'] ?? []),
            fn ($file): bool => $file instanceof UploadedFile,
        ));
        if (($data['attachment'] ?? null) instanceof UploadedFile) {
            $files[] = $data['attachment'];
        }
        if (count($files) > 5) {
            throw ValidationException::withMessages([
                'attachments' => 'Choose up to 5 files or images per message.',
            ]);
        }

        $conversation = null;
        if (! empty($data['conversation_id'])) {
            $conversation = Conversation::query()
                ->whereKey((int) $data['conversation_id'])
                ->whereHas('participants', fn ($query) => $query
                    ->where('users.id', $request->user()->id)
                    ->whereNull('conversation_participants.left_at'))
                ->whereHas('participants', fn ($query) => $query
                    ->where('users.id', $recipient->id)
                    ->whereNull('conversation_participants.left_at'))
                ->firstOrFail();
            $context = $conversations->contextFor($conversation);
        } else {
            $context = app(ConversationStartAuthorizationService::class)->contextForNew(
                $request->user(),
                $recipient,
                filled($data['shipment_id'] ?? null) ? (int) $data['shipment_id'] : null,
            );
            abort_if($context === null, 403, 'This chat requires an existing supported order, support, or shipment relationship.');
        }

        if ($files !== [] && ! $this->attachments->available()) {
            throw ValidationException::withMessages([
                'attachments' => 'File attachments are temporarily unavailable.',
            ]);
        }

        $body = trim((string) ($data['body'] ?? ''));
        if ($files === [] && $body === '') {
            throw ValidationException::withMessages([
                'body' => 'Type a message or attach a file.',
            ]);
        }

        if ($files === []) {
            return [$conversations->send(
                $request->user(),
                (int) $recipient->id,
                $body,
                $context,
                $conversation,
            )];
        }

        $messages = [];
        // Each message row stores one attachment; keep any typed text on the first one.
        foreach ($files as $index => $file) {
            $attachmentPath = null;
            try {
                $attachmentPath = $this->attachments->store($file);
                $messages[] = $conversations->send(
                    $request->user(),
                    (int) $recipient->id,
                    $index === 0 ? $body : '',
                    $context,
                    $conversation,
                    $attachmentPath,
                    $this->attachments->originalName($file),
                    $this->attachments->storedMime($file, $attachmentPath),
                );
            } catch (Throwable $exception) {
                $this->attachments->delete($attachmentPath);
                throw $exception;
            }
        }

        return $messages;
    }

    /** @param array<string, mixed> $data
     *  @param array<string, mixed> $context
     */
    public function send(
        Request $request,
        array $data,
        User $recipient,
        ConversationService $conversations,
        array $context = [],
    ): Message {
        return $this->sendMany($request, $data, $recipient, $conversations, $context)[0];
    }

    private function trimWhitespace(string $body): string
    {
        return preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $body) ?? trim($body);
    }
}
