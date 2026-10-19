<?php

namespace App\Services\Communication;

use App\Models\Communication\Conversation;
use App\Models\Communication\Message;
use App\Models\User;
use App\Services\Media\ImageOptimizationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            'nullable', 'file', 'max:5120',
            'extensions:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,rar,7z,mp3,wav,mp4,mov',
            static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $value instanceof UploadedFile || ! $value->isValid()) {
                    return;
                }

                $extension = strtolower($value->getClientOriginalExtension());
                $mimesByExtension = [
                    'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
                    'png' => ['image/png'], 'webp' => ['image/webp'],
                    'pdf' => ['application/pdf'],
                    'doc' => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/x-cfb', 'application/cdfv2'],
                    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
                    'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/x-cfb', 'application/cdfv2'],
                    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
                    'ppt' => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/x-cfb', 'application/cdfv2'],
                    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
                    'txt' => ['text/plain'],
                    'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
                    'zip' => ['application/zip', 'application/x-zip', 'application/x-zip-compressed'],
                    'rar' => ['application/vnd.rar', 'application/x-rar', 'application/x-rar-compressed'],
                    '7z' => ['application/x-7z-compressed'],
                    'mp3' => ['audio/mpeg', 'audio/mp3'],
                    'wav' => ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave'],
                    'mp4' => ['video/mp4', 'application/mp4'],
                    'mov' => ['video/quicktime'],
                ];
                $mime = strtolower((string) $value->getMimeType());

                if (! isset($mimesByExtension[$extension]) || ! in_array($mime, $mimesByExtension[$extension], true)) {
                    $fail('Choose a supported image, document, archive, audio, or video file with a matching extension.');
                    return;
                }

                if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $metadata = @getimagesize($value->getRealPath());
                    if (! isset($metadata['mime']) || $metadata['mime'] !== $mime) {
                        $fail('Upload a valid image file.');
                    }
                }
            },
        ];
    }

    public function store(UploadedFile $file): string
    {
        if (! $this->available()) {
            throw ValidationException::withMessages([
                'attachment' => 'File attachments are temporarily unavailable.',
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return $this->images->store($file, 'message-attachments', 'local');
        }

        $path = $file->storeAs('message-attachments', Str::uuid().'.'.$extension, 'local');
        if (! is_string($path)) {
            throw new \RuntimeException('The message attachment could not be stored.');
        }

        return $path;
    }

    public function originalName(UploadedFile $file): string
    {
        $name = basename(str_replace(chr(92), '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        return $name === '' ? 'attachment.'.$file->getClientOriginalExtension() : Str::limit($name, 180, '');
    }

    public function storedMime(UploadedFile $file, string $path): string
    {
        if (str_ends_with(strtolower($path), '.webp')) {
            return 'image/webp';
        }

        return strtolower((string) ($file->getMimeType() ?: 'application/octet-stream'));
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

    public function originalNameFor(Message $message): ?string
    {
        if (! filled($message->attachment_path)) {
            return null;
        }

        return $message->attachment_original_name ?: basename((string) $message->attachment_path);
    }

    public function mimeFor(Message $message): ?string
    {
        if (! filled($message->attachment_path)) {
            return null;
        }

        if (filled($message->attachment_mime)) {
            return strtolower((string) $message->attachment_mime);
        }

        return match (strtolower(pathinfo((string) $message->attachment_path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    /** @return array{path: string, mime: string, name: string, is_image: bool} */
    public function authorizedFile(User $user, Message $message): array
    {
        abort_unless($user->isActive(), 403);
        abort_unless($this->available() && filled($message->attachment_path), 404);
        $conversation = Conversation::query()->findOrFail($message->conversation_id);
        app(ConversationAuthorizationService::class)->assertCanAccess($user, $conversation);

        $relativePath = (string) $message->attachment_path;
        abort_unless(preg_match('#^message-attachments/[A-Za-z0-9-]+\.(jpg|jpeg|png|webp|pdf|doc|docx|xls|xlsx|ppt|pptx|txt|csv|zip|rar|7z|mp3|wav|mp4|mov)$#D', $relativePath) === 1, 404);

        $disk = Storage::disk('local');
        $absolutePath = realpath($disk->path($relativePath));
        $directory = realpath($disk->path('message-attachments'));
        abort_unless($absolutePath && $directory && str_starts_with($absolutePath, $directory.DIRECTORY_SEPARATOR), 404);

        $mime = $this->mimeFor($message) ?: '';
        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/webp', 'application/pdf',
            'application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/x-cfb', 'application/cdfv2',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain', 'text/csv', 'application/csv', 'application/vnd.rar', 'application/x-rar',
            'application/x-rar-compressed', 'application/zip', 'application/x-zip', 'application/x-zip-compressed',
            'application/x-7z-compressed', 'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav',
            'audio/wave', 'audio/vnd.wave', 'video/mp4', 'application/mp4', 'video/quicktime',
            'application/octet-stream',
        ];
        abort_unless(in_array($mime, $allowedMimes, true), 404);

        if (str_starts_with($mime, 'image/')) {
            $metadata = @getimagesize($absolutePath);
            abort_unless(($metadata['mime'] ?? null) === $mime, 404);
        }

        return [
            'path' => $absolutePath,
            'mime' => $mime,
            'name' => $this->originalNameFor($message) ?: basename($relativePath),
            'is_image' => str_starts_with($mime, 'image/'),
        ];
    }
}
