@php
    $destinationNoun = $destinationNoun ?? 'payout destination';
    $destinationNounPlural = $destinationNounPlural ?? 'payout destinations';
    $destinationSettingsLabel = $destinationSettingsLabel ?? 'Payout settings';
    $destinationSaveLabel = $destinationSaveLabel ?? 'Save payout details';
    $destinationUpdateLabel = $destinationUpdateLabel ?? 'Update payout details';
    $destinationEmptyMessage = $destinationEmptyMessage ?? 'No '.$destinationNounPlural.' saved yet. Add one below; it will become the default automatically.';
    $destinationAddTitle = $destinationAddTitle ?? (count($payoutDestinations) ? 'Add another destination' : 'Add a destination');
    $destinationEditTitle = $destinationEditTitle ?? 'Update destination';
    $destinationIncompleteNote = $destinationIncompleteNote ?? 'Incomplete details; add a complete destination to use it for future payouts.';
    $destinationChannelLabel = $destinationChannelLabel ?? 'Payment channel';
    $destinationChannelHelp = $destinationChannelHelp ?? 'Choose the service receiving this payment.';
@endphp
<div class="payout-destination-list" aria-label="Saved {{ $destinationNounPlural }}">
    @forelse($payoutDestinations as $destination)
        @php($destinationType = $destination['payout_account_type'] ?? \App\Support\PayoutAccountDetails::ACCOUNT_NUMBER)
        @php($destinationComplete = filled($destination['payout_method']) && filled($destination['payout_account_name']) && filled($destination['payout_account_number']) && ($destination['payout_method'] !== 'Bank Transfer' || filled($destination['payout_bank_name'])))
        <article class="payout-destination-card">
            <div class="payout-destination-card__head">
                <strong>{{ $destination['payout_method'] }}@if($destination['payout_bank_name']) · {{ $destination['payout_bank_name'] }}@endif</strong>
                @if($destination['is_default'])
                    <span class="payout-saved-badge">Default</span>
                @endif
            </div>
            <dl class="payout-destination-card__details">
                <div><dt>Account holder</dt><dd>{{ $destination['payout_account_name'] }}</dd></div>
                <div><dt>{{ \App\Support\PayoutAccountDetails::typeLabel($destinationType) }}</dt><dd>{{ \App\Support\PayoutAccountDetails::formatNumber($destination['payout_account_number'], $destinationType) }}</dd></div>
            </dl>
            <div class="payout-destination-card__actions">
                <button type="button" class="payout-inline-button" data-payout-edit
                    data-destination-id="{{ $destination['id'] }}"
                    data-payout-method="{{ $destination['payout_method'] }}"
                    data-payout-bank-name="{{ $destination['payout_bank_name'] }}"
                    data-payout-account-name="{{ $destination['payout_account_name'] }}"
                    data-payout-account-type="{{ $destinationType }}"
                    data-payout-account-number="{{ $destination['payout_account_number'] }}"
                    data-is-default="{{ $destination['is_default'] ? '1' : '0' }}">Edit</button>
                @if(!$destination['is_default'] && $destinationComplete)
                    <form method="POST" action="{{ route($payoutDestinationRoute, $payoutDestinationRouteParameters ?? []) }}">
                        @csrf @method('PUT')
                        <input type="hidden" name="destination_action" value="set_default">
                        <input type="hidden" name="destination_id" value="{{ $destination['id'] }}">
                        <button type="submit" class="payout-inline-button">Set as default</button>
                    </form>
                @elseif(!$destinationComplete)
                    <span class="payout-destination-card__note">{{ $destinationIncompleteNote }}</span>
                @endif
                <form method="POST" action="{{ route($payoutDestinationRoute, $payoutDestinationRouteParameters ?? []) }}" data-confirm-title="Remove {{ $destinationNoun }}?" data-confirm-message="Remove this saved {{ $destinationNoun }}? If it is the default, another saved destination will become the default." data-confirm-label="Remove {{ $destinationNoun }}" data-confirm-tone="danger">
                    @csrf @method('PUT')
                    <input type="hidden" name="destination_action" value="remove">
                    <input type="hidden" name="destination_id" value="{{ $destination['id'] }}">
                    <button type="submit" class="payout-inline-button is-danger">Remove</button>
                </form>
            </div>
        </article>
    @empty
        <p class="payout-empty-state">{{ $destinationEmptyMessage }}</p>
    @endforelse
</div>

@php($hasPayoutDefault = collect($payoutDestinations)->contains(fn (array $destination): bool => !empty($destination['is_default'])))
@php($editingDestinationId = old('destination_action') === 'update' ? (string) old('destination_id') : '')
@php($editingDestination = collect($payoutDestinations)->firstWhere('id', $editingDestinationId))
@php($editingDestinationIsDefault = (bool) data_get($editingDestination, 'is_default', false))
@php($payoutEditorTitle = $editingDestinationId !== '' ? $destinationEditTitle : $destinationAddTitle)

<div class="payout-editor">
    <div class="payout-editor__head">
        <div><span class="payout-editor__eyebrow">{{ $destinationSettingsLabel }}</span><h3 data-payout-editor-title>{{ $payoutEditorTitle }}</h3></div>
        <p>{{ $payoutChangeDescription }}</p>
    </div>
    <form class="payout-form payout-fields-grid" method="POST" action="{{ route($payoutDestinationRoute, $payoutDestinationRouteParameters ?? []) }}" data-payout-destination-form data-payout-save-label="{{ $destinationSaveLabel }}" data-payout-update-label="{{ $destinationUpdateLabel }}" data-payout-edit-title="{{ $destinationEditTitle }}" data-payout-has-default="{{ $hasPayoutDefault ? '1' : '0' }}" data-payout-add-title="{{ $destinationAddTitle }}" data-payout-editing="{{ $editingDestinationId !== '' ? '1' : '0' }}" data-payout-edit-default="{{ $editingDestinationIsDefault ? '1' : '0' }}">
        @csrf @method('PUT')
        <input type="hidden" name="destination_action" value="{{ $editingDestinationId !== '' ? 'update' : 'add' }}">
        <input type="hidden" name="destination_id" value="{{ $editingDestinationId }}">
        <label><span>{{ $destinationChannelLabel }}</span><select name="payout_method" required><option value="">Select a channel</option>@foreach(['GCash', 'Maya', 'Bank Transfer', 'Other'] as $method)<option value="{{ $method }}" @selected(old('payout_method') === $method)>{{ $method }}</option>@endforeach</select><small>{{ $destinationChannelHelp }}</small></label>
        @php($payoutMethod = old('payout_method'))
        <label class="payout-bank-field" data-payout-bank-field @if($payoutMethod !== 'Bank Transfer') hidden @endif><span>Bank name</span><input name="payout_bank_name" maxlength="120" value="{{ old('payout_bank_name') }}" autocomplete="organization" placeholder="e.g. BPI"></label>
        <label><span>Account holder name</span><input name="payout_account_name" maxlength="150" value="{{ old('payout_account_name') }}" required autocomplete="name" placeholder="Name on the account"></label>
        @php($payoutAccountType = old('payout_account_type', 'MOBILE_NUMBER'))
        <label><span>Number type</span><select name="payout_account_type" data-payout-account-type required><option value="MOBILE_NUMBER" @selected($payoutAccountType === 'MOBILE_NUMBER')>Mobile number</option><option value="ACCOUNT_NUMBER" @selected($payoutAccountType === 'ACCOUNT_NUMBER')>Account number</option></select></label>
        <label class="payout-number-field"><span data-payout-number-label>{{ \App\Support\PayoutAccountDetails::typeLabel($payoutAccountType) }}</span><input type="text" inputmode="numeric" data-payout-account-number name="payout_account_number" maxlength="150" value="{{ old('payout_account_number') }}" required autocomplete="off" aria-describedby="{{ $payoutHelpId }}"><small id="{{ $payoutHelpId }}" data-payout-number-help>{{ \App\Support\PayoutAccountDetails::formatHint($payoutAccountType) }}</small></label>
        <label class="payout-default-choice"><input type="checkbox" name="make_default" value="1" data-payout-make-default @checked(old('make_default') || ($editingDestinationId !== '' && $editingDestinationIsDefault) || !$hasPayoutDefault)> <span>Set as default for future {{ $payoutPurpose }}</span></label>
        <div class="payout-security-note is-wide"><span class="payout-security-note__icon"><svg viewBox="0 0 20 20" aria-hidden="true"><rect x="4" y="9" width="12" height="8" rx="2"/><path d="M6.5 9V6a3.5 3.5 0 0 1 7 0v3"/></svg></span><div><strong>Private and protected</strong><p>{{ $payoutSecurityCopy }} Account holder names and numbers are encrypted. Card numbers and security codes are never collected.</p></div></div>
        <div class="payout-form-actions is-wide"><small>Check the number carefully before saving.</small><button type="button" class="payout-inline-button" data-payout-cancel-edit @if($editingDestinationId === '') hidden @endif>Cancel edit</button><button type="submit" class="{{ $payoutButtonClass }}" data-payout-submit>{{ $editingDestinationId !== '' ? $destinationUpdateLabel : $destinationSaveLabel }}</button></div>
    </form>
</div>
