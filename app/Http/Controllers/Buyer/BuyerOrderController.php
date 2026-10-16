<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderAddress;
use App\Models\Buyer\OrderItem;
use App\Models\Buyer\Review;
use App\Models\Logistics\ShipmentEvent;
use App\Models\Admin\CommissionTransaction;
use App\Models\Admin\Dispute;
use App\Models\Admin\DisputeEvidence;
use App\Models\Admin\Notification;
use App\Models\Admin\PlatformSetting;
use App\Models\Buyer\ReturnRefundRequest;
use App\Models\Buyer\ReturnRefundRequestItem;
use App\Models\Buyer\ReturnRefundEvidence;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\User;
use App\Services\Communication\ConversationService;
use App\Services\RiderRatingService;
use App\Services\ReviewImageService;
use App\Services\Fulfillment\ShipmentWorkflowService;
use App\Services\Media\ImageOptimizationService;
use App\Services\Maps\MapDataService;
use App\Services\Marketplace\BuyerOrderActionService;
use App\Support\AddressCoordinateValidator;
use App\Services\RefundProofStorageService;
use App\Services\ReturnRefundWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Illuminate\Support\Str;

class BuyerOrderController extends Controller
{
    public function __construct(private readonly ImageOptimizationService $images) {}

    public function success(Request $request): View
    {
        $order = null;
        if ($request->filled('order')) {
            $order = $request->user()
                ->orders()
                ->with(['sellerOrders.items.product', 'sellerOrders.shipments.orderItem', 'address', 'payments'])
                ->where('order_number', $request->query('order'))
                ->first();
        }

        return view('Buyer.orders', [
            'mode' => 'success',
            'selectedOrder' => $order,
            'orders' => $request->user()->orders()->with(['sellerOrders.shipments', 'payments'])->latest()->paginate(10),
        ]);
    }

    public function index(Request $request, ShipmentWorkflowService $workflow, BuyerOrderActionService $actions): View
    {
        $filter = strtoupper((string) $request->query('status', ''));
        $returnRefundReady = $this->returnRefundStorageReady();
        $buyerOrders = $request->user()->orders();
        $deliveredShipmentStatuses = ['DELIVERED', 'COMPLETED'];
        $orderStatusCounts = (clone $buyerOrders)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($count): int => (int) $count);

        $orderStatusCounts->put(
            'PROCESSING',
            (clone $buyerOrders)
                ->where('status', 'PROCESSING')
                ->where(function ($query) use ($deliveredShipmentStatuses): void {
                    $query
                        ->whereHas('sellerOrders', fn ($sellerOrderQuery) => $sellerOrderQuery->whereDoesntHave('shipments'))
                        ->orWhereHas('sellerOrders.shipments', fn ($shipmentQuery) => $shipmentQuery->whereNotIn('current_status', $deliveredShipmentStatuses));
                })
                ->count(),
        );
        $orderStatusCounts->put(
            'DELIVERED',
            (clone $buyerOrders)
                ->where('status', 'PROCESSING')
                ->whereHas('sellerOrders')
                ->whereDoesntHave('sellerOrders', fn ($sellerOrderQuery) => $sellerOrderQuery->whereDoesntHave('shipments'))
                ->whereDoesntHave('sellerOrders.shipments', fn ($shipmentQuery) => $shipmentQuery->whereNotIn('current_status', $deliveredShipmentStatuses))
                ->count(),
        );
        $orderStatusCounts->put('ALL', (clone $buyerOrders)->count());
        $orderStatusCounts->put(
            'RETURNS',
            $returnRefundReady ? (clone $buyerOrders)->whereHas('returnRefundRequests')->count() : 0
        );

        $orderQuery = $request->user()
            ->orders()
            ->with(['sellerOrders.sellerProfile', 'sellerOrders.items.shipment', 'sellerOrders.items.product.images', 'sellerOrders.shipments.orderItem', 'sellerOrders.shipments.events', 'payments', 'disputes']);

        if ($returnRefundReady) {
            $orderQuery->with('returnRefundRequests');
        }

        if ($filter === 'RETURNS') {
            $returnRefundReady
                ? $orderQuery->whereHas('returnRefundRequests')
                : $orderQuery->whereRaw('1 = 0');
        } elseif ($filter === 'DELIVERED') {
            $orderQuery
                ->where('status', 'PROCESSING')
                ->whereHas('sellerOrders')
                ->whereDoesntHave('sellerOrders', fn ($sellerOrderQuery) => $sellerOrderQuery->whereDoesntHave('shipments'))
                ->whereDoesntHave('sellerOrders.shipments', fn ($shipmentQuery) => $shipmentQuery->whereNotIn('current_status', $deliveredShipmentStatuses));
        } elseif ($filter === 'PROCESSING') {
            $orderQuery
                ->where('status', 'PROCESSING')
                ->where(function ($query) use ($deliveredShipmentStatuses): void {
                    $query
                        ->whereHas('sellerOrders', fn ($sellerOrderQuery) => $sellerOrderQuery->whereDoesntHave('shipments'))
                        ->orWhereHas('sellerOrders.shipments', fn ($shipmentQuery) => $shipmentQuery->whereNotIn('current_status', $deliveredShipmentStatuses));
                });
        } elseif (in_array($filter, ['PLACED', 'COMPLETED', 'CANCELLED'], true)) {
            $orderQuery->where('status', $filter);
        }

        $orders = $orderQuery
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $orders->getCollection()->each(fn (Order $order) => $workflow->syncParentOrderProgress($order));
        $orderActionStates = $orders->getCollection()->mapWithKeys(
            fn (Order $order): array => [$order->id => $actions->state($order, $returnRefundReady)]
        );

        return view('Buyer.orders', [
            'mode' => 'index',
            'orders' => $orders,
            'selectedOrder' => null,
            'returnRefundReady' => $returnRefundReady,
            'orderStatusCounts' => $orderStatusCounts,
            'orderActionStates' => $orderActionStates,
        ]);
    }

    public function stream(Request $request): JsonResponse
    {
        $ids = collect($request->query('ids', []))
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->values();

        $orders = $request->user()->orders()
            ->select(['id', 'status', 'updated_at'])
            ->when($ids->isNotEmpty(), fn ($query) => $query->whereIn('id', $ids))
            ->with([
                'sellerOrders' => fn ($query) => $query->select(['id', 'order_id']),
                'sellerOrders.shipments' => fn ($query) => $query->select(['id', 'seller_order_id', 'current_status', 'updated_at']),
            ])
            ->get()
            ->sortBy('id')
            ->values();
        $snapshot = $this->realtimeOrderSnapshot($orders);

        return response()->json([
            'version' => sha1($snapshot->toJson()),
            'orders' => $snapshot->map(fn (array $order): array => [
                'id' => $order['id'],
                'status' => $order['status'],
                'shipments' => $order['shipments'],
            ])->values(),
        ]);
    }

    public function show(Request $request, Order $order, ShipmentWorkflowService $workflow, ReviewImageService $reviewImages, BuyerOrderActionService $actions): View
    {
        abort_unless((int) $order->buyer_user_id === (int) $request->user()->id, 403);
        $workflow->syncParentOrderProgress($order);
        $orderRelations = ['sellerOrders.sellerProfile.user', 'sellerOrders.items.shipment', 'sellerOrders.items.review', 'sellerOrders.items.product.images', 'sellerOrders.shipments.orderItem', 'sellerOrders.shipments.events', 'sellerOrders.shipments.deliveryAttempts', 'sellerOrders.shipments.riderAssignments.riderProfile.user', 'sellerOrders.shipments.riderAssignments.liveLocation', 'sellerOrders.shipments.logisticsCenter.address', 'address', 'payments', 'disputes'];
        if ($this->returnRefundStorageReady()) {
            $orderRelations[] = 'disputes.returnRefundRequest';
            $orderRelations[] = 'returnRefundRequests';
        }
        $selectedOrder = $order->load($orderRelations);
        $selectedOrderActionState = $actions->state($selectedOrder, $this->returnRefundStorageReady());
        $riderRatingsByShipment = $selectedOrder->sellerOrders->flatMap->shipments->mapWithKeys(function ($shipment): array {
            $assignment = $shipment->riderAssignments
                ->where('assignment_type', 'DELIVERY')
                ->sortByDesc('id')
                ->first();
            $review = $shipment->orderItem?->review;

            return $assignment ? [$shipment->id => (int) ($review?->rider_rating ?? 0)] : [];
        });
        $reviewImagesById = $reviewImages->urlsFor($selectedOrder->sellerOrders->flatMap->items->pluck('review')->filter());
        $mapMarkers = app(MapDataService::class)->forShipments($selectedOrder->sellerOrders->flatMap->shipments, includeSeller: true);
        $deliveryPinRequired = $this->canConfirmDeliveryPin($selectedOrder, $selectedOrder->address);

        return view('Buyer.orders', [
            'mode' => 'show',
            'selectedOrder' => $selectedOrder,
            'riderRatingsByShipment' => $riderRatingsByShipment,
            'reviewImagesById' => $reviewImagesById,
            'mapMarkers' => $mapMarkers,
            'deliveryPinRequired' => $deliveryPinRequired,
            'selectedOrderActionState' => $selectedOrderActionState,
            'orders' => $request->user()->orders()->with(['sellerOrders.shipments'])->latest()->paginate(10),
        ]);
    }

    public function confirmDeliveryPin(Request $request, Order $order): RedirectResponse
    {
        abort_unless((int) $order->buyer_user_id === (int) $request->user()->id, 403);
        $data = $request->validate(AddressCoordinateValidator::rules());
        AddressCoordinateValidator::assertValid($data);

        DB::transaction(function () use ($request, $order, $data): void {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $lockedOrder->buyer_user_id === (int) $request->user()->id, 403);
            $orderAddress = OrderAddress::query()
                ->where('order_id', $lockedOrder->id)
                ->lockForUpdate()
                ->first();

            abort_unless($this->canConfirmDeliveryPin($lockedOrder, $orderAddress), 409, 'This order can no longer receive a delivery pin through this flow.');

            // Attach the explicitly confirmed pin to the historical order
            // snapshot only. Never copy from or update the Buyer account address.
            $orderAddress->update([
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
            ]);
        });

        return redirect()->route('buyer.orders.show', $order)->with('buyer_notice', 'Delivery pin confirmed for this order.');
    }

    private function canConfirmDeliveryPin(Order $order, ?OrderAddress $address): bool
    {
        if (! $address || (int) $address->order_id !== (int) $order->id
            || filled($address->latitude) || filled($address->longitude)
            || in_array($order->status, ['DELIVERED', 'COMPLETED', 'CANCELLED', 'RETURNED'], true)) {
            return false;
        }

        $order->loadMissing('sellerOrders.shipments');

        return ! $order->sellerOrders->flatMap->shipments->contains(fn ($shipment): bool => in_array(
            $shipment->current_status,
            ['DELIVERED', 'COMPLETED', 'CANCELLED', 'RETURNED'],
            true,
        ));
    }

    public function sellerConversation(Request $request, Order $order, SellerOrder $sellerOrder, ConversationService $conversations): RedirectResponse
    {
        abort_unless((int) $order->buyer_user_id === (int) $request->user()->id, 403);
        abort_unless((int) $sellerOrder->order_id === (int) $order->id, 404);

        $seller = $sellerOrder->loadMissing('sellerProfile.user')->sellerProfile?->user;
        abort_unless($seller?->isActive(), 404);

        $conversation = $conversations->start($request->user(), $seller->id, [
            'type' => 'ORDER_SELLER',
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
        ]);

        return redirect()->route('buyer.messages', ['seller' => 'conversation-'.$conversation->id]);
    }

    public function supportConversation(Request $request, Order $order, ConversationService $conversations): RedirectResponse
    {
        abort_unless((int) $order->buyer_user_id === (int) $request->user()->id, 403);

        $supportUser = User::query()
            ->where('account_type', User::TYPE_ADMIN)
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('id')
            ->first();

        if (! $supportUser) {
            return back()->with('buyer_notice', 'Support is temporarily unavailable. Please try again shortly.');
        }

        $conversation = $conversations->start($request->user(), $supportUser->id, [
            'type' => 'ORDER_SUPPORT',
            'order_id' => $order->id,
        ]);

        return redirect()->route('buyer.messages', ['seller' => 'conversation-'.$conversation->id]);
    }

    public function cancel(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        abort_unless((int) $order->buyer_user_id === (int) $request->user()->id, 403);
        abort_unless(in_array($order->status, ['PLACED', 'PROCESSING'], true), 409, 'This order can no longer be cancelled.');
        $order->loadMissing('sellerOrders.shipments');
        abort_if(
            $order->sellerOrders->flatMap->shipments->contains(fn ($shipment): bool => in_array($shipment->current_status, [
                'PICKED_UP',
                'AT_SORTING_CENTER',
                'SORTED',
                'ASSIGNED_TO_RIDER',
                'OUT_FOR_DELIVERY',
                'DELIVERED',
                'COMPLETED',
            ], true)),
            409,
            'An order can no longer be cancelled after a parcel has been picked up.'
        );

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $order->update([
            'status' => 'CANCELLED',
            'payment_status' => 'CANCELLED',
            'cancelled_at' => now(),
            'cancellation_reason' => $data['reason'] ?? 'Cancelled by buyer.',
        ]);

        foreach ($order->sellerOrders as $sellerOrder) {
            $sellerOrder->update(['status' => 'CANCELLED']);
            foreach ($sellerOrder->shipments as $shipment) {
                $shipment->update(['current_status' => 'RETURNED']);
                ShipmentEvent::query()->create([
                    'shipment_id' => $shipment->id,
                    'status' => 'RETURNED',
                    'actor_user_id' => $request->user()->id,
                    'notes' => 'Order cancelled by buyer before fulfillment.',
                    'occurred_at' => now(),
                ]);
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'order_number' => $order->order_number,
                'status' => 'CANCELLED',
            ]);
        }

        return back()->with('buyer_notice', 'Order cancelled.');
    }

    public function received(Request $request, Order $order, BuyerOrderActionService $actions): RedirectResponse|JsonResponse
    {
        abort_unless((int) $order->buyer_user_id === (int) $request->user()->id, 403);
        $request->validate([
            'confirm_receipt' => ['required', 'accepted'],
        ]);

        $completed = $actions->confirmReceipt($order, $request->user(), $actions->returnRefundStorageReady());
        abort_unless($completed, 409, 'Receipt can only be confirmed after every parcel has been delivered and any active return/refund request is resolved.');

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'order_number' => $order->order_number,
                'status' => 'COMPLETED',
            ]);
        }

        return redirect(route('buyer.orders.show', $order).'#reviews')
            ->with('buyer_notice', 'Order received. You can now rate the products and write a review.');
    }

    public function returnRefundForm(Request $request, Order $order): View|RedirectResponse
    {
        abort_unless((int) $order->buyer_user_id === (int) $request->user()->id, 403);
        if (! $this->returnRefundStorageReady()) {
            return redirect()->route('buyer.orders.show', $order)->with('buyer_error', 'Return & Refund is still being set up. Please try again after the latest deployment finishes.');
        }
        [$deliveredAt, $deadline] = $this->returnRefundEligibility($order);
        if ($this->activeReturnRefund($order, $request->user()->id)) {
            return redirect()->route('buyer.orders', ['status' => 'RETURNS'])->with('buyer_notice', 'Your return/refund request is already under review.');
        }

        return view('Buyer.return-refund', [
            'order' => $order->load(['sellerOrders.items.product.images', 'sellerOrders.items.productVariant', 'sellerOrders.shipments.events', 'payments']),
            'issues' => $this->returnRefundIssues(),
            'refundMethods' => $this->refundMethods($order),
            'deliveredAt' => $deliveredAt,
            'deadline' => $deadline,
        ]);
    }

    public function returnRefund(Request $request, Order $order, ReturnRefundWorkflowService $workflow, RefundProofStorageService $proofs): View|RedirectResponse|JsonResponse
    {
        abort_unless((int) $order->buyer_user_id === (int) $request->user()->id, 403);

        if (! $request->filled('issue_category')) {
            return $this->returnRefundLegacy($request, $order);
        }

        if (! $this->returnRefundStorageReady()) {
            $message = 'Return & Refund is still being set up. Please try again after the latest deployment finishes.';
            return $request->expectsJson()
                ? response()->json(['message' => $message], 503)
                : back()->with('buyer_error', $message);
        }
        [$deliveredAt] = $this->returnRefundEligibility($order);
        abort_if($this->activeReturnRefund($order, $request->user()->id), 409, 'Your return/refund request is already under review.');

        $issues = $this->returnRefundIssues();
        $data = $request->validate([
            'issue_category' => ['required', 'string', Rule::in(array_keys($issues))],
            'issue_reason' => ['required', 'string', 'max:150'],
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer'],
            'quantities' => ['required', 'array'],
            'description' => ['required', 'string', 'min:1', 'max:2000'],
            'images' => ['nullable', 'array', 'max:6'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'video' => ['nullable', 'file', 'mimes:mp4,mov,webm', 'max:204800'],
            'buyer_email' => ['required', 'email', 'max:255'],
            'confirm_cash' => ['required', 'accepted'],
            'confirm_request' => ['required', 'accepted'],
        ]);

        $issue = $issues[$data['issue_category']];
        abort_unless(in_array($data['issue_reason'], $issue['reasons'], true), 422, 'Choose a valid reason for the selected issue.');

        $order->loadMissing(['sellerOrders.items', 'payments']);
        $itemIds = collect($data['item_ids'])->map(fn ($id): int => (int) $id)->unique()->values();
        $items = $order->items()->whereIn('order_items.id', $itemIds)->get()->keyBy('id');
        abort_unless($items->count() === $itemIds->count(), 422, 'One or more selected items do not belong to this order.');

        $selectedItems = [];
        $totalRefund = 0.0;
        foreach ($itemIds as $itemId) {
            $item = $items->get($itemId);
            $quantity = (int) ($data['quantities'][$itemId] ?? 0);
            $remaining = max(0, (int) $item->quantity - $workflow->activeClaimedQuantity((int) $item->id));
            abort_unless($quantity > 0 && $quantity <= $remaining, 422, 'Choose a valid quantity that has not already been claimed.');
            $amount = round(((float) $item->line_total / max((int) $item->quantity, 1)) * $quantity, 2);
            $selectedItems[] = ['item' => $item, 'quantity' => $quantity, 'amount' => $amount];
            $totalRefund += $amount;
        }

        $requestRecord = DB::transaction(function () use ($request, $order, $data, $issue, $totalRefund, $selectedItems, $workflow, $proofs): ReturnRefundRequest {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_if($this->activeReturnRefund($lockedOrder, $request->user()->id), 409, 'Your return/refund request is already under review.');
            $requestNumber = 'RR-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
            $description = trim($data['description']);
            // The legacy dispute-based creation block remains below only as a
            // compatibility reference; ordinary submissions now use the
            // seller-scoped records and never execute it.
            if (false) {
            $dispute = Dispute::create([
                'dispute_number' => $requestNumber,
                'opened_by_user_id' => $request->user()->id,
                'order_id' => $order->id,
                'type' => 'RETURN_REFUND',
                'subject' => 'Return / Refund Request — '.$order->order_number,
                'description' => "Issue: {$issue['label']}\nReason: {$data['issue_reason']}\nSolution: {$issue['solution']}\n\n{$description}",
                'status' => 'OPEN',
                'opened_at' => now(),
            ]);
            User::query()->where('account_type', User::TYPE_ADMIN)->where('status', User::STATUS_ACTIVE)->get()->each(function (User $admin) use ($order, $dispute): void {
                Notification::create([
                    'user_id' => $admin->id,
                    'type' => 'RETURN_REFUND',
                    'title' => 'Return / refund request received',
                    'message' => 'A buyer opened request '.$dispute->dispute_number.' for order '.$order->order_number.'.',
                    'reference_type' => Dispute::class,
                    'reference_id' => $dispute->id,
                    'action_url' => route('admin.complaints'),
                ]);
            });

            }

            $returnRequest = ReturnRefundRequest::create([
                'request_number' => $requestNumber,
                'dispute_id' => null,
                'order_id' => $order->id,
                'buyer_user_id' => $request->user()->id,
                'issue_category' => $data['issue_category'],
                'issue_reason' => $data['issue_reason'],
                'solution' => $issue['solution'],
                'description' => $description,
                'refund_method' => 'CASH',
                'refundable_amount' => round($totalRefund, 2),
                'requested_amount' => round($totalRefund, 2),
                'buyer_email' => $data['buyer_email'],
                'status' => 'REQUEST_SUBMITTED',
                'submitted_at' => now(),
            ]);

            $selectedBySeller = collect($selectedItems)->groupBy(fn (array $selected): int => (int) $selected['item']->seller_order_id);
            foreach ($selectedBySeller as $sellerOrderId => $sellerItems) {
                $sellerOrder = $lockedOrder->sellerOrders()->with('sellerProfile.user')->whereKey($sellerOrderId)->firstOrFail();
                $sellerTotal = round($sellerItems->sum('amount'), 2);
                $sellerRequest = SellerReturnRefundRequest::create([
                    'return_refund_request_id' => $returnRequest->id,
                    'seller_order_id' => $sellerOrder->id,
                    'seller_profile_id' => $sellerOrder->seller_profile_id,
                    'status' => SellerReturnRefundRequest::STATUS_SELLER_REVIEW,
                    'requested_amount' => $sellerTotal,
                ]);

                foreach ($sellerItems as $selected) {
                    $freshItem = $sellerOrder->items()->whereKey($selected['item']->id)->lockForUpdate()->firstOrFail();
                    $claimed = $workflow->activeClaimedQuantity((int) $freshItem->id);
                    abort_unless($selected['quantity'] <= ((int) $freshItem->quantity - $claimed), 422, 'One or more item quantities were claimed while this request was being submitted.');
                    ReturnRefundRequestItem::create([
                        'return_refund_request_id' => $returnRequest->id,
                        'seller_return_refund_request_id' => $sellerRequest->id,
                        'order_item_id' => $freshItem->id,
                        'quantity' => $selected['quantity'],
                        'refundable_amount' => $selected['amount'],
                    ]);
                }

                $workflow->recordEvent($returnRequest, 'REQUEST_SUBMITTED', $request->user(), $sellerRequest, null, $sellerRequest->status, 'Buyer submitted a seller-scoped return/refund request.');
                $sellerUser = $sellerOrder->sellerProfile?->user;
                if ($sellerUser) {
                    Notification::create([
                        'user_id' => $sellerUser->id,
                        'type' => 'RETURN_REFUND',
                        'title' => 'New return/refund request',
                        'message' => 'Request '.$returnRequest->request_number.' is ready for your review for order '.$lockedOrder->order_number.'.',
                        'reference_type' => SellerReturnRefundRequest::class,
                        'reference_id' => $sellerRequest->id,
                        'action_url' => route('seller.orders.refunds.show', $sellerRequest),
                    ]);
                    $workflow->recordEvent($returnRequest, 'SELLER_NOTIFIED', $request->user(), $sellerRequest, $sellerRequest->status, $sellerRequest->status, 'Responsible Seller notification persisted.');
                }
            }

            foreach ((array) $request->file('images', []) as $image) {
                $stored = $proofs->storeImage($image, 'return-refund-evidence/'.$returnRequest->id);
                ReturnRefundEvidence::create([
                    'return_refund_request_id' => $returnRequest->id,
                    'uploaded_by_user_id' => $request->user()->id,
                    'disk' => $stored['disk'],
                    'file_path' => $stored['path'],
                    'original_name' => $stored['original_name'],
                    'mime_type' => $stored['mime'],
                    'evidence_type' => 'BUYER_PHOTO',
                    'encrypted' => true,
                ]);
            }
            if ($request->hasFile('video')) {
                $stored = $proofs->storeFile($request->file('video'), 'return-refund-evidence/'.$returnRequest->id, ['video/mp4', 'video/quicktime', 'video/webm']);
                ReturnRefundEvidence::create([
                    'return_refund_request_id' => $returnRequest->id,
                    'uploaded_by_user_id' => $request->user()->id,
                    'disk' => $stored['disk'],
                    'file_path' => $stored['path'],
                    'original_name' => $stored['original_name'],
                    'mime_type' => $stored['mime'],
                    'evidence_type' => 'BUYER_VIDEO',
                    'encrypted' => true,
                ]);
            }

            $workflow->recordEvent($returnRequest, 'REQUEST_SUBMITTED', $request->user(), null, null, $returnRequest->status, 'Buyer submitted the return/refund request.');

            return $returnRequest->load(['items.orderItem', 'sellerRequests']);
        });

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Return/refund request submitted.',
                'request_id' => $requestRecord->request_number,
                'status' => $requestRecord->status,
                'solution' => $requestRecord->solution,
                'requested_amount' => $requestRecord->requested_amount,
            ], 201);
        }

        return view('Buyer.return-refund-success', ['requestRecord' => $requestRecord, 'order' => $order]);
    }

    public function returns(): RedirectResponse
    {
        return redirect()->route('buyer.orders', ['status' => 'RETURNS']);
    }

    private function returnRefundEligibility(Order $order): array
    {
        $order->loadMissing('sellerOrders.shipments.events');
        abort_unless(in_array($order->status, ['PROCESSING', 'COMPLETED'], true), 409, 'This order is no longer eligible for return or refund.');
        abort_unless(
            $order->sellerOrders->isNotEmpty()
                && $order->sellerOrders->every(fn ($sellerOrder): bool => $sellerOrder->shipments->isNotEmpty()
                    && $sellerOrder->shipments->every(fn ($shipment): bool => in_array($shipment->current_status, ['DELIVERED', 'COMPLETED'], true))),
            409,
            'Return or refund can only be requested after delivery.'
        );

        $deliveredAt = $order->sellerOrders
            ->flatMap(fn ($sellerOrder) => $sellerOrder->shipments->flatMap->events)
            ->where('status', 'DELIVERED')
            ->max('occurred_at');
        $deadline = $deliveredAt?->copy()->addDays(5);
        abort_unless($deadline && now()->lte($deadline), 409, 'The five-day return and refund window has expired.');

        return [$deliveredAt, $deadline];
    }

    private function returnRefundStorageReady(): bool
    {
        return Schema::hasTable('return_refund_requests')
            && Schema::hasTable('return_refund_request_items')
            && Schema::hasTable('seller_return_refund_requests')
            && Schema::hasTable('return_refund_evidence')
            && Schema::hasTable('refund_payouts')
            && Schema::hasTable('return_refund_events');
    }

    private function activeReturnRefund(Order $order, int $buyerId): mixed
    {
        if (! $this->returnRefundStorageReady()) {
            return null;
        }

        $request = $order->returnRefundRequests()
            ->where('buyer_user_id', $buyerId)
            ->whereNotIn('status', ['COMPLETED', 'REJECTED', 'RESOLVED'])
            ->latest('submitted_at')
            ->first();

        return $request ?: $order->disputes()
            ->where('opened_by_user_id', $buyerId)
            ->where('type', 'RETURN_REFUND')
            ->whereIn('status', ['OPEN', 'UNDER_REVIEW'])
            ->latest('opened_at')
            ->first();
    }

    private function returnRefundIssues(): array
    {
        return [
            'DAMAGED' => ['label' => 'Received damaged item(s)', 'reasons' => ['Scratched item', 'Bent item', 'Shattered item', 'Cracked item', 'Dented item', 'Torn or ripped item', 'Other physical damage'], 'solution' => 'Return & Refund'],
            'DEFECTIVE' => ['label' => 'Product is defective / does not work', 'reasons' => ['Product does not turn on', 'Product is not functioning properly', 'Product stopped working', 'Some functions/features do not work', 'Product is defective upon arrival', 'Other product defect'], 'solution' => 'Return & Refund'],
            'INCORRECT' => ['label' => 'Received incorrect item(s)', 'reasons' => ['Wrong product', 'Wrong variation', 'Wrong color', 'Wrong size', 'Wrong model', 'Wrong quantity/item sent'], 'solution' => 'Return & Refund'],
            'MISSING' => ['label' => 'Did not receive some/all of the item(s)', 'reasons' => ['Parcel was not delivered', 'Missing part of the order', 'Missing item(s) from the parcel', 'Empty parcel received'], 'solution' => 'Refund Only'],
            'OTHER' => ['label' => 'Others', 'reasons' => ['I want to return the item in its original/sealed condition.'], 'solution' => 'Return & Refund'],
        ];
    }

    private function refundMethods(Order $order): array
    {
        return ['CASH' => 'Cash after approval, return custody, and inspection'];
    }

    private function storeDisputeEvidence(Dispute $dispute, mixed $file, int $userId, string $notes): void
    {
        $isImage = $file instanceof UploadedFile && str_starts_with((string) $file->getMimeType(), 'image/');

        DisputeEvidence::create([
            'dispute_id' => $dispute->id,
            'uploaded_by_user_id' => $userId,
            'file_path' => $isImage
                ? $this->images->store($file, 'return-refund-evidence', 'local')
                : $file->store('return-refund-evidence'),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'notes' => $notes,
        ]);
    }

    private function returnRefundLegacy(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        abort_unless((int) $order->buyer_user_id === (int) $request->user()->id, 403);
        $order->loadMissing('sellerOrders.shipments.events');
        abort_unless(in_array($order->status, ['PROCESSING', 'COMPLETED'], true), 409, 'This order is no longer eligible for return or refund.');
        abort_unless(
            $order->sellerOrders->isNotEmpty()
                && $order->sellerOrders->every(fn ($sellerOrder): bool => $sellerOrder->shipments->isNotEmpty()
                    && $sellerOrder->shipments->every(fn ($shipment): bool => in_array($shipment->current_status, ['DELIVERED', 'COMPLETED'], true))),
            409,
            'Return or refund can only be requested after delivery.'
        );

        $deliveredAt = $order->sellerOrders
            ->flatMap(fn ($sellerOrder) => $sellerOrder->shipments->flatMap->events)
            ->where('status', 'DELIVERED')
            ->max('occurred_at');
        abort_unless($deliveredAt && now()->lte($deliveredAt->copy()->addDays(5)), 409, 'The five-day return and refund window has expired.');

        $data = $request->validate([
            'request_type' => ['sometimes', 'required', Rule::in(['Return and refund', 'Refund only'])],
            'reason_category' => [
                'required_with:request_type',
                'nullable',
                Rule::in([
                    'Damaged item',
                    'Defective item',
                    'Wrong product',
                    'Wrong variation',
                    'Missing item',
                    'Missing parts',
                    'Significantly different from description',
                    'Other',
                ]),
            ],
            'details' => ['required_with:request_type', 'nullable', 'string', 'min:20', 'max:3000'],
            'reason' => ['required_without:request_type', 'nullable', 'string', 'min:10', 'max:2000'],
        ]);

        $existing = Dispute::query()
            ->where('order_id', $order->id)
            ->where('opened_by_user_id', $request->user()->id)
            ->where('type', 'RETURN_REFUND')
            ->whereIn('status', ['OPEN', 'UNDER_REVIEW'])
            ->first();

        $created = false;
        $dispute = null;

        if (! $existing) {
            $requestType = $data['request_type'] ?? 'Return and refund';
            $description = isset($data['request_type'])
                ? "Request type: {$requestType}\nReason: {$data['reason_category']}\nDetails: ".trim($data['details'])
                : trim($data['reason']);

            $dispute = Dispute::create([
                'dispute_number' => 'RR-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                'opened_by_user_id' => $request->user()->id,
                'order_id' => $order->id,
                'type' => 'RETURN_REFUND',
                'subject' => 'Return / Refund Request — '.$order->order_number,
                'description' => $description,
                'status' => 'OPEN',
                'opened_at' => now(),
            ]);

            $dispute->update([
                'subject' => $requestType."\u{2014} Return / Refund \u{2014} ".$order->order_number,
            ]);

            $created = true;

            User::query()->where('account_type', User::TYPE_ADMIN)->where('status', User::STATUS_ACTIVE)->get()->each(function (User $admin) use ($order, $dispute): void {
                Notification::create([
                    'user_id' => $admin->id,
                    'type' => 'RETURN_REFUND',
                    'title' => 'Return / refund request received',
                    'message' => 'A buyer opened request '.$dispute->dispute_number.' for order '.$order->order_number.'.',
                    'reference_type' => Dispute::class,
                    'reference_id' => $dispute->id,
                    'action_url' => route('admin.complaints'),
                ]);
            });
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'dispute_id' => $existing?->id ?? $dispute?->id,
                    'status' => $existing?->status ?? $dispute?->status,
                    'already_submitted' => ! $created,
                ],
                'message' => ! $created
                    ? 'Your return/refund request is already under review.'
                    : 'Your return/refund request was submitted for review.',
            ], $created ? 201 : 200);
        }

        return back()->with('buyer_notice', $existing ? 'Your return/refund request is already under review.' : 'Your return/refund request was submitted for review.');
    }

    public function review(Request $request, OrderItem $item, RiderRatingService $riderRatings, ReviewImageService $reviewImages): RedirectResponse|JsonResponse
    {
        $item->loadMissing('review', 'sellerOrder.order', 'shipment.riderAssignments.riderProfile');
        abort_unless((int) $item->sellerOrder->order->buyer_user_id === (int) $request->user()->id, 403);
        abort_unless($item->sellerOrder->order->status === 'COMPLETED', 409, 'You can review after completing the order.');

        $data = $request->validate([
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'rider_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'rider_comment' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($item->review && (filled($data['rating'] ?? null) || filled($data['rider_rating'] ?? null) || $request->hasFile('image'))) {
            if ($request->expectsJson()) {
                abort(409, 'Submitted ratings and photos are locked. You can still edit the review text.');
            }

            return back()->withErrors(['rating' => 'Submitted ratings and photos are locked. You can still edit the review text.'])->withInput();
        }

        if ($request->hasFile('image') && ! filled($data['rating'] ?? null)) {
            if ($request->expectsJson()) {
                abort(422, 'Choose a product rating to attach a photo.');
            }

            return back()->withErrors(['rating' => 'Choose a product rating to attach a photo.'])->withInput();
        }

        if (! $item->review && ! filled($data['rating'] ?? null) && ! filled($data['rider_rating'] ?? null)) {
            if ($request->expectsJson()) {
                abort(422, 'Choose a product rating or a rider rating.');
            }

            return back()->withErrors(['rating' => 'Choose a product rating or a rider rating.'])->withInput();
        }

        $review = Review::query()->firstOrNew(['order_item_id' => $item->id]);
        $review->buyer_user_id = $request->user()->id;
        $review->status = 'PUBLISHED';

        if (filled($data['rating'] ?? null)) {
            $review->rating = (int) $data['rating'];
            $review->comment = $data['comment'] ?? null;
        }

        if ($item->review) {
            $review->comment = $data['comment'] ?? $review->comment;
            $review->rider_comment = $data['rider_comment'] ?? $review->rider_comment;
        }

        if (filled($data['rider_rating'] ?? null)) {
            $assignment = $item->shipment?->riderAssignments
                ->where('assignment_type', 'DELIVERY')
                ->sortByDesc('id')
                ->first();
            abort_unless($assignment?->riderProfile, 409, 'No delivery rider is available to rate for this order.');

            $riderRatings->record(
                $review,
                $assignment->riderProfile,
                (int) $data['rider_rating'],
                $data['rider_comment'] ?? null,
            );
        }

        $review->save();

        if ($request->hasFile('image')) {
            $reviewImages->store($review, $request->file('image'), $request->user(), $request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'review_id' => $review->id,
            ]);
        }

        return back()->with('buyer_notice', 'Your ratings were saved.');
    }

    private function realtimeOrderSnapshot(iterable $orders)
    {
        return collect($orders)
            ->sortBy('id')
            ->map(fn (Order $order): array => [
                'id' => (int) $order->id,
                'status' => (string) $order->status,
                'updated_at' => $order->updated_at?->toIso8601String(),
                'shipments' => $order->sellerOrders
                    ->flatMap(fn (SellerOrder $sellerOrder) => $sellerOrder->shipments->map(fn ($shipment): array => [
                        'id' => (int) $shipment->id,
                        'status' => (string) $shipment->current_status,
                        'updated_at' => $shipment->updated_at?->toIso8601String(),
                    ]))
                    ->sortBy('id')
                    ->values()
                    ->all(),
            ])
            ->values();
    }
}
