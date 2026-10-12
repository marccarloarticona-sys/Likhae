<?php

namespace App\Services\Marketplace;

use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductImage;
use App\Models\Seller\ProductOptionValue;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\Buyer\Review;
use App\Services\ReviewImageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductCatalogService
{
    public function __construct(private readonly ReviewImageService $reviewImages) {}

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

        if ($request->filled('min_price')) {
            $query->whereHas('variants', fn (Builder $variant) => $variant->where('price', '>=', (float) $request->query('min_price')));
        }

        if ((float) $request->query('max_price', 0) > 0) {
            $query->whereHas('variants', fn (Builder $variant) => $variant->where('price', '<=', (float) $request->query('max_price')));
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

    public function applySort(Builder $query, ?string $sort): Builder
    {
        return match ($sort) {
            'name' => $query->orderBy('name'),
            'price-low' => $query->orderBy(
                ProductVariant::selectRaw('MIN(price)')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->where('is_active', true)
            ),
            'price-high' => $query->orderByDesc(
                ProductVariant::selectRaw('MIN(price)')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->where('is_active', true)
            ),
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
    public function productPayload(Product $product, bool $includeDetails = false): array
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
        $minPrice = $activeVariants->min(fn (ProductVariant $variant) => $variant->final_price);

        $payload = [
            'id' => $product->id,
            'seller_profile_id' => $product->seller_profile_id,
            'category_id' => $product->category_id,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $includeDetails ? $product->description : str($product->description ?? '')->limit(180)->toString(),
            'status' => $product->status,
            'rating' => (float) ($product->reviews_avg_rating ?? 0),
            'reviews' => (int) ($product->reviews_count ?? 0),
            'sold' => (int) ($product->order_items_sum_quantity ?? 0),
            'min_price' => $minPrice === null ? null : number_format((float) $minPrice, 2, '.', ''),
            'stock' => $activeVariants->sum('stock'),
            'primary_image' => $primaryImage,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ] : null,
            'seller' => $product->sellerProfile ? [
                'id' => $product->sellerProfile->id,
                'user_id' => $product->sellerProfile->user_id,
                'business_name' => $product->sellerProfile->business_name,
                'status' => $product->sellerProfile->status,
            ] : null,
            'images' => $images,
            'variants' => $activeVariants
                ->map(fn (ProductVariant $variant): array => $this->variantPayload($variant))
                ->values(),
        ];

        if ($includeDetails) {
            $product->loadMissing(['reviews.buyer', 'reviews.orderItem']);
            $reviewImageUrls = $this->reviewImages->urlsFor($product->reviews);
            $payload['customer_reviews'] = $product->reviews
                ->sortByDesc('created_at')
                ->map(fn (Review $review): array => [
                    'name' => $this->maskedBuyerName($review->buyer?->name),
                    'rating' => (int) $review->rating,
                    'comment' => $review->comment,
                    'image_url' => $reviewImageUrls[$review->id] ?? null,
                    'date' => $review->created_at?->format('M j, Y') ?? 'Recently',
                    'variant' => $review->orderItem?->variant_description ?: 'Standard',
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

    /** @return array<string, mixed> */
    public function variantPayload(ProductVariant $variant): array
    {
        $variant->loadMissing(['optionValues.option', 'optionValues.images', 'productImage']);
        $discountType = $variant->resolved_discount_type;
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
            'price' => number_format($variant->final_price, 2, '.', ''),
            'original_price' => number_format((float) $variant->price, 2, '.', ''),
            'discount_type' => $discountType === 'none' ? null : $discountType,
            'discount_value' => number_format((float) ($variant->discount_value ?? 0), 2, '.', ''),
            'discount_percentage' => $variant->discount_percentage,
            'discount_amount' => number_format($variant->discount_amount, 2, '.', ''),
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
        return SellerProfile::query()
            ->active()
            ->with(['user', 'primaryCategory'])
            ->where(function (Builder $query) use ($seller): void {
                if (ctype_digit((string) $seller)) {
                    $query->whereKey((int) $seller);
                    return;
                }

                $name = str_replace('-', ' ', (string) $seller);
                $query->where('business_name', $name)
                    ->orWhere('business_name', (string) $seller);
            })
            ->firstOrFail();
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
