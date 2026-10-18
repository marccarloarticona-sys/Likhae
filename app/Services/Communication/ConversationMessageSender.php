<?php

namespace App\Services\Communication;

use App\Models\Communication\Conversation;
use App\Models\Communication\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;
use Illuminate\Validation\ValidationException;

class ConversationMessageSender
{
    public function __construct(private readonly MessageAttachmentService $attachments) {}

    /** @return array{body: string, conversation_id: int|null, attachment: mixed, shipment_id: int|null} */
    public function validate(Request $request): array
    {
        if (is_string($request->input('body'))) {
            $request->merge(['body' => $this->trimWhitespace($request->input('body'))]);
        }

        return $request->validate([
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
            'shipment_id' => ['nullable', 'integer', 'exists:shipments,id'],
            'body' => ['required', 'string', 'max:1000'],
            'attachment' => $this->attachments->validationRules(),
        ]);
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
        abort_unless($recipient->isActive(), 404);

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

        if ($request->hasFile('attachment') && ! $this->attachments->available()) {
            throw ValidationException::withMessages([
                'attachment' => 'Image attachments are temporarily unavailable.',
            ]);
        }

        $attachmentPath = null;
        try {
            if ($request->hasFile('attachment')) {
                $attachmentPath = $this->attachments->store($request->file('attachment'));
            }

            return $conversations->send(
                $request->user(),
                (int) $recipient->id,
                (string) $data['body'],
                $context,
                $conversation,
                $attachmentPath,
            );
        } catch (Throwable $exception) {
            $this->attachments->delete($attachmentPath);
            throw $exception;
        }
    }

    private function trimWhitespace(string $body): string
    {
        return preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $body) ?? trim($body);
    }
}
