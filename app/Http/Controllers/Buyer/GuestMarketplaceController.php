<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\SellerProfile;
use App\Services\Marketplace\ProductCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GuestMarketplaceController extends Controller
{
    public function __construct(private readonly ProductCatalogService $catalog) {}

    public function home(Request $request): View
    {
        $products = $this->catalog->paginated($request, 12);

        return view('guest.home', [
            'products' => $products,
            'buyerProducts' => $products->getCollection()->map(fn (Product $product) => $this->catalog->productPayload($product)),
            'categories' => $this->catalog->categories(),
        ]);
    }

    public function products(Request $request): View
    {
        $products = $this->catalog->paginated($request, 24);

        return view('guest.products', [
            'products' => $products,
            'buyerProducts' => $products->getCollection()->map(fn (Product $product) => $this->catalog->productPayload($product)),
            'categories' => $this->catalog->categories(),
        ]);
    }

    public function searchSuggestions(Request $request): JsonResponse
    {
        $rawSearch = $request->query('q', '');
        $search = is_scalar($rawSearch) ? mb_substr(trim((string) $rawSearch), 0, 80) : '';
        if (mb_strlen($search) < 2) {
            return response()->json(['suggestions' => []]);
        }

        $normalizedSearch = mb_strtolower($search);
        $escapedSearch = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $normalizedSearch);
        $pattern = '%'.$escapedSearch.'%';
        $prefix = $escapedSearch.'%';
        $storeScoped = $request->query->has('seller_id');
        $sellerIdValue = $request->query('seller_id');
        $sellerId = is_scalar($sellerIdValue) && ctype_digit((string) $sellerIdValue)
            ? (int) $sellerIdValue
            : 0;

        $productQuery = Product::query()
            ->visible()
            ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
            ->when($storeScoped, fn ($query) => $query->where('seller_profile_id', $sellerId))
            ->orderByRaw("CASE WHEN LOWER(name) LIKE ? ESCAPE '!' THEN 0 ELSE 1 END", [$prefix])
            ->orderBy('name')
            ->limit(4);
        $products = $productQuery->get(['name', 'slug']);

        $categories = $storeScoped ? collect() : Category::query()
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
            ->orderByRaw("CASE WHEN LOWER(name) LIKE ? ESCAPE '!' THEN 0 ELSE 1 END", [$prefix])
            ->orderBy('name')
            ->limit(2)
            ->get(['name']);

        $sellers = $storeScoped ? collect() : SellerProfile::query()
            ->where('status', 'ACTIVE')
            ->where('store_visibility', true)
            ->whereHas('user', fn ($user) => $user->where('status', 'ACTIVE'))
            ->whereHas('products', fn ($products) => $products->visible())
            ->whereRaw("LOWER(business_name) LIKE ? ESCAPE '!'", [$pattern])
            ->orderBy('business_name')
            ->limit(2)
            ->get(['business_name']);

        $suggestions = collect()
            ->concat($products->map(fn (Product $product): array => [
                'type' => 'Product',
                'label' => $product->name,
                'query' => $product->name,
            ]))
            ->concat($categories->map(fn (Category $category): array => [
                'type' => 'Category',
                'label' => $category->name,
                'query' => $category->name,
            ]))
            ->concat($sellers->map(fn (SellerProfile $seller): array => [
                'type' => 'Local seller',
                'label' => $seller->business_name,
                'query' => $seller->business_name,
            ]))
            ->values();

        return response()->json(['suggestions' => $suggestions]);
    }

    public function show(string $slug): View
    {
        $product = $this->catalog->findVisibleBySlug($slug);
        $productPayload = $this->catalog->productPayload($product, true);
        $productPayload['seller_rating'] = $product->sellerProfile
            ? $this->catalog->sellerRating($product->sellerProfile)
            : 0;

        return view('guest.product-details', [
            'productModel' => $product,
            'product' => $productPayload,
            'relatedProducts' => $this->catalog->visibleQuery()
                ->whereKeyNot($product->id)
                ->where('category_id', $product->category_id)
                ->limit(4)
                ->get()
                ->map(fn (Product $item) => $this->catalog->productPayload($item)),
        ]);
    }
}
