@php
    $editingRule = $rule ?? null;
    $storedTargetLevel = $editingRule?->product_id ? 'product' : ($editingRule?->subcategory_id ? 'subcategory' : 'category');
    $editingHasOldInput = $editingRule && (string) old('_protection_rule_edit_id') === (string) $editingRule->id;
    $createHasOldInput = !$editingRule && old('_protection_rule_edit_id') === null;
    $fieldValue = fn (string $field, mixed $default = null) => $editingRule
        ? ($editingHasOldInput ? old($field, $editingRule->{$field}) : $editingRule->{$field})
        : ($createHasOldInput ? old($field, $default) : $default);
    $targetLevel = $editingRule
        ? ($editingHasOldInput ? old('target_level', $storedTargetLevel) : $storedTargetLevel)
        : ($createHasOldInput ? old('target_level', filled(old('product_id')) ? 'product' : (filled(old('subcategory_id')) ? 'subcategory' : 'category')) : 'category');
    $calculationType = $fieldValue('calculation_type', 'PERCENTAGE') ?: 'PERCENTAGE';
    $dateValue = fn (string $field) => $editingRule && !$editingHasOldInput
        ? optional($editingRule->{$field})->format('Y-m-d\\TH:i')
        : ($createHasOldInput || $editingHasOldInput ? old($field, $editingRule ? optional($editingRule->{$field})->format('Y-m-d\\TH:i') : null) : null);
    $hasAdvancedValues = collect(['minimum_fee', 'maximum_fee', 'minimum_product_price', 'maximum_product_price', 'effective_from', 'effective_until'])
        ->contains(fn (string $field) => filled($fieldValue($field)));
@endphp

<div class="ad-protection-form__body">
    <fieldset class="ad-protection-step">
        <legend><span>1</span> Where should this rule apply?</legend>
        <p class="ad-protection-help">Choose one level. More specific rules take priority over broader ones.</p>
        <div class="ad-protection-targets">
            @foreach(['category' => ['Category', 'All products in a category'], 'subcategory' => ['Subcategory', 'A narrower group of products'], 'product' => ['Product', 'One specific product']] as $level => [$label, $description])
                <label class="ad-protection-target {{ $targetLevel === $level ? 'is-selected' : '' }}" data-protection-target-option>
                    <input type="radio" name="target_level" value="{{ $level }}" @checked($targetLevel === $level)>
                    <span><strong>{{ $label }}</strong><small>{{ $description }}</small></span>
                </label>
            @endforeach
        </div>

        <div class="ad-protection-target-panel" data-protection-target-panel="category" @if($targetLevel !== 'category') hidden @endif>
            <label class="ad-field">
                <span>Choose a category</span>
                <select name="category_id" @if($targetLevel === 'category') required @endif>
                    <option value="">Select a category</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) $fieldValue('category_id') === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="ad-protection-target-panel" data-protection-target-panel="subcategory" @if($targetLevel !== 'subcategory') hidden @endif>
            <label class="ad-field">
                <span>Choose a subcategory</span>
                <select name="subcategory_id" @if($targetLevel === 'subcategory') required @endif>
                    <option value="">Select a subcategory</option>
                    @foreach($categories as $category)
                        @foreach($category->children as $child)
                            <option value="{{ $child->id }}" @selected((string) $fieldValue('subcategory_id') === (string) $child->id)>{{ $category->name }} / {{ $child->name }}</option>
                        @endforeach
                    @endforeach
                </select>
            </label>
        </div>
        <div class="ad-protection-target-panel" data-protection-target-panel="product" @if($targetLevel !== 'product') hidden @endif>
            <label class="ad-field">
                <span>Choose a product</span>
                <select name="product_id" @if($targetLevel === 'product') required @endif>
                    <option value="">Select a product</option>
                    @foreach($products as $product)
                        <option value="{{ $product->id }}" @selected((string) $fieldValue('product_id') === (string) $product->id)>{{ $product->name }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </fieldset>

    <fieldset class="ad-protection-step">
        <legend><span>2</span> Set the care fee</legend>
        <div class="ad-protection-fee-grid">
            <label class="ad-field">
                <span>Care level <small>Optional</small></span>
                <input name="risk_level" value="{{ $fieldValue('risk_level') }}" maxlength="32" placeholder="For example: Fragile">
                <small>A short label to help you recognize this rule.</small>
            </label>
            <label class="ad-field">
                <span>Fee calculation</span>
                <select name="calculation_type">
                    <option value="PERCENTAGE" @selected($calculationType === 'PERCENTAGE')>Percentage of item price</option>
                    <option value="FLAT" @selected($calculationType === 'FLAT')>Fixed amount</option>
                    <option value="HYBRID" @selected($calculationType === 'HYBRID')>Percentage plus fixed amount</option>
                </select>
                <small>Hybrid adds the percentage and fixed amount together.</small>
            </label>
            <label class="ad-field">
                <span>Charge fee</span>
                <select name="scope">
                    <option value="PER_UNIT" @selected($fieldValue('scope', 'PER_UNIT') === 'PER_UNIT')>For each item</option>
                    <option value="PER_LINE" @selected($fieldValue('scope', 'PER_UNIT') === 'PER_LINE')>Once per order line</option>
                </select>
                <small>For example, 3 units means 3 fees when “For each item” is selected.</small>
            </label>
        </div>

        <div class="ad-protection-amounts">
            <label class="ad-field" data-protection-formula-panel="PERCENTAGE" @if(!in_array($calculationType, ['PERCENTAGE', 'HYBRID'], true)) hidden @endif>
                <span>Percentage rate</span>
                <span class="ad-protection-input-suffix"><input type="number" step="0.0001" min="0.0001" max="100" name="percentage_rate" value="{{ $fieldValue('percentage_rate') }}" placeholder="For example, 5"><span>%</span></span>
            </label>
            <label class="ad-field" data-protection-formula-panel="FLAT" @if(!in_array($calculationType, ['FLAT', 'HYBRID'], true)) hidden @endif>
                <span>Fixed amount</span>
                <span class="ad-protection-input-prefix"><span>PHP</span><input type="number" step="0.01" min="0.01" name="flat_amount" value="{{ $fieldValue('flat_amount') }}" placeholder="For example, 25.00"></span>
            </label>
            <p class="ad-protection-hybrid-note" data-protection-hybrid-note @if($calculationType !== 'HYBRID') hidden @endif>Enter a percentage, a fixed amount, or both.</p>
        </div>
    </fieldset>

    <details class="ad-protection-advanced" @if($hasAdvancedValues) open @endif>
        <summary>Optional limits and schedule <small>Price range, fee limits, and dates</small></summary>
        <div class="ad-protection-advanced__grid">
            <label class="ad-field">
                <span>Item price range <small>PHP</small></span>
                <span class="ad-protection-pair">
                    <input type="number" step="0.01" min="0" name="minimum_product_price" value="{{ $fieldValue('minimum_product_price') }}" placeholder="Minimum">
                    <input type="number" step="0.01" min="0" name="maximum_product_price" value="{{ $fieldValue('maximum_product_price') }}" placeholder="Maximum">
                </span>
                <small>Leave blank to include any item price.</small>
            </label>
            <label class="ad-field">
                <span>Fee limits <small>PHP</small></span>
                <span class="ad-protection-pair">
                    <input type="number" step="0.01" min="0" name="minimum_fee" value="{{ $fieldValue('minimum_fee') }}" placeholder="Minimum fee">
                    <input type="number" step="0.01" min="0" name="maximum_fee" value="{{ $fieldValue('maximum_fee') }}" placeholder="Maximum fee">
                </span>
                <small>Optional floor or cap for the calculated fee.</small>
            </label>
            <label class="ad-field">
                <span>Starts at <small>Optional</small></span>
                <input type="datetime-local" name="effective_from" value="{{ $dateValue('effective_from') }}">
            </label>
            <label class="ad-field">
                <span>Ends at <small>Optional</small></span>
                <input type="datetime-local" name="effective_until" value="{{ $dateValue('effective_until') }}">
            </label>
        </div>
    </details>
</div>
