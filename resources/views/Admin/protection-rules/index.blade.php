@extends('layouts.admin.app')
@section('title', 'Additional Care Rules')
@section('content')
@php
    $hasConfiguredProtectionRules = $ready && $rules->total() > 0;
    $defaultProtectionTab = $hasConfiguredProtectionRules && (!$errors->any() || old('_protection_rule_edit_id')) ? 'rules' : 'create';
@endphp
<div class="ad-protection-page" data-default-tab="{{ $defaultProtectionTab }}">
    <header class="ad-page-head">
        <div>
            <span class="ad-overline">Step 8</span>
            <h2>Additional Care / Fragile Protection</h2>
            <p>Set an optional extra care fee for eligible products. Rules affect new checkouts only; existing orders keep their saved fees.</p>
        </div>
    </header>

    @if(session('status'))
        <div class="ad-protection-flash" role="status">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="ad-protection-flash ad-protection-flash--error" id="protection-rule-errors" role="alert">
            <strong>Please review these details:</strong>
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    @if(!$ready)
        <div class="ad-note is-warning" role="status">Protection-rule tables are not installed yet. No protection fee can be charged until they are available.</div>
    @endif

    @if($ready)
        <nav class="ad-protection-tabs" aria-label="Care rule sections">
            <a href="#protection-create-panel">Create rule</a>
            <a href="#protection-rules-panel">Configured rules <span>{{ $rules->total() }}</span></a>
        </nav>
    @endif

    <section id="protection-create-panel" class="ad-card ad-protection-create ad-protection-tab-panel" aria-labelledby="protection-create-title">
        <div class="ad-card-head">
            <div>
                <span class="ad-overline">New rule</span>
                <h2 id="protection-create-title">Add a care fee rule</h2>
                <p>Choose where it applies, then set the fee calculation.</p>
            </div>
            <span class="ad-status">Inactive by default</span>
        </div>

        <form method="POST" action="{{ route('admin.protection-rules.store') }}" class="ad-protection-form" data-protection-rule-form>
            @csrf
            @include('components.admin.protection-rule-fields', ['rule' => null])
            <div class="ad-protection-form__actions">
                <label class="ad-protection-activate">
                    <input type="checkbox" name="active" value="1" @checked(old('_protection_rule_edit_id') === null && old('active'))>
                    <span><strong>Activate after saving</strong><small>New eligible checkouts can use this rule.</small></span>
                </label>
                <button class="ad-btn ad-btn-primary" @disabled(!$ready)>Save rule</button>
            </div>
        </form>
    </section>

    @if($ready)
        <section id="protection-rules-panel" class="ad-card ad-protection-rules ad-protection-tab-panel" aria-labelledby="protection-rules-title">
            <div class="ad-card-head">
                <div>
                    <span class="ad-overline">Rules</span>
                    <h2 id="protection-rules-title">Configured care fees</h2>
                    <p>Product rules take priority over subcategory rules, which take priority over category rules.</p>
                </div>
            </div>
            <div class="ad-protection-rule-list">
                @forelse($rules as $rule)
                    @php
                        $ruleTarget = $rule->product?->name
                            ?: ($rule->subcategory?->name
                                ? $rule->subcategory->name . ' · ' . ($rule->subcategory->parent?->name ?? 'Subcategory')
                                : ($rule->category?->name ?: 'Unscoped'));
                        $percentage = number_format((float) $rule->percentage_rate, 2) . '%';
                        $flatAmount = 'PHP ' . number_format((float) $rule->flat_amount, 2);
                    @endphp
                    <article class="ad-protection-rule">
                        <header class="ad-protection-rule__header">
                            <div>
                                <span class="ad-protection-rule__target-type">{{ $rule->product_id ? 'Product' : ($rule->subcategory_id ? 'Subcategory' : 'Category') }}</span>
                                <h3>{{ $ruleTarget }}</h3>
                                <p>{{ $rule->risk_level ?: 'General care' }}</p>
                            </div>
                            <div class="ad-protection-rule__actions">
                                <span class="ad-status {{ $rule->active ? 'is-active' : '' }}">{{ $rule->active ? 'Active' : 'Inactive' }}</span>
                                <form method="POST" action="{{ route('admin.protection-rules.toggle', $rule) }}"
                                    data-confirm-title="{{ $rule->active ? 'Deactivate protection rule?' : 'Activate protection rule?' }}"
                                    data-confirm-message="{{ $rule->active ? 'New checkouts will stop using this fee rule. Existing orders keep their saved fee snapshots.' : 'New eligible checkouts may begin using this fee rule.' }}"
                                    data-confirm-label="{{ $rule->active ? 'Deactivate rule' : 'Activate rule' }}"
                                    @if($rule->active) data-confirm-tone="danger" @endif>
                                    @csrf
                                    @method('PATCH')
                                    <button class="ad-btn ad-btn-secondary ad-btn-sm">{{ $rule->active ? 'Deactivate' : 'Activate' }}</button>
                                </form>
                            </div>
                        </header>
                        <div class="ad-protection-rule__details">
                            <div><span>Fee</span><strong>
                                @if($rule->calculation_type === 'PERCENTAGE')
                                    {{ $percentage }} of item price
                                @elseif($rule->calculation_type === 'FLAT')
                                    {{ $flatAmount }}
                                @else
                                    {{ $percentage }} + {{ $flatAmount }}
                                @endif
                            </strong><small>{{ $rule->scope === 'PER_UNIT' ? 'For each item' : 'Once per order line' }}</small></div>
                            <div><span>Item price</span><strong>
                                @if($rule->minimum_product_price !== null || $rule->maximum_product_price !== null)
                                    PHP {{ $rule->minimum_product_price !== null ? number_format((float) $rule->minimum_product_price, 2) : '0.00' }} – {{ $rule->maximum_product_price !== null ? number_format((float) $rule->maximum_product_price, 2) : 'no upper limit' }}
                                @else
                                    Any price
                                @endif
                            </strong>@if($rule->minimum_fee !== null || $rule->maximum_fee !== null)<small>Fee {{ $rule->minimum_fee !== null ? 'min PHP ' . number_format((float) $rule->minimum_fee, 2) : '' }}{{ $rule->minimum_fee !== null && $rule->maximum_fee !== null ? ' · ' : '' }}{{ $rule->maximum_fee !== null ? 'max PHP ' . number_format((float) $rule->maximum_fee, 2) : '' }}</small>@endif</div>
                        </div>
                        <details class="ad-protection-edit" @if((string) old('_protection_rule_edit_id') === (string) $rule->id) open @endif>
                            <summary class="ad-btn ad-btn-secondary ad-btn-sm">Edit rule</summary>
                            <div class="ad-protection-edit__panel">
                                <form method="POST" action="{{ route('admin.protection-rules.update', $rule) }}" class="ad-protection-form" data-protection-rule-form>
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="_protection_rule_edit_id" value="{{ $rule->id }}">
                                    @include('components.admin.protection-rule-fields', ['rule' => $rule])
                                    <div class="ad-protection-form__actions">
                                        <label class="ad-protection-activate">
                                            <input type="checkbox" name="active" value="1" @checked((string) old('_protection_rule_edit_id') === (string) $rule->id ? old('active') : $rule->active)>
                                            <span><strong>Rule is active</strong><small>Only new eligible checkouts are affected.</small></span>
                                        </label>
                                        <button class="ad-btn ad-btn-primary ad-btn-sm">Save changes</button>
                                    </div>
                                </form>
                            </div>
                        </details>
                    </article>
                @empty
                    <div class="ad-protection-empty">
                        <span aria-hidden="true">+</span>
                        <strong>No care fee rules yet</strong>
                        <p>Add a rule above when a product needs extra care or fragile-item protection.</p>
                    </div>
                @endforelse
            </div>
            @if($rules->hasPages())<div class="ad-protection-pagination">{{ $rules->links() }}</div>@endif
        </section>
    @endif
</div>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('[data-protection-rule-form]').forEach(function (form) {
        const targetOptions = form.querySelectorAll('[data-protection-target-option]');
        const targetPanels = form.querySelectorAll('[data-protection-target-panel]');
        const calculation = form.querySelector('[name="calculation_type"]');
        const formulaPanels = form.querySelectorAll('[data-protection-formula-panel]');
        const hybridNote = form.querySelector('[data-protection-hybrid-note]');

        function updateTarget() {
            const selected = form.querySelector('[name="target_level"]:checked')?.value;
            targetPanels.forEach(function (panel) {
                const active = panel.dataset.protectionTargetPanel === selected;
                panel.hidden = !active;
                panel.querySelectorAll('select').forEach(function (input) {
                    input.required = active;
                    if (!active) input.value = '';
                });
            });
            targetOptions.forEach(function (option) {
                const radio = option.querySelector('input[type="radio"]');
                option.classList.toggle('is-selected', radio.checked);
            });
        }

        function updateFormula() {
            const selected = calculation?.value || 'PERCENTAGE';
            if (hybridNote) hybridNote.hidden = selected !== 'HYBRID';
            formulaPanels.forEach(function (panel) {
                const formula = panel.dataset.protectionFormulaPanel;
                const active = formula === selected || selected === 'HYBRID' && formula !== 'HYBRID';
                panel.hidden = !active;
                panel.querySelectorAll('input').forEach(function (input) {
                    if (!active) input.value = '';
                    input.required = active && (selected === 'PERCENTAGE' && input.name === 'percentage_rate'
                        || selected === 'FLAT' && input.name === 'flat_amount');
                });
            });
        }

        targetOptions.forEach(function (option) {
            option.querySelector('input[type="radio"]').addEventListener('change', updateTarget);
        });
        calculation?.addEventListener('change', updateFormula);
        updateTarget();
        updateFormula();
    });
</script>
@endpush
