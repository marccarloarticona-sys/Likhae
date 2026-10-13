@extends('layouts.admin')

@section('title', 'Category Management')
@section('subtitle', 'Organize and manage marketplace categories and subcategories.')
@section('active', 'categories')

@php
    $categoryStats = $categoryStats ?? ['main' => 0, 'subcategories' => 0, 'inactive' => 0, 'archived' => 0];
    $categories = $categories ?? collect();
    $tab = $tab ?? 'all';
    $status = $status ?? null;
    $search = $search ?? '';
    $expanded = (bool) ($expanded ?? false);
    $archiveSupported = (bool) ($archiveSupported ?? false);
@endphp

@section('content')
<div class="ad-page ad-cat-page">
    <div class="ad-page-head">
        <div>
            <span class="ad-overline">Catalog organization</span>
            <h2>Category Management</h2>
            <p>Organize and manage marketplace categories and subcategories.</p>
        </div>
        <button type="button" class="ad-btn ad-btn-primary" data-modal-open="add-main-category">+ Add Main Category</button>
    </div>

    @if(session('status'))
        <div class="ad-note" role="status">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="ad-note is-warning" role="alert">{{ $errors->first() }}</div>
    @endif

    @if(! $archiveSupported)
        <div class="ad-note is-warning" role="note">
            Archive and Restore are unavailable because the current categories table has no reversible archive storage. Deactivated categories remain managed in the All Categories tab. No records were treated as archived.
        </div>
    @endif

    <section class="ad-stat-grid ad-category-stat-grid" aria-label="Category statistics">
        <x-admin.stat-card label="Main categories" :value="number_format($categoryStats['main'])" detail="Non-archived parent categories" icon="chart" />
        <x-admin.stat-card label="Subcategories" :value="number_format($categoryStats['subcategories'])" detail="Non-archived child categories" icon="orders" />
        <x-admin.stat-card label="Deactivated categories" :value="number_format($categoryStats['inactive'])" detail="Main categories and subcategories" icon="case" tone="warning" />
        <x-admin.stat-card label="Archived categories" :value="number_format($categoryStats['archived'])" detail="Reversibly archived records" icon="flag" tone="neutral" />
    </section>

    <nav class="ad-tabs ad-category-tabs" aria-label="Category views">
        <a href="{{ route('admin.categories', array_merge(request()->except('page'), ['tab' => 'all'])) }}" class="{{ $tab === 'all' ? 'is-active' : '' }}" @if($tab === 'all') aria-current="page" @endif>All Categories</a>
        <a href="{{ route('admin.categories', array_merge(request()->except('page'), ['tab' => 'archived'])) }}" class="{{ $tab === 'archived' ? 'is-active' : '' }}" @if($tab === 'archived') aria-current="page" @endif>Archived Categories</a>
    </nav>

    <form class="ad-card ad-filter-bar ad-category-filters" method="GET" action="{{ route('admin.categories') }}" aria-label="Filter categories">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <label class="ad-field ad-category-search-field">
            <span>Search</span>
            <div class="ad-filter-search">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                <input name="search" type="search" value="{{ $search }}" placeholder="Search category or subcategory..." aria-label="Search category or subcategory">
            </div>
        </label>
        @if($tab === 'all')
            <label class="ad-field">
                <span>Status</span>
                <select name="status" aria-label="Category status">
                    <option value="">All</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                </select>
            </label>
        @endif
        <button class="ad-btn ad-btn-primary" type="submit">Search</button>
        @if($search || $status)
            <a class="ad-btn ad-btn-secondary" href="{{ route('admin.categories', ['tab' => $tab]) }}">Clear filters</a>
        @endif
    </form>

    @if($tab === 'archived' && ! $archiveSupported)
        <section class="ad-card ad-category-empty" aria-live="polite">
            <div class="ad-category-empty-icon" aria-hidden="true">◌</div>
            <h3>{{ $archiveSupported ? 'No archived categories.' : 'Archived Categories is not available yet.' }}</h3>
            <p>{{ $archiveSupported ? 'Archived category records will appear here while preserving their parent relationships.' : 'A reversible archive column or equivalent storage must be approved before categories can be archived or restored safely.' }}</p>
        </section>
    @else
        <section class="ad-card ad-category-tree-card" aria-label="Category tree">
            <header class="ad-card-head">
                <div>
                    <span class="ad-overline">Category tree</span>
                    <h2>{{ $tab === 'archived' ? 'Archived Categories' : 'Categories' }}</h2>
                    <p>{{ number_format($categories->total()) }} main category groups{{ $search ? ' matching “'.$search.'”' : '' }}.</p>
                </div>
            </header>

            @if($categories->count())
                <div class="ad-category-tree">
                    @foreach($categories as $mainCategory)
                        @php
                            $children = $mainCategory->children;
                            $childCount = (int) $mainCategory->children_count;
                            $impact = collect([
                                $childCount ? $childCount.' subcategories' : null,
                                $mainCategory->products_count ? $mainCategory->products_count.' products' : null,
                                $mainCategory->seller_profiles_count ? $mainCategory->seller_profiles_count.' Seller registrations' : null,
                                    $mainCategory->seller_applications_count ? $mainCategory->seller_applications_count.' Seller applications' : null,
                            ])->filter()->implode(', ');
                        @endphp
                        <article class="ad-category-group" data-category-group>
                            <div class="ad-category-row ad-category-row-main">
                                <button type="button" class="ad-category-chevron {{ $expanded ? '' : 'is-collapsed' }}" data-category-toggle aria-expanded="{{ $expanded ? 'true' : 'false' }}" aria-controls="category-children-{{ $mainCategory->id }}" title="{{ $expanded ? 'Collapse' : 'Expand' }} {{ $mainCategory->name }} subcategories">
                                    <span aria-hidden="true">⌄</span><span class="sr-only">Toggle {{ $mainCategory->name }} subcategories</span>
                                </button>
                                <div class="ad-category-name">
                                    <strong>{{ $mainCategory->name }}</strong>
                                    <small>{{ $childCount }} {{ $childCount === 1 ? 'subcategory' : 'subcategories' }}@if($tab === 'archived' && $mainCategory->archived_at) · archived {{ $mainCategory->archived_at->format('M j, Y') }}@endif</small>
                                </div>
                                <span class="ad-status {{ $tab === 'archived' ? ($mainCategory->archived_at !== null ? 'is-archived' : 'is-active') : ($mainCategory->is_active ? 'is-active' : 'is-inactive') }}">{{ $tab === 'archived' ? ($mainCategory->archived_at !== null ? 'Archived' : 'Active parent') : ($mainCategory->is_active ? 'Active' : 'Inactive') }}</span>
                                <div class="ad-category-actions">
                                    @if($tab === 'archived')
                                        @if($mainCategory->archived_at !== null)
                                            <form method="POST" action="{{ route('admin.categories.restore', $mainCategory) }}">
                                                @csrf @method('PATCH')
                                                <button type="submit" class="ad-btn ad-btn-primary ad-btn-xs" data-confirm-message="Restore {{ $mainCategory->name }}? Its previous active/inactive state and parent relationships will be preserved.">Restore</button>
                                            </form>
                                        @endif
                                    @else
                                        <button type="button" class="ad-btn ad-btn-secondary ad-btn-xs" data-add-subcategory data-parent-id="{{ $mainCategory->id }}" data-parent-name="{{ $mainCategory->name }}">+ Add Subcategory</button>
                                        <form method="POST" action="{{ route('admin.categories.status', $mainCategory) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="is_active" value="{{ $mainCategory->is_active ? 0 : 1 }}">
                                            <button type="submit" class="ad-btn {{ $mainCategory->is_active ? 'ad-btn-warning-soft' : 'ad-btn-primary' }} ad-btn-xs" @if($mainCategory->is_active) data-impact-confirm="{{ $impact ?: 'No linked records found' }}" @endif>{{ $mainCategory->is_active ? 'Deactivate' : 'Activate' }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.categories.archive', $mainCategory) }}">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="ad-btn ad-btn-warning-soft ad-btn-xs" data-confirm-message="Archive {{ $mainCategory->name }}? Current status: {{ $mainCategory->is_active ? 'Active' : 'Inactive' }}. Linked records checked: {{ $impact ?: 'none' }}. Existing category assignments remain preserved, and the record will move to Archived Categories.">Archive</button>
                                        </form>
                                    @endif
                                </div>
                            </div>

                            <div id="category-children-{{ $mainCategory->id }}" class="ad-category-children" data-category-children @if(! $expanded) hidden @endif>
                                @forelse($children as $subcategory)
                                    @php
                                        $childImpact = collect([
                                            $subcategory->products_count ? $subcategory->products_count.' products' : null,
                                            $subcategory->seller_profiles_count ? $subcategory->seller_profiles_count.' Seller registrations' : null,
                                            $subcategory->seller_applications_count ? $subcategory->seller_applications_count.' Seller applications' : null,
                                        ])->filter()->implode(', ');
                                    @endphp
                                    <div class="ad-category-row ad-category-row-child {{ $subcategory->is_active ? '' : 'is-inactive' }}">
                                        <span class="ad-category-branch" aria-hidden="true">└</span>
                                        <div class="ad-category-name">
                                            <strong>{{ $subcategory->name }}</strong>
                                            @if($tab === 'archived' && $subcategory->archived_at)
                                                <small>Archived {{ $subcategory->archived_at->format('M j, Y') }} under {{ $mainCategory->name }}</small>
                                            @endif
                                            <small>{{ $subcategory->products_count }} {{ $subcategory->products_count === 1 ? 'product' : 'products' }} · under {{ $mainCategory->name }}</small>
                                        </div>
                                        <span class="ad-status {{ $tab === 'archived' ? 'is-archived' : ($subcategory->is_active ? 'is-active' : 'is-inactive') }}">{{ $tab === 'archived' ? 'Archived' : ($subcategory->is_active ? 'Active' : 'Inactive') }}</span>
                                        <div class="ad-category-actions">
                                            @if($tab === 'archived')
                                                @if($mainCategory->archived_at !== null)
                                                    <button type="button" class="ad-btn ad-btn-secondary ad-btn-xs" disabled title="Restore the parent category first">Restore parent first</button>
                                                @else
                                                    <form method="POST" action="{{ route('admin.subcategories.restore', $subcategory) }}">
                                                        @csrf @method('PATCH')
                                                        <button type="submit" class="ad-btn ad-btn-primary ad-btn-xs" data-confirm-message="Restore {{ $subcategory->name }}? Its previous active/inactive state and parent relationship will be preserved.">Restore</button>
                                                    </form>
                                                @endif
                                            @else
                                                <form method="POST" action="{{ route('admin.subcategories.status', $subcategory) }}">
                                                    @csrf @method('PATCH')
                                                    <input type="hidden" name="is_active" value="{{ $subcategory->is_active ? 0 : 1 }}">
                                                    <button type="submit" class="ad-btn {{ $subcategory->is_active ? 'ad-btn-warning-soft' : 'ad-btn-primary' }} ad-btn-xs" @if($subcategory->is_active) data-impact-confirm="{{ $subcategory->products_count ? $subcategory->products_count.' products' : 'No linked products found' }}" @endif>{{ $subcategory->is_active ? 'Deactivate' : 'Activate' }}</button>
                                                </form>
                                                <form method="POST" action="{{ route('admin.subcategories.archive', $subcategory) }}">
                                                    @csrf @method('PATCH')
                                                    <button type="submit" class="ad-btn ad-btn-warning-soft ad-btn-xs" data-confirm-message="Archive {{ $subcategory->name }}? Current status: {{ $subcategory->is_active ? 'Active' : 'Inactive' }}. Linked records checked: {{ $childImpact ?: 'none' }}. Existing category assignments remain preserved, and the record will move to Archived Categories.">Archive</button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <p class="ad-category-no-children">No subcategories under this main category.</p>
                                @endforelse
                                @if($tab !== 'archived')
                                    <button type="button" class="ad-category-add-child" data-add-subcategory data-parent-id="{{ $mainCategory->id }}" data-parent-name="{{ $mainCategory->name }}">+ Add Subcategory</button>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
                <div class="ad-card-body ad-category-pagination">{{ $categories->links() }}</div>
            @else
                <div class="ad-category-empty">
                    <div class="ad-category-empty-icon" aria-hidden="true">⌕</div>
                    <h3>{{ $tab === 'archived' ? 'No archived categories.' : 'No categories match the selected filters.' }}</h3>
                    <p>{{ $tab === 'archived' ? 'Archived categories will appear here while preserving their existing parent relationships.' : 'Try a different category name or status filter.' }}</p>
                </div>
            @endif
        </section>
    @endif
</div>

<div class="ad-modal ad-category-modal" id="add-main-category-modal" aria-hidden="true">
    <div class="ad-modal-content" role="dialog" aria-modal="true" aria-labelledby="add-main-category-title">
        <h3 id="add-main-category-title">Add Main Category</h3>
        <form method="POST" action="{{ route('admin.categories.store') }}">
            @csrf
            <label class="ad-field"><span>Category name</span><input name="name" type="text" maxlength="150" required></label>
            <label class="ad-field"><span>Description</span><textarea name="description" rows="4"></textarea></label>
            <div class="ad-btn-bar"><button type="button" class="ad-btn ad-btn-secondary" data-modal-close>Cancel</button><button type="submit" class="ad-btn ad-btn-primary">Add Main Category</button></div>
        </form>
    </div>
</div>

<div class="ad-modal ad-category-modal" id="add-subcategory-modal" aria-hidden="true">
    <div class="ad-modal-content" role="dialog" aria-modal="true" aria-labelledby="add-subcategory-title">
        <h3 id="add-subcategory-title">Add Subcategory</h3>
        <p class="ad-category-modal-context">Parent: <strong data-parent-label></strong></p>
        <form method="POST" action="{{ route('admin.subcategories.store') }}">
            @csrf
            <input type="hidden" name="category_id" data-parent-id-input>
            <label class="ad-field"><span>Subcategory name</span><input name="name" type="text" maxlength="150" required></label>
            <label class="ad-field"><span>Description</span><textarea name="description" rows="4"></textarea></label>
            <div class="ad-btn-bar"><button type="button" class="ad-btn ad-btn-secondary" data-modal-close>Cancel</button><button type="submit" class="ad-btn ad-btn-primary">Add Subcategory</button></div>
        </form>
    </div>
</div>

<script>
(() => {
    const openModal = (id) => {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        modal.querySelector('input, textarea, select')?.focus();
    };
    const closeModal = (modal) => {
        if (!modal) return;
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
    };

    document.querySelectorAll('[data-category-toggle]').forEach((toggle) => {
        const initialTarget = document.getElementById(toggle.getAttribute('aria-controls'));
        const defaultExpanded = @json($expanded);
        toggle.setAttribute('aria-expanded', String(defaultExpanded));
        toggle.classList.toggle('is-collapsed', !defaultExpanded);
        if (defaultExpanded) {
            initialTarget?.removeAttribute('hidden');
        } else {
            initialTarget?.setAttribute('hidden', 'hidden');
        }
        toggle.addEventListener('click', () => {
            const target = document.getElementById(toggle.getAttribute('aria-controls'));
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', String(!expanded));
            target?.toggleAttribute('hidden', expanded);
            toggle.classList.toggle('is-collapsed', expanded);
            const categoryName = toggle.closest('[data-category-group]')?.querySelector('.ad-category-name strong')?.textContent?.trim() || 'category';
            toggle.title = `${expanded ? 'Expand' : 'Collapse'} ${categoryName} subcategories`;
            toggle.setAttribute('aria-label', toggle.title);
        });
    });

    document.querySelectorAll('[data-modal-open]').forEach((button) => button.addEventListener('click', () => openModal(button.dataset.modalOpen + '-modal')));
    document.querySelectorAll('[data-modal-close]').forEach((button) => button.addEventListener('click', () => closeModal(button.closest('.ad-modal'))));
    document.querySelectorAll('.ad-category-modal').forEach((modal) => modal.addEventListener('click', (event) => { if (event.target === modal) closeModal(modal); }));

    document.querySelectorAll('[data-add-subcategory]').forEach((button) => button.addEventListener('click', () => {
        const modal = document.getElementById('add-subcategory-modal');
        modal.querySelector('[data-parent-id-input]').value = button.dataset.parentId;
        modal.querySelector('[data-parent-label]').textContent = button.dataset.parentName;
        openModal('add-subcategory-modal');
    }));

    document.querySelectorAll('[data-impact-confirm], [data-confirm-message]').forEach((button) => button.addEventListener('click', (event) => {
        const message = button.dataset.confirmMessage || `Deactivate this category? Existing records will remain assigned. Current impact: ${button.dataset.impactConfirm}.`;
        if (! window.confirm(message)) event.preventDefault();
    }));
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') document.querySelectorAll('.ad-category-modal.show').forEach(closeModal); });
})();
</script>
@endsection
