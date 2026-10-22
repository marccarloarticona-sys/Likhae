@extends('layouts.admin.app')

@section('title', 'Product Management')
@section('subtitle', 'Catalog moderation and category management.')
@section('active', 'products')
@section('content')
    <div class="ad-products-page">
        <div class="ad-page-head">
            <div>
                <span class="ad-overline">Catalog</span>
                <h2>Products</h2>
                <p>{{ number_format($products->total()) }} canonical product records{{ $search ? ' matching "'.$search.'"' : '' }} in this view.</p>
            </div>
            <a class="ad-btn ad-btn-secondary" href="{{ route('admin.products.export', array_filter(['status' => $status, 'search' => $search, 'ai_filter' => $aiFilter])) }}">
                Export PDF
            </a>
        </div>

        @if(session('status'))
            <div class="ad-note" role="status">{{ session('status') }}</div>
        @endif

        @if($errors->any())
            <div class="ad-note is-warning" role="alert">{{ $errors->first() }}</div>
        @endif

        <section class="ad-product-stat-grid" aria-label="Product statistics">
            <x-admin.stat-card label="Monitored products" :value="number_format($productStats['total'])" detail="Active and suspended listings" icon="chart" />
            <x-admin.stat-card label="Active products" :value="number_format($productStats['active'])" detail="Currently active listings" icon="orders" tone="success" />
            <x-admin.stat-card label="Suspended products" :value="number_format($productStats['suspended'])" detail="Listings unavailable to buyers" icon="case" tone="warning" />
            <x-admin.stat-card label="Flagged for review" :value="number_format($productStats['flagged'])" detail="Products with unresolved compliance" icon="flag" tone="danger" />
        </section>

        <form class="ad-card ad-card-body ad-filter-bar" method="GET" action="{{ route('admin.products') }}" aria-label="Filter products">
            <label class="ad-field ad-product-search-field">
                <span>Search products</span>
                <div class="ad-filter-search">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                    <input name="search" type="search" value="{{ $search ?? '' }}" placeholder="Search product, seller, or store name..." aria-label="Search product, seller, or store name">
                </div>
            </label>
            <label class="ad-field">
                <span>Status</span>
                <select name="status">
                    <option value="">All statuses</option>
                    @foreach(['ACTIVE', 'SUSPENDED'] as $statusOption)
                        <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ str($statusOption)->replace('_', ' ')->headline() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="ad-field">
                <span>AI moderation</span>
                <select name="ai_filter" @disabled(! $moderationAvailable)>
                    <option value="">All moderation states</option>
                    <option value="ai_flagged" @selected($aiFilter === 'ai_flagged')>AI flagged</option>
                    <option value="needs_review" @selected($aiFilter === 'needs_review')>Needs review</option>
                    <option value="high_risk" @selected($aiFilter === 'high_risk')>High risk</option>
                    <option value="critical" @selected($aiFilter === 'critical')>Critical</option>
                    <option value="scan_failed" @selected($aiFilter === 'scan_failed')>Scan failed / deferred</option>
                    <option value="seller_appealed" @selected($aiFilter === 'seller_appealed')>Seller appealed</option>
                    <option value="suspended" @selected($aiFilter === 'suspended')>Suspended listings</option>
                    <option value="false_positive" @selected($aiFilter === 'false_positive')>False positive / cleared</option>
                </select>
            </label>
            <button class="ad-btn ad-btn-primary" type="submit">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                Search
            </button>
            @if($search)
            <a class="ad-btn ad-btn-secondary" href="{{ route('admin.products', array_filter(['status' => $status, 'ai_filter' => $aiFilter])) }}">Clear search</a>
            @elseif($status || $aiFilter)
                <a class="ad-btn ad-btn-secondary" href="{{ route('admin.products') }}">Clear filters</a>
            @endif
        </form>

        @if(! $moderationAvailable)
            <div class="ad-note is-warning" role="status">AI moderation filters and scan history will appear after scan storage is installed.</div>
        @endif

        <section class="ad-card">
            <div class="ad-table-wrap">
                @php
                    $productListQuery = request()->only(['status', 'search', 'ai_filter', 'page']);
                @endphp
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Seller</th>
                            <th>Product Category</th>
                            <th>Registered Category</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($products as $product)
                            @php
                                $productCategory = $product->category?->parent?->name
                                    ? $product->category->parent->name.' / '.$product->category->name
                                    : ($product->category?->name ?: 'Uncategorized');
                                $registeredCategory = $product->sellerProfile?->primaryCategory?->parent?->name
                                    ? $product->sellerProfile->primaryCategory->parent->name.' / '.$product->sellerProfile->primaryCategory->name
                                    : ($product->sellerProfile?->primaryCategory?->name ?: 'Not registered');
                            @endphp
                            <tr>
                                <td>
                                    <div class="ad-product-identity">
                                        <img
                                            class="ad-product-thumbnail"
                                            src="{{ $product->admin_thumbnail_url ?: asset('images/product-placeholder.svg') }}"
                                            alt="{{ $product->name }}"
                                            width="56"
                                            height="56"
                                            loading="lazy"
                                        >
                                        <div class="ad-product-identity-copy">
                                            <strong>{{ $product->name }}</strong>
                                            <small>Product #{{ $product->id }}</small>
                                            @if($product->complianceCases->isNotEmpty())
                                                <span class="ad-product-flag">Compliance review</span>
                                            @endif
                                            @if($moderationAvailable && $product->latestModerationScan)
                                                <span class="ad-product-flag">AI {{ str($product->latestModerationScan->overall_risk ?: $product->latestModerationScan->scan_status)->replace('_', ' ')->headline() }}</span>
                                                @if($product->latestModerationScan->detected_categories)
                                                    <small>{{ collect($product->latestModerationScan->detected_categories)->map(fn ($category) => str($category)->replace('_', ' ')->headline()->toString())->take(2)->implode(', ') }}</small>
                                                @endif
                                            @elseif($moderationAvailable)
                                                <span class="ad-product-flag">AI Not scanned</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>{{ $product->sellerProfile?->business_name ?: 'Seller unavailable' }}</td>
                                <td>{{ $productCategory }}</td>
                                <td>
                                    {{ $registeredCategory }}
                                    @if($product->admin_category_mismatch)
                                        <span class="ad-category-mismatch">Mismatch</span>
                                    @endif
                                </td>
                                <td>{{ number_format((int) $product->variants->sum('stock')) }}</td>
                                <td>
                                    <span class="ad-status is-{{ \Illuminate\Support\Str::slug($product->status) }}">
                                        {{ str($product->status)->replace('_', ' ')->headline() }}
                                    </span>
                                </td>
                                <td>
                                    <a class="ad-btn ad-btn-secondary" href="{{ route('admin.products.show', array_merge(['product' => $product->id], $productListQuery)) }}">View</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="ad-empty-product" colspan="7">
                                    @if($search)
                                        No products found matching your search.
                                        <a class="ad-btn ad-btn-secondary ad-btn-sm" href="{{ route('admin.products', array_filter(['status' => $status])) }}">Clear search</a>
                                    @else
                                        No products match the selected status.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="ad-card-body">
                {{ $products->links() }}
            </div>
        </section>
    </div>
@endsection
