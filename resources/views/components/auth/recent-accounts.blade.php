@props(['savedAccounts' => collect(), 'portal' => 'marketplace', 'showSavedAccountPicker' => false])

@php
    $savedAccounts = collect($savedAccounts ?? []);
    $roleNames = [
        'ADMIN' => 'Admin',
        'BUYER' => 'Buyer',
        'SELLER' => 'Seller',
        'LOGISTICS' => 'Logistics',
        'RIDER' => 'Rider',
    ];
@endphp

@if($savedAccounts->isNotEmpty())
    <section class="auth-saved-accounts" data-saved-account-picker aria-label="Recent accounts" @if(!$showSavedAccountPicker) hidden @endif>
        <div class="auth-saved-accounts__heading">
            <p class="auth-saved-accounts__label">Recent accounts</p>
            <form method="POST" action="{{ route('login.recent-accounts.clear') }}">
                @csrf
                <input type="hidden" name="portal" value="{{ $portal }}">
                <button type="submit" class="auth-saved-accounts__clear">Clear all</button>
            </form>
        </div>

        <div class="auth-saved-accounts__list">
            @foreach($savedAccounts as $account)
                @php
                    $accountName = filled($account['name'] ?? null) ? $account['name'] : $account['email'];
                    [$emailName, $emailDomain] = array_pad(explode('@', (string) $account['email'], 2), 2, '');
                    $maskedEmail = mb_substr($emailName, 0, 1).'***@'.$emailDomain;
                @endphp
                <div class="auth-saved-account-row">
                    <button
                        type="button"
                        class="auth-saved-account"
                        data-saved-account
                        data-account-name="{{ $accountName }}"
                        data-account-email="{{ $account['email'] }}"
                        data-account-display-email="{{ $maskedEmail }}"
                    >
                        <x-shared.profile-image :path="$account['avatar_path'] ?? null" role="buyer" class="auth-saved-account__avatar-image" :alt="$accountName" />
                        <span class="auth-saved-account__details">
                            <strong>{{ $accountName }}</strong>
                            <span>{{ $roleNames[strtoupper((string) ($account['account_type'] ?? ''))] ?? 'LIKHAE account' }} &middot; {{ $maskedEmail }}</span>
                        </span>
                        <span class="auth-saved-account__arrow" aria-hidden="true">&rarr;</span>
                    </button>
                    <form method="POST" action="{{ route('login.recent-accounts.remove') }}">
                        @csrf
                        <input type="hidden" name="portal" value="{{ $portal }}">
                        <input type="hidden" name="account_id" value="{{ $account['id'] }}">
                        <button type="submit" class="auth-saved-account__remove" aria-label="Remove {{ $accountName }} from recent accounts">Remove</button>
                    </form>
                </div>
            @endforeach
        </div>

        <button type="button" class="auth-use-another-account" data-use-another-account>Use another account</button>
        <p class="auth-saved-accounts__privacy">Passwords are never saved here. Enter your password to continue.</p>
    </section>
@endif
