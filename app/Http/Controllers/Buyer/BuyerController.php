<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Address;
use App\Models\Buyer\BuyerVoucherClaim;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\Review;
use App\Models\Buyer\SellerFollower;
use App\Models\Buyer\WishlistItem;
use App\Models\Admin\Notification;
use App\Models\Communication\Conversation;
use App\Models\Communication\Message;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\Category;
use App\Models\Seller\SellerPromotion;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Services\Account\BuyerEmailChangeOtpService;
use App\Services\Account\BuyerMobileChangeOtpService;
use App\Services\Account\ProfilePhotoService;
use App\Services\Communication\ConversationService;
use App\Services\Communication\ConversationMessageSender;
use App\Services\Communication\MessageAttachmentService;
use App\Services\Communication\ConversationContextPresenter;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\BuyerProductAvailabilityService;
use App\Services\Marketplace\BuyerVoucherClaimService;
use App\Services\Marketplace\CheckoutService;
use App\Services\Marketplace\FlashSaleReminderService;
use App\Services\Marketplace\PlatformCampaignCatalog;
use App\Services\Marketplace\PurchaseLimitService;
use App\Services\Marketplace\ProductCatalogService;
use App\Services\Marketplace\ProductPriceResolver;
use App\Services\Marketplace\RewardPointsService;
use App\Services\Marketplace\SellerPromotionBuyerService;
use App\Services\Marketplace\VoucherRedemptionCounter;
use App\Support\PhilippineAddressValidator;
use App\Support\AddressCoordinateValidator;
use App\Support\BuyerNameRules;
use App\Support\PhilippineMobileNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class BuyerController extends Controller
{
    public function __construct(
        private readonly ProductCatalogService $catalog,
        private readonly CartService $cartService,
        private readonly ConversationService $conversations,
        private readonly PlatformCampaignCatalog $platformCampaigns,
        private readonly PurchaseLimitService $purchaseLimits,
        private readonly SellerPromotionBuyerService $sellerPromotions,
    ) {}

    public function home(Request $request): View
    {
        $products = $this->catalog->paginated($request, 12);
        $bestSellingProducts = $this->catalog->applySort(
            $this->catalog->visibleQuery(),
            'best-selling'
        )->limit(2)->get()
            ->map(fn (Product $product) => $this->catalog->productPayload($product));
        $dealProducts = $this->productDiscountsQuery()
            ->limit(4)
            ->get()
            ->map(fn (Product $product) => $this->dealProductPayload($product, 'product_discount'))
            ->filter()
            ->values();
        $flashDealProducts = $this->activeFlashSaleProductsQuery()
            ->limit(4)
            ->get()
            ->map(fn (Product $product) => $this->dealProductPayload($product, 'flash_sale'))
            ->filter()
            ->values();
        $marketplacePromotions = $this->sellerPromotions->forMarketplace($request->user(), 8);

        return view('buyer.home', [
            'products' => $products,
            'buyerProducts' => $products->getCollection()->map(fn (Product $product) => $this->catalog->productPayload($product)),
            'heroProducts' => $bestSellingProducts,
            'dealProducts' => $dealProducts,
            'flashDealProducts' => $flashDealProducts,
            'bundleOffers' => $marketplacePromotions->where('promotion_type', SellerPromotion::TYPE_BUNDLE)->take(3)->values(),
            'freeGiftOffers' => $marketplacePromotions->where('promotion_type', SellerPromotion::TYPE_BUY_X_GET_Y)->take(3)->values(),
            'homeVoucherPreviews' => $this->homepageVoucherPreviews($request->user()),
            'platformCampaigns' => $this->platformCampaigns->homePreview(),
            'categories' => $this->catalog->categories(),
        ]);
    }

    public function discounts(Request $request): View
    {
        $query = $this->catalog->applyFilters($this->productDiscountsQuery(), $request);
        $products = $this->catalog->applySort($query, $request->query('sort', 'best-selling'))
            ->paginate(24)
            ->withQueryString();
        $discountProducts = $products->getCollection()
            ->map(fn (Product $product) => $this->dealProductPayload($product, 'product_discount'))
            ->filter()
            ->values();

        return view('buyer.discounts', [
            'products' => $products,
            'dealProducts' => $discountProducts,
        ]);
    }

    public function freeGifts(Request $request): View
    {
        return view('buyer.free-gifts', [
            'promotions' => $this->sellerPromotions->forMarketplace(
                $request->user(),
                80,
                SellerPromotion::TYPE_BUY_X_GET_Y,
            ),
        ]);
    }

    public function flashDeals(Request $request, FlashSaleReminderService $reminders): View
    {
        $query = $this->catalog->applyFilters($this->activeFlashSaleProductsQuery(), $request);
        $products = $this->catalog->applySort(
            $query,
            $request->query('sort', 'latest')
        )->paginate(24)->withQueryString();

        $dealProducts = $products->getCollection()
            ->map(fn (Product $product) => $this->dealProductPayload($product, 'flash_sale'))
            ->filter()
            ->values();

        $upcomingQuery = $this->catalog->visibleQuery();
        $upcomingFlashSchemaReady = Schema::hasColumn('products', 'flash_sale_starts_at')
            && Schema::hasColumn('products', 'flash_sale_ends_at')
            && Schema::hasColumn('product_variants', 'flash_sale_price')
            && Schema::hasColumn('product_variants', 'flash_sale_allocation')
            && Schema::hasColumn('product_variants', 'flash_sale_sold');
        if ($upcomingFlashSchemaReady) {
            $upcomingQuery->whereNotNull('flash_sale_starts_at')
                ->where('flash_sale_starts_at', '>', now())
                ->whereHas('sellerProfile', fn (Builder $seller) => $seller->where('vacation_mode', false))
                ->where(function (Builder $query): void {
                    $query->whereNull('flash_sale_ends_at')->orWhereColumn('flash_sale_ends_at', '>', 'flash_sale_starts_at');
                })
                ->whereHas('variants', function (Builder $variants): void {
                    $variants->where('is_active', true)
                        ->where('stock', '>', 0)
                        ->where('price', '>', 0)
                        ->whereNotNull('flash_sale_price')
                        ->where('flash_sale_price', '>', 0)
                        ->whereColumn('flash_sale_price', '<', 'product_variants.price')
                        ->whereColumn('flash_sale_allocation', '>', 'flash_sale_sold')
                        ->when(Schema::hasColumn('products', 'flash_sale_is_paused'), fn (Builder $flash) => $flash->whereRaw('COALESCE(products.flash_sale_is_paused, 0) = 0'));
                });
        } else {
            $upcomingQuery->whereRaw('1 = 0');
        }
        $upcomingProducts = $this->catalog->applySort(
            $this->catalog->applyFilters($upcomingQuery, $request),
            $request->query('sort', 'latest')
        )->limit(24)->get()
            ->map(fn (Product $product) => $this->upcomingDealProductPayload($product))
            ->filter()
            ->values();
        $soldOutProducts = $this->soldOutFlashSaleProductsQuery()->limit(24)->get()
            ->map(fn (Product $product) => $this->soldOutFlashSaleProductPayload($product))
            ->filter()
            ->values();
        $reminderStates = $reminders->reminderStatesFor($request->user(), $upcomingProducts);

        return view('buyer.flash-deals', [
            'products' => $products,
            'dealProducts' => $dealProducts,
            'upcomingDealProducts' => $upcomingProducts,
            'soldOutDealProducts' => $soldOutProducts,
            'flashSaleReminderStates' => $reminderStates,
        ]);
    }

    private function discountedProductsQuery(): Builder
    {
        return $this->catalog->visibleQuery()
            ->whereHas('variants', fn (Builder $variants) => $variants
                ->where('is_active', true)
                ->where('stock', '>', 0)
                ->where('price', '>', 0)
                ->whereRaw(app(ProductPriceResolver::class)->sqlExpression().' < product_variants.price'));
    }

    private function productDiscountsQuery(): Builder
    {
        $now = now()->toDateTimeString();

        return $this->catalog->visibleQuery()
            ->whereHas('sellerProfile', fn (Builder $seller) => $seller->where('vacation_mode', false))
            ->when(Schema::hasColumn('products', 'discounts_enabled'), fn (Builder $query) => $query
                ->where(fn (Builder $enabled) => $enabled->whereNull('discounts_enabled')->orWhere('discounts_enabled', true)))
            ->when(Schema::hasColumn('products', 'discount_starts_at'), fn (Builder $query) => $query
                ->where(fn (Builder $window) => $window->whereNull('discount_starts_at')->orWhere('discount_starts_at', '<=', $now)))
            ->when(Schema::hasColumn('products', 'discount_ends_at'), fn (Builder $query) => $query
                ->where(fn (Builder $window) => $window->whereNull('discount_ends_at')->orWhere('discount_ends_at', '>', $now)))
            ->whereHas('variants', function (Builder $variants) use ($now): void {
                $variants->where('is_active', true)
                    ->where('stock', '>', 0)
                    ->where('price', '>', 0.01)
                    ->where('discount_value', '>', 0)
                    ->where(function (Builder $types): void {
                        $types->whereRaw("LOWER(TRIM(COALESCE(discount_type, ''))) = 'fixed'")
                            ->orWhereRaw("LOWER(TRIM(COALESCE(discount_type, ''))) <> 'none'");
                    });

                if (Schema::hasColumn('product_variants', 'flash_sale_price')
                    && Schema::hasColumn('product_variants', 'flash_sale_allocation')
                    && Schema::hasColumn('product_variants', 'flash_sale_sold')
                    && Schema::hasColumn('products', 'flash_sale_starts_at')
                    && Schema::hasColumn('products', 'flash_sale_ends_at')) {
                    $variants->where(function (Builder $notActiveFlashSale) use ($now): void {
                        $notActiveFlashSale->whereNull('flash_sale_price')
                            ->orWhere('flash_sale_price', '<=', 0)
                            ->orWhereColumn('flash_sale_allocation', '<=', 'flash_sale_sold')
                            ->orWhereHas('product', function (Builder $product) use ($now): void {
                                $product->where(function (Builder $inactive) use ($now): void {
                                    if (Schema::hasColumn('products', 'flash_sale_is_paused')) {
                                        $inactive->where('flash_sale_is_paused', true)->orWhere(function (Builder $window) use ($now): void {
                                            $window->whereNotNull('flash_sale_starts_at')->where('flash_sale_starts_at', '>', $now);
                                        })->orWhere(function (Builder $window) use ($now): void {
                                            $window->whereNotNull('flash_sale_ends_at')->where('flash_sale_ends_at', '<=', $now);
                                        });
                                    } else {
                                        $inactive->where(function (Builder $window) use ($now): void {
                                            $window->whereNotNull('flash_sale_starts_at')->where('flash_sale_starts_at', '>', $now);
                                        })->orWhere(function (Builder $window) use ($now): void {
                                            $window->whereNotNull('flash_sale_ends_at')->where('flash_sale_ends_at', '<=', $now);
                                        });
                                    }
                                });
                            });
                    });
                }
            });
    }

    private function activeFlashSaleProductsQuery(): Builder
    {
        if (! Schema::hasColumn('products', 'flash_sale_starts_at')
            || ! Schema::hasColumn('products', 'flash_sale_ends_at')
            || ! Schema::hasColumn('product_variants', 'flash_sale_price')
            || ! Schema::hasColumn('product_variants', 'flash_sale_allocation')
            || ! Schema::hasColumn('product_variants', 'flash_sale_sold')) {
            return $this->catalog->visibleQuery()->whereRaw('1 = 0');
        }

        $query = $this->catalog->visibleQuery()
            ->whereHas('sellerProfile', fn (Builder $seller) => $seller->where('vacation_mode', false))
            ->where(fn (Builder $window) => $window->whereNull('flash_sale_starts_at')->orWhere('flash_sale_starts_at', '<=', now()))
            ->where(fn (Builder $window) => $window->whereNull('flash_sale_ends_at')->orWhere('flash_sale_ends_at', '>', now()))
            ->whereHas('variants', fn (Builder $variants) => $variants
                ->where('is_active', true)
                ->where('stock', '>', 0)
                ->where('price', '>', 0)
                ->where('flash_sale_price', '>', 0)
                ->whereColumn('flash_sale_price', '<', 'product_variants.price')
                ->whereColumn('flash_sale_allocation', '>', 'flash_sale_sold'));

        if (Schema::hasColumn('products', 'flash_sale_is_paused')) {
            $query->where(fn (Builder $paused) => $paused->whereNull('flash_sale_is_paused')->orWhere('flash_sale_is_paused', false));
        }

        return $query;
    }

    private function soldOutFlashSaleProductsQuery(): Builder
    {
        if (! Schema::hasColumn('products', 'flash_sale_starts_at')
            || ! Schema::hasColumn('products', 'flash_sale_ends_at')
            || ! Schema::hasColumn('product_variants', 'flash_sale_price')
            || ! Schema::hasColumn('product_variants', 'flash_sale_allocation')
            || ! Schema::hasColumn('product_variants', 'flash_sale_sold')) {
            return $this->catalog->visibleQuery()->whereRaw('1 = 0');
        }

        return $this->catalog->visibleQuery()
            ->whereHas('sellerProfile', fn (Builder $seller) => $seller->where('vacation_mode', false))
            ->where(fn (Builder $window) => $window->whereNull('flash_sale_starts_at')->orWhere('flash_sale_starts_at', '<=', now()))
            ->where(fn (Builder $window) => $window->whereNull('flash_sale_ends_at')->orWhere('flash_sale_ends_at', '>', now()))
            ->when(Schema::hasColumn('products', 'flash_sale_is_paused'), fn (Builder $query) => $query
                ->where(fn (Builder $paused) => $paused->whereNull('flash_sale_is_paused')->orWhere('flash_sale_is_paused', false)))
            ->whereHas('variants', fn (Builder $variants) => $variants
                ->where('is_active', true)
                ->where('flash_sale_price', '>', 0)
                ->where('flash_sale_allocation', '>', 0)
                ->whereColumn('flash_sale_price', '<', 'product_variants.price')
                ->where(fn (Builder $soldOut) => $soldOut
                    ->whereColumn('flash_sale_allocation', '<=', 'flash_sale_sold')
                    ->orWhere('stock', '<=', 0)))
            ->whereDoesntHave('variants', fn (Builder $variants) => $variants
                ->where('is_active', true)
                ->where('stock', '>', 0)
                ->where('flash_sale_price', '>', 0)
                ->whereColumn('flash_sale_price', '<', 'product_variants.price')
                ->whereColumn('flash_sale_allocation', '>', 'flash_sale_sold'));
    }

    /** @return array<string, mixed>|null */
    private function soldOutFlashSaleProductPayload(Product $product): ?array
    {
        $product->loadMissing('variants.product', 'sellerProfile', 'category');
        $variant = $product->variants
            ->filter(fn (ProductVariant $variant): bool => $variant->is_active
                && $variant->flash_sale_price !== null
                && (float) $variant->flash_sale_price > 0
                && ($variant->flash_sale_remaining <= 0 || $variant->stock <= 0))
            ->sortBy(fn (ProductVariant $variant): float => (float) $variant->flash_sale_price)
            ->first();

        if (! $variant) {
            return null;
        }

        $payload = $this->catalog->productPayload($product);
        $payload['deal_variant'] = $this->catalog->variantPayload($variant);

        return $payload;
    }

    /** @return array<string, mixed>|null */
    private function upcomingDealProductPayload(Product $product): ?array
    {
        $product->loadMissing(['variants', 'sellerProfile', 'category']);
        $variant = $product->variants
            ->filter(fn (ProductVariant $variant): bool => $variant->is_active && $variant->stock > 0)
            ->map(function (ProductVariant $variant): array {
                $upcomingFlashSale = $variant->flash_sale_status === 'SCHEDULED'
                    && $variant->flash_sale_remaining > 0
                    && $variant->flash_sale_price !== null;
                $price = $upcomingFlashSale
                    ? [
                        'price' => min((float) $variant->price, (float) $variant->flash_sale_price),
                        'discount_amount' => max(0, (float) $variant->price - (float) $variant->flash_sale_price),
                        'source' => 'flash_sale',
                    ]
                    : app(ProductPriceResolver::class)->resolve($variant);

                return [
                    'variant' => $variant,
                    'discount_amount' => $price['discount_amount'],
                    'final_price' => $price['price'],
                    'upcoming_flash_sale' => $upcomingFlashSale,
                ];
            })
            ->filter(fn (array $row): bool => $row['discount_amount'] > 0)
            ->sortBy('final_price')
            ->first();

        if (! $variant) {
            return null;
        }

        $dealVariant = array_merge($this->catalog->variantPayload($variant['variant']), [
            'price' => number_format($variant['final_price'], 2, '.', ''),
            'discount_amount' => number_format($variant['discount_amount'], 2, '.', ''),
            'price_source' => $variant['upcoming_flash_sale'] ? 'flash_sale' : $this->catalog->variantPayload($variant['variant'])['price_source'],
        ]);
        $payload = $this->catalog->productPayload($product);
        $payload['deal_variant'] = $dealVariant;
        $payload['flash_sale_starts_at'] = $product->flash_sale_starts_at?->toIso8601String();
        $payload['flash_sale_reminder_available'] = $product->flash_sale_starts_at?->greaterThan(now()->addMinutes(FlashSaleReminderService::LEAD_MINUTES)) ?? false;

        return $payload;
    }

    /** @return array<string, mixed>|null */
    private function dealProductPayload(Product $product, ?string $source = null): ?array
    {
        $product->loadMissing('variants.product', 'sellerProfile', 'category');
        $dealVariant = $product->variants
            ->filter(fn (ProductVariant $variant): bool => $variant->is_active
                && $variant->stock > 0
                && $variant->price > 0)
            ->map(function (ProductVariant $variant): array {
                return ['variant' => $variant, 'resolved' => app(ProductPriceResolver::class)->resolve($variant)];
            })
            ->filter(fn (array $row): bool => $row['resolved']['discount_amount'] > 0
                && ($source === null || $row['resolved']['source'] === $source))
            ->sortBy(fn (array $row): float => $row['resolved']['price'])
            ->first();

        if (! $dealVariant) {
            return null;
        }

        $payload = $this->catalog->productPayload($product);
        $payload['deal_variant'] = $this->catalog->variantPayload($dealVariant['variant']);

        return $payload;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function homepageVoucherPreviews(User $buyer): Collection
    {
        $sellerVouchers = app(BuyerVoucherClaimService::class)->claimableSellerVouchers($buyer)
            ->reject(fn (Voucher $voucher): bool => (bool) ($voucher->sellerProfile?->vacation_mode ?? false))
            ->take(3)
            ->map(fn (Voucher $voucher): array => [
                'id' => (int) $voucher->id,
                'kind' => 'seller',
                'name' => $voucher->name,
                'source' => $voucher->sellerProfile?->business_name ?? 'Seller',
                'value' => $voucher->discount_type === 'PERCENT'
                    ? number_format((float) $voucher->discount_value, 0).'% off'
                    : '₱'.number_format((float) $voucher->discount_value, 2).' off',
                'minimum' => (float) $voucher->minimum_order_amount,
                'maximum' => $voucher->maximum_discount_amount,
                'expires' => $voucher->ends_at?->format('M j, Y') ?? 'No expiry',
            ]);

        if (! Schema::hasTable('vouchers') || ! Schema::hasColumn('vouchers', 'platform_code_key')) {
            return $sellerVouchers->values();
        }

        $platformVouchers = Voucher::query()
            ->whereNull('seller_profile_id')
            ->whereNotNull('platform_code_key')
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('ends_at')
            ->limit(24)
            ->get();
        $globalUses = VoucherRedemptionCounter::countsFor($platformVouchers);
        $buyerUses = VoucherRedemptionCounter::countsFor($platformVouchers, (int) $buyer->id);
        $selectable = app(\App\Services\Finance\PlatformCampaignBudgetService::class);

        $platformCards = $platformVouchers
            ->filter(fn (Voucher $voucher): bool => ! $voucher->requiresReview()
                && ($voucher->usage_limit === null || $globalUses->get($voucher->id, 0) < $voucher->usage_limit)
                && ($voucher->per_user_limit === null || $buyerUses->get($voucher->id, 0) < $voucher->per_user_limit)
                && $selectable->selectable($voucher))
            ->take(3)
            ->map(fn (Voucher $voucher): array => [
                'id' => (int) $voucher->id,
                'kind' => 'platform',
                'name' => $voucher->name,
                'source' => 'LIKHAE',
                'value' => $voucher->discount_type === 'PERCENT'
                    ? number_format((float) $voucher->discount_value, 0).'% off'
                    : '₱'.number_format((float) $voucher->discount_value, 2).' off',
                'minimum' => (float) $voucher->minimum_order_amount,
                'maximum' => $voucher->maximum_discount_amount,
                'expires' => $voucher->ends_at?->format('M j, Y') ?? 'No expiry',
            ]);

        return $sellerVouchers->concat($platformCards)->take(4)->values();
    }

    public function products(Request $request): View
    {
        [$filters, $catalogFilterValues, $catalogErrors] = $this->validatedCatalogFilters($request);
        $catalogBaseQuery = $this->catalog->visibleQuery()->with('sellerProfile.businessAddress');
        $catalogPriceRangeQuery = $this->filterBuyerCatalog(clone $catalogBaseQuery, [
            'category' => $filters['category'] ?? '',
        ]);
        $catalogPriceRange = $this->buyerPriceRange($catalogPriceRangeQuery);
        $query = $this->filterBuyerCatalog($catalogBaseQuery, $filters);
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

        return view('buyer.products', [
            'products' => $paginator,
            'buyerProducts' => $paginator->getCollection()->map(fn (Product $product) => $this->buyerCatalogPayload($product)),
            'categories' => $this->catalog->categories(),
            'catalogTotal' => $this->catalog->visibleQuery()->count(),
            'catalogLocations' => $this->catalogLocations(),
            'searchSuggestions' => $searchSuggestions,
            'catalogFilterValues' => $catalogFilterValues,
            'catalogQueryParameters' => $this->catalogQueryParameters($filters),
            'catalogErrors' => $catalogErrors,
            'catalogPriceRange' => $catalogPriceRange,
        ]);
    }

    /** @return array{array<string, mixed>, array<string, string>, ViewErrorBag} */
    private function validatedCatalogFilters(Request $request): array
    {
        $query = $request->query();
        if (! array_key_exists('q', $query) && array_key_exists('search', $query)) {
            $query['q'] = $query['search'];
        }
        foreach (['q', 'search', 'category', 'province', 'municipality', 'sort', 'view'] as $key) {
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
            'province' => ['nullable', 'string', 'max:150'],
            'municipality' => ['nullable', 'string', 'max:150'],
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
        $filters['province'] = trim((string) ($filters['province'] ?? ''));
        $filters['municipality'] = trim((string) ($filters['municipality'] ?? ''));
        $filters['sort'] = strtolower(trim((string) ($filters['sort'] ?? 'latest')));
        if ($filters['sort'] === 'featured') {
            $filters['sort'] = 'latest';
        }
        $filters['view'] = strtolower(trim((string) ($filters['view'] ?? 'grid')));

        $filterValues = [];
        foreach (['q', 'category', 'min_price', 'max_price', 'rating', 'province', 'municipality', 'sort', 'view'] as $key) {
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

        $province = (string) ($filters['province'] ?? '');
        $municipality = (string) ($filters['municipality'] ?? '');
        if ($province !== '' || $municipality !== '') {
            $query->whereHas('sellerProfile.businessAddress', function (Builder $address) use ($province, $municipality): void {
                if ($province !== '') {
                    $address->whereRaw('LOWER(TRIM(addresses.province_name)) = ?', [mb_strtolower($province)]);
                }
                if ($municipality !== '') {
                    $address->whereRaw('LOWER(TRIM(addresses.municipality_name)) = ?', [mb_strtolower($municipality)]);
                }
            });
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
        return app(ProductPriceResolver::class)->sqlExpression();
    }

    /** @return array{min: float, max: float, step: float, available: bool} */
    private function buyerPriceRange(Builder $products): array
    {
        $priceExpression = $this->variantSellingPriceSql();
        $perProductPrices = ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereIn('product_variants.product_id', (clone $products)->select('products.id'))
            ->where('product_variants.is_active', true)
            ->groupBy('product_variants.product_id')
            ->selectRaw("MIN({$priceExpression}) AS selling_price");
        $range = DB::query()
            ->fromSub($perProductPrices, 'buyer_product_prices')
            ->selectRaw('MIN(selling_price) AS min_price, MAX(selling_price) AS max_price')
            ->first();

        if ($range?->min_price === null || $range?->max_price === null) {
            return ['min' => 0.0, 'max' => 1.0, 'step' => 0.01, 'available' => false];
        }

        $minimum = (float) $range->min_price;
        $maximum = (float) $range->max_price;
        $span = max(0.01, $maximum - $minimum);
        $step = max(0.01, ceil(($span / 500) * 100) / 100);
        if ($maximum <= $minimum) {
            $maximum = $minimum + max(1.0, $step);
        }

        return ['min' => $minimum, 'max' => $maximum, 'step' => $step, 'available' => true];
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
        foreach (['q', 'category', 'min_price', 'max_price', 'rating', 'province', 'municipality', 'sort', 'view'] as $key) {
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
        $payload['seller']['location'] = $this->publicStoreLocation($product->sellerProfile?->businessAddress);

        return $payload;
    }

    private function catalogLocations()
    {
        return Address::query()
            ->whereHas('sellerProfile.products', fn (Builder $products) => $products->visible())
            ->orderBy('province_name')
            ->orderBy('municipality_name')
            ->get(['province_name', 'municipality_name'])
            ->map(fn (Address $address): array => [
                'province' => trim((string) $address->province_name),
                'municipality' => trim((string) $address->municipality_name),
            ])
            ->filter(fn (array $location): bool => $location['province'] !== '' && $location['municipality'] !== '')
            ->unique(fn (array $location): string => mb_strtolower($location['province'].'|'.$location['municipality']))
            ->values();
    }

    private function publicStoreLocation(?Address $address): string
    {
        return collect([$address?->municipality_name, $address?->province_name])
            ->map(fn ($part): string => trim((string) $part))
            ->filter()
            ->implode(', ');
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

    public function product(Request $request, string $slug): View
    {
        $product = $this->catalog->findVisibleBySlug($slug);
        $productPayload = $this->catalog->productPayload($product, true);
        if ($request->user()) {
            $productPayload['purchase_limit_per_buyer_remaining'] = $this->purchaseLimits
                ->remainingAllowance($request->user(), $product);
        }
        $productPayload['seller']['vacation_mode'] = (bool) $product->sellerProfile?->vacation_mode;
        $productPayload['seller_rating'] = $product->sellerProfile
            ? $this->catalog->sellerRating($product->sellerProfile)
            : 0;

        $productVouchers = collect();
        if ($request->user()) {
            $product->loadMissing('variants');
            $claimService = app(BuyerVoucherClaimService::class);
            $collectedClaims = $claimService->collectedSellerClaims($request->user());
            $collectedVouchers = $collectedClaims->pluck('voucher')->filter()->values();
            $collectedGlobalUses = VoucherRedemptionCounter::countsFor($collectedVouchers);
            $collectedBuyerUses = VoucherRedemptionCounter::countsFor($collectedVouchers, (int) $request->user()->id);
            $availableCollectedVouchers = $collectedClaims
                ->filter(fn (BuyerVoucherClaim $claim): bool => $claim->voucher
                    && $claimService->unavailableReason(
                        $claim->voucher,
                        $request->user(),
                        $collectedGlobalUses->get($claim->voucher->id, 0),
                        $collectedBuyerUses->get($claim->voucher->id, 0),
                    ) === null)
                ->pluck('voucher');
            $ownedSellerVouchers = $claimService->claimableSellerVouchers($request->user())
                ->concat($availableCollectedVouchers);
            $variantIds = $product->variants->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $productVouchers = $ownedSellerVouchers
                ->filter(function (Voucher $voucher) use ($product, $variantIds): bool {
                    if ((int) $voucher->seller_profile_id !== (int) $product->seller_profile_id || $voucher->requiresReview()) {
                        return false;
                    }

                    return match (strtoupper((string) ($voucher->applies_to ?? 'STORE'))) {
                        'PRODUCTS' => $voucher->relationLoaded('eligibleProducts')
                            && $voucher->eligibleProducts->contains('id', $product->id),
                        'VARIANTS' => $voucher->relationLoaded('eligibleVariants')
                            && $voucher->eligibleVariants->contains(fn ($variant): bool => in_array((int) $variant->id, $variantIds, true)),
                        default => true,
                    };
                })
                ->unique('id')
                ->values();
            $claimedIds = $availableCollectedVouchers
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $productVouchers->each(fn (Voucher $voucher) => $voucher->setAttribute('buyer_has_claim', in_array((int) $voucher->id, $claimedIds, true)));
        }

        return view('buyer.product-details', [
            'productModel' => $product,
            'product' => $productPayload,
            'sellerPromotions' => $this->sellerPromotions->forProduct($product, $request->user()),
            'productVouchers' => $productVouchers,
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
        return $this->sellerStore($seller, $request, false);
    }

    public function publicShop(string $seller, Request $request): View
    {
        return $this->sellerStore($seller, $request, true);
    }

    private function sellerStore(string $seller, Request $request, bool $guest): View
    {
        $sellerProfile = $this->catalog->sellerByRouteKey($seller);
        abort_if($guest && (! $sellerProfile->store_visibility || $sellerProfile->user?->status !== User::STATUS_ACTIVE), 404);
        $sellerProfile->loadMissing('businessAddress');
        $sellerProfile->setAttribute('location', $this->publicStoreLocation($sellerProfile->businessAddress));
        $sellerRating = $this->catalog->sellerRating($sellerProfile);
        $sellerProfile->setAttribute('rating', $sellerRating > 0 ? $sellerRating : null);
        $sellerProfile->setAttribute('rating_count', Review::query()
            ->where('status', 'PUBLISHED')
            ->whereNotNull('rating')
            ->whereHas('orderItem.sellerOrder', fn (Builder $query) => $query->where('seller_profile_id', $sellerProfile->id))
            ->count());
        $sellerProfile->setAttribute('joined', $sellerProfile->created_at?->format('M Y'));
        $sellerProfile->setAttribute('followers', SellerFollower::query()
            ->where('seller_profile_id', $sellerProfile->id)
            ->count());
        $sellerProfile->setAttribute('is_following', $request->user()
            ? SellerFollower::query()
                ->where('seller_profile_id', $sellerProfile->id)
                ->where('buyer_user_id', $request->user()->id)
                ->exists()
            : false);

        [$filters, $filterValues, $errors] = $this->validatedStoreFilters($request);
        $baseQuery = $this->catalog->visibleQuery()
            ->where('seller_profile_id', $sellerProfile->id);
        $storePriceRange = $this->buyerPriceRange(clone $baseQuery);
        $storeProductCount = (clone $baseQuery)->count();
        $usedCategoryIds = (clone $baseQuery)
            ->whereNotNull('category_id')
            ->distinct()
            ->pluck('category_id')
            ->map(fn ($id): int => (int) $id)
            ->values();
        $productCategories = Category::query()
            ->whereIn('id', $usedCategoryIds)
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->with('parent')
            ->get();
        $rootCategoryIds = $productCategories
            ->map(fn (Category $category): int => (int) ($category->parent_id ?? $category->id))
            ->unique()
            ->values();
        $storeCategories = Category::query()
            ->whereIn('id', $rootCategoryIds)
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get();
        $selectedCategoryId = (int) ($filters['category_id'] ?? 0);
        $selectedSubcategoryId = (int) ($filters['subcategory_id'] ?? 0);
        $storeSubcategories = $productCategories
            ->filter(fn (Category $category): bool => $category->parent_id !== null
                && ($selectedCategoryId === 0 || (int) $category->parent_id === $selectedCategoryId))
            ->sortBy('name')
            ->values();
        $query = clone $baseQuery;

        $selectedCategoryIsAvailable = $selectedCategoryId === 0
            || $storeCategories->contains(fn (Category $category): bool => (int) $category->id === $selectedCategoryId);
        $selectedSubcategory = $selectedSubcategoryId > 0
            ? $storeSubcategories->first(fn (Category $category): bool => (int) $category->id === $selectedSubcategoryId)
            : null;
        if (! $selectedCategoryIsAvailable || ($selectedSubcategoryId > 0 && ! $selectedSubcategory)) {
            $query->whereRaw('1 = 0');
        } elseif ($selectedSubcategory) {
            $query->where('category_id', $selectedSubcategoryId);
        } elseif ($selectedCategoryId > 0) {
            $categoryProductIds = $productCategories
                ->filter(fn (Category $category): bool => (int) ($category->parent_id ?? $category->id) === $selectedCategoryId)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $query->whereIn('category_id', $categoryProductIds);
        }

        if ($filters['q'] !== '') {
            $escapedSearch = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['q']));
            $pattern = '%'.$escapedSearch.'%';
            $query->where(function (Builder $matches) use ($pattern): void {
                $matches->whereRaw("LOWER(products.name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(products.description) LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        $filtered = $this->filterBuyerCatalog($query, [
            'q' => '',
            'category' => '',
            'min_price' => $filters['min_price'],
            'max_price' => $filters['max_price'],
            'rating' => $filters['rating'],
            'province' => '',
            'municipality' => '',
        ]);
        if ($errors->has('min_price') || $errors->has('max_price')) {
            $filtered->whereRaw('1 = 0');
        }

        $products = $this->sortBuyerCatalog($filtered, $filters['sort'])
            ->paginate(24, ['*'], 'page', $filters['page'])
            ->appends($filters['query_parameters']);

        $storeVouchers = collect();
        $storeClaimableVouchers = collect();
        if (! $guest && $request->user()) {
            $claimedVouchers = Voucher::query()
                ->where('seller_profile_id', $sellerProfile->id)
                ->when(Schema::hasColumn('vouchers', 'platform_code_key'), fn ($vouchers) => $vouchers->whereNull('platform_code_key'))
                ->where('is_active', true)
                ->where(fn ($vouchers) => $vouchers->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($vouchers) => $vouchers->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                ->whereHas('buyerClaims', fn ($claims) => $claims->where('buyer_user_id', $request->user()->id))
                ->with('sellerProfile.user')
                ->orderBy('id')
                ->when(Schema::hasTable('voucher_products'), fn ($vouchers) => $vouchers->with('eligibleProducts:id'))
                ->when(Schema::hasTable('voucher_variants'), fn ($vouchers) => $vouchers->with('eligibleVariants:id,product_id'))
                ->get();
            $globalUses = VoucherRedemptionCounter::countsFor($claimedVouchers);
            $buyerUses = VoucherRedemptionCounter::countsFor($claimedVouchers, (int) $request->user()->id);
            $claimService = app(BuyerVoucherClaimService::class);
            $storeVouchers = $claimedVouchers
                ->filter(fn (Voucher $voucher): bool => $claimService->unavailableReason(
                    $voucher,
                    $request->user(),
                    $globalUses->get($voucher->id, 0),
                    $buyerUses->get($voucher->id, 0),
                ) === null)
                ->values();

            $selectedVoucherId = (int) ($filters['voucher'] ?? 0);
            if ($selectedVoucherId > 0 && $storeVouchers->contains('id', $selectedVoucherId)) {
                $storeVouchers = $storeVouchers
                    ->sortBy(fn (Voucher $voucher): int => (int) $voucher->id === $selectedVoucherId ? 0 : 1)
                    ->values();
                $storeVouchers->first()->setAttribute('selected_for_store', true);
            }

            $storeClaimableVouchers = $claimService->claimableSellerVouchers($request->user())
                ->where('seller_profile_id', $sellerProfile->id)
                ->values();
        } elseif ($guest) {
            $publicVouchersQuery = Voucher::query()
                ->where('seller_profile_id', $sellerProfile->id)
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                ->where(fn ($query) => $query->whereNull('usage_limit')->orWhereRaw(
                    'vouchers.usage_limit > (SELECT COUNT(*) FROM seller_orders WHERE seller_orders.voucher_id = vouchers.id AND seller_orders.status <> ?)',
                    ['CANCELLED'],
                ));
            if (Schema::hasColumn('vouchers', 'visibility')) {
                $publicVouchersQuery->where('visibility', 'PUBLIC');
            }
            if (Schema::hasColumn('vouchers', 'platform_code_key')) {
                $publicVouchersQuery->whereNull('platform_code_key');
            }
            if (Schema::hasColumn('vouchers', 'requires_review')) {
                $publicVouchersQuery->where('requires_review', false);
            }
            if (Schema::hasColumn('vouchers', 'applies_to')) {
                $publicVouchersQuery->whereIn('applies_to', ['STORE', 'store']);
            }
            $storeVouchers = $publicVouchersQuery->orderBy('id')->limit(10)->get()
                ->filter(fn (Voucher $voucher): bool => ! $voucher->requiresReview())
                ->values();
        }

        return view($guest ? 'guest.store' : 'buyer.store', [
            'seller' => $sellerProfile,
            'products' => $products,
            'buyerProducts' => $products->getCollection()->map(fn (Product $product) => $this->catalog->productPayload($product)),
            'categories' => $this->catalog->categories(),
            'storeProductCount' => $storeProductCount,
            'storeFilterValues' => $filterValues,
            'storeErrors' => $errors,
            'storePriceRange' => $storePriceRange,
            'storeCategories' => $storeCategories,
            'storeSubcategories' => $storeSubcategories,
            'storeVouchers' => $storeVouchers,
            'storeClaimableVouchers' => $storeClaimableVouchers,
            'sellerPromotions' => $this->sellerPromotions->forSeller((int) $sellerProfile->id, $request->user()),
            'guest' => $guest,
        ]);
    }

    public function followShop(Request $request, string $seller): RedirectResponse|JsonResponse
    {
        $sellerProfile = $this->catalog->sellerByRouteKey($seller);
        $follower = DB::transaction(function () use ($request, $sellerProfile): SellerFollower {
            User::query()->whereKey($request->user()->id)->lockForUpdate()->first();

            return SellerFollower::query()->firstOrCreate([
                'buyer_user_id' => $request->user()->id,
                'seller_profile_id' => $sellerProfile->id,
            ]);
        });

        if ($request->expectsJson()) {
            return response()->json([
                'following' => true,
                'followers' => SellerFollower::query()->where('seller_profile_id', $sellerProfile->id)->count(),
            ], $follower->wasRecentlyCreated ? 201 : 200);
        }

        return back()->with('buyer_notice', 'You are now following '.$sellerProfile->business_name.'.');
    }

    public function unfollowShop(Request $request, string $seller): RedirectResponse|JsonResponse
    {
        $sellerProfile = $this->catalog->sellerByRouteKey($seller);
        DB::transaction(function () use ($request, $sellerProfile): void {
            User::query()->whereKey($request->user()->id)->lockForUpdate()->first();
            SellerFollower::query()
                ->where('buyer_user_id', $request->user()->id)
                ->where('seller_profile_id', $sellerProfile->id)
                ->delete();
        });

        $followers = SellerFollower::query()->where('seller_profile_id', $sellerProfile->id)->count();
        if ($request->expectsJson()) {
            return response()->json(['following' => false, 'followers' => $followers]);
        }

        return back()->with('buyer_notice', 'You are no longer following '.$sellerProfile->business_name.'.');
    }

    /** @return array{array<string, mixed>, array<string, string>, ViewErrorBag} */
    private function validatedStoreFilters(Request $request): array
    {
        $query = $request->query();
        foreach (['q', 'sort'] as $key) {
            if (isset($query[$key]) && is_string($query[$key])) {
                $query[$key] = trim($query[$key]);
            }
        }
        if (isset($query['sort']) && is_string($query['sort'])) {
            $query['sort'] = strtolower($query['sort']);
        }

        $validator = Validator::make($query, [
            'q' => ['nullable', 'string', 'max:120'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'rating' => ['nullable', 'numeric', 'between:0,5'],
            'sort' => ['nullable', 'string', Rule::in(['latest', 'price-low', 'price-high', 'best-rated', 'best-selling'])],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'subcategory_id' => ['nullable', 'integer', 'min:1'],
            'voucher' => ['nullable', 'integer', 'min:1'],
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
        $valid = $hasErrors ? $validator->valid() : $validator->validated();
        $filters = [
            'q' => trim((string) ($valid['q'] ?? '')),
            'min_price' => $valid['min_price'] ?? null,
            'max_price' => $valid['max_price'] ?? null,
            'rating' => (float) ($valid['rating'] ?? 0),
            'sort' => strtolower(trim((string) ($valid['sort'] ?? 'latest'))),
            'category_id' => max(0, (int) ($valid['category_id'] ?? 0)),
            'subcategory_id' => max(0, (int) ($valid['subcategory_id'] ?? 0)),
            'voucher' => max(0, (int) ($valid['voucher'] ?? 0)),
            'page' => max(1, (int) ($valid['page'] ?? 1)),
        ];
        $filterValues = [];
        foreach (['q', 'min_price', 'max_price', 'rating', 'sort', 'category_id', 'subcategory_id', 'voucher'] as $key) {
            $value = $query[$key] ?? '';
            $filterValues[$key] = is_scalar($value) ? trim((string) $value) : '';
        }
        $queryParameters = array_filter([
            'q' => $filters['q'],
            'min_price' => $filterValues['min_price'],
            'max_price' => $filterValues['max_price'],
            'rating' => $filters['rating'] > 0 ? (string) $filters['rating'] : '',
            'sort' => $filters['sort'] !== 'latest' ? $filters['sort'] : '',
            'category_id' => $filters['category_id'] > 0 ? (string) $filters['category_id'] : '',
            'subcategory_id' => $filters['subcategory_id'] > 0 ? (string) $filters['subcategory_id'] : '',
            'voucher' => $filters['voucher'] > 0 ? (string) $filters['voucher'] : '',
        ], fn ($value) => $value !== '' && $value !== null);
        $filters['query_parameters'] = $queryParameters;

        $errors = new ViewErrorBag;
        $errors->put('default', $validator->errors());

        return [$filters, $filterValues, $errors];
    }

    public function cart(Request $request): View
    {
        $buyer = $request->user();
        $cartItems = $this->cartService->activeCart($buyer)
            ->items()
            ->with([
                'productVariant.optionValues.option',
                'productVariant.product' => fn ($query) => $query->withTrashed()->with([
                    'images',
                    'category',
                    'sellerProfile.user',
                ]),
                'productVariant.productImage',
            ])
            ->latest()
            ->get();

        $purchaseLimitExceededProducts = $this->purchaseLimitExceededProducts($cartItems, $buyer);

        $cartItems->each(function (CartItem $item) use ($purchaseLimitExceededProducts): void {
            $availability = $this->cartItemAvailability($item);
            if ($availability['code'] === 'INSUFFICIENT_STOCK') {
                $availability['message'] = 'Only '.max(0, (int) ($item->productVariant?->stock ?? 0)).' available.';
            }
            $item->setAttribute('cart_availability', $availability);
            $productId = (int) ($item->productVariant?->product?->id ?? 0);
            $item->setAttribute('purchase_limit_exceeded', isset($purchaseLimitExceededProducts[$productId]));
        });

        $availableItems = $cartItems->filter(
            fn (CartItem $item): bool => (bool) data_get($item, 'cart_availability.available')
                && ! (bool) data_get($item, 'purchase_limit_exceeded')
        );
        $checkoutService = app(CheckoutService::class);
        $availableVouchers = $checkoutService->availableVouchers($availableItems, $buyer)
            ->map(function ($vouchers) use ($checkoutService, $availableItems, $buyer) {
                return $vouchers->filter(function (Voucher $voucher) use ($checkoutService, $availableItems, $buyer): bool {
                    try {
                        $checkoutService->preview($availableItems, $voucher->code, $buyer);

                        return true;
                    } catch (ValidationException) {
                        return false;
                    }
                })->values();
            })
            ->filter(fn ($vouchers) => $vouchers->isNotEmpty());
        $cartPromotions = $cartItems
            ->map(fn (CartItem $item): int => (int) ($item->productVariant?->product?->seller_profile_id ?? 0))
            ->filter()
            ->unique()
            ->flatMap(fn (int $sellerId) => $this->sellerPromotions->forSeller($sellerId, $request->user()))
            ->unique('id')
            ->values();

        return view('buyer.cart', [
            'cartItems' => $cartItems,
            'availableVouchers' => $availableVouchers,
            'cartPromotions' => $cartPromotions,
            'defaultAddress' => $buyer->addresses()->orderByDesc('is_default')->latest()->first(),
        ]);
    }

    public function cartPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cart_item_ids' => ['present', 'array', 'max:200'],
            'cart_item_ids.*' => ['required', 'integer', 'distinct'],
            'voucher_code' => ['nullable', 'string', 'max:80'],
            'seller_profile_id' => ['nullable', 'integer'],
            'platform_voucher_code' => ['nullable', 'string', 'max:80'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['cart_item_ids'])));
        if ($ids === []) {
            return response()->json([
                'success' => true,
                'subtotal' => 0,
                'discount_total' => 0,
                'shipping_total' => null,
                'shipping_estimate_available' => false,
                'estimated_total' => 0,
                'voucher_applied' => false,
                'voucher_message' => null,
                'items' => [],
                'message' => null,
            ]);
        }

        $items = $this->cartService->selectedItems($request->user(), $ids);
        abort_unless($items->count() === count($ids), 403);

        $purchaseLimitExceededProducts = $this->purchaseLimitExceededProducts($items, $request->user());

        if ($items->isEmpty()) {
            return response()->json([
                'success' => true,
                'subtotal' => 0,
                'discount_total' => 0,
                'shipping_total' => null,
                'shipping_estimate_available' => false,
                'estimated_total' => 0,
                'voucher_applied' => false,
                'voucher_message' => null,
                'items' => [],
                'message' => null,
            ]);
        }

        $unavailableItem = $items->first(fn (CartItem $item): bool => ! $this->cartItemAvailability($item)['available']);
        if ($unavailableItem || $purchaseLimitExceededProducts !== []) {
            $message = $purchaseLimitExceededProducts !== []
                ? 'One or more selected products exceed the purchase limit. Reduce the cart quantity before checkout.'
                : $this->cartItemAvailability($unavailableItem)['message'];

            return response()->json([
                'success' => false,
                'message' => $message,
                'subtotal' => 0,
                'discount_total' => 0,
                'shipping_total' => null,
                'shipping_estimate_available' => false,
                'estimated_total' => 0,
                'voucher_applied' => false,
                'voucher_message' => null,
            ], 422);
        }

        $sellerVoucherCode = mb_strtoupper(trim((string) ($data['voucher_code'] ?? '')));
        $requestedSellerId = (int) ($data['seller_profile_id'] ?? 0);
        $platformVoucherCode = mb_strtoupper(trim((string) ($data['platform_voucher_code'] ?? '')));
        $checkoutService = app(CheckoutService::class);
        $buyer = $request->user();
        $defaultAddress = $buyer->addresses()->orderByDesc('is_default')->latest()->first();
        $automaticRouting = $checkoutService->automaticRoutingReady();
        $shippingEstimateAvailable = $automaticRouting
            ? $defaultAddress !== null
            : $checkoutService->availableLogisticsProviders()->isNotEmpty();

        try {
            $platformVoucherCodes = collect($checkoutService->availableVouchers($items, $buyer)->get('platform', []))
                ->pluck('code')
                ->map(fn ($code): string => mb_strtoupper((string) $code));

            if ($platformVoucherCode !== '' && ! $platformVoucherCodes->contains($platformVoucherCode)) {
                throw ValidationException::withMessages([
                    'platform_voucher_code' => 'That LIKHAE voucher is no longer available. Choose another voucher.',
                ]);
            }

            $sellerSelection = [];
            if ($sellerVoucherCode !== '') {
                if ($platformVoucherCodes->contains($sellerVoucherCode)) {
                    throw ValidationException::withMessages([
                        'voucher_code' => 'Choose LIKHAE vouchers from the platform voucher picker.',
                    ]);
                }

                $sellerVoucherMatches = $checkoutService->availableVouchers($items, $buyer)
                    ->except(['platform', 'shipping'])
                    ->flatMap(fn ($vouchers, $sellerId) => collect($vouchers)
                        ->filter(fn (Voucher $voucher): bool => mb_strtoupper((string) $voucher->code) === $sellerVoucherCode)
                        ->map(fn (Voucher $voucher): int => (int) $sellerId));

                if ($requestedSellerId > 0 && ! $sellerVoucherMatches->contains($requestedSellerId)) {
                    throw ValidationException::withMessages([
                        'voucher_code' => 'That Seller voucher is not available for the selected items at this shop.',
                    ]);
                }

                if ($requestedSellerId === 0 && $sellerVoucherMatches->count() !== 1) {
                    throw ValidationException::withMessages([
                        'voucher_code' => $sellerVoucherMatches->isEmpty()
                            ? 'That Seller voucher is not available for the selected items.'
                            : 'This code is available at more than one selected shop. Choose it from the correct Seller section.',
                    ]);
                }

                $sellerSelection[$requestedSellerId > 0 ? $requestedSellerId : $sellerVoucherMatches->first()] = $sellerVoucherCode;
            }

            $preview = $checkoutService->preview(
                $items,
                [
                    'seller' => $sellerSelection,
                    'platform' => $platformVoucherCode,
                    'shipping' => '',
                ],
                $buyer,
                null,
                $automaticRouting ? $defaultAddress : null,
            );
        } catch (ValidationException $exception) {
            $exceptionErrors = $exception->errors();
            $failedVoucherCode = array_key_exists('platform_voucher_code', $exceptionErrors)
                ? $platformVoucherCode
                : $sellerVoucherCode;
            $hasVoucher = $sellerVoucherCode !== '' || $platformVoucherCode !== '';
            $voucherMessage = $hasVoucher
                ? $this->cartVoucherErrorMessage($exception, $items, $failedVoucherCode, $buyer)
                : (collect($exception->errors())->flatten()->filter()->first()
                    ?: 'Cart totals could not be refreshed. Review your selected items and try again.');
            $fallbackPreview = null;
            try {
                $fallbackPreview = $checkoutService->preview(
                    $items,
                    '',
                    $buyer,
                    null,
                    $automaticRouting ? $defaultAddress : null,
                );
            } catch (ValidationException) {
                // Keep the subtotal visible while shipping remains unknown.
            }
            $fallbackSubtotal = $fallbackPreview
                ? (float) $fallbackPreview['subtotal']
                : $items->sum(fn (CartItem $item): float => (float) ($item->productVariant?->final_price ?? 0) * (int) $item->quantity);
            $fallbackShippingAvailable = $shippingEstimateAvailable && $fallbackPreview !== null;
            $fallbackShipping = $fallbackPreview ? max(0, (float) $fallbackPreview['shipping_total']) : 0.0;

            return response()->json([
                'success' => false,
                'subtotal' => round($fallbackSubtotal, 2),
                'discount_total' => 0,
                'shipping_total' => $fallbackShippingAvailable ? round($fallbackShipping, 2) : null,
                'shipping_estimate_available' => $fallbackShippingAvailable,
                'estimated_total' => round(max(0, $fallbackSubtotal + ($fallbackShippingAvailable ? $fallbackShipping : 0)), 2),
                'voucher_applied' => false,
                'voucher_message' => $voucherMessage,
                'items' => $this->cartPreviewItemPrices($items),
                'message' => $hasVoucher ? null : $voucherMessage,
            ]);
        }

        $subtotal = (float) $preview['subtotal'];
        $discount = min($subtotal, max(0, (float) $preview['discount_total']));
        $shipping = max(0, (float) $preview['shipping_total']);

        return response()->json([
            'success' => true,
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discount, 2),
            'shipping_total' => $shippingEstimateAvailable ? round($shipping, 2) : null,
            'shipping_estimate_available' => $shippingEstimateAvailable,
            'estimated_total' => round(max(0, $subtotal - $discount + ($shippingEstimateAvailable ? $shipping : 0)), 2),
            'voucher_applied' => $sellerVoucherCode !== '' || $platformVoucherCode !== '',
            'seller_voucher_applied' => $sellerVoucherCode !== '',
            'seller_voucher_code' => $sellerVoucherCode,
            'seller_voucher_id' => $sellerSelection === [] ? null : (int) array_key_first($sellerSelection),
            'platform_voucher_applied' => $platformVoucherCode !== '',
            'platform_voucher_code' => $platformVoucherCode,
            'voucher_message' => $sellerVoucherCode !== '' && $platformVoucherCode !== ''
                ? 'Seller and LIKHAE vouchers are both applied to eligible items.'
                : (($sellerVoucherCode !== '' || $platformVoucherCode !== '') ? 'Voucher applied to eligible selected items.' : null),
            'items' => $this->cartPreviewItemPrices($items),
            'message' => null,
        ]);
    }

    /** @return array<int, true> */
    private function purchaseLimitExceededProducts(Collection $items, User $buyer): array
    {
        return $items
            ->filter(fn (CartItem $item): bool => $item->productVariant?->product !== null
                && $item->productVariant->product->purchase_limit_per_buyer !== null)
            ->groupBy(fn (CartItem $item): int => (int) $item->productVariant->product->id)
            ->filter(function (Collection $productItems) use ($buyer): bool {
                /** @var Product $product */
                $product = $productItems->first()->productVariant->product;
                $cartQuantity = (int) $productItems->sum(fn (CartItem $item): int => (int) $item->quantity);

                return ! $this->purchaseLimits->cartWithinLimit($buyer, $product, $cartQuantity);
            })
            ->keys()
            ->mapWithKeys(fn ($productId): array => [(int) $productId => true])
            ->all();
    }

    /** @return array{available: bool, restricted: bool, code: string, message: string} */
    private function cartItemAvailability(CartItem $item): array
    {
        $item->loadMissing(['productVariant.product.sellerProfile.user']);
        $variant = $item->productVariant;
        $product = $variant?->product;
        $seller = $product?->sellerProfile;
        $sellerUser = $seller?->user;

        return app(BuyerProductAvailabilityService::class)->inspect(
            $variant,
            $product,
            $seller,
            $sellerUser,
            (int) $item->quantity,
        );
    }

    /** @return array<int, array{id: int, price: float, original_price: float, stock: int}> */
    private function cartPreviewItemPrices($items): array
    {
        return $items->map(fn (CartItem $item): array => [
            'id' => (int) $item->id,
            'price' => max(0, (float) ($item->productVariant?->final_price ?? 0)),
            'original_price' => max(0, (float) ($item->productVariant?->price ?? 0)),
            'stock' => max(0, (int) ($item->productVariant?->stock ?? 0)),
        ])->values()->all();
    }

    private function cartVoucherErrorMessage(ValidationException $exception, $items, string $code, User $buyer): string
    {
        $original = (string) (collect($exception->errors())->flatten()->filter()->first() ?? '');
        $normalized = mb_strtolower($original);

        if (str_contains($normalized, 'not active yet')) {
            return 'This voucher is not available yet.';
        }
        if (str_contains($normalized, 'expired')) {
            return 'This voucher has expired.';
        }
        if (str_contains($normalized, 'redemption limit') || str_contains($normalized, 'usage limit')) {
            return 'This voucher has reached its usage limit.';
        }
        if (str_contains($normalized, 'maximum number of times')) {
            return 'You have reached the usage limit for this voucher.';
        }
        if (str_contains($normalized, 'add php ')) {
            $voucher = Voucher::query()->whereRaw('UPPER(code) = ?', [mb_strtoupper($code)])->get();
            $sellerIds = $voucher->pluck('seller_profile_id')->filter()->unique();
            $eligibleItems = $sellerIds->count() === 1
                ? $items->filter(fn (CartItem $item): bool => (int) ($item->productVariant?->product?->seller_profile_id ?? 0) === (int) $sellerIds->first())
                : $items;
            $eligibleSubtotal = $eligibleItems->sum(fn (CartItem $item): float => (float) ($item->productVariant?->final_price ?? 0) * (int) $item->quantity);
            preg_match('/add php ([0-9,]+(?:\.[0-9]{1,2})?) more/i', $original, $matches);
            $remaining = isset($matches[1]) ? (float) str_replace(',', '', $matches[1]) : 0.0;

            return 'This voucher requires a minimum eligible purchase of ₱'.number_format($eligibleSubtotal + $remaining, 2).'.';
        }
        if (str_contains($normalized, 'does not apply') || str_contains($normalized, 'do not apply') || str_contains($normalized, 'selected cart items')) {
            return 'This voucher cannot be applied to the selected products.';
        }
        if (str_contains($normalized, 'we could not find') || str_contains($normalized, 'invalid or no longer available')) {
            return 'This voucher code is invalid.';
        }
        if (str_contains($normalized, 'that seller voucher is not available for the selected items')) {
            $sellerIds = $items
                ->map(fn (CartItem $item): int => (int) ($item->productVariant?->product?->seller_profile_id ?? 0))
                ->filter(fn (int $sellerId): bool => $sellerId > 0)
                ->unique()
                ->values();
            $matchingVouchers = Voucher::query()
                ->whereRaw('UPPER(code) = ?', [mb_strtoupper(trim($code))])
                ->whereIn('seller_profile_id', $sellerIds)
                ->whereHas('buyerClaims', fn ($query) => $query->where('buyer_user_id', $buyer->id))
                ->with('sellerProfile.user')
                ->get();

            if ($matchingVouchers->count() === 1) {
                $voucher = $matchingVouchers->first();
                $claimService = app(BuyerVoucherClaimService::class);
                $globalUses = (int) VoucherRedemptionCounter::countsFor($matchingVouchers)->get($voucher->id, 0);
                $buyerUses = (int) VoucherRedemptionCounter::countsFor($matchingVouchers, (int) $buyer->id)->get($voucher->id, 0);
                $unavailableReason = $claimService->unavailableReason($voucher, $buyer, $globalUses, $buyerUses);

                if ($unavailableReason !== null) {
                    return $unavailableReason;
                }
            }

            return 'This voucher code is invalid.';
        }

        return $original !== '' ? $original : 'This voucher code is invalid.';
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

    public function updateCart(Request $request, CartItem $item): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $this->cartService->ensureOwner($request->user(), $item);
        $item->loadMissing('productVariant');
        if (! $item->productVariant) {
            throw ValidationException::withMessages([
                'quantity' => 'This cart item is no longer available. Remove it from your cart.',
            ]);
        }

        $item = $this->cartService->update($request->user(), $item, (int) $data['quantity']);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Cart quantity updated.',
                'quantity' => (int) $item->quantity,
            ]);
        }

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
                'avatar' => $other ? $this->conversations->avatarUrlFor($other) : asset('images/defaults/profile-avatar.svg'),
                'other_last_read_at' => $other?->pivot?->last_read_at
                    ? \Illuminate\Support\Carbon::parse($other->pivot->last_read_at)->toIso8601String()
                    : '',
                'last_message' => $this->conversations->messagePreviewFor($conversation->latestMessage),
                'time' => $conversation->latestMessage?->sent_at?->diffForHumans() ?? '',
                'unread' => 0,
                'messages' => [],
            ];
        })->filter(fn (array $row): bool => filled($row['id']))->values();

        $selectedSlug = (string) $request->query('seller', '');
        $active = $rows->firstWhere('slug', $selectedSlug) ?: $rows->first();
        $activeConversation = $active
            ? $conversations->firstWhere('id', (int) $active['conversation_id'])
            : null;
        $activeRecipient = $activeConversation?->participants
            ?->first(fn ($participant) => (int) $participant->id !== (int) $request->user()->id);
        $activeRecipientOnline = $activeRecipient
            && (bool) data_get($activeRecipient->notification_preferences, 'show_online_status', true)
            && Cache::store('database')->has('message-presence:user:'.$activeRecipient->id);
        if ($activeConversation) {
            $this->conversations->markRead($activeConversation, $request->user());
        }
        $chatMessages = $activeConversation
            ? $this->conversations->messagesFor($request->user(), (int) $activeConversation->id)
            : collect();
        $referenceProduct = null;
        $productSlug = trim((string) $request->query('product', ''));
        if ($active && $productSlug !== '') {
            $product = Product::query()
                ->visible()
                ->with($this->catalog->productRelations())
                ->where('slug', $productSlug)
                ->whereHas('sellerProfile', fn (Builder $seller) => $seller->where('user_id', $active['id']))
                ->first();
            if ($product) {
                $referenceProduct = $this->catalog->productPayload($product);
            }
        }

        $smartHelpBootstrap = null;
        if ($activeConversation
            && $activeConversation->participants->count() === 2
            && (($active['is_seller'] ?? false) || ($active['is_support'] ?? false))) {
            $smartHelpBootstrap = app(\App\Services\Communication\SmartMessagingService::class)->bootstrapForBuyer(
                $request->user(),
                $activeConversation,
                isset($referenceProduct['id']) ? (int) $referenceProduct['id'] : null,
                $request->integer('variant_id') ?: null,
            );
        }

        return view('buyer.messages', [
            'buyerProducts' => $referenceProduct ? collect([$referenceProduct]) : collect(),
            'conversationRows' => $rows,
            'dbActiveSeller' => null,
            'activeRecipientOnline' => $activeRecipientOnline,
            'chatMessages' => $chatMessages,
            'messageAttachmentsReady' => app(MessageAttachmentService::class)->available(),
            'messageContext' => app(ConversationContextPresenter::class)->forUser($request->user(), $activeConversation),
            'smartHelpBootstrap' => $smartHelpBootstrap,
        ]);
    }

    public function messageStream(Request $request): JsonResponse
    {
        $conversationId = $request->integer('conversation');
        if ($conversationId <= 0) {
            $recipientId = $request->integer('seller_id');
            $conversationId = (int) Conversation::query()
                ->whereHas('participants', fn ($query) => $query->where('users.id', $request->user()->id))
                ->whereHas('participants', fn ($query) => $query->where('users.id', $recipientId))
                ->value('id');
        }

        $messages = $conversationId > 0
            ? $this->conversations->streamPayload($request->user(), $conversationId, $request->integer('after_id') ?: null)
            : collect();

        return response()->json([
            'success' => true,
            'messages' => $messages,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function sendMessage(Request $request, ConversationMessageSender $sender): JsonResponse|RedirectResponse
    {
        $recipientData = $request->validate([
            'recipient_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);
        $data = $recipientData + $sender->validate($request);

        $recipient = User::query()
            ->whereKey((int) $data['recipient_id'])
            ->where('status', User::STATUS_ACTIVE)
            ->firstOrFail();

        $isActiveSeller = $recipient->sellerProfile?->status === 'ACTIVE';
        abort_unless($isActiveSeller || $recipient->isAccountType(User::TYPE_ADMIN), 404);

        $messages = $sender->sendMany($request, $data, $recipient, $this->conversations);

        if ($request->expectsJson()) {
            $messagePayloads = array_map(fn (Message $message): array => [
                ...$this->conversations->messagePayload($message),
                'from_me' => true,
            ], $messages);

            return response()->json([
                'success' => true,
                'message' => $messagePayloads[0] ?? null,
                'messages' => $messagePayloads,
            ]);
        }

        return back()->with('buyer_notice', 'Message sent.');
    }

    public function wishlist(Request $request): View
    {
        $wishlistProducts = WishlistItem::query()
            ->where('user_id', $request->user()->id)
            ->with(['product' => fn ($query) => $query->withTrashed()->with($this->catalog->productRelations())->withAvg('reviews', 'rating')->withCount('reviews')])
            ->latest()
            ->get()
            ->map(function (WishlistItem $item) {
                $product = $item->product;
                if (! $product) {
                    return [
                        'id' => $item->product_id,
                        'name' => 'Unavailable product',
                        'status' => 'UNAVAILABLE',
                        'rating' => 0,
                        'reviews' => 0,
                        'sold' => 0,
                        'min_price' => null,
                        'stock' => 0,
                        'primary_image' => null,
                        'category' => null,
                        'seller' => null,
                        'images' => collect(),
                        'variants' => collect(),
                        'unavailable' => true,
                        'availability_restricted' => true,
                        'wishlist_added_at' => $item->created_at,
                    ];
                }

                $seller = $product->sellerProfile;
                $activeVariants = $product->variants->where('is_active', true);
                $availabilityRestricted = $product->trashed()
                    || $product->status !== 'ACTIVE'
                    || $seller?->status !== 'ACTIVE'
                    || $seller?->user?->status !== User::STATUS_ACTIVE
                    || $activeVariants->isEmpty();

                if ($availabilityRestricted) {
                    return [
                        'id' => $item->product_id,
                        'name' => 'Unavailable product',
                        'status' => 'UNAVAILABLE',
                        'rating' => 0,
                        'reviews' => 0,
                        'sold' => 0,
                        'min_price' => null,
                        'stock' => 0,
                        'primary_image' => null,
                        'category' => null,
                        'seller' => null,
                        'images' => collect(),
                        'variants' => collect(),
                        'unavailable' => true,
                        'availability_restricted' => true,
                        'wishlist_added_at' => $item->created_at,
                    ];
                }

                $payload = $this->catalog->productPayload($product);
                $sellerOnVacation = (bool) $seller?->vacation_mode;
                $payload['seller']['vacation_mode'] = $sellerOnVacation;
                $payload['unavailable'] = $sellerOnVacation || (int) $activeVariants->sum('stock') < 1;
                $payload['availability_restricted'] = false;
                $payload['wishlist_added_at'] = $item->created_at;

                return $payload;
            });

        return view('buyer.wishlist', compact('wishlistProducts'));
    }

    public function toggleWishlist(Request $request, string $productId): RedirectResponse|JsonResponse
    {
        abort_unless(ctype_digit($productId), 404);
        $data = $request->validate(['saved' => ['sometimes', 'boolean']]);
        $buyerId = (int) $request->user()->id;
        $existing = WishlistItem::query()
            ->where('user_id', $buyerId)
            ->where('product_id', (int) $productId)
            ->first();

        if (array_key_exists('saved', $data)) {
            $saved = filter_var($data['saved'], FILTER_VALIDATE_BOOLEAN);
            if ($saved) {
                Product::query()->findOrFail((int) $productId);
                WishlistItem::query()->firstOrCreate([
                    'user_id' => $buyerId,
                    'product_id' => (int) $productId,
                ]);
            } else {
                WishlistItem::query()
                    ->where('user_id', $buyerId)
                    ->where('product_id', (int) $productId)
                    ->delete();
            }
        } else {
            if ($existing) {
                $existing->delete();
                $saved = false;
            } else {
                Product::query()->findOrFail((int) $productId);
                WishlistItem::query()->firstOrCreate([
                    'user_id' => $buyerId,
                    'product_id' => (int) $productId,
                ]);
                $saved = true;
            }
        }

        $message = $saved ? 'Product saved to your wishlist.' : 'Product removed from your wishlist.';
        if ($request->expectsJson()) {
            return response()->json([
                'product_id' => (int) $productId,
                'saved' => $saved,
                'message' => $message,
            ]);
        }

        return back()->with('buyer_notice', $message);
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

        $claimService = app(BuyerVoucherClaimService::class);
        $claimableSellerVouchers = $claimService->claimableSellerVouchers($buyer);
        $collectedSellerClaims = $claimService->collectedSellerClaims($buyer);
        $collectedSellerVouchers = $collectedSellerClaims
            ->pluck('voucher')
            ->filter()
            ->values();
        $collectedGlobalUses = VoucherRedemptionCounter::countsFor($collectedSellerVouchers);
        $collectedBuyerUses = VoucherRedemptionCounter::countsFor($collectedSellerVouchers, (int) $buyer->id);

        $sellerVoucherCard = function (Voucher $voucher, bool $collected, ?string $unavailableReason = null): array {
            return [
                'id' => (int) $voucher->id,
                'icon' => $voucher->requiresReview() ? 'Review' : ($voucher->discount_type === 'PERCENT' ? number_format((float) $voucher->discount_value, 0).'%' : '₱'),
                'status' => $voucher->sellerProfile?->business_name ?? 'Seller',
                'value' => $voucher->requiresReview() ? 'Voucher under review'
                    : ($voucher->discount_type === 'PERCENT'
                    ? number_format((float) $voucher->discount_value, 0).'% off'
                    : '₱'.number_format((float) $voucher->discount_value, 2).' off'),
                'condition' => (float) $voucher->minimum_order_amount > 0
                    ? 'Minimum spend ₱'.number_format((float) $voucher->minimum_order_amount, 2)
                    : 'No minimum spend',
                'code' => $voucher->code,
                'expires' => $voucher->ends_at?->format('M j, Y') ?? 'No expiry',
                'collected' => $collected,
                'unavailable_reason' => $unavailableReason,
                'use_url' => route('buyer.shop', [
                    'seller' => $voucher->seller_profile_id,
                    'voucher' => $voucher->id,
                ]),
            ];
        };

        $collectedSellerCards = $collectedSellerClaims
            ->map(function (BuyerVoucherClaim $claim) use ($buyer, $claimService, $collectedGlobalUses, $collectedBuyerUses, $sellerVoucherCard): array {
                $voucher = $claim->voucher;
                $reason = $claimService->unavailableReason(
                    $voucher,
                    $buyer,
                    $collectedGlobalUses->get($voucher->id, 0),
                    $collectedBuyerUses->get($voucher->id, 0),
                );

                return $sellerVoucherCard($voucher, true, $reason);
            });

        $claimableSellerCards = $claimableSellerVouchers
            ->map(fn (Voucher $voucher): array => $sellerVoucherCard($voucher, false));

        $platformVouchers = Voucher::query()
            ->with('sellerProfile')
            ->whereNull('seller_profile_id')
            ->when(Schema::hasColumn('vouchers', 'platform_code_key'), fn ($query) => $query->whereNotNull('platform_code_key'))
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->get();
        $redemptionCounts = VoucherRedemptionCounter::countsFor($platformVouchers);
        $buyerRedemptionCounts = VoucherRedemptionCounter::countsFor($platformVouchers, (int) $buyer->id);
        $platformVoucherCards = $platformVouchers
            ->filter(fn (Voucher $voucher): bool => ($voucher->usage_limit === null
                    || $redemptionCounts->get($voucher->id, 0) < $voucher->usage_limit)
                && ($voucher->per_user_limit === null
                    || $buyerRedemptionCounts->get($voucher->id, 0) < $voucher->per_user_limit)
                && ! $voucher->requiresReview()
                && app(\App\Services\Finance\PlatformCampaignBudgetService::class)->selectable($voucher))
            ->map(fn (Voucher $voucher): array => [
                'id' => (int) $voucher->id,
                'icon' => $voucher->discount_type === 'PERCENT' ? number_format((float) $voucher->discount_value, 0).'%' : '₱',
                'status' => 'LIKHAE Platform Campaign',
                'value' => $voucher->discount_type === 'PERCENT'
                    ? number_format((float) $voucher->discount_value, 0).'% off'
                    : '₱'.number_format((float) $voucher->discount_value, 2).' off',
                'condition' => (float) $voucher->minimum_order_amount > 0
                    ? 'Minimum spend ₱'.number_format((float) $voucher->minimum_order_amount, 2)
                    : 'No minimum spend',
                'code' => $voucher->code,
                'expires' => $voucher->ends_at?->format('M j, Y') ?? 'No expiry',
                'collected' => false,
                'use_url' => route('buyer.products'),
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

        $redeemedPointActivities = $orders
            ->filter(fn ($order): bool => strtoupper((string) $order->status) !== 'CANCELLED')
            ->map(function ($order): ?array {
                $redeemedPoints = (int) $order->sellerOrders
                    ->filter(fn ($sellerOrder): bool => strtoupper((string) $sellerOrder->status) !== 'CANCELLED')
                    ->sum('reward_points_redeemed');

                return $redeemedPoints > 0 ? [
                    'label' => 'Reward points redeemed on order '.$order->order_number,
                    'date' => $order->placed_at?->format('M j, Y'),
                    'amount' => '-'.number_format($redeemedPoints).' points',
                    'negative' => true,
                ] : null;
            })
            ->filter()
            ->values();

        return view('buyer.rewards', [
            'sellerVoucherCards' => $collectedSellerCards->concat($claimableSellerCards)->values(),
            'platformVoucherCards' => $platformVoucherCards,
            'voucherHistory' => $voucherHistory,
            'pointsBalance' => app(RewardPointsService::class)->balance($buyer),
            'pointActivities' => $pointActivities->concat($redeemedPointActivities)->values(),
        ]);
    }

    public function notifications(Request $request): View
    {
        return view('buyer.notifications.index', [
            'notifications' => $request->user()->notifications()
                ->orderByRaw('CASE WHEN type = ? THEN 0 ELSE 1 END', ['ANNOUNCEMENT'])
                ->latest('created_at')
                ->latest('id')
                ->paginate(20),
        ]);
    }

    public function notificationStream(Request $request): JsonResponse
    {
        $notifications = $request->user()->notifications()
            ->orderByRaw('CASE WHEN type = ? THEN 0 ELSE 1 END', ['ANNOUNCEMENT'])
            ->latest('created_at')
            ->latest('id')
            ->limit(20)
            ->get(['id', 'type', 'title', 'message', 'action_url', 'read_at', 'created_at', 'updated_at']);

        $rows = $notifications->map(fn ($notification): array => [
            'id' => (int) $notification->id,
            'category' => match (true) {
                strtoupper((string) $notification->type) === 'ANNOUNCEMENT' => 'announcements',
                str_starts_with(strtoupper((string) $notification->type), 'STORE_') => 'stores',
                str_contains(strtolower((string) $notification->type), 'message') => 'messages',
                str_contains(strtolower((string) $notification->type), 'reward') => 'rewards',
                str_contains(strtolower((string) $notification->type), 'account') => 'account',
                default => 'orders',
            },
            'title' => $notification->title,
            'message' => $notification->message,
            'open_url' => route('buyer.notifications.open', $notification),
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

    public function openNotification(Request $request, Notification $notification): RedirectResponse
    {
        $ownedNotification = $request->user()->notifications()->whereKey($notification->id)->firstOrFail();
        if ($ownedNotification->read_at === null) {
            $ownedNotification->forceFill(['read_at' => now()])->save();
        }

        return redirect()->to($this->buyerNotificationDestination($ownedNotification->action_url));
    }

    private function buyerNotificationDestination(?string $url): string
    {
        $fallback = route('buyer.notifications');
        $url = trim((string) $url);
        if ($url === '' || str_contains($url, '\\') || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return $fallback;
        }

        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return $fallback;
        }

        $path = (string) ($parts['path'] ?? '');
        if (! str_starts_with($path, '/buyer/')) {
            return $fallback;
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
            $allowedHosts = array_filter([strtolower($requestHost = (string) request()->getHost()), $appHost]);
            if (! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
                || ! in_array(strtolower((string) ($parts['host'] ?? '')), $allowedHosts, true)) {
                return $fallback;
            }
        }

        return $path
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    public function account(
        Request $request,
        BuyerEmailChangeOtpService $emailChanges,
        BuyerMobileChangeOtpService $mobileChanges,
    ): View
    {
        $buyer = $request->user();
        $addresses = $buyer->addresses()->latest()->get();
        $followingStoresReady = Schema::hasTable('seller_followers');
        $followingStores = null;

        if ($request->query('tab') === 'following' && $followingStoresReady) {
            $followingStores = SellerFollower::query()
                ->where('buyer_user_id', $buyer->id)
                ->whereHas('sellerProfile', fn ($query) => $query
                    ->where('status', 'ACTIVE')
                    ->where('store_visibility', true)
                    ->whereHas('user', fn ($userQuery) => $userQuery->where('status', 'ACTIVE')))
                ->with('sellerProfile.primaryCategory')
                ->latest()
                ->paginate(12)
                ->withQueryString();
        }

        return view('buyer.account', [
            'tab' => $request->query('tab', 'profile'),
            'addresses' => $addresses,
            'addressCount' => $addresses->count(),
            'addressLimit' => 5,
            'followingStoresReady' => $followingStoresReady,
            'followingStores' => $followingStores,
            'pendingEmail' => $emailChanges->pendingEmail($buyer),
            'pendingPhone' => $mobileChanges->pendingPhone($buyer),
            'reviews' => Review::query()
                ->where('buyer_user_id', $buyer->id)
                ->with('orderItem')
                ->latest()
                ->paginate(10)
                ->withQueryString(),
        ]);
    }

    public function settings(Request $request): View
    {
        return view('buyer.settings', [
            'preferences' => $this->buyerPreferences($request->user()),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme' => ['required', Rule::in(['light', 'dark', 'system'])],
            'language' => ['required', Rule::in(['en'])],
            'show_online_status' => ['sometimes', 'boolean'],
        ]);

        $buyerId = $request->user()->getKey();
        DB::transaction(function () use ($request, $data, $buyerId): void {
            $buyer = User::query()->whereKey($buyerId)->lockForUpdate()->firstOrFail();
            $currentPreferences = $this->buyerPreferences($buyer);
            $preferences = array_merge($currentPreferences, [
                'order_updates' => $request->boolean('order_updates'),
                'delivery_updates' => $request->boolean('delivery_updates'),
                'chat_updates' => $request->boolean('chat_updates'),
                'promotion_updates' => $request->boolean('promotion_updates'),
                'show_online_status' => $request->exists('show_online_status')
                    ? $request->boolean('show_online_status')
                    : $currentPreferences['show_online_status'],
                'theme' => $data['theme'],
                'language' => $data['language'],
                'notification_sounds' => $request->boolean('notification_sounds'),
            ]);

            $buyer->forceFill(['notification_preferences' => $preferences])->save();
        });

        if (! $request->boolean('show_online_status')) {
            Cache::store('database')->forget('message-presence:user:'.$request->user()->id);
        }

        return back()->with('buyer_notice', 'System settings updated.');
    }

    public function updateThemePreference(Request $request): JsonResponse
    {
        $unknownFields = array_diff(array_keys($request->all()), ['theme']);
        if ($unknownFields !== []) {
            throw ValidationException::withMessages([
                (string) reset($unknownFields) => 'This setting field is not allowed.',
            ]);
        }

        $data = $request->validate([
            'theme' => ['required', 'string', Rule::in(['light', 'dark', 'system'])],
        ]);

        DB::transaction(function () use ($request, $data): void {
            $buyer = User::query()
                ->whereKey($request->user()->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $preferences = array_merge($this->buyerPreferences($buyer), [
                'theme' => $data['theme'],
            ]);

            $buyer->forceFill(['notification_preferences' => $preferences])->save();
        });

        return response()->json(['theme' => $data['theme']]);
    }

    public function updateAiPreference(Request $request): JsonResponse
    {
        $allowedFields = ['key', 'enabled'];
        $unknownFields = array_diff(array_keys($request->all()), $allowedFields);
        if ($unknownFields !== []) {
            throw ValidationException::withMessages([
                (string) reset($unknownFields) => 'This setting field is not allowed.',
            ]);
        }

        $data = $request->validate([
            'key' => ['required', 'string', Rule::in(['ai_assistant_enabled', 'ai_response_sound'])],
            'enabled' => ['required', 'boolean'],
        ]);
        $enabled = (bool) $data['enabled'];

        DB::transaction(function () use ($request, $data, $enabled): void {
            $buyer = User::query()
                ->whereKey($request->user()->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $preferences = array_merge($this->buyerPreferences($buyer), [
                $data['key'] => $enabled,
            ]);

            $buyer->forceFill(['notification_preferences' => $preferences])->save();
        });

        return response()->json([
            'key' => $data['key'],
            'enabled' => $enabled,
        ]);
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
            'show_online_status' => true,
            'ai_assistant_enabled' => true,
            'ai_response_sound' => false,
            'theme' => 'system',
            'language' => 'en',
            'notification_sounds' => true,
        ], (array) $user->notification_preferences);
    }

    public function saveProfile(
        Request $request,
        ProfilePhotoService $profilePhotos,
        BuyerEmailChangeOtpService $emailChanges,
        BuyerMobileChangeOtpService $mobileChanges,
    ): RedirectResponse|JsonResponse
    {
        foreach (['first_name', 'middle_initial', 'last_name', 'name_extension', 'email', 'phone'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => trim($request->input($field))]);
            }
        }
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower($request->input('email'))]);
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'min:2', 'max:100', BuyerNameRules::personName()],
            'middle_initial' => ['nullable', 'string', 'max:10', BuyerNameRules::personName(true)],
            'last_name' => ['required', 'string', 'min:2', 'max:100', BuyerNameRules::personName()],
            'name_extension' => ['nullable', 'string', 'max:20', BuyerNameRules::extension()],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'birthday' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'email_change_code' => ['nullable', 'digits:6'],
            'mobile_change_code' => ['nullable', 'digits:6'],
        ]);

        $user = $request->user();
        $submittedPhone = PhilippineMobileNumber::normalize($data['phone']);
        $currentPhone = PhilippineMobileNumber::normalize((string) $user->contact_number);
        $sameLegacyPhone = trim($data['phone']) === (string) $user->contact_number;
        if ($submittedPhone === null && ! $sameLegacyPhone) {
            throw ValidationException::withMessages([
                'phone' => 'Enter a valid Philippine mobile number.',
            ]);
        }
        $phoneChanged = $submittedPhone !== null && $submittedPhone !== $currentPhone;

        $email = mb_strtolower(trim($data['email']));
        $currentEmail = mb_strtolower((string) $user->email);
        $emailChanged = $email !== $currentEmail;
        if ($emailChanged && $phoneChanged) {
            throw ValidationException::withMessages([
                'phone' => 'Save your email change first, then confirm your mobile number change with that verified email.',
            ]);
        }

        if ($phoneChanged && ! $user->email_verified_at) {
            throw ValidationException::withMessages([
                'phone' => 'Verify your account email before changing your mobile number.',
            ]);
        }

        if ($emailChanged) {
            $emailInUse = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->whereKeyNot($user->id)
                ->exists();

            if ($emailInUse) {
                throw ValidationException::withMessages([
                    'email' => 'This email address cannot be used. Try another email address.',
                ]);
            }

            if (! filled($data['email_change_code'] ?? null)) {
                if ($emailChanges->pendingEmail($user) !== $email) {
                    $emailChanges->send($user, $email);
                }

                $message = 'A six-digit verification code was sent to '.$email.'. Verify it to save your profile changes.';

                if ($request->expectsJson()) {
                    return response()->json([
                        'requires_verification' => true,
                        'verification_for' => 'email_change',
                        'target' => $email,
                        'message' => $message,
                    ], 202);
                }

                return back()->withInput()->with('profile_email_otp_pending', true);
            }
        }

        if ($phoneChanged && ! filled($data['mobile_change_code'] ?? null)) {
            if ($mobileChanges->pendingPhone($user) !== $submittedPhone) {
                $mobileChanges->send($user, (string) $submittedPhone);
            }

            $message = 'A six-digit code was sent to your verified email address. Enter it to confirm your mobile number change.';

            if ($request->expectsJson()) {
                return response()->json([
                    'requires_verification' => true,
                    'verification_for' => 'mobile_change',
                    'target' => (string) $user->email,
                    'message' => $message,
                ], 202);
            }

            return back()->withInput()->with('profile_mobile_otp_pending', true);
        }

        $changes = [
            'first_name' => trim($data['first_name']),
            'middle_initial' => filled($data['middle_initial'] ?? null) ? trim($data['middle_initial']) : null,
            'last_name' => trim($data['last_name']),
            'name_extension' => filled($data['name_extension'] ?? null) ? trim($data['name_extension']) : null,
            'birthday' => $data['birthday'] ?? $user->birthday,
            'sex' => filled($data['gender'] ?? null) ? strtoupper($data['gender']) : null,
        ];

        if ($emailChanged) {
            try {
                $emailChanges->confirm($user, (string) $data['email_change_code'], function (string $pendingEmail) use ($user, $email, $changes): void {
                    if ($pendingEmail !== $email) {
                        throw ValidationException::withMessages([
                            'code' => 'This code was sent for a different email address. Request a new code.',
                        ]);
                    }

                    DB::transaction(function () use ($user, $email, $changes): void {
                        $lockedBuyer = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                        $emailInUse = User::query()
                            ->whereRaw('LOWER(email) = ?', [$email])
                            ->whereKeyNot($lockedBuyer->id)
                            ->exists();

                        if ($emailInUse) {
                            throw ValidationException::withMessages([
                                'email' => 'This email address can no longer be used. Try another email address.',
                            ]);
                        }

                        $lockedBuyer->forceFill($changes + [
                            'email' => $email,
                            'email_verified_at' => now(),
                        ])->save();
                    });
                });
            } catch (QueryException $exception) {
                if (User::query()->whereRaw('LOWER(email) = ?', [$email])->whereKeyNot($user->id)->exists()) {
                    $emailChanges->invalidate($user);
                    throw ValidationException::withMessages([
                        'email' => 'This email address can no longer be used. Try another email address.',
                    ]);
                }

                throw $exception;
            } catch (ValidationException $exception) {
                if (str_contains(mb_strtolower((string) collect($exception->errors())->flatten()->first()), 'can no longer be used')) {
                    $emailChanges->invalidate($user);
                }

                throw $exception;
            }
            $mobileChanges->invalidate($user);
        } elseif ($phoneChanged) {
            $originalPhone = (string) $user->contact_number;
            $verifiedEmail = $currentEmail;

            $mobileChanges->confirm($user, (string) $data['mobile_change_code'], function (string $pendingPhone) use ($user, $submittedPhone, $originalPhone, $verifiedEmail, $changes): void {
                if ($pendingPhone !== $submittedPhone) {
                    throw ValidationException::withMessages([
                        'code' => 'This code was sent for a different mobile number. Request a new code.',
                    ]);
                }

                DB::transaction(function () use ($user, $pendingPhone, $originalPhone, $verifiedEmail, $changes): void {
                    $lockedBuyer = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

                    if (! $lockedBuyer->email_verified_at || mb_strtolower((string) $lockedBuyer->email) !== $verifiedEmail) {
                        throw ValidationException::withMessages([
                            'code' => 'The verified email address changed. Request a new mobile number code.',
                        ]);
                    }

                    if ((string) $lockedBuyer->contact_number !== $originalPhone) {
                        throw ValidationException::withMessages([
                            'code' => 'Your mobile number changed after this code was sent. Request a new code.',
                        ]);
                    }

                    $lockedBuyer->forceFill($changes + ['contact_number' => $pendingPhone])->save();
                });
            });
        } else {
            $user->update($changes);
        }

        if ($request->hasFile('profile_photo')) {
            $user->refresh();
            $profilePhotos->replace($user, $request->file('profile_photo'));
        }

        $notice = match (true) {
            $emailChanged => 'Your email and profile changes have been verified and saved.',
            $phoneChanged => 'Your mobile number and profile changes have been confirmed and saved.',
            default => 'Profile updated.',
        };

        if ($request->expectsJson()) {
            return response()->json([
                'saved' => true,
                'message' => $notice,
                'redirect' => route('buyer.account', ['tab' => 'profile']),
            ]);
        }

        return back()->with('buyer_notice', $notice);
    }

    public function savePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'new_password' => [
                'required',
                'confirmed',
                'different:current_password',
                'max:72',
                PasswordRule::min(8)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        $request->user()->update(['password' => Hash::make($data['new_password'])]);

        return back()->with('buyer_notice', 'Password updated.');
    }

    public function verifyEmailChange(
        Request $request,
        BuyerEmailChangeOtpService $emailChanges,
        BuyerMobileChangeOtpService $mobileChanges,
    ): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $buyer = $request->user();

        try {
            $emailChanges->confirm($buyer, $data['code'], function (string $pendingEmail) use ($buyer): void {
                DB::transaction(function () use ($buyer, $pendingEmail): void {
                    $lockedBuyer = User::query()->whereKey($buyer->id)->lockForUpdate()->firstOrFail();
                    $emailInUse = User::query()
                        ->whereRaw('LOWER(email) = ?', [$pendingEmail])
                        ->whereKeyNot($lockedBuyer->id)
                        ->exists();

                    if ($emailInUse) {
                        throw ValidationException::withMessages([
                            'email' => 'This email address can no longer be used. Try another email address.',
                        ]);
                    }

                    $lockedBuyer->forceFill([
                        'email' => $pendingEmail,
                        'email_verified_at' => now(),
                    ])->save();
                });
            });
        } catch (QueryException $exception) {
            if (User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower((string) ($emailChanges->pendingEmail($buyer) ?? ''))])
                ->whereKeyNot($buyer->id)->exists()) {
                $emailChanges->invalidate($buyer);
                throw ValidationException::withMessages([
                    'email' => 'This email address can no longer be used. Try another email address.',
                ]);
            }

            throw $exception;
        } catch (ValidationException $exception) {
            if (str_contains(mb_strtolower((string) collect($exception->errors())->flatten()->first()), 'can no longer be used')) {
                $emailChanges->invalidate($buyer);
            }

            throw $exception;
        }

        $mobileChanges->invalidate($buyer);

        return back()->with('buyer_notice', 'Your new email address is verified and active.');
    }

    public function resendEmailChangeCode(Request $request, BuyerEmailChangeOtpService $emailChanges): RedirectResponse|JsonResponse
    {
        $buyer = $request->user();
        $pendingEmail = $emailChanges->pendingEmail($buyer);
        if (! $pendingEmail) {
            throw ValidationException::withMessages([
                'email' => 'Request an email change before asking for a verification code.',
            ]);
        }

        $emailInUse = User::query()
            ->whereRaw('LOWER(email) = ?', [$pendingEmail])
            ->whereKeyNot($buyer->id)
            ->exists();
        if ($emailInUse) {
            $emailChanges->invalidate($buyer);
            throw ValidationException::withMessages([
                'email' => 'This email address can no longer be used. Try another email address.',
            ]);
        }

        $emailChanges->send($buyer, $pendingEmail);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'A new verification code was sent to '.$pendingEmail.'.']);
        }

        return back()->with('buyer_notice', 'A verification code was sent to the pending email address.');
    }

    public function resendMobileChangeCode(Request $request, BuyerMobileChangeOtpService $mobileChanges): RedirectResponse|JsonResponse
    {
        $buyer = $request->user();
        $pendingPhone = $mobileChanges->pendingPhone($buyer);
        if (! $pendingPhone) {
            throw ValidationException::withMessages([
                'phone' => 'Request a mobile number change before asking for a verification code.',
            ]);
        }

        $mobileChanges->send($buyer, $pendingPhone);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'A new verification code was sent to your verified email address.']);
        }

        return back()->with('buyer_notice', 'A new verification code was sent to your verified email address.');
    }

    public function saveAddress(Request $request): RedirectResponse
    {
        $data = $this->validatedAddress($request);
        $buyer = $request->user();
        $requestedDefault = $request->boolean('is_default');

        DB::transaction(function () use ($buyer, $data, $requestedDefault): void {
            $lockedBuyer = User::query()->whereKey($buyer->id)->lockForUpdate()->firstOrFail();
            $addressCount = $lockedBuyer->addresses()->count();

            if ($addressCount >= 5) {
                throw ValidationException::withMessages([
                    'address_limit' => 'You can save up to 5 delivery addresses.',
                ]);
            }

            $makeDefault = $requestedDefault || $addressCount === 0;
            if ($makeDefault) {
                $lockedBuyer->addresses()->update(['is_default' => false]);
            }

            $lockedBuyer->addresses()->create($this->addressAttributes($data) + [
                'is_default' => $makeDefault,
            ]);
        });

        return redirect()->route('buyer.account', ['tab' => 'addresses'])
            ->with('address_notice', 'Delivery address saved successfully.');
    }

    public function setDefaultAddress(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'address_id' => ['required', 'integer'],
        ]);
        $buyerId = $request->user()->getKey();

        DB::transaction(function () use ($buyerId, $data): void {
            $buyer = User::query()->whereKey($buyerId)->lockForUpdate()->firstOrFail();
            $address = $buyer->addresses()
                ->whereKey((int) $data['address_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $buyer->addresses()->whereKeyNot($address->id)->update(['is_default' => false]);
            $address->forceFill(['is_default' => true])->save();
        });

        return redirect()->route('buyer.account', ['tab' => 'addresses'])
            ->with('address_notice', 'Default delivery address updated successfully.');
    }

    public function updateAddress(Request $request, Address $address): RedirectResponse
    {
        abort_unless((int) $address->user_id === (int) $request->user()->id, 403);

        $data = $this->validatedAddress($request, $address);

        $buyer = $request->user();
        $makeDefault = $request->boolean('is_default');

        DB::transaction(function () use ($buyer, $address, $data, &$makeDefault): void {
            $lockedBuyer = User::query()->whereKey($buyer->id)->lockForUpdate()->firstOrFail();
            $lockedAddress = $lockedBuyer->addresses()->whereKey($address->id)->lockForUpdate()->firstOrFail();

            if ($makeDefault) {
                $lockedBuyer->addresses()->whereKeyNot($lockedAddress->id)->update(['is_default' => false]);
            } elseif ($lockedAddress->is_default) {
                $makeDefault = true;
            }

            $lockedAddress->update($this->addressAttributes($data, $lockedAddress) + [
                'is_default' => $makeDefault,
            ]);
        });

        return redirect()->route('buyer.account', ['tab' => 'addresses'])
            ->with('address_notice', 'Delivery address updated successfully.');
    }

    private function validatedAddress(Request $request, ?Address $existing = null): array
    {
        if (is_string($request->input('contact_number'))) {
            $normalizedContact = PhilippineMobileNumber::normalize($request->input('contact_number'));
            if ($normalizedContact !== null) {
                $request->merge(['contact_number' => $normalizedContact]);
            }
        }

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:50'],
            'recipient_name' => ['required', 'string', 'max:200'],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^(?:09|\\+?639)\\d{9}$/'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'is_default' => ['nullable', 'boolean'],
        ] + PhilippineAddressValidator::rules() + AddressCoordinateValidator::rules());

        PhilippineAddressValidator::assertValid($data);
        AddressCoordinateValidator::assertValid($data);
        AddressCoordinateValidator::assertFreshForAddress($existing, $data);
        $data['contact_number'] = PhilippineMobileNumber::normalize($data['contact_number'])
            ?? throw ValidationException::withMessages([
                'contact_number' => 'Enter a valid Philippine mobile number for the delivery recipient.',
            ]);

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
            $lockedBuyer = User::query()->whereKey($buyer->id)->lockForUpdate()->firstOrFail();
            $lockedAddress = $lockedBuyer->addresses()->whereKey($address->id)->lockForUpdate()->firstOrFail();
            $addressCount = $lockedBuyer->addresses()->count();

            if ($lockedAddress->is_default && $addressCount > 1) {
                throw ValidationException::withMessages([
                    'address' => 'Set another address as default before deleting this address.',
                ]);
            }

            $lockedAddress->delete();
        });

        return redirect()->route('buyer.account', ['tab' => 'addresses'])
            ->with('address_notice', 'Delivery address deleted successfully.');
    }
}
