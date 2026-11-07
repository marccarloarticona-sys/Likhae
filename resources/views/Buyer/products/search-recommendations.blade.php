<section class="lk-search-recommendations" data-catalog-recommendations
    data-recommendation-url="{{ route('buyer.products.recommendations', array_merge($queryParameters, ['recommendation_page' => 2])) }}"
    data-next-page="{{ $recommendations->hasMorePages() ? 2 : '' }}"
    aria-labelledby="searchRecommendationsTitle">
    <div class="lk-search-recommendations__heading">
        <span class="lk-search-recommendations__eyebrow">More to explore</span>
        <div class="lk-search-recommendations__title-row">
            <h2 id="searchRecommendationsTitle">You might also like</h2>
            @if(filled($catalogFilterValues['q'] ?? null))
                <span class="lk-search-recommendations__query">Based on <strong>&ldquo;{{ $catalogFilterValues['q'] }}&rdquo;</strong></span>
            @endif
        </div>
        <p>Discover more products from trusted local sellers.</p>
    </div>
    <div class="lk-product-grid {{ $activeView === 'list' ? 'is-list-view' : '' }}" data-recommendation-grid>
        @include('buyer.products.recommendation-cards', ['products' => $recommendationProducts])
    </div>
    @if($recommendationProducts->isEmpty())
        <p class="lk-search-recommendations__status">No other products are available for these filters right now.</p>
    @endif
    <p class="lk-search-recommendations__status" data-recommendation-status role="status" aria-live="polite" hidden></p>
    <button class="lk-search-recommendations__retry" type="button" data-recommendation-retry hidden>Try again</button>
    <div data-recommendation-sentinel aria-hidden="true"></div>
</section>
