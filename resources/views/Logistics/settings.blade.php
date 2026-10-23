@extends('layouts.logistics.app')
@section('title','System Settings')
@push('styles')@vite('resources/css/shared/workspace-settings.css')@endpush
@section('content')
    <x-shared.workspace.settings :config="$settingsConfig" :preferences="$preferences" />
    <section class="ws-settings-page" aria-labelledby="rider-policy-heading">
        <div class="ws-settings-card is-wide">
            <header><b aria-hidden="true">RP</b><div><h2 id="rider-policy-heading">Rider Policy</h2><p>Publish operational requirements for Riders assigned to your Logistics Center. Other providers and their Riders cannot see or manage these versions.</p></div></header>
            <div class="ws-settings-list">
                @if($policySchemaReady)
                    <form method="POST" action="{{ route('logistics.policies.rider.store') }}" class="ws-rider-policy-form">
                        @csrf
                        <label>Policy title<input name="title" maxlength="255" value="{{ old('title', 'Rider Operational Policy') }}" required></label>
                        @error('title')<small class="ws-policy-error">{{ $message }}</small>@enderror
                        <label>Version<input name="version" maxlength="50" value="{{ old('version') }}" placeholder="For example: 1.0" required></label>
                        @error('version')<small class="ws-policy-error">{{ $message }}</small>@enderror
                        <label>Effective date <small>Blank means immediately</small><input type="datetime-local" name="effective_at" value="{{ old('effective_at') }}"></label>
                        @error('effective_at')<small class="ws-policy-error">{{ $message }}</small>@enderror
                        <label class="ws-policy-body">Complete approved policy text<textarea name="body" rows="8" required>{{ old('body') }}</textarea></label>
                        @error('body')<small class="ws-policy-error">{{ $message }}</small>@enderror
                        <div class="ws-policy-actions"><p>Published versions remain in history. Only your Riders will be required to sign in again when a version takes effect.</p><button type="submit">Publish Rider Policy</button></div>
                    </form>
                @else
                    <p class="ws-settings-row"><span><strong>Policy storage is not installed</strong><small>Run the additive policy migration before publishing.</small></span></p>
                @endif
            </div>
            <div class="ws-settings-list" aria-label="Rider policy history">
                @forelse($riderPolicies as $policy)
                    <details class="ws-rider-policy-history">
                        <summary><strong>{{ $policy->title }}</strong><span>Version {{ $policy->version }} · {{ $policy->effective_at?->format('M j, Y') ?? 'Immediate' }}</span></summary>
                        <div>{{ $policy->body }}</div>
                    </details>
                @empty
                    <p class="ws-settings-row"><span><strong>No Rider Policy versions published</strong><small>Publish your first operational policy above.</small></span></p>
                @endforelse
            </div>
        </div>
    </section>
@endsection

@push('styles')
<style>
    .ws-rider-policy-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;padding:18px 20px;border-bottom:1px solid var(--ws-line)}
    .ws-rider-policy-form label{display:grid;gap:6px;color:var(--ws-text);font-size:12px;font-weight:800}
    .ws-rider-policy-form label small{display:inline;color:var(--ws-muted);font-weight:500}
    .ws-rider-policy-form input,.ws-rider-policy-form textarea{width:100%;border:1px solid var(--ws-line);border-radius:10px;background:var(--ws-surface);color:var(--ws-text);padding:10px 12px;font:inherit;font-weight:500}
    .ws-rider-policy-form .ws-policy-body,.ws-rider-policy-form .ws-policy-actions{grid-column:1/-1}
    .ws-policy-actions{display:flex;align-items:center;justify-content:space-between;gap:16px}.ws-policy-actions p{max-width:560px;margin:0;color:var(--ws-muted);font-size:11px;line-height:1.5}
    .ws-policy-actions button{border:0;border-radius:10px;background:#963d31;color:#fff;padding:11px 16px;font:inherit;font-size:12px;font-weight:800;cursor:pointer}
    .ws-policy-error{grid-column:1/-1;color:#b42318;font-size:11px}
    .ws-rider-policy-history{padding:14px 20px;border-top:1px solid var(--ws-line)}.ws-rider-policy-history summary{display:flex;justify-content:space-between;gap:12px;cursor:pointer;color:var(--ws-text);font-size:12px}.ws-rider-policy-history summary span{color:var(--ws-muted);font-size:11px}.ws-rider-policy-history div{white-space:pre-wrap;padding-top:14px;color:var(--ws-muted);font-size:12px;line-height:1.6}
    @media(max-width:680px){.ws-rider-policy-form{grid-template-columns:1fr}.ws-rider-policy-form .ws-policy-body,.ws-rider-policy-form .ws-policy-actions{grid-column:auto}.ws-policy-actions{align-items:stretch;flex-direction:column}.ws-policy-actions button{width:100%}.ws-rider-policy-history summary{align-items:flex-start;flex-direction:column}}
</style>
@endpush
