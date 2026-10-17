<?php

namespace App\Services\Communication;

use App\Models\Communication\Conversation;
use App\Models\Communication\Message;
use App\Models\User;
use App\Services\Media\ImageOptimizationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MessageAttachmentService
{
    public function __construct(private readonly ImageOptimizationService $images) {}

    public function available(): bool
    {
        return Schema::hasColumn('messages', 'attachment_path');
    }

    /** @return array<int, mixed> */
    public function validationRules(): array
    {
        return [
            'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp',
            'mimetypes:image/jpeg,image/png,image/webp', 'extensions:jpg,jpeg,png,webp', 'max:5120',
            static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $value instanceof UploadedFile || ! $value->isValid()) {
                    return;
                }

                $metadata = @getimagesize($value->getRealPath());
                $mime = $metadata['mime'] ?? null;
                $extension = strtolower($value->getClientOriginalExtension());
                $extensionsByMime = [
                    'image/jpeg' => ['jpg', 'jpeg'],
                    'image/png' => ['png'],
                    'image/webp' => ['webp'],
                ];

                if (! isset($extensionsByMime[$mime]) || ! in_array($extension, $extensionsByMime[$mime], true)) {
                    $fail('Upload a valid JPEG, PNG, or WebP image with a matching file extension.');
                }
            },
        ];
    }

    public function store(UploadedFile $file): string
    {
        if (! $this->available()) {
            throw ValidationException::withMessages([
                'attachment' => 'Image attachments are temporarily unavailable.',
            ]);
        }

        return $this->images->store($file, 'message-attachments', 'local');
    }

    public function delete(?string $path): void
    {
        if (filled($path)) {
            Storage::disk('local')->delete($path);
        }
    }

    public function privateUrl(Message $message): ?string
    {
        return filled($message->attachment_path)
            ? route('messages.attachments.show', $message->id)
            : null;
    }

    /** @return array{path: string, mime: string} */
    public function authorizedFile(User $user, Message $message): array
    {
        abort_unless($user->isActive(), 403);
        abort_unless($this->available() && filled($message->attachment_path), 404);
        $conversation = Conversation::query()->findOrFail($message->conversation_id);
        app(ConversationAuthorizationService::class)->assertCanAccess($user, $conversation);

        $relativePath = (string) $message->attachment_path;
        abort_unless(preg_match('#^message-attachments/[A-Fa-f0-9-]+\.(jpg|jpeg|png|webp)$#D', $relativePath) === 1, 404);

        $disk = Storage::disk('local');
        $absolutePath = realpath($disk->path($relativePath));
        $directory = realpath($disk->path('message-attachments'));
        abort_unless($absolutePath && $directory && str_starts_with($absolutePath, $directory.DIRECTORY_SEPARATOR), 404);

        $metadata = @getimagesize($absolutePath);
        $mime = $metadata['mime'] ?? '';
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return ['path' => $absolutePath, 'mime' => $mime];
    }
}
