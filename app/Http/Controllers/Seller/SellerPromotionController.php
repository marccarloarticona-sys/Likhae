<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\SellerPromotion;
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
    public function index(Request $request): View
    {
        $seller = $this->seller($request);
        $promotionStorageReady = $this->promotionStorageReady();
        $variants = collect();
        $promotions = collect();

        if ($promotionStorageReady) {
            $variants = $seller->products()
                ->where('status', 'ACTIVE')
                ->whereHas('variants', fn ($query) => $query->where('is_active', true))
                ->with(['variants' => fn ($query) => $query->where('is_active', true)->with('product')])
                ->orderBy('name')
                ->get()
                ->flatMap(fn ($product) => $product->variants)
                ->sortBy(fn (ProductVariant $variant) => $variant->product->name.' '.$variant->description)
                ->values();

            $promotions = $seller->promotions()
                ->with(['components.productVariant.product', 'buyVariant.product', 'rewardVariant.product'])
                ->latest('id')
                ->paginate(15);
        }

        return view('seller.promotions.index', [
            'seller' => $seller,
            'variants' => $variants,
            'promotions' => $promotions,
            'promotionStorageReady' => $promotionStorageReady,
        ]);
    }

    public function store(Request $request): RedirectResponse
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
            'bundle_price' => ['required_if:promotion_type,'.SellerPromotion::TYPE_BUNDLE, 'nullable', 'numeric', 'gt:0', 'max:9999999999.99'],
            'components' => ['required_if:promotion_type,'.SellerPromotion::TYPE_BUNDLE, 'array', 'min:2', 'max:10'],
            'components.*.product_variant_id' => ['required_if:promotion_type,'.SellerPromotion::TYPE_BUNDLE, 'integer', 'distinct', 'exists:product_variants,id'],
            'components.*.quantity' => ['required_if:promotion_type,'.SellerPromotion::TYPE_BUNDLE, 'integer', 'min:1', 'max:99'],
            'buy_variant_id' => ['required_if:promotion_type,'.SellerPromotion::TYPE_BUY_X_GET_Y, 'integer', 'exists:product_variants,id'],
            'buy_quantity' => ['required_if:promotion_type,'.SellerPromotion::TYPE_BUY_X_GET_Y, 'integer', 'min:1', 'max:99'],
            'reward_variant_id' => ['required_if:promotion_type,'.SellerPromotion::TYPE_BUY_X_GET_Y, 'integer', 'exists:product_variants,id'],
            'reward_quantity' => ['required_if:promotion_type,'.SellerPromotion::TYPE_BUY_X_GET_Y, 'integer', 'min:1', 'max:99'],
        ]);

        if (! empty($data['starts_at']) && ! empty($data['ends_at'])
            && Carbon::parse($data['ends_at'])->lessThanOrEqualTo(Carbon::parse($data['starts_at']))) {
            throw ValidationException::withMessages(['ends_at' => 'The promotion end time must be later than its start time.']);
        }

        DB::transaction(function () use ($data, $seller, $type): void {
            if ($type === SellerPromotion::TYPE_BUNDLE) {
                $rows = collect($data['components'])->map(fn (array $row): array => [
                    'product_variant_id' => (int) $row['product_variant_id'],
                    'quantity' => (int) $row['quantity'],
                ])->values();
                $variants = $this->lockOwnedActiveVariants($seller, $rows->pluck('product_variant_id')->all());
                $regularBundleTotal = $rows->sum(fn (array $row): float => (float) $variants->get($row['product_variant_id'])->final_price * $row['quantity']);
                if ((float) $data['bundle_price'] >= $regularBundleTotal) {
                    throw ValidationException::withMessages([
                        'bundle_price' => 'The bundle price must be less than the current combined price of its components.',
                    ]);
                }

                $promotion = $seller->promotions()->create([
                    'name' => $data['name'],
                    'promotion_type' => SellerPromotion::TYPE_BUNDLE,
                    'bundle_price' => $data['bundle_price'],
                    'starts_at' => $data['starts_at'] ?? null,
                    'ends_at' => $data['ends_at'] ?? null,
                    'is_active' => true,
                ]);
                $promotion->components()->createMany($rows->all());

                return;
            }

            $variants = $this->lockOwnedActiveVariants($seller, [
                (int) $data['buy_variant_id'],
                (int) $data['reward_variant_id'],
            ]);
            $seller->promotions()->create([
                'name' => $data['name'],
                'promotion_type' => SellerPromotion::TYPE_BUY_X_GET_Y,
                'buy_variant_id' => $variants->get((int) $data['buy_variant_id'])->id,
                'buy_quantity' => (int) $data['buy_quantity'],
                'reward_variant_id' => $variants->get((int) $data['reward_variant_id'])->id,
                'reward_quantity' => (int) $data['reward_quantity'],
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'is_active' => true,
            ]);
        }, attempts: 3);

        return back()->with('status', 'Promotion saved. Discounts apply at checkout when the required products and quantities are in the cart.');
    }

    public function toggle(Request $request, int $promotion): RedirectResponse
    {
        $seller = $this->seller($request);
        abort_unless($this->promotionStorageReady(), 503);

        $promotion = SellerPromotion::query()->findOrFail($promotion);
        abort_unless((int) $promotion->seller_profile_id === (int) $seller->id, 404);

        DB::transaction(function () use ($promotion): void {
            $locked = SellerPromotion::query()->whereKey($promotion->id)->lockForUpdate()->firstOrFail();
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
            && Schema::hasTable('seller_promotion_components');
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
