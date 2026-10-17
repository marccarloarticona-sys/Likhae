<?php

namespace App\Services\Account;

use App\Models\User;
use App\Services\Media\ImageOptimizationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProfilePhotoService
{
    public function __construct(private readonly ImageOptimizationService $images) {}

    public function replace(User $user, UploadedFile $photo): void
    {
        $newPath = $this->images->store($photo, 'profile-photos');

        if (! $newPath) {
            throw ValidationException::withMessages([
                'profile_photo' => 'The profile photo could not be saved. Please try again.',
            ]);
        }

        $oldPath = $user->profile_photo_path;

        try {
            $user->forceFill(['profile_photo_path' => $newPath])->save();
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($newPath);

            throw $exception;
        }

        if ($oldPath && $oldPath !== $newPath && ! Str::startsWith($oldPath, ['http://', 'https://'])) {
            Storage::disk('public')->delete($oldPath);
        }
    }
}
