<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Buyer\OrderItem;
use App\Models\Logistics\ParcelScan;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\Seller\SellerOrder;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\ShipmentWorkflowService;
use App\Services\Maps\MapDataService;
use App\Support\FinancialRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use RuntimeException;
use Illuminate\View\View;

class SellerOrderController extends Controller
{
    public function index(Request $request, LogisticsSchemaReadiness $schema): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:all,placed,confirmed,preparing,ready-for-pickup,shipping,completed,cancelled,returns'],
            'q' => ['nullable', 'string', 'max:200'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ]);
        $requestedStatus = strtolower((string) ($filters['status'] ?? 'all'));
        $search = trim((string) ($filters['q'] ?? ''));
        $fromDate = $filters['from_date'] ?? null;
        $toDate = $filters['to_date'] ?? null;

        $selectedOrderView = null;
        $selectedRecord = null;
        if ($request->query('mode') === 'show') {
            $orderNumber = $request->validate([
                'order' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9-]+$/'],
            ])['order'];
            $candidate = SellerOrder::query()->where('seller_order_number', $orderNumber)->first();
            abort_unless($candidate, 404);
            abort_unless((int) $candidate->seller_profile_id === (int) $seller->id, 403);
        }

        $orderRelations = ['order.buyer', 'order.address', 'order.payments', 'voucher', 'items.product.images', 'shipment.events', 'shipment.pickupRequests', 'shipment.riderAssignments.riderProfile.user', 'shipment.riderAssignments.liveLocation', 'shipment.logisticsCenter.address', 'shipment.sellerOrder.sellerProfile.businessAddress'];
        $orderRelations = [...$orderRelations,
            'items.shipment.events',
            'shipments.events',
            'shipments.pickupRequests',
            'shipments.riderAssignments.riderProfile.user',
            'shipments.riderAssignments.liveLocation',
            'shipments.logisticsCenter.address',
            'shipments.sellerOrder.sellerProfile.businessAddress',
        ];
        if (Schema::hasTable('cod_collections')) {
            $orderRelations = [...$orderRelations, 'items.shipment.codCollection', 'shipments.codCollection'];
        }
        $pickupBatchSchemaReady = $schema->sellerPickupBatchesReady();
        if ($pickupBatchSchemaReady) {
            $orderRelations = [...$orderRelations,
                'shipments.riderAssignments.localTripItems.trip.seller',
                'shipments.riderAssignments.localTripItems.trip.logisticsCenter.address',
                'shipments.riderAssignments.localTripItems.trip.items',
                'shipments.riderAssignments.localTripItems.trip.items.riderAssignment.shipment',
            ];
        }
        $ownedOrders = SellerOrder::query()->where('seller_profile_id', $seller->id);
        $ordersQuery = (clone $ownedOrders)
            ->with($orderRelations)
            ->latest();

        $statusValues = match ($requestedStatus) {
            'placed' => ['PLACED'],
            'confirmed' => ['CONFIRMED'],
            'preparing' => ['PREPARING'],
            'ready-for-pickup' => ['READY_FOR_PICKUP'],
            'shipping' => ['PICKED_UP', 'AT_SORTING_CENTER', 'SORTED', 'ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY', 'DELIVERED', 'DELIVERY_FAILED', 'RETURNED'],
            'completed' => ['COMPLETED'],
            'cancelled' => ['CANCELLED'],
            default => null,
        };
        if ($statusValues !== null) {
            $ordersQuery->whereIn('status', $statusValues);
        }
        if ($search !== '') {
            $ordersQuery->where(function ($query) use ($search): void {
                $query->where('seller_order_number', 'like', '%'.$search.'%')
                    ->orWhereHas('order', function ($orderQuery) use ($search): void {
                        $orderQuery->where('order_number', 'like', '%'.$search.'%')
                            ->orWhereHas('buyer', fn ($buyerQuery) => $buyerQuery
                                ->where('first_name', 'like', '%'.$search.'%')
                                ->orWhere('last_name', 'like', '%'.$search.'%')
                                ->orWhere('email', 'like', '%'.$search.'%'));
                    })
                    ->orWhereHas('items', fn ($itemQuery) => $itemQuery->where('product_name', 'like', '%'.$search.'%'));
            });
        }
        foreach ([['from_date', '>='], ['to_date', '<=']] as [$dateField, $operator]) {
            $date = $filters[$dateField] ?? null;
            if ($date) {
                $ordersQuery->whereHas('order', fn ($orderQuery) => $orderQuery
                    ->whereRaw('DATE(COALESCE(placed_at, created_at)) '.$operator.' ?', [$date]));
            }
        }
        $orders = $ordersQuery->paginate(20)->withQueryString();
        $ordersPage = $orders->getCollection();

        if ($request->query('mode') === 'show') {
            $selectedRecord = (clone $ownedOrders)->where('seller_order_number', $orderNumber)->with($orderRelations)->firstOrFail();
        }

        $progressOrders = $selectedRecord ? $ordersPage->concat([$selectedRecord]) : $ordersPage;
        $pickupAssignments = $progressOrders
            ->flatMap(fn (SellerOrder $order) => $order->shipments->flatMap->riderAssignments)
            ->filter(fn ($assignment): bool => $assignment->assignment_type === 'PICKUP'
                && $assignment->relationLoaded('localTripItems')
                && $assignment->localTripItems->isNotEmpty())
            ->unique('id')
            ->values();
        $successfulPickupScanKeys = $this->successfulPickupScanKeys($pickupAssignments);
        if ($selectedRecord) {
            $selectedOrderView = $this->viewOrder($selectedRecord, $pickupBatchSchemaReady, $successfulPickupScanKeys);
        }

        $refundSearch = $requestedStatus === 'returns' ? $search : '';
        $refundStatus = strtoupper((string) $request->query('refund_status', ''));
        $refundBaseQuery = SellerReturnRefundRequest::query()->where('seller_profile_id', $seller->id);
        $refundCounts = (clone $refundBaseQuery)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $refundQuery = (clone $refundBaseQuery)
            ->with(['request.order.buyer', 'request.events', 'sellerOrder', 'sellerProfile', 'items.orderItem', 'payouts'])
            ->when($refundSearch !== '', function ($query) use ($refundSearch): void {
                $query->where(function ($nested) use ($refundSearch): void {
                    $nested
                        ->whereHas('request', fn ($requestQuery) => $requestQuery->where('request_number', 'like', '%'.$refundSearch.'%'))
                        ->orWhereHas('request.order', fn ($orderQuery) => $orderQuery->where('order_number', 'like', '%'.$refundSearch.'%'))
                        ->orWhereHas('request.buyer', fn ($buyerQuery) => $buyerQuery->where('first_name', 'like', '%'.$refundSearch.'%')->orWhere('last_name', 'like', '%'.$refundSearch.'%')->orWhere('email', 'like', '%'.$refundSearch.'%'))
                        ->orWhereHas('items.orderItem', fn ($itemQuery) => $itemQuery->where('product_name', 'like', '%'.$refundSearch.'%'));
                });
            })
            ->when(in_array($refundStatus, [SellerReturnRefundRequest::STATUS_SELLER_REVIEW, SellerReturnRefundRequest::STATUS_APPROVED, SellerReturnRefundRequest::STATUS_ADMIN_REFUND_REQUIRED, SellerReturnRefundRequest::STATUS_REJECTED, SellerReturnRefundRequest::STATUS_REFUND_SENT, SellerReturnRefundRequest::STATUS_CONFIRMED, SellerReturnRefundRequest::STATUS_ESCALATED, SellerReturnRefundRequest::STATUS_DISPUTE_RESOLVED], true), fn ($query) => $query->where('status', $refundStatus))
            ->latest();
        $refundRequests = $requestedStatus === 'returns' ? $refundQuery->paginate(15)->withQueryString() : null;

        $counts = (clone $ownedOrders)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $statusCounts = $counts->mapWithKeys(fn ($count, $status): array => [$this->statusKey((string) $status) => (int) $count]);
        $statusCounts->put('returns', (int) $refundCounts->sum());
        $readyPickupCount = (clone $ownedOrders)->where('status', 'READY_FOR_PICKUP')->with('shipments')->get()
            ->sum(fn (SellerOrder $order): int => $order->shipments->whereIn('current_status', ['READY_FOR_PICKUP', 'PICKUP_ASSIGNED'])->count());

        return view('seller.orders', [
            'seller' => $seller,
            'orders' => $orders,
            'counts' => $counts,
            'activeStatus' => strtoupper((string) $request->query('status', '')),
            'status' => $requestedStatus,
            'search' => $search,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'selectedOrderView' => $selectedOrderView,
            'sellerOrders' => $ordersPage->map(fn (SellerOrder $order): array => $this->viewOrder($order, $pickupBatchSchemaReady, $successfulPickupScanKeys)),
            'refundRequests' => $refundRequests,
            'refundCounts' => $refundCounts,
            'refundStatus' => $refundStatus,
            'statusCounts' => $statusCounts,
            'readyPickupCount' => $readyPickupCount,
        ]);
    }

    public function pickupProgress(Request $request, int $trip): JsonResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);
        abort_unless(app(LogisticsSchemaReadiness::class)->sellerPickupBatchesReady(), 503);

        $pickup = RiderLocalTrip::query()
            ->whereKey($trip)
            ->where('trip_type', RiderLocalTrip::TYPE_PICKUP)
            ->where('seller_profile_id', $seller->id)
            ->with([
                'rider.user',
                'items.riderAssignment.shipment',
            ])
            ->firstOrFail();

        $progress = $this->pickupProgressFor(
            $pickup,
            $this->successfulPickupScanKeys($pickup->items->map(fn ($item) => $item->riderAssignment)->filter()),
        );

        return response()->json([
            'batch' => 'P-'.$pickup->id,
            'status' => $pickup->status,
            'rider' => $pickup->rider?->user?->name,
            'collected' => $progress['collected'],
            'total' => $progress['total'],
            'parcels' => $progress['parcels']->map(fn (array $parcel): array => [
                'tracking_number' => $parcel['tracking_number'],
                'status' => $parcel['status'],
            ]),
        ]);
    }

    public function transition(Request $request, SellerOrder $sellerOrder, ShipmentWorkflowService $workflow): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerOrder->seller_profile_id === (int) $seller->id, 403);

        if ($request->input('action') === 'cancel' && is_string($request->input('cancellation_reason'))) {
            $request->merge(['cancellation_reason' => trim($request->input('cancellation_reason'))]);
        }

        $data = $request->validate([
            'action' => ['required', 'string', 'in:confirm,prepare,pack,ready,handover_confirm,cancel'],
            'cancellation_reason' => ['required_if:action,cancel', 'nullable', 'string', 'max:500'],
            'shipment_ids' => ['required_if:action,pack,ready,handover_confirm', 'array', 'min:1', 'max:100'],
            'shipment_ids.*' => ['integer', 'distinct', 'min:1'],
        ]);

        if (in_array(($data['action'] ?? null), ['ready', 'handover_confirm'], true)
            && count($data['shipment_ids'] ?? []) > \App\Support\LocalTripCapacityRules::MAX_PARCELS_PER_TRIP) {
            return back()->withErrors(['shipment_ids' => 'Select 1 to 10 parcels at a time.']);
        }

        try {
            $workflow->sellerTransition(
                $sellerOrder,
                $data['action'],
                $request->user(),
                $data['cancellation_reason'] ?? null,
                $data['shipment_ids'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['parcels' => $exception->getMessage()]);
        }

        return back()->with('status', 'Seller order updated successfully.');
    }

    public function recordParcelMeasurements(Request $request, SellerOrder $sellerOrder, OrderItem $orderItem, ShipmentWorkflowService $workflow): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerOrder->seller_profile_id === (int) $seller->id, 403);
        abort_unless((int) $orderItem->seller_order_id === (int) $sellerOrder->id, 404);

        $data = $request->validate([
            'parcel_weight_kg' => ['required', 'numeric', 'gt:0', 'max:9999999.999', 'decimal:0,3'],
            'parcel_volume_liters' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
        ]);
        $shipment = $orderItem->shipment()->firstOrFail();

        try {
            $workflow->recordPackedMeasurements(
                $shipment,
                $request->user(),
                (string) $data['parcel_weight_kg'],
                (string) $data['parcel_volume_liters'],
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['measurements' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Measured packed weight and volume saved for this parcel.');
    }

    public function ensureParcels(Request $request, SellerOrder $sellerOrder, ShipmentWorkflowService $workflow): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerOrder->seller_profile_id === (int) $seller->id, 403);

        try {
            $shipments = $workflow->ensureItemShipments($sellerOrder, $request->user());
        } catch (RuntimeException $exception) {
            return redirect()->route('seller.orders', ['mode' => 'show', 'order' => $sellerOrder->seller_order_number])
                ->withErrors(['parcels' => $exception->getMessage()]);
        }

        return redirect()->route('seller.orders', ['mode' => 'show', 'order' => $sellerOrder->seller_order_number])
            ->with('status', $shipments->count().' parcel waybill(s) are now linked to their product lines. Print each label before handoff.');
    }

    public function handover(Request $request, SellerOrder $sellerOrder, ShipmentWorkflowService $workflow): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerOrder->seller_profile_id === (int) $seller->id, 403);

        $data = $request->validate([
            'handover_method' => ['required', 'string', 'in:logistics_pickup,seller_dropoff'],
            'pickup_date' => ['nullable', 'date', 'after_or_equal:today'],
            'pickup_window' => ['nullable', 'string', 'in:10:00 AM - 12:00 PM,1:00 PM - 3:00 PM,3:00 PM - 5:00 PM'],
            'pickup_note' => ['nullable', 'string', 'max:1000'],
            'shipment_ids' => ['required', 'array', 'min:1', 'max:10'],
            'shipment_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);

        try {
            $handoverNote = collect([
                filled($data['pickup_date'] ?? null) ? 'Preferred date: '.$data['pickup_date'] : null,
                filled($data['pickup_window'] ?? null) ? 'Preferred window: '.$data['pickup_window'] : null,
                filled($data['pickup_note'] ?? null) ? 'Note: '.trim($data['pickup_note']) : null,
            ])->filter()->implode(' | ');
            $workflow->requestSellerHandover(
                $sellerOrder,
                $request->user(),
                $data['handover_method'] === 'seller_dropoff',
                $handoverNote,
                $data['shipment_ids'],
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['parcels' => $exception->getMessage()]);
        }

        return redirect()->route('seller.orders')->with('status', $data['handover_method'] === 'seller_dropoff'
            ? 'Drop-off registered. Bring the parcel and tracking number to your assigned logistics center.'
            : 'Logistics pickup requested.');
    }

    private function viewOrder(SellerOrder $order, bool $pickupBatchSchemaReady, array $successfulPickupScanKeys): array
    {
        $address = $order->order?->address;
        $items = $order->items;
        $shipments = $order->shipments;
        $packedCheckpoint = fn ($shipment): bool => $shipment && $shipment->events->contains(
            fn ($event): bool => $event->status === 'PREPARING' && str_starts_with((string) $event->notes, 'SELLER_PACKED_CHECKPOINT:'),
        );
        $parcelRows = $items->map(fn ($item): array => [
            'item' => $item,
            'shipment' => $item->shipment,
            'packed' => $packedCheckpoint($item->shipment),
        ])->values();
        $assignments = $shipments->flatMap->riderAssignments;
        $assignment = $assignments
            ?->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])
            ->sortByDesc('id')
            ->first();
        $pickupAssignment = $assignments
            ?->where('assignment_type', 'PICKUP')
            ->sortByDesc('id')
            ->first();
        $pickupBatch = $pickupBatchSchemaReady
            ? $pickupAssignment?->localTripItems?->sortByDesc('id')->first()?->trip
            : null;
        $pickupProgress = $pickupBatch ? $this->pickupProgressFor($pickupBatch, $successfulPickupScanKeys) : [
            'collected' => 0,
            'total' => 0,
            'parcels' => collect(),
        ];
        $parcelStatuses = $shipments->pluck('current_status');
        $pickupStatus = match (true) {
            $parcelStatuses->isNotEmpty() && $parcelStatuses->every(fn (string $status): bool => in_array($status, ['PICKED_UP', 'AT_SORTING_CENTER', 'SORTED', 'ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY', 'DELIVERED', 'COMPLETED'], true)) => 'Picked up',
            $pickupBatch?->status === 'EN_ROUTE_TO_HUB' => 'In transit to logistics',
            $pickupBatch?->status === 'IN_PROGRESS' => 'Pickup in Progress',
            $pickupAssignment && in_array($pickupAssignment->status, ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'], true) => 'Pickup scheduled',
            $parcelStatuses->contains('PICKUP_ASSIGNED') => 'Pickup scheduled',
            $parcelStatuses->contains('READY_FOR_PICKUP') => 'Waiting for Logistics',
            default => null,
        };

        $itemSubtotalCents = FinancialRules::cents($order->item_subtotal);
        $voucherCents = FinancialRules::cents($order->voucher_discount);
        $rewardPointsDiscountCents = FinancialRules::cents($order->reward_points_discount ?? 0);
        $shippingCents = FinancialRules::cents($order->shipping_fee);
        $shippingVoucherDiscountCents = FinancialRules::cents($order->shipping_voucher_discount ?? 0);
        $protectionCents = FinancialRules::cents($order->protection_total ?? 0);
        $grandTotalCents = FinancialRules::cents($order->grand_total);
        $codCollections = $shipments
            ->filter(fn ($shipment): bool => $shipment->relationLoaded('codCollection'))
            ->map(fn ($shipment) => $shipment->getRelation('codCollection'))
            ->filter();
        $codAmountCents = $codCollections->isNotEmpty()
            ? $codCollections->sum(fn ($collection): int => FinancialRules::cents($collection->expected_amount))
            : $items->sum(fn ($item): int => FinancialRules::cents(\App\Support\OrderItemParcelAmount::forItem($order, $item)['total']));
        $paidOnline = $order->order?->payments?->contains(fn ($payment): bool => $payment->method === 'ONLINE' && $payment->status === 'PAID') ?? false;
        $pickupIrreversible = $assignments->contains(fn ($candidate): bool => $candidate->assignment_type === 'PICKUP'
            && in_array($candidate->status, ['IN_PROGRESS', 'COMPLETED'], true));
        $codCollected = $codCollections->contains(fn ($collection): bool => in_array($collection->status, ['COLLECTED', 'REMITTED', 'DISCREPANCY'], true));

        return [
            'id' => $order->seller_order_number,
            'db_id' => $order->id,
            'date' => ($order->order?->placed_at ?? $order->order?->created_at ?? $order->created_at)?->format('M d, Y g:i A'),
            'placed_at' => $order->order?->placed_at ?? $order->order?->created_at ?? $order->created_at,
            'buyer_id' => $order->order?->buyer_user_id,
            'buyer' => $order->order?->buyer?->name ?? 'Buyer',
            'buyer_note' => $order->buyer_note,
            'items' => $items->values(),
            'parcels' => $parcelRows,
            'ready_parcels' => $parcelRows->filter(fn (array $parcel): bool => $parcel['packed']
                && $parcel['shipment']?->current_status === 'PREPARING')->values(),
            'unpacked_parcels' => $parcelRows->filter(fn (array $parcel): bool => ! $parcel['packed']
                && $parcel['shipment']?->current_status === 'PREPARING')->values(),
            'ready_parcel_count' => $shipments->whereIn('current_status', ['READY_FOR_PICKUP', 'PICKUP_ASSIGNED'])->count(),
            'unresolved_legacy_shipments' => $shipments->whereNull('order_item_id')->values(),
            'has_unresolved_legacy_shipment' => $items->count() > 1
                && $shipments->contains(fn ($shipment): bool => $shipment->order_item_id === null),
            'parcels_need_setup' => $items->contains(fn ($item): bool => ! $item->shipment)
                || $shipments->contains(fn ($shipment): bool => $shipment->order_item_id === null),
            'packed' => $shipments->isNotEmpty() && $shipments->every($packedCheckpoint),
            'product_search' => $items->pluck('product_name')->filter()->implode(' '),
            'total' => (float) $order->grand_total,
            'item_subtotal' => (float) $order->item_subtotal,
            'voucher_discount' => (float) $order->voucher_discount,
            'reward_points_redeemed' => (int) ($order->reward_points_redeemed ?? 0),
            'reward_points_discount' => (float) ($order->reward_points_discount ?? 0),
            'voucher_code' => $order->voucher?->code,
            'shipping_fee' => (float) $order->shipping_fee,
            'shipping_voucher_discount' => (float) ($order->shipping_voucher_discount ?? 0),
            'protection_total' => (float) ($order->protection_total ?? 0),
            'cod_amount' => $order->order?->payments?->first()?->method === 'COD' ? $codAmountCents / 100 : null,
            'financial_reconciles' => $itemSubtotalCents - $voucherCents - $rewardPointsDiscountCents + $shippingCents - $shippingVoucherDiscountCents + $protectionCents === $grandTotalCents,
            'can_cancel' => in_array($order->status, ['PLACED', 'CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP'], true)
                && ! $paidOnline && ! $pickupIrreversible && ! $codCollected,
            'payment' => str($order->order?->payments?->first()?->method ?? 'PENDING')->headline()->toString(),
            'logistics_provider' => $shipments->firstWhere('current_status', 'READY_FOR_PICKUP')?->logisticsCenter?->business_name
                ?? $shipments->first()?->logisticsCenter?->business_name ?? 'Not assigned',
            'shipping' => $assignment?->riderProfile?->user?->name ?? $pickupAssignment?->riderProfile?->user?->name ?? 'Waiting for Logistics',
            'pickup_status' => $pickupStatus,
            'pickup_batch' => $pickupBatch ? 'P-'.$pickupBatch->id : null,
            'pickup_batch_size' => $pickupBatch?->items?->count() ?? ($pickupAssignment ? 1 : null),
            'pickup_progress_collected' => $pickupProgress['collected'],
            'pickup_progress_total' => $pickupProgress['total'],
            'pickup_progress_parcels' => $pickupProgress['parcels'],
            'pickup_batch_status' => $pickupBatch?->status,
            'pickup_progress_active' => $pickupBatch && in_array($pickupBatch->status, [
                RiderLocalTrip::LOADING,
                RiderLocalTrip::READY,
                RiderLocalTrip::IN_PROGRESS,
            ], true),
            'pickup_progress_url' => $pickupBatch
                ? route('seller.orders.pickups.progress', $pickupBatch->id)
                : null,
            'pickup_progress_shipment_ids' => $pickupProgress['parcels']->pluck('shipment_id')->values(),
            'pickup_rider' => $pickupAssignment?->riderProfile?->user?->name,
            'pickup_assigned_at' => $pickupAssignment?->assigned_at,
            'pickup_collected_at' => $pickupAssignment?->completed_at,
            'pickup_hub' => $pickupBatch?->logisticsCenter?->business_name ?? $shipments->first()?->logisticsCenter?->business_name,
            'shipping_address' => $address?->formatted() ?? 'Address unavailable',
            'status' => str($order->status)->replace('_', ' ')->title()->toString(),
            'status_key' => $this->statusKey($order->status),
            'delivery' => $shipments->first(),
            'map_markers' => app(MapDataService::class)->forShipments($shipments, includeSeller: true),
        ];
    }

    /** @param Collection<int, \App\Models\Rider\RiderAssignment> $assignments */
    private function successfulPickupScanKeys(Collection $assignments): array
    {
        $assignmentIds = $assignments
            ->filter(fn ($assignment): bool => $assignment->assignment_type === 'PICKUP')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($assignmentIds->isEmpty() || ! Schema::hasTable('parcel_scans')) {
            return [];
        }

        return ParcelScan::query()
            ->whereIn('rider_assignment_id', $assignmentIds)
            ->where('scan_type', 'PICKUP_COLLECTED')
            ->where('result', 'SUCCESS')
            ->get(['shipment_id', 'rider_assignment_id', 'scanned_code'])
            ->mapWithKeys(fn (ParcelScan $scan): array => [
                $this->pickupScanKey((int) $scan->shipment_id, (int) $scan->rider_assignment_id, (string) $scan->scanned_code) => true,
            ])
            ->all();
    }

    /**
     * Progress is derived from successful parcel scan records, one entry per waybill.
     *
     * @return array{collected:int,total:int,parcels:\Illuminate\Support\Collection<int, array{shipment_id:int,tracking_number:string,status:string,collected:bool}>}
     */
    private function pickupProgressFor(RiderLocalTrip $pickup, array $successfulPickupScanKeys): array
    {
        $parcels = $pickup->items
            ->map(function ($item) use ($successfulPickupScanKeys): ?array {
                $assignment = $item->riderAssignment;
                $shipment = $assignment?->shipment;
                if (! $shipment) {
                    return null;
                }

                $collected = isset($successfulPickupScanKeys[
                    $this->pickupScanKey((int) $shipment->id, (int) $assignment->id, (string) $shipment->tracking_number)
                ]);

                return [
                    'shipment_id' => (int) $shipment->id,
                    'tracking_number' => (string) $shipment->tracking_number,
                    'status' => $collected ? 'Collected' : match ($assignment->status) {
                        'REJECTED' => 'Not collected',
                        'CANCELLED' => 'Cancelled',
                        default => 'Waiting',
                    },
                    'collected' => $collected,
                ];
            })
            ->filter()
            ->unique('shipment_id')
            ->values();

        return [
            'collected' => $parcels->where('collected', true)->count(),
            'total' => $parcels->count(),
            'parcels' => $parcels,
        ];
    }

    private function pickupScanKey(int $shipmentId, int $assignmentId, string $code): string
    {
        return $shipmentId.'|'.$assignmentId.'|'.$code;
    }

    private function statusKey(string $status): string
    {
        return match ($status) {
            'PLACED' => 'placed',
            'CONFIRMED' => 'confirmed',
            'PREPARING' => 'preparing',
            'READY_FOR_PICKUP' => 'ready-for-pickup',
            'PICKED_UP' => 'shipping',
            'COMPLETED' => 'completed',
            'CANCELLED' => 'cancelled',
            default => strtolower(str_replace('_', '-', $status)),
        };
    }
}
