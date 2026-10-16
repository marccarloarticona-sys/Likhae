<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin\Dispute;
use App\Models\Buyer\Address;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\Order;
use App\Models\Buyer\WishlistItem;
use App\Models\Communication\Conversation;
use App\Models\Communication\ConversationParticipant;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAssignment;
use App\Models\Seller\Product;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Services\Communication\ConversationService;
use App\Services\Fulfillment\ShipmentWorkflowService;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\CheckoutService;
use App\Services\Marketplace\BuyerOrderActionService;
use App\Services\Marketplace\ProductCatalogService;
use App\Services\Marketplace\VoucherRedemptionCounter;
use App\Services\Media\ImageOptimizationService;
use App\Support\AddressCoordinateValidator;
use App\Support\PhilippineAddressValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BuyerApiController extends Controller
{
    public function cart(Request $request, CartService $cart): JsonResponse
    {
        $this->authorizeBuyer($request);

        return response()->json([
            'success' => true,
            'data' => $cart->items($request->user())->map(fn (CartItem $item): array => $this->cartItemPayload($item))->values(),
        ]);
    }

    public function addCartItem(Request $request, CartService $cart): JsonResponse
    {
        $this->authorizeBuyer($request);
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'product_variant_id' => ['required', 'integer'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:999'],
        ]);

        $product = Product::query()->visible()->with('sellerProfile')->findOrFail($data['product_id']);
        if ($product->sellerProfile?->vacation_mode) {
            throw ValidationException::withMessages([
                'product_id' => 'This seller is currently on vacation and is not accepting purchases.',
            ]);
        }

        $item = $cart->add(
            $request->user(),
            $product,
            (int) $data['product_variant_id'],
            (int) ($data['quantity'] ?? 1),
        );
        $item->load(['productVariant.product.images', 'productVariant.product.sellerProfile', 'productVariant.optionValues.option']);

        return response()->json(['success' => true, 'data' => $this->cartItemPayload($item)], 201);
    }

    public function updateCartItem(Request $request, CartItem $item, CartService $cart): JsonResponse
    {
        $this->authorizeBuyer($request);
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:999']]);
        $item = $cart->update($request->user(), $item, (int) $data['quantity']);
        $item->load(['productVariant.product.images', 'productVariant.product.sellerProfile', 'productVariant.optionValues.option']);

        return response()->json(['success' => true, 'data' => $this->cartItemPayload($item)]);
    }

    public function removeCartItem(Request $request, CartItem $item, CartService $cart): JsonResponse
    {
        $this->authorizeBuyer($request);
        $cart->remove($request->user(), $item);

        return response()->json(['success' => true]);
    }

    public function orders(Request $request, ShipmentWorkflowService $workflow): JsonResponse
    {
        $this->authorizeBuyer($request);
        $orders = $request->user()->orders()
            ->with([
                'buyer',
                'sellerOrders.items.product.images',
                'sellerOrders.items.shipment',
                'sellerOrders.shipments.orderItem',
                'sellerOrders.shipments.events',
                'sellerOrders.shipments.riderAssignments.liveLocation',
                'address',
                'payments',
                'disputes' => fn ($query) => $query
                    ->where('type', 'RETURN_REFUND')
                    ->whereIn('status', ['OPEN', 'UNDER_REVIEW']),
            ])
            ->latest()
            ->paginate(min(max($request->integer('per_page', 20), 1), 50));
        $orders->getCollection()->each(fn (Order $order) => $workflow->syncParentOrderProgress($order));

        return response()->json([
            'success' => true,
            'data' => $orders->getCollection()->map(fn (Order $order): array => $this->orderPayload($order))->values(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function showOrder(Request $request, Order $order, ShipmentWorkflowService $workflow): JsonResponse
    {
        $this->authorizeBuyer($request);
        abort_unless((int) $order->buyer_user_id === (int) $request->user()->id, 403);
        $workflow->syncParentOrderProgress($order);
        $order->load([
            'buyer',
            'sellerOrders.items.product.images',
            'sellerOrders.items.shipment',
            'sellerOrders.shipments.orderItem',
            'sellerOrders.shipments.events',
            'sellerOrders.shipments.riderAssignments.liveLocation',
            'address',
            'payments',
            'disputes' => fn ($query) => $query
                ->where('type', 'RETURN_REFUND')
                ->whereIn('status', ['OPEN', 'UNDER_REVIEW']),
        ]);

        return response()->json(['success' => true, 'data' => $this->orderPayload($order)]);
    }

    public function liveRiderLocation(Request $request, Order $order): JsonResponse
    {
        $this->authorizeBuyer($request);
        abort_unless((int) $order->buyer_user_id === (int) $request->user()->id, 403);

        $order->load('sellerOrders.shipments.riderAssignments.liveLocation');
        $locations = $order->sellerOrders->flatMap->shipments
            ->mapWithKeys(fn (Shipment $shipment): array => [(int) $shipment->id => $this->riderLocationPayload($shipment)])
            ->filter();

        return response()->json([
            'success' => true,
            'data' => [
                'rider_location' => $locations->first(),
                'rider_locations' => $locations,
            ],
        ]);
    }

    public function placeOrder(Request $request, CheckoutService $checkout): JsonResponse
    {
        $this->authorizeBuyer($request);
        $data = $request->validate([
            'checkout_attempt_key' => ['required', 'uuid'],
            'cart_item_ids' => ['required', 'array', 'min:1'],
            'cart_item_ids.*' => ['required', 'integer', 'distinct'],
            'address_id' => ['required', 'integer'],
            'payment_method' => ['required', Rule::in(['COD'])],
            'voucher_code' => ['nullable', 'string', 'max:80', 'prohibited_with:voucher_codes'],
            'voucher_codes' => ['nullable', 'array', 'prohibited_with:voucher_code'],
            'voucher_codes.*' => ['nullable', 'string', 'max:80'],
            'protection_item_ids' => ['nullable', 'array'],
            'protection_item_ids.*' => ['integer'],
            'seller_notes' => ['nullable', 'array'],
            'seller_notes.*' => ['nullable', 'string', 'max:500'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['cart_item_ids'])));
        $vacationItem = CartItem::query()
            ->whereIn('id', $ids)
            ->whereHas('cart', fn ($cart) => $cart->where('buyer_user_id', $request->user()->id)->where('status', 'ACTIVE'))
            ->with('productVariant.product.sellerProfile')
            ->get()
            ->first(fn (CartItem $item): bool => (bool) $item->productVariant?->product?->sellerProfile?->vacation_mode);
        if ($vacationItem) {
            throw ValidationException::withMessages([
                'cart_item_ids' => 'A seller in your cart is on vacation and is not accepting purchases. Remove those items to continue.',
            ]);
        }

        $voucherCodes = collect((array) ($data['voucher_codes'] ?? []))
            ->mapWithKeys(fn ($value, $key) => [(int) $key => mb_strtoupper(trim((string) $value))])
            ->filter()
            ->all();
        $singleVoucherCode = mb_strtoupper(trim((string) ($data['voucher_code'] ?? '')));
        $protectionItemIds = collect((array) ($data['protection_item_ids'] ?? []))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $order = $checkout->placeOrder(
            $request->user(),
            (int) $data['address_id'],
            $ids,
            $data['payment_method'],
            $singleVoucherCode !== '' ? $singleVoucherCode : $voucherCodes,
            null,
            $protectionItemIds,
            (array) ($data['seller_notes'] ?? []),
            $data['checkout_attempt_key'],
        );

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (string) $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'grand_total' => (float) $order->grand_total,
            ],
        ], $order->wasRecentlyCreated ? 201 : 200);
    }

    public function profile(Request $request): JsonResponse
    {
        $this->authorizeBuyer($request);

        return response()->json(['success' => true, 'user' => $this->userPayload($request->user())]);
    }

    public function addresses(Request $request): JsonResponse
    {
        $this->authorizeBuyer($request);

        return response()->json([
            'success' => true,
            'data' => $request->user()->addresses()->orderByDesc('is_default')->latest()->get()
                ->map(fn (Address $address): array => $this->addressPayload($address))->values(),
        ]);
    }

    public function createAddress(Request $request): JsonResponse
    {
        $this->authorizeBuyer($request);
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:50'],
            'recipient_name' => ['required', 'string', 'max:200'],
            'contact_number' => ['required', 'string', 'max:30'],
            ...PhilippineAddressValidator::rules(),
            ...AddressCoordinateValidator::rules(),
            'is_default' => ['sometimes', 'boolean'],
        ]);
        PhilippineAddressValidator::assertValid($data);
        AddressCoordinateValidator::assertValid($data);

        if ($request->boolean('is_default')) {
            $request->user()->addresses()->update(['is_default' => false]);
        }
        $addressData = $data + ['is_default' => $request->boolean('is_default')];
        $address = $request->user()->addresses()->create($addressData);

        return response()->json(['success' => true, 'data' => $this->addressPayload($address)], 201);
    }

    public function deleteAddress(Request $request, Address $address): JsonResponse
    {
        $this->authorizeBuyer($request);
        abort_unless((int) $address->user_id === (int) $request->user()->id, 403);
        DB::transaction(function () use ($request, $address): void {
            $wasDefault = (bool) $address->is_default;
            $address->delete();
            if ($wasDefault) {
                $request->user()->addresses()->latest()->first()?->update(['is_default' => true]);
            }
        });

        return response()->json(['success' => true]);
    }

    public function setDefaultAddress(Request $request, Address $address): JsonResponse
    {
        $this->authorizeBuyer($request);
        abort_unless((int) $address->user_id === (int) $request->user()->id, 403);

        DB::transaction(function () use ($request, $address): void {
            $request->user()->addresses()->update(['is_default' => false]);
            $address->update(['is_default' => true]);
        });

        return response()->json(['success' => true, 'data' => $this->addressPayload($address->fresh())]);
    }

    public function updateProfile(Request $request, ImageOptimizationService $images): JsonResponse
    {
        $this->authorizeBuyer($request);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_initial' => ['nullable', 'string', 'max:10'],
            'last_name' => ['required', 'string', 'max:100'],
            'name_extension' => ['nullable', 'string', 'max:20'],
            'contact_number' => ['required', 'string', 'max:30', Rule::unique('users', 'contact_number')->ignore($request->user()->id)],
            'birthday' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'sex' => ['sometimes', 'nullable', Rule::in(['MALE', 'FEMALE', 'OTHER', 'PREFER_NOT_TO_SAY'])],
            'profile_photo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
        $user = $request->user();
        unset($data['profile_photo']);
        if (isset($data['sex'])) {
            $data['sex'] = $data['sex'] === '' ? null : Str::upper($data['sex']);
        }
        $oldPath = null;
        if ($request->hasFile('profile_photo')) {
            $data['profile_photo_path'] = $images->store($request->file('profile_photo'), 'profile-photos');
            $oldPath = $user->profile_photo_path;
        }
        $user->update($data);
        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json(['success' => true, 'user' => $this->userPayload($user->fresh())]);
    }

    public function deleteProfilePhoto(Request $request): JsonResponse
    {
        $this->authorizeBuyer($request);
        $user = $request->user();
        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
            $user->update(['profile_photo_path' => null]);
        }

        return response()->json(['success' => true, 'user' => $this->userPayload($user->fresh())]);
    }

    public function wishlist(Request $request, ProductCatalogService $catalog): JsonResponse
    {
        $this->authorizeBuyer($request);
        $items = WishlistItem::query()
            ->where('user_id', $request->user()->id)
            ->with(['product' => fn ($query) => $query->with($catalog->productRelations())
                ->withAvg('reviews', 'rating')
                ->withCount('reviews')
                ->withSum('orderItems', 'quantity')])
            ->latest()
            ->get()
            ->filter(fn (WishlistItem $item): bool => $item->product !== null)
            ->map(fn (WishlistItem $item): array => [
                'product' => $catalog->productPayload($item->product),
                'wishlisted_at' => $item->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function toggleWishlist(Request $request): JsonResponse
    {
        $this->authorizeBuyer($request);
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
        ]);
        $product = Product::query()->visible()->findOrFail((int) $data['product_id']);
        $item = WishlistItem::query()
            ->where('user_id', $request->user()->id)
            ->where('product_id', $product->id)
            ->first();

        if ($item) {
            $item->delete();
            $wishlisted = false;
        } else {
            WishlistItem::query()->create([
                'user_id' => $request->user()->id,
                'product_id' => $product->id,
            ]);
            $wishlisted = true;
        }

        return response()->json([
            'success' => true,
            'data' => ['product_id' => $product->id, 'wishlisted' => $wishlisted],
        ]);
    }

    public function rewards(Request $request): JsonResponse
    {
        $this->authorizeBuyer($request);
        $orders = $request->user()->orders()
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
        $buyerRedemptionCounts = VoucherRedemptionCounter::countsFor($activeVouchers, (int) $request->user()->id);
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
                'seller_id' => $voucher->seller_profile_id,
                'seller_name' => $voucher->sellerProfile?->business_name,
                'campaign_name' => $voucher->name,
                'can_use' => true,
            ])
            ->values();
        $voucherHistory = $orders->flatMap(fn ($order) => $order->sellerOrders
            ->whereNotNull('voucher_id')
            ->groupBy('voucher_id')
            ->map(fn ($sellerOrders): array => [
                'voucher' => $sellerOrders->first()->voucher?->code ?? 'Voucher',
                'benefit' => '-₱'.number_format((float) $sellerOrders->sum('voucher_discount'), 2),
                'order' => $order->order_number,
                'status' => Str::headline($order->status),
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

        return response()->json([
            'success' => true,
            'data' => [
                'active_vouchers' => $activeVouchers,
                'voucher_history' => $voucherHistory,
                'points_balance' => ($completedOrders->count() * 50) + ($reviews->count() * 20),
                'point_activities' => $pointActivities,
                'available_cashback' => 0,
                'pending_cashback' => 0,
                'cashback_activities' => [],
                'points_per_completed_order' => 50,
                'points_per_review' => 20,
                'cashback_rate' => 0,
            ],
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $this->authorizeBuyer($request);
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);
        $request->user()->update(['password' => Hash::make($data['password'])]);

        return response()->json(['success' => true, 'message' => 'Password updated.']);
    }

    public function notifications(Request $request): JsonResponse
    {
        $this->authorizeBuyer($request);
        $rows = $request->user()->notifications()->latest()->paginate(min(max($request->integer('per_page', 20), 1), 50));

        return response()->json([
            'success' => true,
            'data' => $rows->getCollection()->map(fn ($notification): array => [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $notification->title,
                'message' => $notification->message,
                'action_url' => $notification->action_url,
                'reference_type' => $notification->reference_type,
                'reference_id' => $notification->reference_id,
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at?->toIso8601String(),
            ])->values(),
            'unread_count' => $request->user()->notifications()->whereNull('read_at')->count(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    public function markNotificationsRead(Request $request): JsonResponse
    {
        $this->authorizeBuyer($request);
        $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function conversations(Request $request, ConversationService $service): JsonResponse
    {
        $this->authorizeBuyer($request);
        $user = $request->user();
        $conversations = $service->listFor($user, false, 100, true);
        $unreadCounts = $service->unreadCountsFor($user, $conversations);
        $rows = $conversations->map(function (Conversation $conversation) use ($request, $unreadCounts): array {
            $other = $conversation->participants->first(fn (User $participant): bool => (int) $participant->id !== (int) $request->user()->id);
            $seller = $other?->sellerProfile;

            return [
                'id' => (string) $conversation->id,
                'conversation_id' => (string) $conversation->id,
                'seller_id' => $other?->id,
                'seller_name' => $seller?->business_name ?: $other?->name ?: 'LIKHAE User',
                'slug' => 'conversation-'.$conversation->id,
                'last_message' => $conversation->latestMessage?->body ?? '',
                'time' => $conversation->latestMessage?->sent_at?->toIso8601String(),
                'unread' => (int) $unreadCounts->get($conversation->id, 0),
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function conversationMessages(Request $request, int $conversation, ConversationService $service): JsonResponse
    {
        $this->authorizeBuyer($request);
        $messages = $service->messagesFor(
            $request->user(),
            $conversation,
            50,
            max(0, $request->integer('after_id')) ?: null,
        );
        ConversationParticipant::query()
            ->where('conversation_id', $conversation)
            ->where('user_id', $request->user()->id)
            ->update(['last_read_at' => now()]);

        return response()->json([
            'success' => true,
            'data' => $messages->map(function ($message) use ($request): array {
                $attachmentPath = $message->getAttribute('attachment_path');

                return [
                'id' => (string) $message->id,
                'conversation_id' => (string) $message->conversation_id,
                'body' => $message->body,
                'sender_user_id' => $message->sender_user_id,
                'sent_at' => $message->sent_at?->toIso8601String(),
                'from_buyer' => (int) $message->sender_user_id === (int) $request->user()->id,
                'attachment_url' => $attachmentPath ? Storage::disk('public')->url($attachmentPath) : null,
                'attachment_name' => $attachmentPath ? basename($attachmentPath) : null,
                ];
            })->values(),
        ]);
    }

    public function sendMessage(Request $request, ConversationService $service, ImageOptimizationService $images): JsonResponse
    {
        $this->authorizeBuyer($request);
        $data = $request->validate([
            'recipient_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'body' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')->where('buyer_user_id', $request->user()->id)],
        ]);
        abort_if(blank($data['body'] ?? null) && ! $request->hasFile('attachment'), 422, 'A message or photo attachment is required.');
        $recipient = SellerProfile::query()
            ->where('status', 'ACTIVE')
            ->whereHas('user', fn ($query) => $query->whereKey($data['recipient_id'])->where('status', 'ACTIVE'))
            ->firstOrFail();
        $attachmentPath = $request->hasFile('attachment')
            ? $images->store($request->file('attachment'), 'message-attachments')
            : null;
        $message = $service->send($request->user(), (int) $recipient->user_id, trim((string) ($data['body'] ?? '')) ?: '[Photo]', [
            'order_id' => $data['order_id'] ?? null,
        ]);
        if ($attachmentPath) {
            $message->forceFill(['attachment_path' => $attachmentPath])->save();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (string) $message->id,
                'conversation_id' => (string) $message->conversation_id,
                'body' => $message->body,
                'sender_user_id' => $message->sender_user_id,
                'sent_at' => $message->sent_at?->toIso8601String(),
                'from_buyer' => true,
                'attachment_url' => $attachmentPath ? Storage::disk('public')->url($attachmentPath) : null,
                'attachment_name' => $request->file('attachment')?->getClientOriginalName(),
            ],
        ], 201);
    }

    private function authorizeBuyer(Request $request): void
    {
        abort_unless($request->user()?->isAccountType(User::TYPE_BUYER), 403, 'Buyer access is required.');
    }

    private function cartItemPayload(CartItem $item): array
    {
        $variant = $item->productVariant;
        $product = $variant?->product;

        return [
            'id' => (string) $item->id,
            'product_id' => $product?->id,
            'product_variant_id' => $variant?->id,
            'name' => $product?->name ?? 'Product',
            'slug' => $product?->slug,
            'category' => $product?->category?->name ?? '',
            'variant' => $variant?->description ?? 'Standard',
            'seller' => $product?->sellerProfile?->business_name ?? 'Seller',
            'seller_id' => $product?->seller_profile_id,
            'price' => (float) ($variant?->price ?? 0),
            'old_price' => $product?->original_price,
            'quantity' => (int) $item->quantity,
            'stock' => (int) ($variant?->stock ?? 0),
            'image_url' => $product?->images?->first()?->file_path,
        ];
    }

    private function addressPayload(Address $address): array
    {
        return [
            'id' => (string) $address->id,
            'label' => $address->label ?? 'Address',
            'recipient_name' => $address->recipient_name,
            'contact_number' => $address->contact_number,
            'province_code' => $address->province_code,
            'province_name' => $address->province_name,
            'municipality_code' => $address->municipality_code,
            'municipality_name' => $address->municipality_name,
            'barangay_code' => $address->barangay_code,
            'barangay_name' => $address->barangay_name,
            'postal_code' => $address->postal_code,
            'house_number' => $address->house_number,
            'street_address' => $address->street_address,
            'landmark' => $address->landmark,
            'latitude' => $address->latitude !== null ? (float) $address->latitude : null,
            'longitude' => $address->longitude !== null ? (float) $address->longitude : null,
            'is_default' => (bool) $address->is_default,
            'formatted_address' => $address->formatted(),
        ];
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'middle_initial' => $user->middle_initial,
            'last_name' => $user->last_name,
            'name_extension' => $user->name_extension,
            'email' => $user->email,
            'contact_number' => $user->contact_number,
            'birthday' => $user->birthday?->toDateString(),
            'sex' => $user->sex,
            'profile_photo_url' => $user->profile_photo_path ? Storage::disk('public')->url($user->profile_photo_path) : null,
            'account_type' => $user->account_type,
        ];
    }

    private function orderPayload(Order $order): array
    {
        $actionState = app(BuyerOrderActionService::class)->state(
            $order,
            Schema::hasTable('return_refund_requests') && Schema::hasTable('return_refund_request_items'),
        );
        $items = $order->sellerOrders->flatMap(fn ($sellerOrder) => $sellerOrder->items->map(fn ($item): array => [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'name' => $item->product_name,
            'variant' => $item->variant_description,
            'image_url' => $item->product?->images?->first()?->file_path,
            'unit_price' => (float) $item->unit_price,
            'quantity' => (int) $item->quantity,
            'tracking_number' => $item->shipment?->tracking_number,
            'shipment_status' => $item->shipment?->current_status,
        ]))->values();
        $shipments = $order->sellerOrders->flatMap->shipments;
        $shipment = $shipments->first();
        $activeReturnRequest = $order->disputes->first(
            fn (Dispute $dispute): bool => $dispute->type === 'RETURN_REFUND'
                && in_array($dispute->status, ['OPEN', 'UNDER_REVIEW'], true),
        );
        $events = $shipments->flatMap->events
            ->sortBy('occurred_at')->values();
        return [
            'id' => (string) $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'buyer_status' => $activeReturnRequest ? 'returns' : null,
            'buyer_status_label' => $activeReturnRequest ? 'Return / Refund Requested' : null,
            'status_label' => str($order->status)->headline()->toString(),
            'payment_method' => $order->payments->first()?->method ?? 'Not specified',
            'payment_status' => $order->payment_status,
            'grand_total' => (float) $order->grand_total,
            'buyer' => ['name' => $order->buyer?->name, 'contact_number' => $order->buyer?->contact_number],
            'address' => $order->address ? [
                'house_number' => $order->address->house_number,
                'street' => $order->address->street_address,
                'barangay' => $order->address->barangay_name,
                'municipality' => $order->address->municipality_name,
                'province' => $order->address->province_name,
                'postal_code' => $order->address->postal_code,
                'latitude' => $order->address->latitude !== null ? (float) $order->address->latitude : null,
                'longitude' => $order->address->longitude !== null ? (float) $order->address->longitude : null,
                'formatted_address' => $order->address->formatted(),
            ] : null,
            'tracking_number' => $shipment?->tracking_number,
            'shipment_id' => $shipment?->id,
            'rider_location' => $this->riderLocationPayload($shipment),
            'shipments' => $shipments->map(fn (Shipment $shipment): array => [
                'id' => (int) $shipment->id,
                'tracking_number' => (string) $shipment->tracking_number,
                'status' => (string) $shipment->current_status,
                'item' => $shipment->orderItem ? [
                    'id' => (int) $shipment->orderItem->id,
                    'name' => $shipment->orderItem->product_name,
                    'sku' => $shipment->orderItem->sku,
                    'quantity' => (int) $shipment->orderItem->quantity,
                ] : null,
                'rider_location' => $this->riderLocationPayload($shipment),
            ])->values(),
            'items' => $items,
            'timeline' => $events->map(fn ($event): array => [
                'status' => $event->status,
                'label' => str($event->status)->headline()->toString(),
                'notes' => $event->notes,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ]),
            'created_at' => $order->placed_at?->toIso8601String(),
            'allow_cancel' => in_array($order->status, ['PLACED', 'PROCESSING'], true),
            'allow_mark_received' => $actionState['can_confirm_received'],
            'allow_review' => $order->status === 'COMPLETED',
            'allow_return_request' => $actionState['can_request_return'],
            'has_active_return_request' => (bool) $activeReturnRequest,
        ];
    }

    private function riderLocationPayload(?Shipment $shipment): ?array
    {
        if ($shipment?->current_status !== 'OUT_FOR_DELIVERY') {
            return null;
        }

        $assignment = $shipment->riderAssignments->first(
            fn (RiderAssignment $assignment): bool => $assignment->assignment_type === RiderAssignment::TYPE_DELIVERY
                && $assignment->status === 'IN_PROGRESS',
        );
        $location = $assignment?->liveLocation;

        if (! $location?->recorded_at?->greaterThan(now()->subMinutes(2))) {
            return null;
        }

        return [
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
            'recorded_at' => $location->recorded_at->toIso8601String(),
        ];
    }
}
