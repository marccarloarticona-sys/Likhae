<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Address;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\WishlistItem;
use App\Models\Communication\Conversation;
use App\Models\Communication\ConversationParticipant;
use App\Models\Communication\Message;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Services\Account\ProfilePhotoService;
use App\Services\Communication\ConversationService;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\CheckoutService;
use App\Services\Marketplace\ProductCatalogService;
use App\Services\Marketplace\VoucherRedemptionCounter;
use App\Support\PhilippineAddressValidator;
use App\Support\AddressCoordinateValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class BuyerController extends Controller
{
    public function __construct(
        private readonly ProductCatalogService $catalog,
        private readonly CartService $cartService,
        private readonly ConversationService $conversations,
    ) {}

    public function home(Request $request): View
    {
        $products = $this->catalog->paginated($request, 12);
        $bestSellingProducts = $this->catalog->applySort(
            $this->catalog->visibleQuery(),
            'best-selling'
        )->limit(2)->get()
            ->map(fn (Product $product) => $this->catalog->productPayload($product));
        $dealProducts = $this->discountedProductsQuery()
            ->limit(4)
            ->get()
            ->map(fn (Product $product) => $this->dealProductPayload($product))
            ->filter()
            ->values();

        return view('Buyer.home', [
            'products' => $products,
            'buyerProducts' => $products->getCollection()->map(fn (Product $product) => $this->catalog->productPayload($product)),
            'heroProducts' => $bestSellingProducts,
            'dealProducts' => $dealProducts,
            'categories' => $this->catalog->categories(),
        ]);
    }

    public function flashDeals(Request $request): View
    {
        $query = $this->catalog->applyFilters($this->discountedProductsQuery(), $request);
        $products = $this->catalog->applySort(
            $query,
            $request->query('sort', 'best-selling')
        )->paginate(24)->withQueryString();

        $dealProducts = $products->getCollection()
            ->map(fn (Product $product) => $this->dealProductPayload($product))
            ->filter()
            ->values();

        return view('Buyer.flash-deals', [
            'products' => $products,
            'dealProducts' => $dealProducts,
        ]);
    }

    private function discountedProductsQuery(): Builder
    {
        return $this->catalog->visibleQuery()
            ->whereHas('variants', fn (Builder $variants) => $variants
                ->where('is_active', true)
                ->where('stock', '>', 0)
                ->where('price', '>', 0)
                ->where('discount_value', '>', 0)
                ->where(function (Builder $discountType): void {
                    $discountType->whereNull('discount_type')
                        ->orWhereRaw("LOWER(TRIM(discount_type)) <> 'none'");
                }));
    }

    /** @return array<string, mixed>|null */
    private function dealProductPayload(Product $product): ?array
    {
        $dealVariant = $product->variants
            ->filter(fn (ProductVariant $variant): bool => $variant->is_active
                && $variant->stock > 0
                && $variant->discount_amount > 0)
            ->sortBy(fn (ProductVariant $variant): float => $variant->final_price)
            ->first();

        if (! $dealVariant) {
            return null;
        }

        return array_merge($this->catalog->productPayload($product), [
            'deal_variant' => $this->catalog->variantPayload($dealVariant),
        ]);
    }

    public function products(Request $request): View
    {
        [$filters, $catalogFilterValues, $catalogErrors] = $this->validatedCatalogFilters($request);
        $query = $this->filterBuyerCatalog($this->catalog->visibleQuery(), $filters);
        $invalidPriceRange = $catalogErrors->has('min_price') || $catalogErrors->has('max_price');
        if ($invalidPriceRange) {
            $query->whereRaw('1 = 0');
        }
        $paginator = $this->sortBuyerCatalog($query, $filters['sort'] ?? 'latest')
            ->paginate(24, ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)))
            ->appends($this->catalogQueryParameters($filters));
        $searchSuggestions = ! $invalidPriceRange && $paginator->total() === 0 && $filters['q'] !== ''
            ? $this->catalogSearchSuggestions($filters)
            : [];

        return view('Buyer.products', [
            'products' => $paginator,
            'buyerProducts' => $paginator->getCollection()->map(fn (Product $product) => $this->buyerCatalogPayload($product)),
            'categories' => $this->catalog->categories(),
            'catalogTotal' => $this->catalog->visibleQuery()->count(),
            'catalogLocations' => $this->catalogLocations(),
            'searchSuggestions' => $searchSuggestions,
            'catalogFilterValues' => $catalogFilterValues,
            'catalogQueryParameters' => $this->catalogQueryParameters($filters),
            'catalogErrors' => $catalogErrors,
        ]);
    }

    /** @return array{array<string, mixed>, array<string, string>, ViewErrorBag} */
    private function validatedCatalogFilters(Request $request): array
    {
        $query = $request->query();
        if (! array_key_exists('q', $query) && array_key_exists('search', $query)) {
            $query['q'] = $query['search'];
        }
        foreach (['q', 'search', 'category', 'location', 'sort', 'view'] as $key) {
            if (isset($query[$key]) && is_string($query[$key])) {
                $query[$key] = trim($query[$key]);
                if (in_array($key, ['category', 'sort', 'view'], true)) {
                    $query[$key] = strtolower($query[$key]);
                }
            }
        }

        $validator = Validator::make($query, [
            'q' => ['nullable', 'string', 'max:120'],
            'search' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'rating' => ['nullable', 'numeric', 'between:0,5'],
            'location' => ['nullable', 'string', 'max:150'],
            'sort' => ['nullable', 'string', Rule::in(['latest', 'price-low', 'price-high', 'best-rated', 'best-selling', 'oldest', 'name', 'featured'])],
            'view' => ['nullable', 'string', Rule::in(['grid', 'list'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        $validator->after(function ($validator) use ($query): void {
            if ($validator->errors()->has('min_price') || $validator->errors()->has('max_price')) {
                return;
            }

            $minimum = $query['min_price'] ?? null;
            $maximum = $query['max_price'] ?? null;
            if ($minimum !== null && $minimum !== '' && $maximum !== null && $maximum !== ''
                && (float) $minimum > (float) $maximum) {
                $message = 'Maximum price must be greater than or equal to minimum price.';
                $validator->errors()->add('min_price', $message);
                $validator->errors()->add('max_price', $message);
            }
        });

        $hasErrors = $validator->fails();
        $filters = $hasErrors ? $validator->valid() : $validator->validated();
        $filters['q'] = trim((string) ($filters['q'] ?? ''));
        $filters['category'] = strtolower(trim((string) ($filters['category'] ?? '')));
        $filters['location'] = trim((string) ($filters['location'] ?? ''));
        $filters['sort'] = strtolower(trim((string) ($filters['sort'] ?? 'latest')));
        if ($filters['sort'] === 'featured') {
            $filters['sort'] = 'latest';
        }
        $filters['view'] = strtolower(trim((string) ($filters['view'] ?? 'grid')));

        $filterValues = [];
        foreach (['q', 'category', 'min_price', 'max_price', 'rating', 'location', 'sort', 'view'] as $key) {
            $value = $query[$key] ?? '';
            $filterValues[$key] = is_scalar($value) ? trim((string) $value) : '';
        }
        $errors = new ViewErrorBag;
        $errors->put('default', $validator->errors());

        return [$filters, $filterValues, $errors];
    }

    /** @param array<string, mixed> $filters */
    private function filterBuyerCatalog(Builder $query, array $filters, bool $includeSearch = true): Builder
    {
        $search = $includeSearch ? (string) ($filters['q'] ?? '') : '';
        if ($search !== '') {
            $escapedSearch = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
            $pattern = '%'.$escapedSearch.'%';
            $query->where(function (Builder $matches) use ($pattern): void {
                $matches->whereRaw("LOWER(products.name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(products.description) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('category', fn (Builder $category) => $category->whereRaw("LOWER(categories.name) LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('sellerProfile', fn (Builder $seller) => $seller->whereRaw("LOWER(seller_profiles.business_name) LIKE ? ESCAPE '!'", [$pattern]));
            });
        }

        $category = (string) ($filters['category'] ?? '');
        if ($category !== '' && $category !== 'all') {
            $query->whereHas('category', function (Builder $categoryQuery) use ($category): void {
                $categoryQuery->where('slug', $category)
                    ->orWhereHas('parent', fn (Builder $parent) => $parent->where('slug', $category));
            });
        }

        $minimum = $filters['min_price'] ?? null;
        $maximum = $filters['max_price'] ?? null;
        if (($minimum !== null && $minimum !== '') || ($maximum !== null && $maximum !== '')) {
            $sellingPrice = $this->variantSellingPriceSubquery();
            if ($minimum !== null && $minimum !== '') {
                $this->whereSellingPrice($query, '>=', (string) $minimum, $sellingPrice);
            }
            if ($maximum !== null && $maximum !== '') {
                $this->whereSellingPrice($query, '<=', (string) $maximum, $sellingPrice);
            }
        }

        $minimumRating = (float) ($filters['rating'] ?? 0);
        if ($minimumRating > 0) {
            $ratedProductIds = DB::table('order_items')
                ->join('reviews', 'reviews.order_item_id', '=', 'order_items.id')
                ->where('reviews.status', 'PUBLISHED')
                ->whereNotNull('reviews.rating')
                ->groupBy('order_items.product_id')
                ->havingRaw('CAST(AVG(reviews.rating) AS DECIMAL(3, 2)) >= CAST(? AS DECIMAL(3, 2))', [(string) $minimumRating])
                ->select('order_items.product_id');

            $query->whereIn('products.id', $ratedProductIds);
        }

        $location = (string) ($filters['location'] ?? '');
        if ($location !== '') {
            $query->whereHas('sellerProfile', fn (Builder $seller) => $seller
                ->whereRaw('LOWER(TRIM(seller_profiles.location)) = ?', [mb_strtolower($location)]));
        }

        return $query;
    }

    private function variantSellingPriceSubquery(): Builder
    {
        $priceExpression = $this->variantSellingPriceSql();

        return ProductVariant::query()
            ->selectRaw("MIN({$priceExpression})")
            ->whereColumn('product_variants.product_id', 'products.id')
            ->where('product_variants.is_active', true);
    }

    private function whereSellingPrice(Builder $query, string $operator, string $price, Builder $sellingPrice): void
    {
        $subquery = $sellingPrice->toSql();
        $bindings = array_merge($sellingPrice->getBindings(), [$price]);

        $query->whereRaw(
            "CAST(({$subquery}) AS DECIMAL(12, 2)) {$operator} CAST(? AS DECIMAL(12, 2))",
            $bindings,
        );
    }

    private function variantSellingPriceSql(): string
    {
        $type = "LOWER(TRIM(COALESCE(product_variants.discount_type, '')))";
        $value = 'COALESCE(product_variants.discount_value, 0)';
        $price = 'product_variants.price';

        return "CAST(CASE
            WHEN {$type} = 'fixed' AND {$value} > 0 THEN CASE WHEN {$value} >= {$price} THEN 0 ELSE {$price} - {$value} END
            WHEN ({$type} = 'percentage' OR ({$type} NOT IN ('fixed', 'percentage', 'none') AND {$value} > 0)) AND {$value} > 0 THEN CASE WHEN {$value} >= 100 THEN 0 ELSE {$price} * (1 - {$value} / 100) END
            ELSE {$price}
        END AS DECIMAL(12, 2))";
    }

    private function sortBuyerCatalog(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'price-low' => $query->orderBy($this->variantSellingPriceSubquery())->orderBy('products.id'),
            'price-high' => $query->orderByDesc($this->variantSellingPriceSubquery())->orderBy('products.id'),
            'best-rated' => $query->orderByDesc('reviews_avg_rating')->orderByDesc('products.id'),
            'best-selling' => $query->orderByDesc('order_items_sum_quantity')->orderByDesc('products.id'),
            'oldest' => $query->orderBy('products.created_at')->orderBy('products.id'),
            'name' => $query->orderBy('products.name')->orderBy('products.id'),
            default => $query->orderByDesc('products.created_at')->orderByDesc('products.id'),
        };
    }

    /** @param array<string, mixed> $filters */
    private function catalogSearchSuggestions(array $filters): array
    {
        $term = mb_strtolower((string) ($filters['q'] ?? ''));
        if (mb_strlen($term) < 3) {
            return [];
        }

        $candidates = $this->filterBuyerCatalog($this->catalog->visibleQuery(), $filters, false)
            ->setEagerLoads([])
            ->orderBy('products.name')
            ->limit(2000)
            ->get(['products.id', 'products.name']);
        $threshold = max(1, min(3, (int) ceil(mb_strlen($term) * 0.25)));

        return $candidates
            ->map(fn (Product $product): array => [
                'name' => (string) $product->name,
                'distance' => $this->searchSuggestionDistance($term, (string) $product->name),
            ])
            ->filter(fn (array $candidate): bool => $candidate['distance'] > 0 && $candidate['distance'] <= $threshold)
            ->sortBy('distance')
            ->unique(fn (array $candidate): string => mb_strtolower($candidate['name']))
            ->take(3)
            ->pluck('name')
            ->values()
            ->all();
    }

    private function searchSuggestionDistance(string $term, string $name): int
    {
        $normalizedName = mb_strtolower(trim((string) preg_replace('/[^\pL\pN]+/u', ' ', $name)));
        $words = preg_split('/\s+/u', $normalizedName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $candidates = [$normalizedName, str_replace(' ', '', $normalizedName), ...$words];

        for ($size = 2; $size <= min(3, count($words)); $size++) {
            for ($start = 0; $start <= count($words) - $size; $start++) {
                $candidates[] = implode('', array_slice($words, $start, $size));
                $candidates[] = implode(' ', array_slice($words, $start, $size));
            }
        }

        return collect($candidates)
            ->map(fn (string $candidate): int => levenshtein($term, $candidate))
            ->min() ?? PHP_INT_MAX;
    }

    /** @param array<string, mixed> $filters */
    private function catalogQueryParameters(array $filters): array
    {
        $parameters = [];
        foreach (['q', 'category', 'min_price', 'max_price', 'rating', 'location', 'sort', 'view'] as $key) {
            $value = $filters[$key] ?? null;
            if ($value !== null && $value !== ''
                && ! ($key === 'category' && $value === 'all')
                && ! ($key === 'rating' && (string) $value === '0')
                && ! ($key === 'sort' && $value === 'latest')
                && ! ($key === 'view' && $value === 'grid')) {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }

    private function buyerCatalogPayload(Product $product): array
    {
        $payload = $this->catalog->productPayload($product);
        $payload['seller']['vacation_mode'] = (bool) $product->sellerProfile?->vacation_mode;
        $payload['seller']['location'] = (string) ($product->sellerProfile?->location ?? '');

        return $payload;
    }

    private function catalogLocations()
    {
        return SellerProfile::query()
            ->whereNotNull('location')
            ->whereHas('products', fn (Builder $products) => $products->visible())
            ->select('location')
            ->distinct()
            ->orderBy('location')
            ->pluck('location')
            ->map(fn ($location) => trim((string) $location))
            ->filter()
            ->unique(fn ($location) => mb_strtolower($location))
            ->values();
    }

    private function ensureBuyerProductCanBePurchased(Product $product): void
    {
        $product->loadMissing('sellerProfile.user');
        abort_unless(
            $product->status === 'ACTIVE'
                && $product->sellerProfile?->status === 'ACTIVE'
                && $product->sellerProfile?->user?->status === User::STATUS_ACTIVE,
            404
        );

        if ($product->sellerProfile->vacation_mode) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'This seller is currently on vacation and is not accepting purchases.',
            ]);
        }
    }

    public function product(string $slug): View
    {
        $product = $this->catalog->findVisibleBySlug($slug);
        $productPayload = $this->catalog->productPayload($product, true);
        $productPayload['seller']['vacation_mode'] = (bool) $product->sellerProfile?->vacation_mode;
        $productPayload['seller_rating'] = $product->sellerProfile
            ? $this->catalog->sellerRating($product->sellerProfile)
            : 0;

        return view('Buyer.product-details', [
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

    public function shop(string $seller, Request $request): View
    {
        $sellerProfile = $this->catalog->sellerByRouteKey($seller);
        $sellerProfile->setAttribute('rating', $this->catalog->sellerRating($sellerProfile));
        $query = $this->catalog->visibleQuery()
            ->where('seller_profile_id', $sellerProfile->id);

        $products = $this->catalog->applySort(
            $this->catalog->applyFilters($query, $request),
            $request->query('sort')
        )->paginate(24)->withQueryString();

        return view('Buyer.store', [
            'seller' => $sellerProfile,
            'products' => $products,
            'buyerProducts' => $products->getCollection()->map(fn (Product $product) => $this->catalog->productPayload($product)),
            'categories' => $this->catalog->categories(),
        ]);
    }

    public function cart(Request $request): View
    {
        $cartItems = $this->cartService->items($request->user());

        return view('Buyer.cart', [
            'cartItems' => $cartItems,
            'availableVouchers' => app(CheckoutService::class)
                ->availableVouchers($cartItems, $request->user()),
            'defaultAddress' => $request->user()->addresses()->orderByDesc('is_default')->latest()->first(),
        ]);
    }

    public function addCart(Request $request, Product $product): RedirectResponse|JsonResponse
    {
        $this->ensureBuyerProductCanBePurchased($product);

        $data = $request->validate([
            'product_variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $product->id)->where('is_active', true)],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'checkout' => ['nullable', 'boolean'],
        ], [
            'product_variant_id.required' => 'Please select a variation.',
            'product_variant_id.integer' => 'Please select a variation.',
            'product_variant_id.exists' => 'The selected variation is invalid or unavailable.',
        ]);

        $this->ensureBuyerVariantIsAvailable($product, (int) $data['product_variant_id']);

        $item = $this->cartService->add($request->user(), $product, (int) $data['product_variant_id'], (int) ($data['quantity'] ?? 1));

        if ($request->boolean('checkout')) {
            $request->session()->put('checkout_cart_item_ids', [$item->id]);
            $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes', 'checkout_seller_notes']);

            return redirect()->route('buyer.checkout');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Product added to cart.',
                'cart_item_id' => $item->id,
            ]);
        }

        return back()->with('buyer_notice', 'Product added to cart.');
    }

    public function buyNow(Request $request, Product $product): RedirectResponse
    {
        $this->ensureBuyerProductCanBePurchased($product);

        $data = $request->validate([
            'product_variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $product->id)->where('is_active', true)],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
        ], [
            'product_variant_id.required' => 'Please select a variation.',
            'product_variant_id.integer' => 'Please select a variation.',
            'product_variant_id.exists' => 'The selected variation is invalid or unavailable.',
        ]);

        $this->ensureBuyerVariantIsAvailable($product, (int) $data['product_variant_id']);

        $item = $this->cartService->add(
            $request->user(),
            $product,
            (int) $data['product_variant_id'],
            (int) ($data['quantity'] ?? 1),
        );

        // Checkout uses persisted cart items for stock locking and totals, but
        // Buy Now selects only this item and always leaves this endpoint via
        // the checkout page rather than the cart response path.
        $request->session()->put('checkout_cart_item_ids', [$item->id]);
        $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes', 'checkout_seller_notes']);

        return redirect()->route('buyer.checkout');
    }

    private function ensureBuyerVariantIsAvailable(Product $product, int $variantId): void
    {
        $variant = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->find($variantId);

        if (! $variant) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'The selected variation is invalid or unavailable.',
            ]);
        }

        if ((int) $variant->stock < 1) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'This variation is out of stock.',
            ]);
        }
    }

    public function updateCart(Request $request, CartItem $item): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $this->cartService->update($request->user(), $item, (int) $data['quantity']);

        return back()->with('buyer_notice', 'Cart updated.');
    }

    public function removeCart(Request $request, CartItem $item): RedirectResponse
    {
        $this->cartService->remove($request->user(), $item);

        return back()->with('buyer_notice', 'Item removed from cart.');
    }

    public function messages(Request $request): View|RedirectResponse
    {
        $sellerId = $request->integer('seller_id');

        if ($sellerId > 0) {
            $seller = User::query()
                ->with('sellerProfile')
                ->whereKey($sellerId)
                ->where('status', User::STATUS_ACTIVE)
                ->firstOrFail();

            abort_unless($seller->sellerProfile?->status === 'ACTIVE', 404);

            $conversation = $this->conversations->start($request->user(), $seller->id, [
                'type' => 'PRODUCT_SELLER',
            ]);

            return redirect()->route('buyer.messages', array_filter([
                'seller' => 'conversation-'.$conversation->id,
                'product' => $request->query('product'),
            ], static fn ($value) => filled($value)));
        }

        $conversations = $this->conversations->listFor($request->user(), false, null, true);
        if ($conversations->isNotEmpty()) {
            ConversationParticipant::query()
                ->where('user_id', $request->user()->id)
                ->whereIn('conversation_id', $conversations->pluck('id'))
                ->update(['last_read_at' => now()]);
        }
        $rows = $conversations->map(function ($conversation) use ($request): array {
            $other = $conversation->participants->first(fn ($participant) => (int) $participant->id !== (int) $request->user()->id);
            $seller = $other?->sellerProfile;
            $isSeller = $seller?->status === 'ACTIVE';
            $isSupport = $other?->isAccountType(User::TYPE_ADMIN) ?? false;
            $name = $isSupport ? 'LIKHAE Support' : ($isSeller ? $seller->business_name : ($other?->name ?: 'LIKHAE User'));

            return [
                'id' => $other?->id,
                'conversation_id' => $conversation->id,
                'name' => $name,
                'slug' => 'conversation-'.$conversation->id,
                'store_key' => $isSeller ? $seller->id : null,
                'is_seller' => $isSeller,
                'is_support' => $isSupport,
                'avatar' => 'https://ui-avatars.com/api/?name='.urlencode($name).'&background=561C17&color=fff',
                'last_message' => $conversation->latestMessage?->body ?: 'Start a conversation.',
                'time' => $conversation->latestMessage?->sent_at?->diffForHumans() ?? '',
                'unread' => 0,
                'messages' => [],
            ];
        })->filter(fn (array $row): bool => filled($row['id']))->values();

        $selectedSlug = (string) $request->query('seller', '');
        $active = $rows->firstWhere('slug', $selectedSlug) ?: $rows->first();
        $chatMessages = $active
            ? Message::query()
                ->where('conversation_id', $active['conversation_id'])
                ->latest('sent_at')
                ->limit(50)
                ->get(['id', 'conversation_id', 'body', 'sender_user_id', 'sent_at'])
                ->reverse()
                ->values()
            : collect();

        return view('Buyer.messages', [
            'buyerProducts' => collect(),
            'conversationRows' => $rows,
            'dbActiveSeller' => null,
            'chatMessages' => $chatMessages,
        ]);
    }

    public function messageStream(Request $request): JsonResponse
    {
        $recipientId = (int) $request->query('seller_id');
        $afterId = (int) $request->query('after_id');
        $conversation = $recipientId > 0
            ? Conversation::query()
                ->select('conversations.id')
                ->whereHas('participants', fn ($query) => $query->where('users.id', $request->user()->id))
                ->whereHas('participants', fn ($query) => $query->where('users.id', $recipientId))
                ->first()
            : null;

        $messages = collect();
        if ($conversation) {
            $query = Message::query()
                ->where('conversation_id', $conversation->id)
                ->when($afterId > 0, fn ($query) => $query->where('id', '>', $afterId)->oldest('id'), fn ($query) => $query->latest('sent_at'))
                ->limit(50)
                ->get(['id', 'conversation_id', 'body', 'sender_user_id', 'sent_at']);

            $messages = $afterId > 0 ? $query->values() : $query->reverse()->values();
        }

        return response()->json([
            'success' => true,
            'messages' => $messages->map(fn ($message): array => [
                'id' => $message->id,
                'body' => $message->body,
                'sender_user_id' => $message->sender_user_id,
                'sent_at' => $message->sent_at?->toIso8601String(),
            ])->values(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function sendMessage(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'recipient_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'conversation_id' => ['nullable', 'integer', Rule::exists('conversations', 'id')],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $recipient = User::query()
            ->whereKey((int) $data['recipient_id'])
            ->where('status', User::STATUS_ACTIVE)
            ->firstOrFail();

        $isActiveSeller = $recipient->sellerProfile?->status === 'ACTIVE';
        abort_unless($isActiveSeller || $recipient->isAccountType(User::TYPE_ADMIN), 404);

        $context = [];
        $conversation = null;
        if (! empty($data['conversation_id'])) {
            $conversation = Conversation::query()
                ->whereKey((int) $data['conversation_id'])
                ->whereHas('participants', fn ($query) => $query->where('users.id', $request->user()->id))
                ->whereHas('participants', fn ($query) => $query->where('users.id', $recipient->id))
                ->firstOrFail();

            $context = $this->conversations->contextFor($conversation);
        }

        $message = $this->conversations->send($request->user(), $recipient->id, trim($data['body']), $context, $conversation);

        if ($request->expectsJson()) {
            $sentAt = $message->sent_at ?? $message->created_at;

            return response()->json([
                'success' => true,
                'message' => [
                    'id' => (string) $message->id,
                    'conversation_id' => (string) $message->conversation_id,
                    'sender_id' => (string) $message->sender_user_id,
                    'body' => $message->body,
                    'from_me' => true,
                    'time' => $sentAt?->diffForHumans() ?? 'Just now',
                ],
            ]);
        }

        return back()->with('buyer_notice', 'Message sent.');
    }

    public function wishlist(Request $request): View
    {
        $wishlistProducts = WishlistItem::query()
            ->where('user_id', $request->user()->id)
            ->with(['product' => fn ($query) => $query->with($this->catalog->productRelations())->withAvg('reviews', 'rating')->withCount('reviews')])
            ->latest()
            ->get()
            ->filter(fn (WishlistItem $item) => $item->product !== null)
            ->map(function (WishlistItem $item) {
                $payload = $this->catalog->productPayload($item->product);
                $payload['wishlist_added_at'] = $item->created_at;
                return $payload;
            });

        return view('Buyer.wishlist', compact('wishlistProducts'));
    }

    public function toggleWishlist(Request $request, Product $product): RedirectResponse
    {
        $item = WishlistItem::query()->where('user_id', $request->user()->id)->where('product_id', $product->id)->first();
        $item ? $item->delete() : WishlistItem::create(['user_id' => $request->user()->id, 'product_id' => $product->id]);
        return back()->with('buyer_notice', $item ? 'Product removed from your wishlist.' : 'Product saved to your wishlist.');
    }

    public function removeWishlistItems(Request $request): RedirectResponse
    {
        $productIds = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
        ])['product_ids'];

        WishlistItem::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('product_id', $productIds)
            ->delete();

        return back()->with('buyer_notice', 'Selected products removed from your wishlist.');
    }

    public function clearWishlist(Request $request): RedirectResponse
    {
        WishlistItem::query()
            ->where('user_id', $request->user()->id)
            ->delete();

        return back()->with('buyer_notice', 'Wishlist cleared.');
    }

    public function rewards(Request $request): View
    {
        $buyer = $request->user();
        $orders = $buyer->orders()
            ->with(['sellerOrders.voucher', 'items.review'])
            ->latest('placed_at')
            ->get();

        $activeVouchers = Voucher::query()
            ->with('sellerProfile')
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->get();
        $redemptionCounts = VoucherRedemptionCounter::countsFor($activeVouchers);
        $buyerRedemptionCounts = VoucherRedemptionCounter::countsFor($activeVouchers, (int) $buyer->id);
        $activeVouchers = $activeVouchers
            ->filter(fn (Voucher $voucher): bool => ($voucher->usage_limit === null
                    || $redemptionCounts->get($voucher->id, 0) < $voucher->usage_limit)
                && ($voucher->per_user_limit === null
                    || $buyerRedemptionCounts->get($voucher->id, 0) < $voucher->per_user_limit))
            ->map(fn (Voucher $voucher): array => [
                'icon' => $voucher->discount_type === 'PERCENT' ? number_format((float) $voucher->discount_value, 0).'%' : '₱',
                'status' => $voucher->sellerProfile?->business_name ?? 'LIKHAE Platform',
                'value' => $voucher->discount_type === 'PERCENT'
                    ? number_format((float) $voucher->discount_value, 0).'% off'
                    : '₱'.number_format((float) $voucher->discount_value, 2).' off',
                'condition' => (float) $voucher->minimum_order_amount > 0
                    ? 'Minimum spend ₱'.number_format((float) $voucher->minimum_order_amount, 2)
                    : 'No minimum spend',
                'code' => $voucher->code,
                'expires' => $voucher->ends_at?->format('M j, Y') ?? 'No expiry',
            ])
            ->values();

        $voucherHistory = $orders->flatMap(fn ($order) => $order->sellerOrders
            ->whereNotNull('voucher_id')
            ->groupBy('voucher_id')
            ->map(fn ($sellerOrders): array => [
                'voucher' => $sellerOrders->first()->voucher?->code ?? 'Voucher',
                'benefit' => '-₱'.number_format((float) $sellerOrders->sum('voucher_discount'), 2),
                'order' => $order->order_number,
                'status' => str($order->status)->headline()->toString(),
            ]))->values();

        $completedOrders = $orders->where('status', 'COMPLETED');
        $reviews = $orders->flatMap->items->pluck('review')->filter();
        $pointActivities = $completedOrders->map(fn ($order): array => [
            'label' => 'Completed order '.$order->order_number,
            'date' => $order->completed_at?->format('M j, Y') ?? $order->placed_at?->format('M j, Y'),
            'amount' => '+50 points',
            'negative' => false,
        ])->concat($reviews->map(fn ($review): array => [
            'label' => 'Product review submitted',
            'date' => $review->created_at?->format('M j, Y'),
            'amount' => '+20 points',
            'negative' => false,
        ]))->values();

        return view('Buyer.rewards', [
            'activeVouchers' => $activeVouchers,
            'voucherHistory' => $voucherHistory,
            'pointsBalance' => ($completedOrders->count() * 50) + ($reviews->count() * 20),
            'pointActivities' => $pointActivities,
        ]);
    }

    public function notifications(Request $request): View
    {
        return view('Buyer.notifications', [
            'notifications' => $request->user()->notifications()->latest()->paginate(20),
        ]);
    }

    public function notificationStream(Request $request): JsonResponse
    {
        $notifications = $request->user()->notifications()
            ->latest()
            ->limit(20)
            ->get(['id', 'type', 'title', 'message', 'action_url', 'read_at', 'created_at', 'updated_at']);

        $rows = $notifications->map(fn ($notification): array => [
            'id' => (int) $notification->id,
            'category' => str_contains(strtolower((string) $notification->type), 'message') ? 'messages'
                : (str_contains(strtolower((string) $notification->type), 'reward') ? 'rewards'
                    : (str_contains(strtolower((string) $notification->type), 'account') ? 'account' : 'orders')),
            'title' => $notification->title,
            'message' => $notification->message,
            'action_url' => $notification->action_url ?: route('buyer.notifications'),
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ])->values();

        return response()->json([
            'version' => sha1($notifications->map(fn ($notification): array => [
                'id' => (int) $notification->id,
                'read_at' => $notification->read_at?->toIso8601String(),
                'updated_at' => $notification->updated_at?->toIso8601String(),
            ])->values()->toJson()),
            'notifications' => $rows,
            'unread_count' => $rows->whereNull('read_at')->count(),
        ]);
    }

    public function markNotificationsRead(Request $request): RedirectResponse
    {
        $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('buyer_notice', 'Notifications marked as read.');
    }

    public function account(Request $request): View
    {
        return view('Buyer.account', [
            'tab' => $request->query('tab', 'profile'),
            'addresses' => $request->user()->addresses()->latest()->get(),
            'reviews' => $request->user()->orders()->with('items.review')->latest()->get()->flatMap->items->pluck('review')->filter(),
        ]);
    }

    public function settings(Request $request): View
    {
        return view('Buyer.settings', [
            'preferences' => $this->buyerPreferences($request->user()),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme' => ['required', Rule::in(['light', 'dark', 'system'])],
            'language' => ['required', Rule::in(['en'])],
        ]);

        $preferences = array_merge($this->buyerPreferences($request->user()), [
            'order_updates' => $request->boolean('order_updates'),
            'delivery_updates' => $request->boolean('delivery_updates'),
            'chat_updates' => $request->boolean('chat_updates'),
            'promotion_updates' => $request->boolean('promotion_updates'),
            'ai_assistant_enabled' => $request->boolean('ai_assistant_enabled'),
            'ai_response_sound' => $request->boolean('ai_response_sound'),
            'theme' => $data['theme'],
            'language' => $data['language'],
            'notification_sounds' => $request->boolean('notification_sounds'),
        ]);

        $request->user()->forceFill(['notification_preferences' => $preferences])->save();

        return back()->with('buyer_notice', 'System settings updated.');
    }

    public function destroyAccount(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'delete_confirmation' => ['required', 'string', 'in:DELETE'],
        ]);

        $buyer = $request->user();
        $hasActiveOrders = $buyer->orders()
            ->whereNotIn('status', ['COMPLETED', 'CANCELLED', 'RETURNED'])
            ->exists();

        if ($hasActiveOrders) {
            return back()->withErrors(['delete_confirmation' => 'Complete or cancel your active orders before closing this account.']);
        }

        // Orders use restrictive user foreign keys, so deactivation protects
        // historical fulfilment, payments, and reports without deleting them.
        $buyer->forceFill(['status' => User::STATUS_DEACTIVATED])->save();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Your Buyer account has been closed.');
    }

    /** @return array<string, bool|string> */
    private function buyerPreferences(User $user): array
    {
        return array_merge([
            'order_updates' => true,
            'delivery_updates' => true,
            'chat_updates' => true,
            'promotion_updates' => true,
            'ai_assistant_enabled' => true,
            'ai_response_sound' => false,
            'theme' => 'system',
            'language' => 'en',
            'notification_sounds' => true,
        ], (array) $user->notification_preferences);
    }

    public function saveProfile(Request $request, ProfilePhotoService $profilePhotos): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_initial' => ['nullable', 'string', 'max:10'],
            'last_name' => ['required', 'string', 'max:100'],
            'name_extension' => ['nullable', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($request->user()->id)],
            'phone' => ['required', 'string', 'max:30', Rule::unique('users', 'contact_number')->ignore($request->user()->id)],
            'birthday' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $user = $request->user();
        $changes = [
            'first_name' => trim($data['first_name']),
            'middle_initial' => filled($data['middle_initial'] ?? null) ? trim($data['middle_initial']) : null,
            'last_name' => trim($data['last_name']),
            'name_extension' => filled($data['name_extension'] ?? null) ? trim($data['name_extension']) : null,
            'email' => $data['email'],
            'contact_number' => $data['phone'],
            'birthday' => $data['birthday'] ?? $user->birthday,
            'sex' => filled($data['gender'] ?? null) ? strtoupper($data['gender']) : null,
        ];

        $user->update($changes);

        if ($request->hasFile('profile_photo')) {
            $profilePhotos->replace($user, $request->file('profile_photo'));
        }

        return back()->with('buyer_notice', 'Profile updated.');
    }

    public function savePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $request->user()->update(['password' => Hash::make($data['password'])]);

        return back()->with('buyer_notice', 'Password updated.');
    }

    public function saveAddress(Request $request): RedirectResponse
    {
        $data = $this->validatedAddress($request);
        $buyer = $request->user();
        $makeDefault = $request->boolean('is_default') || ! $buyer->addresses()->exists();

        DB::transaction(function () use ($buyer, $data, $makeDefault): void {
            if ($makeDefault) {
                $buyer->addresses()->update(['is_default' => false]);
            }

            $buyer->addresses()->create($this->addressAttributes($data) + [
                'is_default' => $makeDefault,
            ]);
        });

        return back()->with('buyer_notice', 'Address saved.');
    }

    public function updateAddress(Request $request, Address $address): RedirectResponse
    {
        abort_unless((int) $address->user_id === (int) $request->user()->id, 403);

        $data = $this->validatedAddress($request, $address);

        $buyer = $request->user();
        $makeDefault = $request->boolean('is_default');

        DB::transaction(function () use ($buyer, $address, $data, &$makeDefault): void {
            if ($makeDefault) {
                $buyer->addresses()->whereKeyNot($address->id)->update(['is_default' => false]);
            } elseif ($address->is_default) {
                $replacement = $buyer->addresses()
                    ->whereKeyNot($address->id)
                    ->latest()
                    ->first();

                if ($replacement) {
                    $buyer->addresses()->update(['is_default' => false]);
                    $replacement->update(['is_default' => true]);
                } else {
                    $makeDefault = true;
                }
            }

            $address->update($this->addressAttributes($data, $address) + [
                'is_default' => $makeDefault,
            ]);
        });

        return back()->with('buyer_notice', 'Address updated.');
    }

    private function validatedAddress(Request $request, ?Address $existing = null): array
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:50'],
            'recipient_name' => ['required', 'string', 'max:200'],
            'contact_number' => ['required', 'string', 'max:30'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'is_default' => ['nullable', 'boolean'],
        ] + PhilippineAddressValidator::rules() + AddressCoordinateValidator::rules());

        PhilippineAddressValidator::assertValid($data);
        AddressCoordinateValidator::assertValid($data);
        AddressCoordinateValidator::assertFreshForAddress($existing, $data);

        return $data;
    }

    private function addressAttributes(array $data, ?Address $existing = null): array
    {
        return [
            'label' => $data['label'] ?? 'Home',
            'recipient_name' => $data['recipient_name'],
            'contact_number' => $data['contact_number'],
            ...PhilippineAddressValidator::storageAttributes($data),
            'latitude' => $data['latitude'] ?? $existing?->latitude,
            'longitude' => $data['longitude'] ?? $existing?->longitude,
        ];
    }

    public function deleteAddress(Request $request, Address $address): RedirectResponse
    {
        abort_unless((int) $address->user_id === (int) $request->user()->id, 403);
        $buyer = $request->user();

        DB::transaction(function () use ($buyer, $address): void {
            $address->delete();

            if (! $buyer->addresses()->where('is_default', true)->exists()) {
                $buyer->addresses()->latest()->first()?->update(['is_default' => true]);
            }
        });

        return back()->with('buyer_notice', 'Address deleted.');
    }
}
