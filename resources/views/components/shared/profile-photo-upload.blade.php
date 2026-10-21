@props([
    'user',
    'label' => 'Profile photo',
    'name' => 'profile_photo',
    'id' => 'profile-photo-upload',
])

@php
    $photoPath = $user?->profile_photo_path;
    $initials = collect(explode(' ', trim((string) ($user?->name ?? 'Account'))))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('') ?: 'LK';
@endphp

<div class="profile-photo-upload" data-profile-photo-picker>
    <div class="profile-photo-upload__preview">
        <span data-profile-photo-initials hidden>{{ $initials }}</span>
        <x-shared.profile-image :path="$photoPath" role="buyer" :alt="$user?->name ?? 'Profile photo'" data-profile-photo-image />
    </div>
    <div class="profile-photo-upload__details">
        <strong>{{ $slot->isNotEmpty() ? $slot : $label }}</strong>
        <p>JPG, PNG, or WebP. Maximum 5 MB.</p>
        <label class="profile-photo-upload__button" for="{{ $id }}">Choose photo</label>
        <input id="{{ $id }}" class="profile-photo-upload__input" type="file" name="{{ $name }}" accept="image/jpeg,image/png,image/webp" data-profile-photo-input>
    </div>
</div>

@once
    <style>
        .profile-photo-upload { display: flex; align-items: center; gap: 14px; }
        .profile-photo-upload__preview { display: grid; width: 72px; height: 72px; flex: 0 0 72px; place-items: center; overflow: hidden; border: 1px solid #eadccc; border-radius: 50%; background: #f6efe7; color: #7d241b; font-size: 18px; font-weight: 800; }
        .profile-photo-upload__preview img { width: 100%; height: 100%; object-fit: cover; }
        .profile-photo-upload__details { display: grid; justify-items: start; gap: 4px; }
        .profile-photo-upload__details strong { font-size: 13px; }
        .profile-photo-upload__details p { margin: 0; color: #8d7669; font-size: 11px; }
        .profile-photo-upload__button { display: inline-flex; margin-top: 3px; cursor: pointer; border: 1px solid #eadccc; border-radius: 8px; background: #fffdf9; padding: 7px 11px; color: #6b2920; font-size: 11px; font-weight: 700; }
        .profile-photo-upload__button:hover { background: #f6efe7; }
        .profile-photo-upload__input { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; clip-path: inset(50%); }
        .profile-photo-upload__input:focus-visible + .profile-photo-upload__button { outline: 2px solid #a93e30; outline-offset: 2px; }
        .dark .profile-photo-upload__preview { border-color: #49342b; background: #211b17; color: #f3c6ba; }
        .dark .profile-photo-upload__details p { color: #b8a69c; }
        .dark .profile-photo-upload__button { border-color: #49342b; background: #211b17; color: #f3c6ba; }
        .dark .profile-photo-upload__button:hover { background: #382820; }
    </style>
    <script>
        document.addEventListener('change', event => {
            const input = event.target.closest('[data-profile-photo-input]');
            if (!input || !input.files?.[0]) return;

            const picker = input.closest('[data-profile-photo-picker]');
            const image = picker?.querySelector('[data-profile-photo-image]');
            const initials = picker?.querySelector('[data-profile-photo-initials]');
            if (!image || !initials) return;

            image.src = URL.createObjectURL(input.files[0]);
            image.hidden = false;
            initials.hidden = true;
        });
    </script>
@endonce
