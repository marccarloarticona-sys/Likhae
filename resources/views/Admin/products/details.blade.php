@extends('layouts.admin')

@section('title', 'Product Details')
@section('subtitle', 'Catalog inspection with authorized moderation actions.')
@section('active', 'products')
@php
    $productCategory = $product->category;
    $registeredCategory = $product->sellerProfile?->primaryCategory;
    $sellerUser = $product->sellerProfile?->user;
    $categoryLabel = static function ($category): string {
        if (! $category) {
            return 'Not provided';
        }

        return $category->parent?->name
            ? $category->parent->name.' / '.$category->name
            : $category->name;
    };
    $variants = $product->variants;
    $priceValues = $variants->pluck('price')->filter(fn ($price): bool => $price !== null)->map(fn ($price): float => (float) $price)->values();
    $discountedValues = $variants
        ->filter(fn ($variant): bool => (float) $variant->final_price < (float) $variant->price)
        ->map(fn ($variant): float => (float) $variant->final_price)
        ->filter(fn (float $price): bool => $price > 0)
        ->values();
    $formatRange = static function ($values): string {
        if ($values->isEmpty()) {
            return 'Not provided';
        }

        $min = (float) $values->min();
        $max = (float) $values->max();

        return $min === $max
            ? 'PHP '.number_format($min, 2)
            : 'PHP '.number_format($min, 2).' – PHP '.number_format($max, 2);
    };
    $gallery = $imageGallery;
    $primaryImage = $gallery->firstWhere('is_primary', true) ?: $gallery->first();
    $primaryImageUrl = $primaryImage['url'] ?? asset('images/product-placeholder.svg');
    $hasImages = $gallery->isNotEmpty();
    $skus = $variants->pluck('sku')->filter()->unique()->implode(', ');
    $complianceCases = $product->complianceCases->sortByDesc(fn ($case) => $case->created_at?->timestamp ?? 0)->values();
    $moderationScans = $moderationScans ?? collect();
    $backQuery = collect($backQuery)->filter(fn ($value): bool => filled($value))->all();
@endphp

@section('content')
    <div class="ad-product-details-page">
        <div class="ad-page-head">
            <div>
                <span class="ad-overline">Product #{{ $product->id }}</span>
                <h2>{{ $product->name }}</h2>
                <p>Catalog inspection with authorized moderation actions.</p>
            </div>
            <a class="ad-btn ad-btn-secondary" href="{{ route('admin.products', $backQuery) }}">Back to Products</a>
        </div>

        @if(session('status'))
            <div class="ad-note" role="status">{{ session('status') }}</div>
        @endif

        @if($errors->any())
            <div class="ad-note is-warning" role="alert">{{ $errors->first() }}</div>
        @endif

        <section class="ad-card ad-card-body ad-product-moderation-grid" aria-label="Product moderation">
            <div>
                <span class="ad-overline">Admin action</span>
                <h2>Moderate product</h2>
                <p class="ad-product-moderation-note">Suspend or reinstate this listing after reviewing the product and its compliance history.</p>
                @if(! in_array($product->status, ['ACTIVE', 'SUSPENDED'], true))
                    <p class="ad-product-moderation-note">This {{ str($product->status)->lower() }} listing remains Seller-managed.</p>
                @endif
            </div>
            @if(in_array($product->status, ['ACTIVE', 'SUSPENDED'], true))
                <form class="ad-product-moderation-form" method="POST" action="{{ route('admin.products.moderate', ['product' => $product->id]) }}" data-confirm-title="Apply moderation change?" data-confirm-message="This will update the product's marketplace availability." data-confirm-label="Apply change">
                    @csrf
                    <label class="ad-field">
                        <span>Moderation status</span>
                        <select name="status" aria-label="Moderation status for {{ $product->name }}">
                            @foreach(['ACTIVE', 'SUSPENDED'] as $moderationStatus)
                                <option value="{{ $moderationStatus }}" @selected($product->status === $moderationStatus)>{{ str($moderationStatus)->replace('_', ' ')->headline() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="ad-field">
                        <span>Reason</span>
                        <input name="reason" value="{{ old('reason') }}" maxlength="1000" placeholder="Required for suspension or reinstatement" aria-label="Moderation reason for {{ $product->name }}">
                    </label>
                    <div class="ad-product-moderation-actions">
                        <button class="ad-btn ad-btn-primary" type="submit">Save moderation</button>
                    </div>
                </form>
            @endif
        </section>

        @if($product->status === 'ACTIVE')
            <section class="ad-card ad-card-body">
                <div class="ad-card-head" style="padding:0 0 12px;border-bottom:1px solid var(--ad-border);">
                    <div><span class="ad-overline">Seller compliance</span><h2>Warn Seller</h2><p>Record the correction required while keeping the product active.</p></div>
                </div>
                <form class="ad-product-warning-form" method="POST" action="{{ route('admin.products.warn', ['product' => $product->id]) }}" data-confirm-title="Send warning to seller?" data-confirm-message="Send this warning to the seller about the product listing?" data-confirm-label="Send warning" style="padding-top:14px;">
                    @csrf
                    <label class="ad-field">
                        <span>Warning category</span>
                        <select name="category" required>
                            <option value="">Select a category</option>
                            @foreach($warningCategories as $warningKey => $warningLabel)
                                <option value="{{ $warningKey }}" @selected(old('category') === $warningKey)>{{ $warningLabel }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="ad-field">
                        <span>Detailed warning reason</span>
                        <textarea name="reason" required minlength="10" maxlength="5000" placeholder="Explain the confirmed requirement or policy concern.">{{ old('reason') }}</textarea>
                    </label>
                    <label class="ad-field">
                        <span>Required corrective action</span>
                        <textarea name="corrective_action" required minlength="5" maxlength="5000" placeholder="Tell the Seller exactly what must be corrected.">{{ old('corrective_action') }}</textarea>
                    </label>
                    <label class="ad-field">
                        <span>Supporting evidence or reference (optional)</span>
                        <textarea name="evidence" maxlength="5000" placeholder="Add a case, policy, or evidence reference when available.">{{ old('evidence') }}</textarea>
                    </label>
                    <div class="ad-product-moderation-actions">
                        <button class="ad-btn ad-btn-warning-soft" type="submit">Confirm Warning</button>
                    </div>
                </form>
            </section>
        @elseif($product->status === 'SUSPENDED')
            <section class="ad-card ad-card-body">
                <p class="ad-product-moderation-note">This product is suspended and unavailable to buyers. Resolve the compliance case before reinstating it.</p>
            </section>
        @endif

        <section class="ad-card ad-card-body" aria-labelledby="ai-moderation-title">
            <div class="ad-card-head" style="padding:0 0 12px;border-bottom:1px solid var(--ad-border);">
                <div><span class="ad-overline">Cloudflare Workers AI</span><h2 id="ai-moderation-title">Product AI moderation</h2><p>AI findings are review signals only. A human Admin decision is required for a final policy outcome.</p></div>
                <span class="ad-status {{ $moderationHealth['status'] === 'HEALTHY' ? 'is-clear' : ($moderationHealth['status'] === 'DEGRADED' ? 'is-pending' : 'is-unknown') }}">{{ $moderationHealth['status'] }}</span>
            </div>
            <div style="padding-top:14px;">
                @if(! $moderationHealth['table_ready'])
                    <p class="ad-product-moderation-note">Scan storage is not available yet. Apply the product moderation migration before using scan history and review actions.</p>
                @elseif(! $moderationHealth['enabled'])
                    <p class="ad-product-moderation-note">Cloudflare Workers AI moderation is disabled or its server credentials are not configured. No seller listing data is being sent to a model.</p>
                @endif

                @if($moderationHealth['enabled'] && $moderationHealth['table_ready'] && in_array($product->status, ['ACTIVE', 'SUSPENDED'], true))
                    <form method="POST" action="{{ route('admin.products.ai.rescan', $product) }}" style="margin:0 0 18px;">
                        @csrf
                        <button class="ad-btn ad-btn-secondary" type="submit">Queue manual rescan</button>
                    </form>
                @endif

                @if($moderationScans->isEmpty())
                    <p class="ad-product-moderation-note">No scan records are available for this product.</p>
                @else
                    <div class="ad-compliance-list">
                        @foreach($moderationScans as $scan)
                            @php
                                $categoryLabels = collect($scan->detected_categories ?? [])->map(fn ($category) => config('product_moderation.categories.'.$category, config('product_moderation.image_categories.'.$category, str($category)->replace('_', ' ')->headline())))->implode(', ');
                                $detections = collect($scan->object_detections ?? [])->flatMap(fn ($result) => (array) ($result['detections'] ?? []))->pluck('label')->filter()->unique()->implode(', ');
                            @endphp
                            <article class="ad-compliance-case" style="margin:12px 0;">
                                <div class="ad-compliance-case-head">
                                    <div><strong>Scan #{{ $scan->id }} · {{ str($scan->overall_risk ?: $scan->scan_status)->replace('_', ' ')->headline() }}</strong><p>{{ $scan->scanned_at?->format('M d, Y H:i') ?: 'Not completed' }} · {{ $scan->is_automatic ? 'Automatic' : 'Admin requested' }}</p></div>
                                    @if($scan->held_at)<span class="ad-status is-pending">AI listing hold</span>@endif
                                </div>
                                <dl class="ad-product-detail-meta" style="margin:12px 0;">
                                    <div><dt>Overall risk</dt><dd>{{ $scan->overall_risk ?: 'Unknown' }}</dd></div>
                                    <div><dt>Human decision</dt><dd>{{ str($scan->decision_status)->replace('_', ' ')->headline() }}</dd></div>
                                    <div><dt>Appeal status</dt><dd>{{ str($scan->appeal_status)->replace('_', ' ')->headline() }}</dd></div>
                                    <div><dt>Models</dt><dd>{{ collect($scan->model_versions ?? [])->filter()->implode(', ') ?: 'Not recorded' }}</dd></div>
                                </dl>
                                @if($categoryLabels)<p><strong>Possible categories:</strong> {{ $categoryLabels }}</p>@endif
                                @if($detections)<p><strong>Object detector labels:</strong> {{ $detections }}</p>@endif
                                @foreach(['Text' => $scan->text_findings, 'Image' => $scan->image_findings] as $signalName => $findings)
                                    @if($findings && ! empty($findings['category_probabilities']))
                                        @php $topSignal = collect($findings['category_probabilities'])->sortDesc()->first(); $topCategory = collect($findings['category_probabilities'])->sortDesc()->keys()->first(); @endphp
                                        <p><strong>{{ $signalName }} signal:</strong> {{ config('product_moderation.categories.'.$topCategory, config('product_moderation.image_categories.'.$topCategory, str($topCategory)->replace('_', ' ')->headline())) }} ({{ number_format((float) $topSignal * 100, 1) }}% model score)</p>
                                    @endif
                                @endforeach
                                @if($scan->evidence_summary)<p>{{ $scan->evidence_summary }}</p>@endif
                                @if($scan->failure_code)<p class="ad-product-moderation-note">Scan status: {{ $scan->failure_code }}</p>@endif
                                @if($scan->decision_reason)<p><strong>Admin decision note:</strong> {!! nl2br(e($scan->decision_reason)) !!}</p>@endif

                                @if($scan->appeal_status === 'APPEAL_SUBMITTED')
                                    <div class="ad-card ad-card-body" style="margin:12px 0;">
                                        <h3>Seller appeal</h3>
                                        <p><strong>Submitted:</strong> {{ $scan->appeal_submitted_at?->format('M d, Y H:i') ?: 'Date unavailable' }}</p>
                                        <p>{!! nl2br(e($scan->appeal_explanation)) !!}</p>
                                        @if($scan->appeal_evidence_path)
                                            <p><a class="ad-btn ad-btn-secondary ad-btn-sm" href="{{ route('admin.products.ai.appeals.evidence', [$product, $scan]) }}">Download private evidence</a></p>
                                        @endif
                                        <form method="POST" action="{{ route('admin.products.ai.appeals.review', [$product, $scan]) }}">
                                            @csrf
                                            <label class="ad-field"><span>Appeal decision</span><select name="decision" required><option value="APPROVED">Approve appeal / clear AI decision</option><option value="DENIED">Deny appeal / keep current decision</option><option value="REQUEST_CLARIFICATION">Request clarification</option></select></label>
                                            <label class="ad-field"><span>Reason sent to Seller</span><textarea name="reason" required minlength="10" maxlength="2000" placeholder="Explain the evidence reviewed and the outcome.">{{ old('reason') }}</textarea></label>
                                            <button class="ad-btn ad-btn-primary" type="submit">Record appeal decision</button>
                                        </form>
                                    </div>
                                @elseif($scan->appeal_status === 'APPEAL_UNDER_REVIEW')
                                    <p>Admin requested clarification. Awaiting Seller response.</p>
                                @elseif($scan->decision_status === 'PENDING' && $scan->scan_status === 'COMPLETE')
                                    <form method="POST" action="{{ route('admin.products.ai.review', [$product, $scan]) }}" style="margin-top:14px;">
                                        @csrf
                                        <label class="ad-field"><span>Human review outcome</span><select name="decision" required><option value="FALSE_POSITIVE">False positive / clear AI flag</option><option value="REQUEST_SELLER_EDIT">Request Seller edit</option><option value="CONFIRMED_POLICY_VIOLATION">Confirm policy violation</option></select></label>
                                        <label class="ad-field"><span>Review reason</span><textarea name="reason" required minlength="10" maxlength="2000" placeholder="Record the evidence and policy basis for this decision.">{{ old('reason') }}</textarea></label>
                                        <button class="ad-btn ad-btn-primary" type="submit">Save human decision</button>
                                    </form>
                                @elseif($scan->appeal_decision_reason)
                                    <p><strong>Appeal decision note:</strong> {!! nl2br(e($scan->appeal_decision_reason)) !!}</p>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        <div class="ad-product-detail-grid">
            <section class="ad-card ad-card-body ad-product-gallery" aria-label="Product images">
                <div class="ad-product-gallery-main">
                    <a href="{{ $primaryImageUrl }}" target="_blank" rel="noopener" aria-label="Open primary product image in a new tab">
                        <img src="{{ $primaryImageUrl }}" alt="{{ $primaryImage['alt'] ?? $product->name }}" loading="eager">
                    </a>
                    <span class="ad-product-gallery-hint">Open image</span>
                    @unless($hasImages)
                        <span class="ad-product-gallery-placeholder">No product image provided. Showing the LIKHAE placeholder.</span>
                    @endunless
                </div>
                @if($gallery->isNotEmpty())
                    <div class="ad-product-gallery-thumbs" aria-label="Additional product images">
                        @foreach($gallery as $image)
                            <a class="ad-product-gallery-thumb {{ $image['is_primary'] ? 'is-primary' : '' }}" href="{{ $image['url'] }}" target="_blank" rel="noopener" aria-label="Open {{ $image['alt'] }} in a new tab">
                                <img src="{{ $image['url'] }}" alt="{{ $image['alt'] }}" loading="lazy">
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="ad-card">
                <header class="ad-card-head">
                    <div>
                        <span class="ad-overline">Product information</span>
                        <h2>{{ $product->name }}</h2>
                    </div>
                    <span class="ad-status is-{{ \Illuminate\Support\Str::slug($product->status) }}">{{ str($product->status)->replace('_', ' ')->headline() }}</span>
                </header>
                <div class="ad-card-body">
                    <dl class="ad-product-detail-meta">
                        <div><dt>Product ID</dt><dd>#{{ $product->id }}</dd></div>
                        <div><dt>SKU</dt><dd>{{ $skus ?: 'Not provided' }}</dd></div>
                        <div><dt>Product category</dt><dd>{{ $categoryLabel($productCategory) }}</dd></div>
                        <div><dt>Price</dt><dd>{{ $formatRange($priceValues) }}</dd></div>
                        <div><dt>Discounted price</dt><dd>{{ $discountedValues->isNotEmpty() ? $formatRange($discountedValues) : 'Not provided' }}</dd></div>
                        <div><dt>Available stock</dt><dd>{{ $variants->isNotEmpty() ? number_format((int) $variants->sum('stock')) : 'Not provided' }}</dd></div>
                        <div><dt>Date created</dt><dd>{{ $product->created_at?->format('M d, Y H:i') ?? 'Not provided' }}</dd></div>
                        <div><dt>Last updated</dt><dd>{{ $product->updated_at?->format('M d, Y H:i') ?? 'Not provided' }}</dd></div>
                    </dl>
                </div>
            </section>
        </div>

        <section class="ad-card">
            <header class="ad-card-head"><div><h2>Description</h2><p>Seller-provided product description.</p></div></header>
            <div class="ad-card-body ad-product-description">{{ $product->description ?: 'Not provided' }}</div>
        </section>

        <section class="ad-card">
            <header class="ad-card-head"><div><h2>Available variations</h2><p>Read-only variant, pricing, and stock information.</p></div></header>
            <div class="ad-table-wrap">
                <table class="ad-table">
                    <thead><tr><th>Variation</th><th>SKU</th><th>Price</th><th>Discounted price</th><th>Stock</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse($variants as $variant)
                            @php
                                $variation = $variant->optionValues->map(fn ($value): string => ($value->option?->name ? $value->option->name.': ' : '').$value->value)->filter()->implode(' · ');
                                $hasDiscount = (float) $variant->final_price < (float) $variant->price;
                            @endphp
                            <tr>
                                <td><strong>{{ $variation ?: 'Default variation' }}</strong></td>
                                <td>{{ $variant->sku ?: 'Not provided' }}</td>
                                <td>PHP {{ number_format((float) $variant->price, 2) }}</td>
                                <td>{{ $hasDiscount ? 'PHP '.number_format((float) $variant->final_price, 2) : 'Not provided' }}</td>
                                <td>{{ number_format((int) $variant->stock) }}</td>
                                <td><span class="ad-status {{ $variant->is_active ? 'is-active' : 'is-suspended' }}">{{ $variant->is_active ? 'Active' : 'Inactive' }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="ad-product-table-muted">No variations recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="ad-card">
            <header class="ad-card-head"><div><h2>Seller information</h2><p>Seller account and category compliance context.</p></div></header>
            <div class="ad-card-body">
                <dl class="ad-product-seller-grid">
                    <div><dt class="ad-product-detail-label">Seller / shop name</dt><dd>{{ $product->sellerProfile?->business_name ?: 'Not provided' }}</dd></div>
                    <div><dt class="ad-product-detail-label">Seller account reference</dt><dd>@if($sellerUser)<a class="ad-link" href="{{ route('admin.users.show', ['user' => $sellerUser->id]) }}">USR-{{ $sellerUser->id }}</a>@else Not provided @endif</dd></div>
                    <div><dt class="ad-product-detail-label">Registered business category</dt><dd>{{ $categoryLabel($registeredCategory) }}</dd></div>
                    <div><dt class="ad-product-detail-label">Product category</dt><dd>{{ $categoryLabel($productCategory) }}</dd></div>
                    <div><dt class="ad-product-detail-label">Category compliance</dt><dd><span class="ad-product-compliance-result {{ ! $registeredCategory || ! $productCategory ? 'is-muted' : ($product->admin_category_mismatch ? 'is-warning' : '') }}">{{ ! $registeredCategory || ! $productCategory ? 'Category data unavailable' : ($product->admin_category_mismatch ? 'Not compliant' : 'Compliant') }}</span></dd></div>
                </dl>
                @if($registeredCategory && $productCategory && ! $product->admin_category_mismatch)
                    <p class="ad-product-table-muted" style="margin:18px 0 0;font-size:13px;">The product uses the Seller's registered category or an active permitted subcategory.</p>
                @elseif($product->admin_category_mismatch)
                    <p class="ad-product-table-muted" style="margin:18px 0 0;font-size:13px;">The product category is outside the Seller's registered category and active permitted subcategories.</p>
                @endif
            </div>
        </section>

        <section class="ad-card" id="compliance">
            <header class="ad-card-head">
                <div><h2>Seller compliance</h2><p>Existing product moderation warnings, cases, and actions.</p></div>
                @if($complianceCases->isNotEmpty())<a class="ad-btn ad-btn-secondary ad-btn-sm" href="{{ route('admin.compliance') }}">Open Compliance</a>@endif
            </header>
            <div class="ad-card-body">
                <p><strong>Current moderation status:</strong> <span class="ad-status is-{{ \Illuminate\Support\Str::slug($product->status) }}">{{ str($product->status)->replace('_', ' ')->headline() }}</span></p>
                @if($complianceCases->isEmpty())
                    <p class="ad-product-table-muted">No recorded compliance violations.</p>
                @else
                    <div class="ad-compliance-list">
                        @foreach($complianceCases as $case)
                            <article class="ad-compliance-case" id="compliance-case-{{ $case->id }}">
                                <div class="ad-compliance-case-head">
                                    <div><h3>{{ $case->case_number }}</h3><p>{{ str($case->violation_type)->replace('_', ' ')->headline() }} - Opened {{ $case->opened_at?->format('M d, Y H:i') ?? 'Not provided' }} by {{ $case->openedByAdmin?->name ?: 'System' }}</p></div>
                                    <span class="ad-status is-{{ \Illuminate\Support\Str::slug($case->status) }}">{{ str($case->status)->replace('_', ' ')->headline() }}</span>
                                </div>
                                <p>{{ $case->description ?: 'No violation reason recorded.' }}</p>
                                @if($case->actions->isNotEmpty())
                                    <div class="ad-compliance-actions">
                                        <strong>Moderation history</strong>
                                        @foreach($case->actions as $action)
                                            <span>{{ $action->performed_at?->format('M d, Y H:i') ?? 'Not provided' }} · {{ str($action->action_type)->replace('_', ' ')->headline() }} · {{ $action->performedBy?->name ?: 'System' }}{{ $action->reason ? ' — '.$action->reason : '' }}</span>
                                        @endforeach
                                    </div>
                                @endif
                                @if($case->responses->isNotEmpty())
                                    <div class="ad-compliance-actions">
                                        <strong>Seller corrections and responses</strong>
                                        @foreach($case->responses as $response)
                                            <div style="padding:8px 0;border-top:1px solid var(--ad-border);">
                                                <span><strong>Submitted {{ $response->submitted_at?->format('M d, Y H:i') ?? 'Date unavailable' }}</strong></span>
                                                <p><strong>Explanation:</strong> {!! nl2br(e($response->explanation)) !!}</p>
                                                <p><strong>Corrective action:</strong> {!! nl2br(e($response->corrective_action)) !!}</p>
                                                @if($response->evidence_path)
                                                    <a class="ad-link" href="{{ route('admin.compliance.responses.evidence', [$case, $response]) }}">Download Seller evidence</a>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    </div>
@endsection
