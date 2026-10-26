<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Address;
use App\Models\Buyer\BuyerVoucherClaim;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\FlashSaleReminder;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderAddress;
use App\Models\Buyer\Review;
use App\Models\Buyer\ReturnRefundEvidence;
use App\Models\Buyer\ReturnRefundRequest;
use App\Models\Returns\CashRefundObligation;
use App\Models\Buyer\SellerFollower;
use App\Models\Admin\Notification;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Seller\Product;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Services\Account\BuyerEmailChangeOtpService;
use App\Services\Fulfillment\ShipmentWorkflowService;
use App\Services\Marketplace\BuyerVoucherClaimService;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\CheckoutService;
use App\Services\Marketplace\FlashSaleReminderService;
use App\Services\Marketplace\PlatformCampaignCatalog;
use App\Services\Marketplace\VoucherRedemptionCounter;
use App\Services\Marketplace\ProductCatalogService;
use App\Support\AddressCoordinateValidator;
use App\Support\PhilippineAddressValidator;
use App\Support\PhilippineMobileNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BuyerParityApiController extends Controller
{
    public function checkoutOptions(Request $request, CheckoutService $checkout): JsonResponse
    {
        $this->authorizeBuyer($request);

        return response()->json([
            'success' => true,
            'automatic_routing' => $checkout->automaticRoutingReady(),
            'data' => $checkout->availableLogisticsProviders()->map(fn (LogisticsCenter $center): array => [
                'id' => (int) $center->id,
                'name' => $center->business_name,
                'fee' => (float) $center->shipping_fee,
            ])->values(),
        ]);
    }

    public function checkoutPreview(Request $request, CartService $cart, CheckoutService $checkout): JsonResponse
    {
        $this->authorizeBuyer($request);
        $data = $request->validate([
            'cart_item_ids' => ['required', 'array', 'min:1'],
            'cart_item_ids.*' => ['required', 'integer', 'distinct'],
            'address_id' => ['required', 'integer', Rule::exists('addresses', 'id')->where('user_id', $request->user()->id)],
            'logistics_center_id' => ['nullable', 'integer'],
            'voucher_codes' => ['nullable', 'array'],
            'voucher_codes.seller' => ['nullable', 'array'],
            'voucher_codes.seller.*' => ['nullable', 'string', 'max:80'],
            'voucher_codes.platform' => ['nullable', 'string', 'max:80'],
            'voucher_codes.shipping' => ['nullable', 'string', 'max:80'],
            'protection_item_ids' => ['nullable', 'array'],
            'protection_item_ids.*' => ['integer'],
            'reward_points' => ['nullable', 'integer', 'min:0'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['cart_item_ids'])));
        $items = $cart->selectedItems($request->user(), $ids);
        abort_unless($items->count() === count($ids), 403, 'One or more cart items do not belong to this buyer.');
        $address = $request->user()->addresses()->findOrFail((int) $data['address_id']);
        $provider = isset($data['logistics_center_id'])
            ? LogisticsCenter::query()->approvedActive()->findOrFail((int) $data['logistics_center_id'])
            : null;
        $voucherCodes = $this->normalizeVoucherCodes((array) ($data['voucher_codes'] ?? []));
        $quote = $checkout->preview(
            $items,
            $voucherCodes,
            $request->user(),
            $provider,
            $address,
            array_map('intval', (array) ($data['protection_item_ids'] ?? [])),
            rewardPointsToRedeem: (int) ($data['reward_points'] ?? 0),
        );
        $quote['groups'] = collect($quote['groups'])->map(fn (array $group): array => $this->quoteGroupPayload($group))->values();
        $quote['price_snapshot'] = $items->mapWithKeys(fn (CartItem $item): array => [
            (string) $item->id => (float) $item->productVariant?->final_price,
        ])->all();
        $quote['delivery_availability'] = $checkout->deliveryAvailability($items, $address, $provider);

        return response()->json(['success' => true, 'data' => $quote]);
    }

    private function quoteGroupPayload(array $group): array
    {
        $group['items'] = collect($group['items'] ?? [])->map(function ($item): array {
            return is_array($item) ? $item : (array) $item;
        })->values();

        return $group;
    }

    public function placeOrder(Request $request, CartService $cart, CheckoutService $checkout): JsonResponse
    {
        $this->authorizeBuyer($request);
        if (is_string($request->input('contact_number'))) {
            $normalized = PhilippineMobileNumber::normalize($request->input('contact_number'));
            if ($normalized !== null) $request->merge(['contact_number' => $normalized]);
        }
        $data = $request->validate([
            'cart_item_ids' => ['required', 'array', 'min:1'],
            'cart_item_ids.*' => ['required', 'integer', 'distinct'],
            'address_id' => ['required', 'integer', Rule::exists('addresses', 'id')->where('user_id', $request->user()->id)],
            'recipient_name' => ['required', 'string', 'max:200'],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^(?:09|\+?639)\d{9}$/'],
            'payment_method' => ['required', Rule::in(['COD'])],
            'voucher_codes' => ['nullable', 'array'],
            'voucher_codes.seller' => ['nullable', 'array'],
            'voucher_codes.seller.*' => ['nullable', 'string', 'max:80'],
            'voucher_codes.platform' => ['nullable', 'string', 'max:80'],
            'voucher_codes.shipping' => ['nullable', 'string', 'max:80'],
            'seller_notes' => ['nullable', 'array'],
            'seller_notes.*' => ['nullable', 'string', 'max:200'],
            'protection_item_ids' => ['nullable', 'array'],
            'protection_item_ids.*' => ['integer', 'distinct'],
            'logistics_center_id' => ['nullable', 'integer'],
            'reward_points' => ['nullable', 'integer', 'min:0'],
            'checkout_attempt_key' => ['required', 'uuid'],
            'expected_price_snapshot' => ['nullable', 'array'],
            'expected_price_snapshot.*' => ['required', 'numeric', 'min:0'],
        ]);
        $ids = array_values(array_map('intval', $data['cart_item_ids']));
        $items = $cart->selectedItems($request->user(), $ids);
        abort_unless($items->count() === count($ids), 403, 'One or more cart items do not belong to this buyer.');
        $address = $request->user()->addresses()->findOrFail((int) $data['address_id']);
        $voucherCodes = $this->normalizeVoucherCodes((array) ($data['voucher_codes'] ?? []));
        $sellerNotes = collect((array) ($data['seller_notes'] ?? []))
            ->mapWithKeys(fn ($value, $key): array => [(int) $key => trim((string) $value)])
            ->filter(fn (string $value): bool => $value !== '')
            ->all();
        $priceSnapshot = isset($data['expected_price_snapshot'])
            ? collect($data['expected_price_snapshot'])->mapWithKeys(fn ($value, $key): array => [(int) $key => (float) $value])->all()
            : null;
        $order = $checkout->placeOrder(
            $request->user(),
            $address,
            $items,
            $data['payment_method'],
            $voucherCodes,
            isset($data['logistics_center_id']) ? (int) $data['logistics_center_id'] : null,
            array_map('intval', (array) ($data['protection_item_ids'] ?? [])),
            $sellerNotes,
            $data['checkout_attempt_key'],
            $priceSnapshot,
            (int) ($data['reward_points'] ?? 0),
            recipientName: trim($data['recipient_name']),
            contactNumber: PhilippineMobileNumber::normalize($data['contact_number'])
                ?? throw ValidationException::withMessages(['contact_number' => 'Enter a valid Philippine mobile number.']),
        );

        return response()->json(['success' => true, 'data' => [
            'id' => (string) $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'grand_total' => (float) $order->grand_total,
            'reward_points_redeemed' => (int) $order->reward_points_redeemed,
            'reward_points_discount' => (float) $order->reward_points_discount,
        ]], 201);
    }

    public function storeVouchers(Request $request, string $seller): JsonResponse
    {
        $this->authorizeBuyer($request);
        $profile = app(ProductCatalogService::class)->sellerByRouteKey($seller);
        abort_unless($profile->status === 'ACTIVE' && $profile->store_visibility && $profile->user?->isActive(), 404);

        $claimedVouchers = Voucher::query()
            ->where('seller_profile_id', $profile->id)
            ->when(Schema::hasColumn('vouchers', 'platform_code_key'), fn ($vouchers) => $vouchers->whereNull('platform_code_key'))
            ->where('is_active', true)
            ->where(fn ($vouchers) => $vouchers->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($vouchers) => $vouchers->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->whereHas('buyerClaims', fn ($claims) => $claims->where('buyer_user_id', $request->user()->id))
            ->with('sellerProfile.user')
            ->orderBy('id')
            ->get();
        $globalUses = VoucherRedemptionCounter::countsFor($claimedVouchers);
        $buyerUses = VoucherRedemptionCounter::countsFor($claimedVouchers, (int) $request->user()->id);
        $claimService = app(BuyerVoucherClaimService::class);
        $vouchers = $claimedVouchers
            ->filter(fn (Voucher $voucher): bool => $claimService->unavailableReason(
                $voucher,
                $request->user(),
                $globalUses->get($voucher->id, 0),
                $buyerUses->get($voucher->id, 0),
            ) === null)
            ->map(fn (Voucher $voucher): array => [
                'id' => (int) $voucher->id,
                'name' => (string) $voucher->name,
                'code' => (string) $voucher->code,
                'discount_type' => (string) $voucher->discount_type,
                'discount_value' => (float) $voucher->discount_value,
                'minimum_order_amount' => (float) $voucher->minimum_order_amount,
                'maximum_discount_amount' => $voucher->maximum_discount_amount === null
                    ? null
                    : (float) $voucher->maximum_discount_amount,
                'expires' => $voucher->ends_at?->format('M j, Y') ?? 'No expiry',
                'can_use' => true,
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $vouchers]);
    }
    public function setStoreFollowing(Request $request, string $seller): JsonResponse
    {
        $this->authorizeBuyer($request);
        $profile = app(ProductCatalogService::class)->sellerByRouteKey($seller);
        abort_unless($profile->status === 'ACTIVE' && $profile->store_visibility && $profile->user?->isActive(), 404);

        $follow = SellerFollower::query()->firstOrCreate([
            'buyer_user_id' => $request->user()->id,
            'seller_profile_id' => $profile->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => ['following' => true, 'followers' => SellerFollower::query()->where('seller_profile_id', $profile->id)->count()],
        ], $follow->wasRecentlyCreated ? 201 : 200);
    }

    public function storeFollowingStatus(Request $request, string $seller): JsonResponse
    {
        $this->authorizeBuyer($request);
        $profile = app(ProductCatalogService::class)->sellerByRouteKey($seller);
        $following = SellerFollower::query()->where('buyer_user_id', $request->user()->id)
            ->where('seller_profile_id', $profile->id)->exists();

        return response()->json(['success' => true, 'data' => [
            'following' => $following,
            'followers' => SellerFollower::query()->where('seller_profile_id', $profile->id)->count(),
        ]]);
    }

    public function removeStoreFollowing(Request $request, string $seller): JsonResponse
    {
        $this->authorizeBuyer($request);
        $profile = app(ProductCatalogService::class)->sellerByRouteKey($seller);
        SellerFollower::query()->where('buyer_user_id', $request->user()->id)
            ->where('seller_profile_id', $profile->id)->delete();

        return response()->json([
            'success' => true,
            'data' => ['following' => false, 'followers' => SellerFollower::query()->where('seller_profile_id', $profile->id)->count()],
        ]);
    }

    public function setFlashSaleReminder(Request $request, Product $product, FlashSaleReminderService $reminders): JsonResponse
    {
        $this->authorizeBuyer($request);
        $reminder = $reminders->register($request->user(), $product);

        return response()->json(['success' => true, 'data' => [
            'product_id' => (int) $product->id,
            'status' => $reminder->status,
            'sale_starts_at' => $reminder->sale_starts_at?->toIso8601String(),
            'scheduled_at' => $reminder->scheduled_at?->toIso8601String(),
        ]], 201);
    }

    public function flashSaleReminderStatus(Request $request, Product $product, FlashSaleReminderService $reminders): JsonResponse
    {
        $this->authorizeBuyer($request);
        $states = $reminders->reminderStatesFor($request->user(), [$product]);

        return response()->json(['success' => true, 'data' => [
            'product_id' => (int) $product->id,
            'registered' => isset($states[$product->id]),
            'sale_starts_at' => $product->flash_sale_starts_at?->toIso8601String(),
        ]]);
    }

    public function removeFlashSaleReminder(Request $request, Product $product, FlashSaleReminderService $reminders): JsonResponse
    {
        $this->authorizeBuyer($request);

        return response()->json(['success' => true, 'data' => [
            'product_id' => (int) $product->id,
            'cancelled' => $reminders->cancel($request->user(), $product),
        ]]);
    }

    public function event(Request $request, Voucher $voucher, PlatformCampaignCatalog $campaigns): JsonResponse
    {
        $this->authorizeBuyer($request);
        abort_unless($campaigns->available()->contains('id', $voucher->id), 404);
        $claimed = $voucher->buyerClaims()->where('buyer_user_id', $request->user()->id)->exists();

        return response()->json(['success' => true, 'data' => [
            'id' => (int) $voucher->id,
            'title' => $voucher->name ?: 'Platform event',
            'code' => $voucher->code,
            'discount_type' => $voucher->discount_type,
            'discount_value' => (float) $voucher->discount_value,
            'minimum_order_amount' => (float) $voucher->minimum_order_amount,
            'maximum_discount_amount' => $voucher->maximum_discount_amount === null ? null : (float) $voucher->maximum_discount_amount,
            'starts_at' => $voucher->starts_at?->toIso8601String(),
            'ends_at' => $voucher->ends_at?->toIso8601String(),
            'claimed' => $claimed,
        ]]);
    }

    public function claimVoucher(Request $request, Voucher $voucher): JsonResponse
    {
        $this->authorizeBuyer($request);
        $buyer = $request->user();
        abort_unless($buyer->isActive(), 403);
        $claim = DB::transaction(function () use ($buyer, $voucher): BuyerVoucherClaim {
            $campaign = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            abort_unless(Schema::hasColumn('vouchers', 'platform_code_key')
                && $campaign->seller_profile_id === null
                && filled($campaign->platform_code_key), 404);

            $existing = BuyerVoucherClaim::query()
                ->where('buyer_user_id', $buyer->id)
                ->where('voucher_id', $campaign->id)
                ->first();
            if ($existing) return $existing;

            abort_unless($campaign->is_active
                && (! $campaign->starts_at || $campaign->starts_at->lte(now()))
                && (! $campaign->ends_at || $campaign->ends_at->gte(now())), 422, 'This platform voucher is not available to collect now.');
            abort_unless(app(\App\Services\Finance\PlatformCampaignBudgetService::class)->selectable($campaign), 422, 'This platform campaign needs an available LIKHAE budget before it can be collected.');
            $globalUses = VoucherRedemptionCounter::countsFor(collect([$campaign]))->get($campaign->id, 0);
            $buyerUses = VoucherRedemptionCounter::countsFor(collect([$campaign]), (int) $buyer->id)->get($campaign->id, 0);
            abort_unless($campaign->usage_limit === null || $globalUses < $campaign->usage_limit, 422, 'This platform voucher has reached its usage limit.');
            abort_unless($campaign->per_user_limit === null || $buyerUses < $campaign->per_user_limit, 422, 'You have reached the usage limit for this voucher.');

            return BuyerVoucherClaim::query()->create([
                'buyer_user_id' => $buyer->id,
                'voucher_id' => $campaign->id,
            ]);
        }, 3);

        return response()->json(['success' => true, 'message' => 'Voucher collected.', 'data' => [
            'voucher_id' => (int) $voucher->id,
            'claimed' => true,
            'claimed_at' => $claim->created_at?->toIso8601String(),
        ]], 201);
    }

    public function updateAddress(Request $request, Address $address): JsonResponse
    {
        $this->authorizeBuyer($request);
        abort_unless((int) $address->user_id === (int) $request->user()->id, 403);
        if (is_string($request->input('contact_number'))) {
            $normalized = PhilippineMobileNumber::normalize($request->input('contact_number'));
            if ($normalized !== null) $request->merge(['contact_number' => $normalized]);
        }
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:50'],
            'recipient_name' => ['required', 'string', 'max:200'],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^(?:09|\+?639)\d{9}$/'],
            ...PhilippineAddressValidator::rules(),
            ...AddressCoordinateValidator::rules(),
            'is_default' => ['sometimes', 'boolean'],
        ]);
        $data['contact_number'] = PhilippineMobileNumber::normalize($data['contact_number'])
            ?? throw ValidationException::withMessages(['contact_number' => 'Enter a valid Philippine mobile number.']);
        PhilippineAddressValidator::assertValid($data);
        AddressCoordinateValidator::assertValid($data);
        AddressCoordinateValidator::assertFreshForAddress($address, $data);

        $saved = DB::transaction(function () use ($request, $address, $data): Address {
            $buyer = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $locked = $buyer->addresses()->whereKey($address->id)->lockForUpdate()->firstOrFail();
            $makeDefault = $request->boolean('is_default') || $locked->is_default;
            if ($makeDefault) $buyer->addresses()->whereKeyNot($locked->id)->update(['is_default' => false]);
            $locked->update([
                'label' => $data['label'] ?? 'Home',
                'recipient_name' => $data['recipient_name'],
                'contact_number' => $data['contact_number'],
                ...PhilippineAddressValidator::storageAttributes($data),
                'latitude' => $data['latitude'] ?? $locked->latitude,
                'longitude' => $data['longitude'] ?? $locked->longitude,
                'is_default' => $makeDefault,
            ]);

            return $locked->fresh();
        });

        return response()->json(['success' => true, 'data' => [
            'id' => (string) $saved->id,
            'label' => $saved->label,
            'recipient_name' => $saved->recipient_name,
            'contact_number' => $saved->contact_number,
            'province_name' => $saved->province_name,
            'municipality_name' => $saved->municipality_name,
            'barangay_name' => $saved->barangay_name,
            'postal_code' => $saved->postal_code,
            'house_number' => $saved->house_number,
            'street_address' => $saved->street_address,
            'landmark' => $saved->landmark,
            'latitude' => $saved->latitude,
            'longitude' => $saved->longitude,
            'is_default' => (bool) $saved->is_default,
            'formatted_address' => $saved->formatted(),
        ]]);
    }

    public function requestEmailChange(Request $request, BuyerEmailChangeOtpService $emailChanges): JsonResponse
    {
        $this->authorizeBuyer($request);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $email = mb_strtolower(trim($data['email']));
        if ($email === mb_strtolower((string) $request->user()->email)) {
            return response()->json(['success' => true, 'message' => 'This is already your current email address.']);
        }
        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->whereKeyNot($request->user()->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'This email address is already in use.']);
        }
        $emailChanges->send($request->user(), $email);

        return response()->json(['success' => true, 'requires_verification' => true, 'pending_email' => $email,
            'message' => 'A verification code was sent to the new email address.'], 202);
    }

    public function resendEmailChangeCode(Request $request, BuyerEmailChangeOtpService $emailChanges): JsonResponse
    {
        $this->authorizeBuyer($request);
        $email = $emailChanges->pendingEmail($request->user());
        if (! $email) throw ValidationException::withMessages(['email' => 'Request an email change before asking for a verification code.']);
        $emailChanges->send($request->user(), $email);

        return response()->json(['success' => true, 'message' => 'A new verification code was sent.']);
    }

    public function verifyEmailChange(Request $request, BuyerEmailChangeOtpService $emailChanges): JsonResponse
    {
        $this->authorizeBuyer($request);
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $buyer = $request->user();
        $emailChanges->confirm($buyer, $data['code'], function (string $email) use ($buyer): void {
            if (User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->whereKeyNot($buyer->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'This email address is already in use.']);
            }
            $buyer->forceFill(['email' => $email, 'email_verified_at' => now()])->save();
        });

        return response()->json(['success' => true, 'user' => [
            'id' => $buyer->id,
            'name' => $buyer->name,
            'first_name' => $buyer->first_name,
            'middle_initial' => $buyer->middle_initial,
            'last_name' => $buyer->last_name,
            'name_extension' => $buyer->name_extension,
            'email' => $buyer->email,
            'email_verified' => true,
            'contact_number' => $buyer->contact_number,
            'birthday' => $buyer->birthday?->toDateString(),
            'sex' => $buyer->sex,
            'profile_photo_url' => $buyer->profile_photo_path ? Storage::disk('public')->url($buyer->profile_photo_path) : null,
            'account_type' => $buyer->account_type,
        ]]);
    }

    public function buyerSettings(Request $request): JsonResponse
    {
        $this->authorizeBuyer($request);
        return response()->json(['success' => true, 'data' => $this->preferences($request->user())]);
    }

    public function updateBuyerSettings(Request $request): JsonResponse
    {
        $this->authorizeBuyer($request);
        $data = $request->validate([
            'theme' => ['sometimes', Rule::in(['light', 'dark', 'system'])],
            'language' => ['sometimes', Rule::in(['en'])],
            'order_updates' => ['sometimes', 'boolean'],
            'delivery_updates' => ['sometimes', 'boolean'],
            'chat_updates' => ['sometimes', 'boolean'],
            'promotion_updates' => ['sometimes', 'boolean'],
            'show_online_status' => ['sometimes', 'boolean'],
            'ai_assistant_enabled' => ['sometimes', 'boolean'],
            'ai_response_sound' => ['sometimes', 'boolean'],
            'notification_sounds' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();
        $current = $this->preferences($user);
        $user->forceFill(['notification_preferences' => array_merge($current, $data)])->save();
        if (array_key_exists('show_online_status', $data) && ! $data['show_online_status']) {
            cache()->forget('message-presence:user:'.$user->id);
        }

        return response()->json(['success' => true, 'data' => $this->preferences($user->fresh())]);
    }

    private function preferences(User $user): array
    {
        return array_merge([
            'order_updates' => true, 'delivery_updates' => true, 'chat_updates' => true,
            'promotion_updates' => true, 'show_online_status' => true,
            'ai_assistant_enabled' => true, 'ai_response_sound' => false,
            'theme' => 'system', 'language' => 'en', 'notification_sounds' => true,
        ], (array) $user->notification_preferences);
    }

    public function deleteAccount(Request $request): JsonResponse
    {
        $this->authorizeBuyer($request);
        $request->validate(['current_password' => ['required', 'current_password'], 'delete_confirmation' => ['required', 'string', 'in:DELETE']]);
        $user = $request->user();
        if ($user->orders()->whereNotIn('status', ['COMPLETED', 'CANCELLED', 'RETURNED'])->exists()) {
            throw ValidationException::withMessages(['delete_confirmation' => 'Complete or cancel your active orders before closing this account.']);
        }
        $user->forceFill(['status' => User::STATUS_DEACTIVATED])->save();
        return response()->json(['success' => true, 'message' => 'Your Buyer account has been closed.']);
    }

    public function reviews(Request $request): JsonResponse
    {
        $this->authorizeBuyer($request);
        $reviews = Review::query()->where('buyer_user_id', $request->user()->id)
            ->with(['orderItem.order', 'orderItem.product'])->latest()->paginate(min(max($request->integer('per_page', 20), 1), 50));
        return response()->json(['success' => true, 'data' => $reviews->getCollection()->map(fn (Review $review): array => [
            'id' => (int) $review->id, 'rating' => (int) $review->rating, 'comment' => $review->comment,
            'created_at' => $review->created_at?->toIso8601String(),
            'order_id' => (string) $review->orderItem?->order_id,
            'order_number' => $review->orderItem?->order?->order_number,
            'product_id' => (int) ($review->orderItem?->product_id ?? 0),
            'product_name' => $review->orderItem?->product_name,
            'product_slug' => $review->orderItem?->product?->slug,
            'image_url' => $review->orderItem?->product?->images?->first()?->file_path,
        ])->values(), 'meta' => ['current_page' => $reviews->currentPage(), 'last_page' => $reviews->lastPage(), 'total' => $reviews->total()]]);
    }

    public function markNotificationRead(Request $request, string $notification): JsonResponse
    {
        $this->authorizeBuyer($request);
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        if (! $item->read_at) $item->forceFill(['read_at' => now()])->save();
        return response()->json(['success' => true, 'data' => ['id' => (string) $item->id, 'read_at' => $item->read_at?->toIso8601String()]]);
    }

    public function openNotification(Request $request, string $notification): JsonResponse
    {
        $this->authorizeBuyer($request);
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        if (! $item->read_at) $item->forceFill(['read_at' => now()])->save();
        return response()->json(['success' => true, 'data' => ['action_url' => $item->action_url, 'read_at' => $item->read_at?->toIso8601String()]]);
    }

    public function dismissAnnouncement(Request $request, string $notification): JsonResponse
    {
        $this->authorizeBuyer($request);
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        abort_unless(strtoupper((string) $item->type) === 'ANNOUNCEMENT', 422, 'Only announcements can be dismissed.');
        $item->delete();
        return response()->json(['success' => true]);
    }

    public function buyAgain(Request $request, Order $order, CartService $cart): JsonResponse
    {
        $this->authorizeBuyer($request);
        abort_unless((int) $order->buyer_user_id === (int) $request->user()->id, 403);
        $order->loadMissing('sellerOrders.items.product');
        $added = 0;
        $unavailable = 0;
        foreach ($order->sellerOrders->flatMap->items as $item) {
            $product = Product::query()->visible()->find($item->product_id);
            $variant = $product?->variants()->whereKey($item->product_variant_id)->where('is_active', true)->first();
            if (! $product || ! $variant || (int) $variant->stock < 1 || $product->sellerProfile?->vacation_mode) {
                $unavailable++;
                continue;
            }
            try {
                $cart->add($request->user(), $product, (int) $variant->id, min((int) $item->quantity, (int) $variant->stock));
                $added++;
            } catch (ValidationException) {
                $unavailable++;
            }
        }
        return response()->json(['success' => true, 'data' => ['added' => $added, 'unavailable' => $unavailable]]);
    }

    public function confirmDeliveryPin(Request $request, Order $order): JsonResponse
    {
        $this->authorizeBuyer($request);
        abort_unless((int) $order->buyer_user_id === (int) $request->user()->id, 403);
        $data = $request->validate(AddressCoordinateValidator::rules());
        AddressCoordinateValidator::assertValid($data);
        DB::transaction(function () use ($request, $order, $data): void {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $lockedOrder->buyer_user_id === (int) $request->user()->id, 403);
            $snapshot = OrderAddress::query()->where('order_id', $lockedOrder->id)->lockForUpdate()->first();
            abort_unless($snapshot && ! filled($snapshot->latitude) && ! filled($snapshot->longitude)
                && ! in_array($lockedOrder->status, ['DELIVERED', 'COMPLETED', 'CANCELLED', 'RETURNED'], true), 409,
                'This order can no longer receive a delivery pin through this flow.');
            $lockedOrder->loadMissing('sellerOrders.shipments');
            abort_if($lockedOrder->sellerOrders->flatMap->shipments->contains(fn ($shipment): bool => in_array($shipment->current_status, ['DELIVERED', 'COMPLETED', 'CANCELLED', 'RETURNED'], true)), 409);
            $snapshot->update(['latitude' => $data['latitude'], 'longitude' => $data['longitude']]);
        });
        return response()->json(['success' => true, 'message' => 'Delivery pin confirmed for this order.']);
    }

    public function returnRequests(Request $request): JsonResponse
    {
        $this->authorizeBuyer($request);
        $rows = ReturnRefundRequest::query()->where('buyer_user_id', $request->user()->id)
            ->with(['order', 'sellerRequests.sellerProfile', 'sellerRequests.items.orderItem', 'sellerRequests.payouts'])
            ->latest('submitted_at')->paginate(min(max($request->integer('per_page', 20), 1), 50));
        return response()->json(['success' => true, 'data' => $rows->getCollection()->map(fn (ReturnRefundRequest $row): array => [
            'id' => (string) $row->id, 'request_number' => $row->request_number, 'status' => $row->status,
            'issue_category' => $row->issue_category, 'issue_reason' => $row->issue_reason,
            'submitted_at' => $row->submitted_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
            'order_id' => (string) $row->order_id, 'order_number' => $row->order?->order_number,
            'seller_cases' => $row->sellerRequests->map(fn (SellerReturnRefundRequest $case): array => [
                'id' => (string) $case->id, 'seller_name' => $case->sellerProfile?->business_name,
                'status' => $case->status, 'requested_amount' => (float) $case->requested_amount,
                'approved_amount' => $case->approved_amount === null ? null : (float) $case->approved_amount,
            ])->values(),
        ])->values(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]]);
    }

    public function returnRequest(Request $request, ReturnRefundRequest $returnRequest): JsonResponse
    {
        $this->authorizeBuyer($request);
        abort_unless((int) $returnRequest->buyer_user_id === (int) $request->user()->id, 403);
        $relations = ['order', 'items.orderItem', 'evidence', 'events.actor', 'sellerRequests.sellerProfile', 'sellerRequests.items.orderItem', 'sellerRequests.payouts', 'sellerRequests.events.actor'];
        if (Schema::hasTable('cash_refund_obligations')) {
            $relations[] = 'sellerRequests.cashRefundObligation';
        }
        $returnRequest->load($relations);
        $sellerEvidence = ReturnRefundEvidence::query()
            ->whereIn('seller_return_refund_request_id', $returnRequest->sellerRequests->pluck('id'))
            ->get()
            ->groupBy('seller_return_refund_request_id');
        return response()->json(['success' => true, 'data' => [
            'id' => (string) $returnRequest->id, 'request_number' => $returnRequest->request_number,
            'status' => $returnRequest->status, 'description' => $returnRequest->description,
            'issue_category' => $returnRequest->issue_category, 'issue_reason' => $returnRequest->issue_reason,
            'refund_method' => $returnRequest->refund_method, 'refundable_amount' => (float) $returnRequest->refundable_amount,
            'submitted_at' => $returnRequest->submitted_at?->toIso8601String(),
            'order' => ['id' => (string) $returnRequest->order_id, 'order_number' => $returnRequest->order?->order_number],
            'items' => $returnRequest->items->map(fn ($item): array => ['product_name' => $item->orderItem?->product_name, 'quantity' => (int) $item->quantity, 'amount' => (float) $item->requested_amount])->values(),
            'events' => $returnRequest->events->sortBy('created_at')->map(fn ($event): array => ['event' => $event->event, 'note' => $event->note, 'created_at' => $event->created_at?->toIso8601String()])->values(),
            'evidence' => $returnRequest->evidence->map(fn ($evidence): array => ['id' => (string) $evidence->id, 'name' => $evidence->original_name, 'mime_type' => $evidence->mime_type])->values(),
            'seller_cases' => $returnRequest->sellerRequests->map(function (SellerReturnRefundRequest $case) use ($sellerEvidence): array {
                $cashRefund = $case->relationLoaded('cashRefundObligation')
                    ? $case->getRelation('cashRefundObligation')
                    : null;

                return [
                    'id' => (string) $case->id,
                    'seller_name' => $case->sellerProfile?->business_name,
                    'status' => $case->status,
                    'requested_amount' => (float) $case->requested_amount,
                    'approved_amount' => $case->approved_amount === null ? null : (float) $case->approved_amount,
                    'can_confirm_refund' => $case->status === SellerReturnRefundRequest::STATUS_REFUND_SENT && $case->payouts->isNotEmpty(),
                    'cash_refund_obligation_id' => $cashRefund?->id === null ? null : (string) $cashRefund->id,
                    'can_confirm_cash_refund' => $cashRefund?->state === CashRefundObligation::CASH_PAID,
                    'can_escalate' => in_array($case->status, [SellerReturnRefundRequest::STATUS_REJECTED, SellerReturnRefundRequest::STATUS_REFUND_SENT, SellerReturnRefundRequest::STATUS_APPROVED], true) && ! $case->dispute_id,
                    'events' => $case->events->sortBy('created_at')->map(fn ($event): array => [
                        'event' => $event->event_type,
                        'from_status' => $event->from_status,
                        'to_status' => $event->to_status,
                        'note' => $event->details,
                        'created_at' => $event->created_at?->toIso8601String(),
                    ])->values(),
                    'evidence' => $sellerEvidence->get($case->id, collect())->map(fn ($evidence): array => [
                        'id' => (string) $evidence->id,
                        'name' => $evidence->original_name,
                        'mime_type' => $evidence->mime_type,
                    ])->values(),
                    'payouts' => $case->payouts->map(fn ($payout): array => [
                        'id' => (string) $payout->id,
                        'amount_sent' => (float) $payout->amount_sent,
                        'sent_at' => $payout->sent_at?->toIso8601String(),
                        'proof_available' => is_string($payout->proof_path) && $payout->proof_path !== '',
                        'proof_mime' => $payout->proof_mime,
                    ])->values(),
                ];
            })->values(),
        ]]);
    }

    private function normalizeVoucherCodes(array $codes): array
    {
        if (array_intersect(['seller', 'platform', 'shipping'], array_keys($codes)) !== []) {
            return [
                'seller' => collect((array) ($codes['seller'] ?? []))
                    ->mapWithKeys(fn ($code, $sellerId): array => [(int) $sellerId => mb_strtoupper(trim((string) $code))])
                    ->filter()
                    ->all(),
                'platform' => mb_strtoupper(trim((string) ($codes['platform'] ?? ''))),
                'shipping' => mb_strtoupper(trim((string) ($codes['shipping'] ?? ''))),
            ];
        }

        return collect($codes)
            ->mapWithKeys(fn ($code, $sellerId): array => [(int) $sellerId => mb_strtoupper(trim((string) $code))])
            ->filter()
            ->all();
    }
    private function authorizeBuyer(Request $request): void
    {
        abort_unless($request->user()?->isAccountType(User::TYPE_BUYER), 403, 'Buyer access is required.');
    }
}
