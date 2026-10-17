<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DefaultProfileImageTest extends TestCase
{
    public function test_profile_image_uses_only_existing_local_files_and_role_defaults(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profile-photos/buyer.webp', 'custom-image');

        $uploaded = Blade::render('<x-shared.profile-image :path="$path" role="buyer" alt="Buyer" />', [
            'path' => 'profile-photos/buyer.webp',
        ]);
        $missing = Blade::render('<x-shared.profile-image :path="$path" role="buyer" alt="Buyer" />', [
            'path' => 'profile-photos/missing.webp',
        ]);
        $remote = Blade::render('<x-shared.profile-image :path="$path" role="seller" alt="Store" />', [
            'path' => 'https://outside.example/store.png',
        ]);
        $banner = Blade::render('<x-shared.profile-image role="store-banner" alt="Store banner" />');

        $this->assertStringContainsString('storage/profile-photos/buyer.webp', $uploaded);
        $this->assertStringContainsString('images/defaults/profile-avatar.svg', $missing);
        $this->assertStringContainsString('images/defaults/store-logo.svg', $remote);
        $this->assertStringNotContainsString('outside.example', $remote);
        $this->assertStringContainsString('images/defaults/store-banner.svg', $banner);
        $this->assertStringContainsString('this.onerror=null', $banner);
        $this->assertFileExists(public_path('images/defaults/profile-avatar.svg'));
        $this->assertFileExists(public_path('images/defaults/store-logo.svg'));
        $this->assertFileExists(public_path('images/defaults/store-banner.svg'));
    }
}
