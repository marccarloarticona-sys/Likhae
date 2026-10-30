<?php

namespace App\Services\Marketplace;

use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductImage;
use App\Models\Seller\ProductOptionValue;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\Voucher;
use App\Models\Buyer\Review;
use App\Services\ReviewImageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductCatalogService
{
    /** @var array<int, Collection<int, Voucher>> */
    private array $publicVoucherCampaignsBySeller = [];

    public function __construct(
        private readonly ReviewImageService $reviewImages,
        private readonly SellerPromotionBuyerService $sellerPromotions,
    ) {}

    /** @return array<int, string> */
    public function productRelations(): array
    {
        return [
            'category',
            'sellerProfile.user',
            'images.optionValue.option',
            'options.values.images',
            'variants.optionValues.option',
            'variants.optionValues.images',
            'variants.productImage',
        ];
    }

    public function visibleQuery(): Builder
    {
        return Product::query()
            ->visible()
            ->with($this->productRelations())
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->withSum('orderItems', 'quantity');
    }

    public function applyFilters(Builder $query, Request $request): Builder
    {
        $search = trim((string) $request->query('q', $request->query('search', '')));

        if ($search !== '') {
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('category', fn (Builder $category) => $category->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('sellerProfile', fn (Builder $seller) => $seller->where('business_name', 'like', "%{$search}%"));
            });
        }

        $category = trim((string) $request->query('category', ''));
        if ($category !== '' && strtolower($category) !== 'all') {
            $query->whereHas('category', function (Builder $categoryQuery) use ($category): void {
                $categoryQuery->where('slug', $category)
                    ->orWhereHas('parent', fn (Builder $parent) => $parent->where('slug', $category));
            });
        } elseif ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->query('category_id'));
        }

        if ($request->filled('seller_profile_id')) {
            $query->where('seller_profile_id', (int) $request->query('seller_profile_id'));
        }

        if ($request->boolean('available')) {
            $query->whereHas('variants', fn (Builder $variant) => $variant->where('is_active', true)->where('stock', '>', 0));
        }

        $minimumPrice = $request->filled('min_price') ? max(0, (float) $request->query('min_price')) : null;
        $maximumPrice = $request->filled('max_price') ? max(0, (float) $request->query('max_price')) : null;
        if ($minimumPrice !== null && $maximumPrice !== null && $minimumPrice > $maximumPrice) {
            $query->whereRaw('1 = 0');
        }
        if ($minimumPrice !== null || $maximumPrice !== null) {
            $query->whereHas('variants', function (Builder $variant) use ($minimumPrice, $maximumPrice): void {
                $variant->where('is_active', true)->where('stock', '>', 0);
                $sellingPrice = $this->variantSellingPriceSql();

                if ($minimumPrice !== null) {
                    $variant->whereRaw($sellingPrice.' >= CAST(? AS DECIMAL(12, 2))', [$minimumPrice]);
                }
                if ($maximumPrice !== null) {
                    $variant->whereRaw($sellingPrice.' <= CAST(? AS DECIMAL(12, 2))', [$maximumPrice]);
                }
            });
        }

        $location = trim((string) $request->query('location', ''));
        if ($location !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($location)).'%';
            $query->whereHas('sellerProfile', function (Builder $seller) use ($pattern): void {
                $seller->whereRaw("LOWER(COALESCE(seller_profiles.location, '')) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('businessAddress', function (Builder $address) use ($pattern): void {
                        $address->whereRaw("LOWER(COALESCE(addresses.province_name, '')) LIKE ? ESCAPE '!'", [$pattern])
                            ->orWhereRaw("LOWER(COALESCE(addresses.municipality_name, '')) LIKE ? ESCAPE '!'", [$pattern])
                            ->orWhereRaw("LOWER(COALESCE(addresses.barangay_name, '')) LIKE ? ESCAPE '!'", [$pattern]);
                    });
            });
        }

        if ($request->boolean('deals') || $request->query('focus') === 'deals') {
            $query->whereHas('sellerProfile', fn (Builder $seller) => $seller->where('vacation_mode', false));
            $query->whereHas('variants', function (Builder $variant): void {
                $variant->where('is_active', true)
                    ->where('stock', '>', 0)
                    ->where('price', '>', 0)
                    ->whereRaw($this->variantSellingPriceSql().' < product_variants.price');
            });
        }

        $minimumRating = min(5, max(0, (float) $request->query('rating', 0)));
        if ($minimumRating > 0) {
            $ratedProductIds = DB::table('order_items')
                ->join('reviews', 'reviews.order_item_id', '=', 'order_items.id')
                ->where('reviews.status', 'PUBLISHED')
                ->whereNotNull('reviews.rating')
                ->groupBy('order_items.product_id')
                ->havingRaw('AVG(reviews.rating) >= ?', [$minimumRating])
                ->select('order_items.product_id');

            $query->whereIn('products.id', $ratedProductIds);
        }

        return $query;
    }

    private function variantSellingPriceSql(): string
    {
        return app(ProductPriceResolver::class)->sqlExpression();
    }

    private function variantPriceSubquery(string $aggregate): Builder
    {
        $aggregate = $aggregate === 'MAX' ? 'MAX' : 'MIN';

        return ProductVariant::query()
            ->selectRaw("{$aggregate}({$this->variantSellingPriceSql()})")
            ->whereColumn('product_variants.product_id', 'products.id')
            ->where('is_active', true)
            ->where('stock', '>', 0);
    }

    public function applySort(Builder $query, ?string $sort): Builder
    {
        return match ($sort) {
            'name' => $query->orderBy('name'),
            'price-low' => $query->orderBy($this->variantPriceSubquery('MIN')),
            'price-high' => $query->orderByDesc($this->variantPriceSubquery('MAX')),
            'best-rated' => $query->orderByDesc('reviews_avg_rating')->orderByDesc('products.id'),
            'best-selling' => $query->orderByDesc('order_items_sum_quantity')->orderByDesc('products.id'),
            'oldest' => $query->oldest(),
            default => $query->latest(),
        };
    }

    public function paginated(Request $request, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->applySort(
            $this->applyFilters($this->visibleQuery(), $request),
            $request->query('sort')
        );

        return $query->paginate($perPage)->withQueryString();
    }

    public function findVisibleBySlug(string $slug): Product
    {
        return $this->visibleQuery()->where('slug', $slug)->firstOrFail();
    }

    /** @return array<string, mixed> */
    public function productPayload(Product $product, bool $includeDetails = false, bool $dealsMode = false): array
    {
        $product->loadMissing($this->productRelations());

        $images = $product->images
            ->map(fn (ProductImage $image): array => [
                'id' => $image->id,
                'file_path' => $image->file_path,
                'url' => $this->publicUrl($image->file_path),
                'alt_text' => $image->alt_text,
                'is_primary' => (bool) $image->is_primary,
                'sort_order' => (int) $image->sort_order,
                'product_option_value_id' => $image->product_option_value_id,
                'option_name' => $image->optionValue?->option?->name,
                'option_value' => $image->optionValue?->value,
            ])
            ->sortByDesc(fn (array $image): int => ((int) $image['is_primary'] * 100000) + (int) $image['sort_order'])
            ->take(10)
            ->values();

        $primaryImage = $images->firstWhere('is_primary', true) ?: $images->first();
        $activeVariants = $product->variants->where('is_active', true)->values();
        $activeVariants->each(fn (ProductVariant $variant) => $variant->setRelation('product', $product));
        $availableVariants = $activeVariants->filter(fn (ProductVariant $variant): bool => (int) $variant->stock > 0)->values();
        $dealVariant = $availableVariants
            ->filter(fn (ProductVariant $variant): bool => $variant->discount_amount > 0)
            ->sortBy(fn (ProductVariant $variant): float => $variant->final_price)
            ->first();
        $displayVariant = $dealsMode && $dealVariant
            ? $dealVariant
            : ($availableVariants->sortBy(fn (ProductVariant $variant): float => $variant->final_price)->first()
                ?? $activeVariants->sortBy(fn (ProductVariant $variant): float => $variant->final_price)->first());
        $seller = $product->sellerProfile;
        $isCatalogVisible = ! $product->trashed()
            && $product->status === 'ACTIVE'
            && $seller?->status === 'ACTIVE'
            && (bool) ($seller?->store_visibility ?? false)
            && $seller?->user?->status === 'ACTIVE'
            && $activeVariants->isNotEmpty();
        $isAvailable = $isCatalogVisible
            && ! (bool) ($seller?->vacation_mode ?? false)
            && $availableVariants->isNotEmpty();
        $currentPrice = $displayVariant?->final_price;
        $originalPrice = $displayVariant && $displayVariant->discount_amount > 0
            ? (float) $displayVariant->price
            : null;

        $payload = [
            'id' => $product->id,
            'seller_profile_id' => $product->seller_profile_id,
            'category_id' => $product->category_id,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $includeDetails ? $product->description : str($product->description ?? '')->limit(180)->toString(),
            'status' => $product->status,
            'is_active' => $product->status === 'ACTIVE',
            'is_draft' => $product->status === 'DRAFT',
            'is_deleted' => $product->trashed(),
            'is_visible' => (bool) $isCatalogVisible,
            'available' => (bool) $isAvailable,
            'is_available' => (bool) $isAvailable,
            'availability_restricted' => ! $isCatalogVisible,
            'seller_on_vacation' => (bool) ($seller?->vacation_mode ?? false),
            'purchase_limit_per_buyer' => $product->purchase_limit_per_buyer === null
                ? null
                : (int) $product->purchase_limit_per_buyer,
            'flash_sale_starts_at' => $product->flash_sale_starts_at?->toIso8601String(),
            'flash_sale_ends_at' => $product->flash_sale_ends_at?->toIso8601String(),
            'flash_sale_is_paused' => (bool) ($product->flash_sale_is_paused ?? false),
            'flash_sale_limit_per_buyer' => $product->flash_sale_limit_per_buyer === null
                ? null
                : (int) $product->flash_sale_limit_per_buyer,
            'seller_promotion_badges' => $this->sellerPromotions->forProduct($product)
                ->map(fn ($offer): string => $offer->promotion_type === \App\Models\Seller\SellerPromotion::TYPE_BUNDLE
                    ? 'Bundle deal'
                    : 'Buy '.$offer->buy_quantity.' Get '.$offer->reward_quantity.((float) $offer->reward_discount_percent >= 100 ? ' Free' : ' · '.$offer->reward_discount_percent.'% off'))
                ->unique()
                ->values()
                ->all(),
            'seller_voucher_badges' => $this->publicVoucherBadgesForProduct($product),
            'rating' => (float) ($product->reviews_avg_rating ?? 0),
            'reviews' => (int) ($product->reviews_count ?? 0),
            'sold' => (int) ($product->order_items_sum_quantity ?? 0),
            'price' => $currentPrice === null ? null : number_format((float) $currentPrice, 2, '.', ''),
            'min_price' => $currentPrice === null ? null : number_format((float) $currentPrice, 2, '.', ''),
            'original_price' => $originalPrice === null ? null : number_format($originalPrice, 2, '.', ''),
            'old_price' => $originalPrice === null ? null : number_format($originalPrice, 2, '.', ''),
            'stock' => $activeVariants->sum('stock'),
            'primary_image' => $primaryImage,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ] : null,
            'seller' => $seller ? [
                'id' => $seller->id,
                'user_id' => $seller->user_id,
                'business_name' => $seller->business_name,
                'avatar_path' => $seller->avatar_path,
                'status' => $seller->status,
                'store_visibility' => (bool) $seller->store_visibility,
                'vacation_mode' => (bool) $seller->vacation_mode,
                'is_on_vacation' => (bool) $seller->vacation_mode,
            ] : null,
            'images' => $images,
            'variants' => $activeVariants
                ->map(fn (ProductVariant $variant): array => $this->variantPayload($variant))
                ->values(),
        ];

        if ($dealsMode && $dealVariant) {
            $payload['deal_variant'] = $this->variantPayload($dealVariant);
        }

        if ($includeDetails) {
            $payload['description_blocks'] = $this->descriptionBlocks($product, $images);
            $product->loadMissing(['reviews.buyer', 'reviews.orderItem']);
            $reviewPhotoUrls = $this->reviewImages->photoUrlsFor($product->reviews);
            $payload['customer_reviews'] = $product->reviews
                ->sortByDesc('created_at')
                ->map(fn (Review $review): array => [
                    'name' => $this->maskedBuyerName($review->buyer?->name),
                    'rating' => (int) $review->rating,
                    'comment' => $review->comment,
                    'image_urls' => $reviewPhotoUrls[$review->id] ?? [],
                    'image_url' => $reviewPhotoUrls[$review->id][0] ?? null,
                    'date' => $review->created_at?->format('M j, Y') ?? 'Recently',
                    'variant' => $review->orderItem?->variant_description ?: 'Standard',
                    'seller_reply' => filled($review->seller_reply) ? $review->seller_reply : null,
                    'seller_reply_date' => filled($review->seller_reply) ? $review->seller_replied_at?->format('M j, Y') : null,
                    'seller_name' => filled($review->seller_reply) ? $product->sellerProfile?->business_name : null,
                ])
                ->values()
                ->all();
            $payload['options'] = $product->options
                ->map(fn ($option): array => [
                    'id' => $option->id,
                    'name' => $option->name,
                    'sort_order' => (int) $option->sort_order,
                    'values' => $option->values
                        ->map(fn (ProductOptionValue $value): array => [
                            'id' => $value->id,
                            'value' => $value->value,
                            'sort_order' => (int) $value->sort_order,
                        ])
                        ->values(),
                ])
                ->values();
        }

        return $payload;
    }

    /** @return array<int, array<string, mixed>> */
    private function publicVoucherBadgesForProduct(Product $product): array
    {
        $sellerId = (int) $product->seller_profile_id;
        if (! Schema::hasTable('vouchers') || ! Schema::hasColumn('vouchers', 'visibility')) {
            return [];
        }

        if (! array_key_exists($sellerId, $this->publicVoucherCampaignsBySeller)) {
            $query = Voucher::query()
                ->where('seller_profile_id', $sellerId)
                ->where('visibility', 'PUBLIC')
                ->where('is_active', true)
                ->where(fn ($campaign) => $campaign->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($campaign) => $campaign->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                ->where(fn ($campaign) => $campaign->whereNull('usage_limit')->orWhereRaw(
                    'vouchers.usage_limit > (SELECT COUNT(*) FROM seller_orders WHERE seller_orders.voucher_id = vouchers.id AND seller_orders.status <> ?)',
                    ['CANCELLED'],
                ));

            if (Schema::hasColumn('vouchers', 'platform_code_key')) {
                $query->whereNull('platform_code_key');
            }
            if (Schema::hasColumn('vouchers', 'requires_review')) {
                $query->where('requires_review', false);
            }
            if (Schema::hasColumn('vouchers', 'applies_to')
                && Schema::hasTable('voucher_products')
                && Schema::hasTable('voucher_variants')) {
                $query->with([
                    'eligibleProducts:id',
                    'eligibleVariants:id,product_id',
                ]);
            }
            $this->publicVoucherCampaignsBySeller[$sellerId] = $query->orderBy('id')->get()
                ->filter(fn (Voucher $voucher): bool => ! $voucher->requiresReview())
                ->values();
        }

        $badges = $this->publicVoucherCampaignsBySeller[$sellerId]
            ->filter(function (Voucher $voucher) use ($product): bool {
                if (! Schema::hasColumn('vouchers', 'applies_to')) {
                    return true;
                }
                return match (strtoupper((string) ($voucher->applies_to ?? 'STORE'))) {
                    'STORE' => true,
                    'PRODUCTS' => $voucher->relationLoaded('eligibleProducts')
                        && $voucher->eligibleProducts->contains('id', $product->id),
                    'VARIANTS' => $voucher->relationLoaded('eligibleVariants')
                        && $voucher->eligibleVariants->contains(fn ($variant): bool => (int) $variant->product_id === (int) $product->id),
                    default => false,
                };
            })
            ->map(fn (Voucher $voucher): array => [
                'name' => $voucher->name,
                'discount_type' => $voucher->discount_type,
                'discount_value' => (float) $voucher->discount_value,
                'minimum_order_amount' => (float) $voucher->minimum_order_amount,
            ])
            ->values()
            ->take(3)
            ->values()
            ->all();

        return $badges;
    }

    /** @return array<string, mixed> */
    public function variantPayload(ProductVariant $variant): array
    {
        $variant->loadMissing(['product', 'optionValues.option', 'optionValues.images', 'productImage']);
        $resolvedPrice = app(ProductPriceResolver::class)->resolve($variant);
        $flashSaleStatus = $variant->flash_sale_status;
        $flashSaleVisiblePrice = in_array($flashSaleStatus, ['ACTIVE', 'SCHEDULED'], true)
            && $variant->flash_sale_price !== null
            ? number_format((float) $variant->flash_sale_price, 2, '.', '')
            : null;
        $discountType = $resolvedPrice['source'] === 'flash_sale'
            ? 'fixed'
            : ($resolvedPrice['source'] === 'product_discount' ? $variant->resolved_discount_type : 'none');
        $discountValue = $resolvedPrice['source'] === 'flash_sale'
            ? $resolvedPrice['discount_amount']
            : (float) ($variant->discount_value ?? 0);
        $assignedImage = $variant->productImage?->file_path
            ? collect([$this->publicUrl($variant->productImage->file_path)])
            : collect();
        $fallbackImages = $variant->optionValues
            ->flatMap(fn (ProductOptionValue $value) => $value->images->map(fn (ProductImage $image) => $this->publicUrl($image->file_path)))
            ->filter()
            ->unique();

        return [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'price' => number_format($resolvedPrice['price'], 2, '.', ''),
            'original_price' => number_format((float) $variant->price, 2, '.', ''),
            'discount_type' => $discountType === 'none' ? null : $discountType,
            'discount_value' => number_format($discountValue, 2, '.', ''),
            'discount_percentage' => $discountType === 'fixed' ? 0 : $variant->discount_percentage,
            'discount_amount' => number_format($resolvedPrice['discount_amount'], 2, '.', ''),
            'price_source' => $resolvedPrice['source'],
            'flash_sale_active' => $variant->active_flash_sale_price !== null,
            'flash_sale_price' => $flashSaleVisiblePrice,
            'flash_sale_remaining' => $variant->flash_sale_price === null ? 0 : (int) $variant->flash_sale_remaining,
            'flash_sale_status' => $flashSaleStatus,
            'flash_sale_starts_at' => $variant->product?->flash_sale_starts_at?->toIso8601String(),
            'flash_sale_ends_at' => $variant->product?->flash_sale_ends_at?->toIso8601String(),
            'flash_sale_limit_per_buyer' => $variant->product?->flash_sale_limit_per_buyer === null
                ? null
                : (int) $variant->product->flash_sale_limit_per_buyer,
            'payment_method' => $variant->payment_method ?: 'cod_online',
            'stock' => (int) $variant->stock,
            'is_default' => (bool) $variant->is_default,
            'is_active' => (bool) $variant->is_active,
            'description' => $variant->description ?: 'Default',
            'option_values' => $variant->optionValues
                ->map(fn (ProductOptionValue $value): array => [
                    'id' => $value->id,
                    'option_id' => $value->product_option_id,
                    'option' => $value->option?->name,
                    'value' => $value->value,
                ])
                ->values(),
            'image_urls' => $assignedImage->isNotEmpty() ? $assignedImage->values() : $fallbackImages->values(),
        ];
    }

    public function sellerByRouteKey(string|int $seller): SellerProfile
    {
        $query = SellerProfile::query()
            ->active()
            ->with(['user', 'primaryCategory']);

        if (ctype_digit((string) $seller)) {
            return $query->whereKey((int) $seller)->firstOrFail();
        }

        $slug = Str::slug((string) $seller);
        if ($slug === '') {
            throw (new ModelNotFoundException())->setModel(SellerProfile::class, [(string) $seller]);
        }

        $nameParts = array_map(
            static fn (string $part): string => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $part),
            explode('-', $slug),
        );
        $pattern = '%'.implode('%', $nameParts).'%';
        $sellerProfile = $query
            ->whereRaw("LOWER(business_name) LIKE ? ESCAPE '!'", [$pattern])
            ->get()
            ->first(fn (SellerProfile $profile): bool => Str::slug($profile->business_name) === $slug);

        if ($sellerProfile) {
            return $sellerProfile;
        }

        throw (new ModelNotFoundException())->setModel(SellerProfile::class, [(string) $seller]);
    }

    public function sellerRating(SellerProfile $seller): float
    {
        $average = Review::query()
            ->where('status', 'PUBLISHED')
            ->whereHas('orderItem.sellerOrder', fn (Builder $query) => $query->where('seller_profile_id', $seller->id))
            ->avg('rating');

        return round((float) ($average ?? 0), 1);
    }

    private function maskedBuyerName(?string $name): string
    {
        $name = preg_replace('/\s+/u', '', trim((string) $name));
        $length = mb_strlen($name);

        if ($length === 0) {
            return 'Verified Buyer';
        }

        if ($length <= 4) {
            return mb_substr($name, 0, 1).str_repeat('*', 3);
        }

        return mb_substr($name, 0, 2).'****'.mb_substr($name, -2);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $images
     * @return array<int, array<string, mixed>>
     */
    private function descriptionBlocks(Product $product, Collection $images): array
    {
        $description = (string) $product->description;
        $imagesById = $images->keyBy('id');
        $filenamePattern = '(?:[A-Za-z0-9]{40}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})\.(?:jpg|jpeg|png|webp)';
        $pattern = '/\[\[image:image:(\d+)\]\]|\[\[description-image:stored:('.$filenamePattern.')\]\]/i';
        $matched = preg_match_all($pattern, $description, $matches, PREG_OFFSET_CAPTURE);

        if (! $matched) {
            return $description === '' ? [] : [['type' => 'text', 'text' => $description]];
        }

        $blocks = [];
        $offset = 0;
        foreach ($matches[0] as $index => [$token, $tokenOffset]) {
            $text = substr($description, $offset, $tokenOffset - $offset);
            if ($text !== '') {
                $blocks[] = ['type' => 'text', 'text' => $text];
            }

            $imageId = $matches[1][$index][0] ?? '';
            $filename = $matches[2][$index][0] ?? '';
            $image = $imageId !== '' ? $imagesById->get((int) $imageId) : null;
            if ($image) {
                $blocks[] = [
                    'type' => 'image',
                    'url' => $image['url'],
                    'alt' => $image['alt_text'] ?: 'Product description image',
                ];
            } elseif ($filename !== '') {
                $path = "sellers/{$product->seller_profile_id}/products/{$product->id}/descriptions/{$filename}";
                if (Storage::disk('public')->exists($path)) {
                    $blocks[] = [
                        'type' => 'image',
                        'url' => $this->publicUrl($path),
                        'alt' => 'Product description image',
                    ];
                }
            }

            $offset = $tokenOffset + strlen($token);
        }

        $remainingText = substr($description, $offset);
        if ($remainingText !== '') {
            $blocks[] = ['type' => 'text', 'text' => $remainingText];
        }

        return $blocks;
    }

    public function categories()
    {
        return Category::query()
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->with('parent')
            ->withCount(['products' => fn (Builder $products) => $products->visible()])
            ->orderBy('name')
            ->get();
    }

    public function maxVisiblePrice(): int
    {
        $price = ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', fn (Builder $product) => $product->visible())
            ->max('price');

        return max(10000, (int) ceil((float) ($price ?? 0)));
    }

    public function publicUrl(?string $path): ?string
    {
        if (! filled($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return '/storage/'.ltrim($path, '/');
    }
}
