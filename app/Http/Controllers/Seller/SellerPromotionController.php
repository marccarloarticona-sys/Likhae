<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\SellerPromotion;
use App\Services\Seller\SellerPromotionConflictService;
use App\Support\FinancialRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SellerPromotionController extends Controller
{
    public function index(Request $request, SellerPromotionConflictService $promotionConflictService): View
    {
        $seller = $this->seller($request);
        $promotionStorageReady = $this->promotionStorageReady();
        $variants = collect();
        $promotions = collect();
        $promotionConflicts = ['products' => [], 'variants' => []];

        if ($promotionStorageReady) {
            $products = $seller->products()
                ->where('status', 'ACTIVE')
                ->whereHas('variants', fn ($query) => $query->where('is_active', true))
                ->with(['variants' => fn ($query) => $query->where('is_active', true)->with('product')])
                ->orderBy('name')
                ->get();
            $variants = $products->flatMap(fn ($product) => $product->variants)
                ->sortBy(fn (ProductVariant $variant) => $variant->product->name.' '.$variant->description)
                ->values();
            $promotionConflicts = $promotionConflictService->forProducts($seller, $products);

            $promotions = $seller->promotions()
                ->with(['components.productVariant.product', 'buyVariant.product', 'rewardVariant.product', 'rewardProduct'])
                ->latest('id')
                ->paginate(15);
        }

        return view('seller.promotions.index', [
            'seller' => $seller,
            'variants' => $variants,
            'promotions' => $promotions,
            'promotionStorageReady' => $promotionStorageReady,
            'promotionConflicts' => $promotionConflicts,
        ]);
    }

    public function store(Request $request, SellerPromotionConflictService $promotionConflictService): RedirectResponse
    {
        $seller = $this->seller($request);
        if (! $this->promotionStorageReady()) {
            return back()->withErrors([
                'promotion' => 'Product promotions are temporarily unavailable while their database migration is pending.',
            ]);
        }

        $request->merge([
            'promotion_type' => strtoupper(trim((string) $request->input('promotion_type', ''))),
            'name' => trim((string) $request->input('name', '')),
        ]);
        $type = $request->input('promotion_type');

        $data = $request->validate([
            'promotion_type' => ['required', Rule::in([SellerPromotion::TYPE_BUNDLE, SellerPromotion::TYPE_BUY_X_GET_Y])],
            'name' => ['required', 'string', 'max:150'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'bundle_price' => ['required_if:promotion_type,'.SellerPromotion::TYPE_BUNDLE, 'nullable', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'components' => ['required_if:promotion_type,'.SellerPromotion::TYPE_BUNDLE, 'array', 'min:1', 'max:10'],
            'components.*.product_variant_id' => ['required_if:promotion_type,'.SellerPromotion::TYPE_BUNDLE, 'integer', 'distinct', 'exists:product_variants,id'],
            'components.*.quantity' => ['required_if:promotion_type,'.SellerPromotion::TYPE_BUNDLE, 'integer', 'min:1', 'max:99'],
            'components.*.choice_group' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9_-]+$/'],
            'buy_variant_id' => ['required_if:promotion_type,'.SellerPromotion::TYPE_BUY_X_GET_Y, 'integer', 'exists:product_variants,id'],
            'buy_quantity' => ['required_if:promotion_type,'.SellerPromotion::TYPE_BUY_X_GET_Y, 'integer', 'min:1', 'max:99'],
            'reward_product_id' => ['required_if:promotion_type,'.SellerPromotion::TYPE_BUY_X_GET_Y, 'integer', 'exists:products,id'],
            'reward_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'reward_quantity' => ['required_if:promotion_type,'.SellerPromotion::TYPE_BUY_X_GET_Y, 'integer', 'min:1', 'max:99'],
            'reward_discount_percent' => ['required_if:promotion_type,'.SellerPromotion::TYPE_BUY_X_GET_Y, 'numeric', 'gt:0', 'max:100'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_per_buyer' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        if (! empty($data['starts_at']) && ! empty($data['ends_at'])
            && Carbon::parse($data['ends_at'])->lessThanOrEqualTo(Carbon::parse($data['starts_at']))) {
            throw ValidationException::withMessages(['ends_at' => 'The promotion end time must be later than its start time.']);
        }

        DB::transaction(function () use ($data, $seller, $type, $promotionConflictService): void {
            if ($type === SellerPromotion::TYPE_BUNDLE) {
                $rows = collect($data['components'])->map(fn (array $row): array => [
                    'product_variant_id' => (int) $row['product_variant_id'],
                    'quantity' => (int) $row['quantity'],
                    'choice_group' => filled($row['choice_group'] ?? null) ? strtolower(trim((string) $row['choice_group'])) : null,
                ])->values();
                $choiceGroups = $rows->filter(fn (array $row): bool => $row['choice_group'] !== null)->groupBy('choice_group');
                if ($choiceGroups->contains(fn ($options): bool => $options->pluck('quantity')->unique()->count() !== 1)) {
                    throw ValidationException::withMessages([
                        'components' => 'Every option in the same choice group must use the same quantity.',
                    ]);
                }
                $variants = $this->lockOwnedActiveVariants($seller, $rows->pluck('product_variant_id')->all());
                $regularBundleTotal = $this->bundleMinimumTotal($rows, $variants);
                $this->assertBundlePricesStayPositive($rows->all(), $variants, (float) $data['bundle_price'], $regularBundleTotal);
                if ((float) $data['bundle_price'] >= $regularBundleTotal) {
                    throw ValidationException::withMessages([
                    'bundle_price' => 'The bundle price must be less than the lowest possible combined price for one bundle set.',
                    ]);
                }

                $conflicts = $promotionConflictService->conflictsForVariantIds($seller, $rows->pluck('product_variant_id')->all());
                if ($conflicts->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'components' => 'This bundle overlaps an active or scheduled Seller discount, flash sale, voucher, or special offer. Clear the existing offer first: '.$conflicts->pluck('label')->unique()->implode(', ').'.',
                    ]);
                }

                $promotion = $seller->promotions()->create([
                    'name' => $data['name'],
                    'promotion_type' => SellerPromotion::TYPE_BUNDLE,
                    'bundle_price' => $data['bundle_price'],
                    'max_redemptions' => $data['max_redemptions'] ?? null,
                    'max_per_buyer' => $data['max_per_buyer'] ?? null,
                    'redemptions_used' => 0,
                    'starts_at' => $data['starts_at'] ?? null,
                    'ends_at' => $data['ends_at'] ?? null,
                    'is_active' => true,
                ]);
                $promotion->components()->createMany($rows->all());

                return;
            }

            $buyVariantId = (int) $data['buy_variant_id'];
            $rewardProductId = (int) $data['reward_product_id'];
            $buyVariant = $this->lockOwnedActiveVariants($seller, [$buyVariantId])->get($buyVariantId);
            $rewardProduct = $seller->products()
                ->whereKey($rewardProductId)
                ->where('status', 'ACTIVE')
                ->with(['variants' => fn ($query) => $query->where('is_active', true)->orderBy('id')->lockForUpdate()])
                ->lockForUpdate()
                ->first();
            if (! $rewardProduct || $rewardProduct->variants->isEmpty()) {
                throw ValidationException::withMessages(['reward_product_id' => 'Choose one of your own published products with an active variation as the reward.']);
            }
            $rewardVariantId = filled($data['reward_variant_id'] ?? null) ? (int) $data['reward_variant_id'] : null;
            if ($rewardVariantId !== null && ! $rewardProduct->variants->contains('id', $rewardVariantId)) {
                throw ValidationException::withMessages(['reward_variant_id' => 'The selected reward variation must belong to the selected reward product.']);
            }
            $targetRewardVariants = $rewardVariantId
                ? $rewardProduct->variants->where('id', $rewardVariantId)->values()
                : $rewardProduct->variants->values();
            if ($targetRewardVariants->contains(fn (ProductVariant $variant): bool => (float) $variant->final_price < 0.01)) {
                throw ValidationException::withMessages(['reward_product_id' => 'All reward variations must have a normal selling price of at least PHP 0.01.']);
            }
            $targetIds = collect([$buyVariantId])->merge($targetRewardVariants->pluck('id'))->map(fn ($id): int => (int) $id)->unique()->values()->all();
            $conflicts = $promotionConflictService->conflictsForVariantIds($seller, $targetIds);
            if ($conflicts->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'buy_variant_id' => 'This offer overlaps an active or scheduled discount, flash sale, voucher, or special offer. Clear the conflicting campaign first: '.$conflicts->pluck('label')->unique()->implode(', ').'.',
                ]);
            }

            $seller->promotions()->create([
                'name' => $data['name'],
                'promotion_type' => SellerPromotion::TYPE_BUY_X_GET_Y,
                'buy_variant_id' => $buyVariant->id,
                'buy_quantity' => (int) $data['buy_quantity'],
                'reward_variant_id' => $rewardVariantId,
                'reward_product_id' => $rewardProductId,
                'reward_quantity' => (int) $data['reward_quantity'],
                'reward_discount_percent' => $data['reward_discount_percent'],
                'max_redemptions' => $data['max_redemptions'] ?? null,
                'max_per_buyer' => $data['max_per_buyer'] ?? null,
                'redemptions_used' => 0,
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'is_active' => true,
            ]);
        }, attempts: 3);

        return back()->with('status', 'Promotion saved. Discounts apply at checkout when the required products and quantities are in the cart.');
    }

    public function toggle(Request $request, int $promotion, SellerPromotionConflictService $promotionConflictService): RedirectResponse
    {
        $seller = $this->seller($request);
        abort_unless($this->promotionStorageReady(), 503);

        $promotion = SellerPromotion::query()->findOrFail($promotion);
        abort_unless((int) $promotion->seller_profile_id === (int) $seller->id, 404);

        DB::transaction(function () use ($promotion, $seller, $promotionConflictService): void {
            $locked = SellerPromotion::query()->whereKey($promotion->id)->lockForUpdate()->firstOrFail();
            if (! $locked->is_active) {
                $targetVariantIds = [];
                if ($locked->promotion_type === SellerPromotion::TYPE_BUNDLE) {
                    $locked->load('components.productVariant');
                    $rows = $locked->components->map(fn ($component): array => [
                        'product_variant_id' => (int) $component->product_variant_id,
                        'quantity' => (int) $component->quantity,
                        'choice_group' => $component->choice_group,
                    ])->all();
                    $variants = $this->lockOwnedActiveVariants($seller, array_column($rows, 'product_variant_id'));
                    $regularBundleTotal = $this->bundleMinimumTotal(collect($rows), $variants);
                    $this->assertBundlePricesStayPositive($rows, $variants, (float) $locked->bundle_price, $regularBundleTotal);
                    if ((float) $locked->bundle_price >= $regularBundleTotal) {
                        throw ValidationException::withMessages(['promotion' => 'The bundle price must remain below the lowest possible combined price for one bundle set.']);
                    }
                    $targetVariantIds = array_column($rows, 'product_variant_id');
                } elseif ($locked->promotion_type === SellerPromotion::TYPE_BUY_X_GET_Y) {
                    $locked->load(['rewardProduct.variants' => fn ($query) => $query->where('is_active', true)]);
                    $rewardIds = $locked->reward_variant_id
                        ? [(int) $locked->reward_variant_id]
                        : $locked->rewardProduct?->variants?->pluck('id')->map(fn ($id): int => (int) $id)->all() ?? [];
                    $targetVariantIds = collect([(int) $locked->buy_variant_id])->merge($rewardIds)->filter()->unique()->values()->all();
                    $variants = $this->lockOwnedActiveVariants($seller, $targetVariantIds);
                    if (! $locked->buy_variant_id || $variants->get((int) $locked->buy_variant_id) === null
                        || $variants->filter(fn (ProductVariant $variant): bool => (float) $variant->final_price < 0.01)->isNotEmpty()) {
                        throw ValidationException::withMessages(['promotion' => 'Every qualifying and reward variation must be active, Seller-owned, and normally priced at PHP 0.01 or more.']);
                    }
                }

                $conflicts = $promotionConflictService->conflictsForVariantIds($seller, $targetVariantIds, [
                    'exclude_ids' => ['special_offer' => [$locked->id]],
                ]);
                if ($conflicts->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'promotion' => 'This bundle overlaps an active or scheduled Seller discount, flash sale, voucher, or special offer. Clear the existing offer first: '.$conflicts->pluck('label')->unique()->implode(', ').'.',
                    ]);
                }
            }
            $locked->update(['is_active' => ! $locked->is_active]);
        }, attempts: 3);

        return back()->with('status', 'Promotion status updated.');
    }

    private function seller(Request $request): SellerProfile
    {
        $seller = $request->user()?->sellerProfile;
        abort_unless($seller, 403);

        return $seller;
    }

    private function promotionStorageReady(): bool
    {
        return Schema::hasTable('seller_promotions')
            && Schema::hasTable('seller_promotion_components')
            && Schema::hasColumn('seller_promotions', 'reward_product_id')
            && Schema::hasColumn('seller_promotions', 'reward_discount_percent')
            && Schema::hasColumn('seller_promotions', 'max_redemptions')
            && Schema::hasColumn('seller_promotions', 'max_per_buyer')
            && Schema::hasColumn('seller_promotions', 'redemptions_used')
            && Schema::hasColumn('seller_promotion_components', 'choice_group');
    }

    private function bundleMinimumTotal($rows, $variants): float
    {
        $groups = $rows->groupBy(fn (array $row): string => filled($row['choice_group'] ?? null)
            ? 'choice:'.$row['choice_group']
            : 'variant:'.$row['product_variant_id']);

        return (float) $groups->sum(function ($options) use ($variants): float {
            $quantity = (int) $options->first()['quantity'];

            return (float) $options->map(fn (array $row): float => (float) $variants->get($row['product_variant_id'])->final_price)
                ->min() * $quantity;
        });
    }

    /** @param array<int, array{product_variant_id:int,quantity:int,choice_group?:?string}> $rows */
    private function assertBundlePricesStayPositive(array $rows, $variants, float $bundlePrice, float $regularBundleTotal): void
    {
        if ($regularBundleTotal <= 0) {
            throw ValidationException::withMessages(['bundle_price' => 'The selected variants must have a positive selling price.']);
        }

        $groups = collect($rows)->groupBy(fn (array $row): string => filled($row['choice_group'] ?? null)
            ? 'choice:'.$row['choice_group']
            : 'variant:'.$row['product_variant_id']);
        $maximumTotalCents = (int) $groups->sum(function ($options) use ($variants): int {
            $quantity = (int) $options->first()['quantity'];

            return FinancialRules::cents((float) $options->map(fn (array $row): float => (float) $variants->get((int) $row['product_variant_id'])->final_price)->max() * $quantity);
        });
        $bundlePriceCents = FinancialRules::cents($bundlePrice);

        foreach ($rows as $row) {
            $quantity = (int) $row['quantity'];
            $variant = $variants->get((int) $row['product_variant_id']);
            $lineCents = FinancialRules::cents((float) $variant->final_price * $quantity);
            $allocatedLineCents = $maximumTotalCents > 0
                ? intdiv($lineCents * $bundlePriceCents, $maximumTotalCents)
                : 0;
            if ($allocatedLineCents < $quantity) {
                throw ValidationException::withMessages([
                    'bundle_price' => 'This bundle price would make '.$variant->product?->name.' ('.($variant->description ?: $variant->sku).') free. Increase the bundle price so every item stays at least PHP 0.01.',
                ]);
            }
        }
    }

    /** @param array<int> $variantIds
     *  @return \Illuminate\Support\Collection<int, ProductVariant>
     */
    private function lockOwnedActiveVariants(SellerProfile $seller, array $variantIds)
    {
        $variantIds = collect($variantIds)->map(fn ($id): int => (int) $id)->unique()->values();
        $variants = ProductVariant::query()
            ->whereIn('id', $variantIds)
            ->where('is_active', true)
            ->whereHas('product', fn ($query) => $query
                ->where('seller_profile_id', $seller->id)
                ->where('status', 'ACTIVE'))
            ->with(['product.sellerProfile.user', 'optionValues.option'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($variants->count() !== $variantIds->count()) {
            throw ValidationException::withMessages([
                'components' => 'Promotions can only use active variants from your own published products.',
                'buy_variant_id' => 'Promotions can only use active variants from your own published products.',
                'reward_variant_id' => 'Promotions can only use active variants from your own published products.',
            ]);
        }

        return $variants;
    }
}
