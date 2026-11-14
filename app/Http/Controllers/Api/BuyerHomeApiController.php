<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Cart;
use App\Models\Buyer\ProductView;
use App\Models\Buyer\SellerFollower;
use App\Models\Buyer\WishlistItem;
use App\Models\Seller\Product;
use App\Models\User;
use App\Services\Marketplace\ProductCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class BuyerHomeApiController extends Controller
{
    private const FEATURED_LIMIT = 12;
    private const RECOMMENDATION_DEFAULT_PAGE_SIZE = 6;
    private const RECOMMENDATION_MAX_PAGE_SIZE = 36;
    private const VIEW_SIGNAL_MAX_AGE_DAYS = 7;
    private const CATEGORY_INTEREST_CAP = 12.0;
    private const SELLER_INTEREST_CAP = 18.0;

    public function __construct(private readonly ProductCatalogService $catalog) {}

    public function featured(Request $request): JsonResponse
    {
        $this->authorizeBuyer($request);
        $limit = min(max($request->integer('limit', self::FEATURED_LIMIT), 1), self::FEATURED_LIMIT);
        $ids = $this->featuredProductIds($limit);

        return response()->json([
            'success' => true,
            'data' => $this->productPayloadsInOrder($ids),
            'meta' => ['limit' => $limit, 'total' => count($ids)],
        ]);
    }

    public function recommendations(Request $request): JsonResponse
    {
        $this->authorizeBuyer($request);

        $page = max(1, $request->integer('page', 1));
        $perPage = min(max($request->integer('per_page', self::RECOMMENDATION_DEFAULT_PAGE_SIZE), 1), self::RECOMMENDATION_MAX_PAGE_SIZE);
        $buyerId = (int) $request->user()->id;
        $featuredIds = $this->featuredProductIds(self::FEATURED_LIMIT);

        $viewedProductIds = ProductView::query()
            ->where('buyer_user_id', $buyerId)
            ->select('product_id');
        $viewRows = ProductView::query()
            ->where('buyer_user_id', $buyerId)
            ->where('viewed_at', '>=', now()->subDays(self::VIEW_SIGNAL_MAX_AGE_DAYS))
            ->with('product:id,category_id,seller_profile_id')
            ->get(['product_id', 'viewed_at']);
        $wishlistRows = WishlistItem::query()
            ->where('user_id', $buyerId)
            ->with('product:id,category_id,seller_profile_id')
            ->get(['product_id']);
        $wishlistIds = $wishlistRows->pluck('product_id')->map(fn ($id): int => (int) $id)->all();

        $carts = Cart::query()
            ->active()
            ->where('buyer_user_id', $buyerId)
            ->with('items.productVariant.product:id,category_id,seller_profile_id')
            ->get();
        $cartProducts = $carts
            ->flatMap(fn (Cart $cart) => $cart->items->map(fn ($item) => $item->productVariant?->product))
            ->filter()
            ->values();
        $cartIds = $cartProducts->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $followedSellerIds = SellerFollower::query()
            ->where('buyer_user_id', $buyerId)
            ->whereHas('sellerProfile', fn ($seller) => $seller
                ->where('status', 'ACTIVE')
                ->where('store_visibility', true)
                ->whereHas('user', fn ($user) => $user->where('status', 'ACTIVE')))
            ->pluck('seller_profile_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        [$categoryScores, $sellerScores] = $this->activityInterestScores(
            $viewRows,
            $wishlistRows,
            $cartProducts,
        );

        $excludedIds = array_values(array_unique(array_merge(
            $featuredIds,
            $wishlistIds,
            $cartIds,
        )));

        $ranked = Product::query()
            ->visible()
            ->select(['products.id', 'products.category_id', 'products.seller_profile_id'])
            ->withSum('orderItems', 'quantity')
            ->whereHas('sellerProfile', fn ($seller) => $seller->where('vacation_mode', false))
            ->whereHas('variants', fn ($variant) => $variant->where('is_active', true)->where('stock', '>', 0))
            ->when($excludedIds !== [], fn ($query) => $query->whereNotIn('products.id', $excludedIds))
            ->whereNotIn('products.id', $viewedProductIds)
            ->orderByDesc('order_items_sum_quantity')
            ->orderByDesc('products.id')
            ->get();

        $followedSellerLookup = array_fill_keys($followedSellerIds, true);
        $scored = $ranked->values()->map(function (Product $product, int $rank) use (
            $categoryScores,
            $sellerScores,
            $followedSellerLookup,
        ): array {
            $sellerId = (int) $product->seller_profile_id;
            $categoryId = $product->category_id === null ? 0 : (int) $product->category_id;
            $score = isset($followedSellerLookup[$sellerId]) ? 45.0 : 0.0;
            $score += $categoryScores[$categoryId] ?? 0.0;
            $score += $sellerScores[$sellerId] ?? 0.0;

            return ['id' => (int) $product->id, 'score' => $score, 'rank' => $rank];
        });

        $personalized = $scored->contains(fn (array $row): bool => $row['score'] > 0);
        if ($personalized) {
            $scored = $scored->sort(fn (array $a, array $b): int =>
                ($b['score'] <=> $a['score']) ?: ($a['rank'] <=> $b['rank'])
            )->values();
        }

        $total = $scored->count();
        $ids = $scored->slice(($page - 1) * $perPage, $perPage)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return response()->json([
            'success' => true,
            'data' => $this->productPayloadsInOrder($ids),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
                'personalized' => $personalized,
            ],
        ]);
    }

    public function recordView(Request $request): JsonResponse
    {
        $this->authorizeBuyer($request);
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
        ]);
        $product = Product::query()->visible()->findOrFail((int) $validated['product_id']);
        $viewedAt = now();

        ProductView::query()->updateOrCreate(
            [
                'buyer_user_id' => $request->user()->id,
                'product_id' => $product->id,
            ],
            ['viewed_at' => $viewedAt],
        );

        return response()->json([
            'success' => true,
            'data' => ['product_id' => $product->id, 'viewed_at' => $viewedAt->toIso8601String()],
        ]);
    }

    /** @return array<int, int> */
    private function featuredProductIds(int $limit): array
    {
        $ranked = Product::query()
            ->visible()
            ->select(['products.id', 'products.seller_profile_id', 'products.created_at'])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->withSum('orderItems', 'quantity')
            ->orderByDesc('reviews_avg_rating')
            ->orderByDesc('reviews_count')
            ->orderByDesc('order_items_sum_quantity')
            ->orderByDesc('products.created_at')
            ->orderByDesc('products.id')
            ->get();

        $selected = [];
        $selectedLookup = [];
        $representedSellers = [];
        foreach ($ranked as $product) {
            $sellerId = (int) $product->seller_profile_id;
            if (isset($representedSellers[$sellerId])) {
                continue;
            }
            $representedSellers[$sellerId] = true;
            $selected[] = (int) $product->id;
            $selectedLookup[(int) $product->id] = true;
            if (count($selected) >= $limit) {
                break;
            }
        }

        if (count($selected) < $limit) {
            foreach ($ranked as $product) {
                $id = (int) $product->id;
                if (isset($selectedLookup[$id])) {
                    continue;
                }
                $selected[] = $id;
                $selectedLookup[$id] = true;
                if (count($selected) >= $limit) {
                    break;
                }
            }
        }

        shuffle($selected);

        return $selected;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function productPayloadsInOrder(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $products = $this->catalog->visibleQuery()
            ->whereIn('products.id', $ids)
            ->get()
            ->keyBy('id');

        return collect($ids)
            ->map(fn (int $id): ?array => $products->has($id)
                ? $this->catalog->productPayload($products->get($id))
                : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ProductView>  $views
     * @param  Collection<int, WishlistItem>  $wishlist
     * @param  Collection<int, Product>  $cartProducts
     * @return array{0: array<int, float>, 1: array<int, float>}
     */
    private function activityInterestScores(Collection $views, Collection $wishlist, Collection $cartProducts): array
    {
        $categoryScores = [];
        $sellerScores = [];
        $recentViewFloor = now()->subDays(self::VIEW_SIGNAL_MAX_AGE_DAYS);

        foreach ($views as $view) {
            if ($view->viewed_at?->lessThan($recentViewFloor)) {
                continue;
            }
            $product = $view->product;
            if ($product === null) {
                continue;
            }
            $ageHours = max(0.0, now()->diffInHours($view->viewed_at, true));
            $signal = max(0.0, 7.0 - ($ageHours / 24.0));
            $this->addInterest($categoryScores, $sellerScores, $product, $signal);
        }

        foreach ($wishlist as $item) {
            if ($item->product !== null) {
                $this->addInterest($categoryScores, $sellerScores, $item->product, 9.0);
            }
        }

        foreach ($cartProducts as $product) {
            $this->addInterest($categoryScores, $sellerScores, $product, 12.0);
        }

        foreach ($categoryScores as $id => $score) {
            $categoryScores[$id] = min(self::CATEGORY_INTEREST_CAP, $score * 0.25);
        }
        foreach ($sellerScores as $id => $score) {
            $sellerScores[$id] = min(self::SELLER_INTEREST_CAP, $score * 0.35);
        }

        return [$categoryScores, $sellerScores];
    }

    /** @param array<int, float> $categoryScores @param array<int, float> $sellerScores */
    private function addInterest(array &$categoryScores, array &$sellerScores, Product $product, float $signal): void
    {
        if ($product->category_id !== null) {
            $categoryId = (int) $product->category_id;
            $categoryScores[$categoryId] = ($categoryScores[$categoryId] ?? 0.0) + $signal;
        }
        $sellerId = (int) $product->seller_profile_id;
        $sellerScores[$sellerId] = ($sellerScores[$sellerId] ?? 0.0) + $signal;
    }

    private function authorizeBuyer(Request $request): void
    {
        abort_unless($request->user()?->isAccountType(User::TYPE_BUYER), 403, 'Buyer access is required.');
    }
}
