<?php

namespace App\Services\Seller;

use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\SellerPromotion;
use App\Models\Seller\Voucher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SellerPromotionConflictService
{
    /**
     * Build active and scheduled offer warnings for the supplied Seller products.
     *
     * @return array{products: array<int, array<int, array{kind:string,label:string,status:string}>>, variants: array<int, array<int, array{kind:string,label:string,status:string}>>}
     */
    public function forProducts(SellerProfile $seller, Collection $products): array
    {
        $conflicts = ['products' => [], 'variants' => []];
        $variantsById = [];
        $flashSaleSchemaReady = $this->flashSaleSchemaReady();
        $now = now();

        foreach ($products as $product) {
            $conflicts['products'][(int) $product->id] = [];
            foreach ($product->variants->where('is_active', true) as $variant) {
                $variant->setRelation('product', $product);
                $variantsById[(int) $variant->id] = $variant;
                $conflicts['variants'][(int) $variant->id] = [];

                $discountType = $variant->resolved_discount_type;
                $hasDiscount = (bool) ($product->discounts_enabled ?? true)
                    && $discountType !== 'none' && (float) $variant->discount_value > 0;
                $hasFlashSale = $flashSaleSchemaReady
                    && $variant->flash_sale_price !== null
                    && $variant->flash_sale_remaining > 0
                    && ! ($product->flash_sale_ends_at?->isPast() ?? false);
                $discountStartsAt = Schema::hasColumn('products', 'discount_starts_at')
                    ? $product->discount_starts_at
                    : ($variant->flash_sale_price === null ? $product->flash_sale_starts_at : null);
                $discountEndsAt = Schema::hasColumn('products', 'discount_ends_at')
                    ? $product->discount_ends_at
                    : ($variant->flash_sale_price === null ? $product->flash_sale_ends_at : null);
                $discountWindowOpen = ! $discountEndsAt || ! $discountEndsAt->isPast();
                if ($hasDiscount && $discountWindowOpen) {
                    $this->add($conflicts, $product, $variant, [
                        'kind' => 'discount',
                        'label' => 'Seller price discount · '.($variant->description ?: $variant->sku),
                        'status' => $discountStartsAt?->isFuture() ? 'Scheduled' : 'Active',
                    ]);
                }

                // A flash sale and a product discount can coexist as base
                // prices; ProductPriceResolver gives the flash sale priority.
                if ($hasFlashSale) {
                    $this->add($conflicts, $product, $variant, [
                        'kind' => 'flash_sale',
                        'label' => 'Flash sale · '.($variant->description ?: $variant->sku),
                        'status' => (bool) ($product->flash_sale_is_paused ?? false)
                            ? 'Paused'
                            : ($product->flash_sale_starts_at?->isFuture() ? 'Scheduled' : 'Active'),
                    ]);
                }
            }
        }

        $this->addVoucherConflicts($seller, $products, $variantsById, $conflicts);
        $this->addSpecialOfferConflicts($seller, $variantsById, $conflicts);

        return $conflicts;
    }

    /**
     * @param array<int> $variantIds
     * @param array{exclude_kinds?: array<int, string>, exclude_ids?: array<string, array<int>>} $exclude
     * @return Collection<int, array{kind:string,label:string,status:string,id?:int}>
     */
    public function conflictsForVariantIds(SellerProfile $seller, array $variantIds, array $exclude = []): Collection
    {
        $variants = ProductVariant::query()
            ->whereIn('id', array_map('intval', $variantIds))
            ->where('is_active', true)
            ->whereHas('product', fn ($query) => $query
                ->where('seller_profile_id', $seller->id)
                ->where('status', 'ACTIVE'))
            ->with('product')
            ->get();
        $products = $variants->map(fn (ProductVariant $variant) => $variant->product)
            ->filter()
            ->unique('id')
            ->values();

        if ($products->isEmpty()) {
            return collect();
        }

        $map = $this->forProducts($seller, $products);
        $excludedKinds = $exclude['exclude_kinds'] ?? [];
        $excludedIds = $exclude['exclude_ids'] ?? [];

        return $variants
            ->flatMap(fn (ProductVariant $variant) => $map['variants'][(int) $variant->id] ?? [])
            ->reject(function (array $entry) use ($excludedKinds, $excludedIds): bool {
                return in_array($entry['kind'], $excludedKinds, true)
                    || (isset($entry['id']) && in_array((int) $entry['id'], array_map('intval', $excludedIds[$entry['kind']] ?? []), true));
            })
            ->unique(fn (array $entry): string => implode('|', [
                $entry['kind'],
                $entry['id'] ?? '',
                $entry['label'],
                $entry['status'],
            ]))
            ->values();
    }

    private function addVoucherConflicts(SellerProfile $seller, Collection $products, array $variantsById, array &$conflicts): void
    {
        if (! Schema::hasTable('vouchers')) {
            return;
        }

        $now = now();
        $vouchers = Voucher::query()
            ->where('seller_profile_id', $seller->id)
            ->when(Schema::hasColumn('vouchers', 'benefit_scope'), fn ($query) => $query->where('benefit_scope', 'PRODUCT'))
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->withCount(['sellerOrders' => fn ($query) => $query->where('status', '<>', 'CANCELLED')])
            ->get();
        $vouchers = $vouchers->filter(fn (Voucher $voucher): bool => ! $voucher->requiresReview()
            && ($voucher->usage_limit === null
                || (int) $voucher->seller_orders_count < (int) $voucher->usage_limit));

        if ($vouchers->isEmpty()) {
            return;
        }

        $voucherIds = $vouchers->modelKeys();
        $appliesToReady = Schema::hasColumn('vouchers', 'applies_to');
        $productTargetsReady = $appliesToReady && Schema::hasTable('voucher_products');
        $variantTargetsReady = $productTargetsReady && Schema::hasTable('voucher_variants');
        $productTargetIds = $productTargetsReady
            ? DB::table('voucher_products')->whereIn('voucher_id', $voucherIds)->get(['voucher_id', 'product_id'])->groupBy('voucher_id')
            : collect();
        $variantTargetIds = $variantTargetsReady
            ? DB::table('voucher_variants')->whereIn('voucher_id', $voucherIds)->get(['voucher_id', 'product_variant_id'])->groupBy('voucher_id')
            : collect();
        $productIds = $products->mapWithKeys(fn (Product $product) => [(int) $product->id => true]);

        foreach ($vouchers as $voucher) {
            $appliesTo = $appliesToReady ? strtoupper((string) $voucher->applies_to) : 'STORE';
            $entry = [
                'kind' => 'voucher',
                'id' => (int) $voucher->id,
                'label' => 'Seller voucher · '.($voucher->name ?: $voucher->code),
                'status' => $voucher->starts_at?->isFuture() ? 'Scheduled' : 'Active',
            ];

            if ($appliesTo === 'STORE') {
                foreach ($products as $product) {
                    foreach ($product->variants as $variant) {
                        $this->add($conflicts, $product, $variant, $entry);
                    }
                }
            } elseif ($appliesTo === 'PRODUCTS') {
                if (! $productTargetsReady) {
                    continue;
                }
                foreach ($productTargetIds->get($voucher->id, collect()) as $target) {
                    $targetProductId = (int) $target->product_id;
                    if (! $productIds->has($targetProductId)) {
                        continue;
                    }
                    $product = $products->firstWhere('id', $targetProductId);
                    foreach ($product?->variants ?? [] as $variant) {
                        $this->add($conflicts, $product, $variant, $entry);
                    }
                }
            } elseif ($appliesTo === 'VARIANTS') {
                if (! $variantTargetsReady) {
                    continue;
                }
                foreach ($variantTargetIds->get($voucher->id, collect()) as $target) {
                    $variant = $variantsById[(int) $target->product_variant_id] ?? null;
                    if (! $variant) {
                        continue;
                    }
                    $product = $products->firstWhere('id', (int) $variant->product_id);
                    if ($product) {
                        $this->add($conflicts, $product, $variant, $entry);
                    }
                }
            }
        }
    }

    private function addSpecialOfferConflicts(SellerProfile $seller, array $variantsById, array &$conflicts): void
    {
        if (! Schema::hasTable('seller_promotions') || ! Schema::hasTable('seller_promotion_components')) {
            return;
        }

        $now = now();
        $query = $seller->promotions()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->with(['components.productVariant', 'buyVariant', 'rewardVariant']);
        if (Schema::hasColumn('seller_promotions', 'reward_product_id')) {
            $query->with('rewardProduct');
        }
        $offers = $query->get();

        foreach ($offers as $offer) {
            $entry = [
                'kind' => 'special_offer',
                'id' => (int) $offer->id,
                'label' => 'Special offer · '.$offer->name,
                'status' => $offer->starts_at?->isFuture() ? 'Scheduled' : 'Active',
            ];
            $variantIds = $offer->promotion_type === SellerPromotion::TYPE_BUNDLE
                ? $offer->components->pluck('product_variant_id')
                : collect([$offer->buy_variant_id, $offer->reward_variant_id]);
            if ($offer->promotion_type === SellerPromotion::TYPE_BUY_X_GET_Y
                && Schema::hasColumn('seller_promotions', 'reward_product_id')
                && $offer->reward_product_id) {
                $variantIds = $variantIds->merge(collect($variantsById)
                    ->filter(fn (ProductVariant $variant): bool => (int) $variant->product_id === (int) $offer->reward_product_id)
                    ->keys());
            }

            foreach ($variantIds->filter()->unique() as $variantId) {
                $variant = $variantsById[(int) $variantId] ?? null;
                if (! $variant) {
                    continue;
                }
                $product = $variant->relationLoaded('product') ? $variant->getRelation('product') : null;
                if ($product) {
                    $this->add($conflicts, $product, $variant, $entry);
                }
            }
        }
    }

    private function add(array &$conflicts, Product $product, ProductVariant $variant, array $entry): void
    {
        $productId = (int) $product->id;
        $variantId = (int) $variant->id;
        $key = implode('|', [$entry['kind'], $entry['label'], $entry['status']]);

        $conflicts['products'][$productId][$key] = $entry;
        $conflicts['variants'][$variantId][$key] = $entry;
    }

    private function flashSaleSchemaReady(): bool
    {
        return Schema::hasColumn('products', 'flash_sale_starts_at')
            && Schema::hasColumn('products', 'flash_sale_ends_at')
            && Schema::hasColumn('product_variants', 'flash_sale_price')
            && Schema::hasColumn('product_variants', 'flash_sale_allocation')
            && Schema::hasColumn('product_variants', 'flash_sale_sold');
    }
}
