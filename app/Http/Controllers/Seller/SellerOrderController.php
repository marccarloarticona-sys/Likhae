<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Buyer\OrderItem;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ParcelScan;
use App\Models\Logistics\ShipmentReturnWorkflow;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\Seller\SellerOrder;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\ShipmentWorkflowService;
use App\Support\FinancialRules;
use App\Support\ParcelWeightEstimator;
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
            $selectedRecord = $candidate;
        }

        $pickupBatchSchemaReady = $schema->sellerPickupBatchesReady();

        if ($selectedRecord) {
            $detailRelations = [
                'order.buyer',
                'order.address',
                'order.payments',
                'voucher',
                'items.product.images',
                'shipments.events',
                'shipments.pickupRequests',
                'shipments.riderAssignments.riderProfile.user',
                'shipments.logisticsCenter',
            ];
            if (Schema::hasTable('seller_settlements')) {
                $detailRelations[] = 'settlements';
            }
            if (Schema::hasTable('cod_collections')) {
                $detailRelations[] = 'shipments.codCollection';
            }
            if ($pickupBatchSchemaReady) {
                $detailRelations = [...$detailRelations,
                    'shipments.riderAssignments.localTripItems.trip.logisticsCenter',
                    'shipments.riderAssignments.localTripItems.trip.items.riderAssignment.shipment',
                ];
            }
            if ($schema->sellerPickupCenterSelectionReady()) {
                $detailRelations[] = 'shipments.sellerPickupMemberships.pickupRequest.preferredLogisticsCenter';
            }
            if ($schema->sellerSelectedFallbackHubsReady() && $schema->sellerPickupCenterOffersReady()) {
                $detailRelations[] = 'shipments.sellerPickupMemberships.pickupRequest.centerOffers';
            }

            $selectedRecord->load($detailRelations);
            $pickupAssignments = $selectedRecord->shipments
                ->flatMap->riderAssignments
                ->filter(fn ($assignment): bool => $assignment->assignment_type === 'PICKUP'
                    && $assignment->relationLoaded('localTripItems')
                    && $assignment->localTripItems->isNotEmpty())
                ->unique('id')
                ->values();
            $successfulPickupScanKeys = $this->successfulPickupScanKeys($pickupAssignments);
            $selectedOrderView = $this->viewOrder($selectedRecord, $pickupBatchSchemaReady, $successfulPickupScanKeys, $schema);

            return view('seller.orders', [
                'seller' => $seller,
                'mode' => 'show',
                'status' => $requestedStatus,
                'search' => $search,
                'fromDate' => $fromDate,
                'toDate' => $toDate,
                'selectedOrderView' => $selectedOrderView,
            ]);
        }

        $orderRelations = ['order.buyer', 'order.address', 'order.payments', 'voucher', 'items.product.images', 'shipment.events', 'shipment.pickupRequests', 'shipment.riderAssignments.riderProfile.user', 'shipment.riderAssignments.liveLocation', 'shipment.logisticsCenter.address', 'shipment.sellerOrder.sellerProfile.businessAddress'];
        $orderRelations = [...$orderRelations,
            'items.shipment.events',
            'shipments.events',
            'shipments.orderItem.productVariant',
            'shipments.pickupRequests',
            'shipments.riderAssignments.riderProfile.user',
            'shipments.riderAssignments.liveLocation',
            'shipments.logisticsCenter.address',
            'shipments.sellerOrder.sellerProfile.businessAddress',
        ];
        if (Schema::hasTable('seller_settlements')) {
            $orderRelations[] = 'settlements';
        }
        if (Schema::hasTable('cod_collections')) {
            $orderRelations = [...$orderRelations, 'items.shipment.codCollection', 'shipments.codCollection'];
        }
        if ($pickupBatchSchemaReady) {
            $orderRelations = [...$orderRelations,
                'shipments.riderAssignments.localTripItems.trip.seller',
                'shipments.riderAssignments.localTripItems.trip.logisticsCenter.address',
                'shipments.riderAssignments.localTripItems.trip.items',
                'shipments.riderAssignments.localTripItems.trip.items.riderAssignment.shipment',
            ];
        }
        if ($schema->sellerPickupCenterSelectionReady()) {
            $orderRelations[] = 'shipments.sellerPickupMemberships.pickupRequest.preferredLogisticsCenter';
        }
        if ($schema->sellerSelectedFallbackHubsReady() && $schema->sellerPickupCenterOffersReady()) {
            $orderRelations[] = 'shipments.sellerPickupMemberships.pickupRequest.centerOffers';
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

        $progressOrders = $ordersPage;
        $pickupAssignments = $progressOrders
            ->flatMap(fn (SellerOrder $order) => $order->shipments->flatMap->riderAssignments)
            ->filter(fn ($assignment): bool => $assignment->assignment_type === 'PICKUP'
                && $assignment->relationLoaded('localTripItems')
                && $assignment->localTripItems->isNotEmpty())
            ->unique('id')
            ->values();
        $successfulPickupScanKeys = $this->successfulPickupScanKeys($pickupAssignments);
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

        $returnWorkflowSchemaReady = $schema->shipmentReturnsReady();
        $returnEnhancementsReady = $schema->shipmentReturnEnhancementsReady();
        $sellerReturnWorkflows = collect();
        $sellerReturnWorkflowCount = 0;
        if ($returnWorkflowSchemaReady) {
            $sellerReturnWorkflowQuery = ShipmentReturnWorkflow::query()
                ->whereHas('shipment.sellerOrder', fn ($query) => $query->where('seller_profile_id', $seller->id));

            if (Schema::hasColumn('shipment_return_workflows', 'seller_return_refund_request_id')) {
                $sellerReturnWorkflowQuery->whereNull('seller_return_refund_request_id');
            }
            if ($returnEnhancementsReady) {
                $sellerReturnWorkflowQuery->where(function ($query): void {
                    $query->whereNull('return_disposition')
                        ->orWhere('return_disposition', ShipmentReturnWorkflow::DISPOSITION_SELLER_RETURN);
                });
            }

            $sellerReturnWorkflowQuery->whereIn('status', [
                ...ShipmentReturnWorkflow::OPEN_STATUSES,
                ShipmentReturnWorkflow::SELLER_RECEIVED,
            ]);
            $sellerReturnWorkflowCount = (clone $sellerReturnWorkflowQuery)->count();

            if ($requestedStatus === 'returns') {
                $sellerReturnWorkflows = (clone $sellerReturnWorkflowQuery)
                    ->with([
                        'shipment.orderItem',
                        'shipment.sellerOrder.order',
                        'logisticsCenter',
                        'sellerHandoffBy',
                        'sellerReceivedBy',
                    ])
                    ->when($refundSearch !== '', function ($query) use ($refundSearch): void {
                        $query->where(function ($nested) use ($refundSearch): void {
                            $nested->where('reason', 'like', '%'.$refundSearch.'%')
                                ->when(Schema::hasColumn('shipment_return_workflows', 'return_tracking_number'), fn ($search) => $search->orWhere('return_tracking_number', 'like', '%'.$refundSearch.'%'))
                                ->orWhereHas('shipment', fn ($shipment) => $shipment
                                    ->where('tracking_number', 'like', '%'.$refundSearch.'%')
                                    ->orWhereHas('sellerOrder', fn ($order) => $order
                                        ->where('seller_order_number', 'like', '%'.$refundSearch.'%')
                                        ->orWhereHas('order', fn ($parent) => $parent->where('order_number', 'like', '%'.$refundSearch.'%')))
                                    ->orWhereHas('orderItem', fn ($item) => $item->where('product_name', 'like', '%'.$refundSearch.'%')));
                        });
                    })
                    ->latest('updated_at')
                    ->limit(30)
                    ->get();
            }
        }

        $counts = (clone $ownedOrders)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $statusCounts = $counts->mapWithKeys(fn ($count, $status): array => [$this->statusKey((string) $status) => (int) $count]);
        $statusCounts->put('returns', (int) $refundCounts->sum() + $sellerReturnWorkflowCount);
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
            'sellerOrders' => $ordersPage->map(fn (SellerOrder $order): array => $this->viewOrder($order, $pickupBatchSchemaReady, $successfulPickupScanKeys, $schema)),
            'refundRequests' => $refundRequests,
            'sellerReturnWorkflows' => $sellerReturnWorkflows,
            'returnWorkflowSchemaReady' => $returnWorkflowSchemaReady,
            'returnEnhancementsReady' => $returnEnhancementsReady,
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

    public function splitParcels(Request $request, SellerOrder $sellerOrder, OrderItem $orderItem, ShipmentWorkflowService $workflow): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerOrder->seller_profile_id === (int) $seller->id, 403);
        abort_unless((int) $orderItem->seller_order_id === (int) $sellerOrder->id, 404);

        $data = $request->validate([
            'parcel_quantities' => ['required', 'string', 'max:80', 'regex:/^\s*\d+(?:\s*,\s*\d+){1,9}\s*$/'],
        ]);
        $quantities = array_map('intval', preg_split('/\s*,\s*/', trim($data['parcel_quantities'])) ?: []);

        try {
            $parcels = $workflow->splitItemIntoParcels($sellerOrder, $orderItem, $quantities, $request->user());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['parcel_split' => $exception->getMessage()])
                ->withInput()
                ->with('parcel_split_item_id', $orderItem->id)
                ->with('parcel_split_message', $exception->getMessage());
        }

        return redirect()->route('seller.orders', ['mode' => 'show', 'order' => $sellerOrder->seller_order_number])
            ->with('status', 'Parcel split saved. Every parcel is listed on the single order waybill with its own scan code. The existing order shipping fee remains unchanged.');
    }

    public function saveParcelDimensions(Request $request, SellerOrder $sellerOrder, Shipment $shipment, ShipmentWorkflowService $workflow): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerOrder->seller_profile_id === (int) $seller->id, 403);
        abort_unless((int) $shipment->seller_order_id === (int) $sellerOrder->id, 404);
        abort_unless(Schema::hasColumns('shipments', ['parcel_length_cm', 'parcel_width_cm', 'parcel_height_cm']), 503);

        $data = $request->validate([
            'length_cm' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'width_cm' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'height_cm' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
        ]);

        try {
            $savedShipment = $workflow->recordPackedDimensions(
                $shipment,
                $request->user(),
                (string) $data['length_cm'],
                (string) $data['width_cm'],
                (string) $data['height_cm'],
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['parcel_dimensions' => $exception->getMessage()])->withInput();
        }

        $status = ParcelWeightEstimator::recordedWeight($savedShipment) !== null
            ? 'Packed parcel dimensions saved. Total parcel weight is calculated automatically from product weight and parcel quantity.'
            : 'Packed parcel dimensions saved. Add a unit weight to the product to calculate total parcel weight.';

        return back()->with('status', $status);
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
            ->with('status', 'Parcel scan codes are ready. Print one order waybill before handoff.');
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

        if ($data['handover_method'] === 'seller_dropoff') {
            return redirect()->route('seller.orders')
                ->with('status', 'Drop-off registered. Print the order waybill and take the selected parcel(s) to your assigned logistics center for intake scanning.');
        }

        return redirect()->route('seller.logistics')
            ->with('status', 'Parcels are ready. Submit the pickup request from this page.');
    }

    private function viewOrder(
        SellerOrder $order,
        bool $pickupBatchSchemaReady,
        array $successfulPickupScanKeys,
        LogisticsSchemaReadiness $schema,
    ): array
    {
        $address = $order->order?->address;
        $items = $order->items;
        $shipments = $order->shipments;
        $parcelDimensionsReady = Schema::hasColumns('shipments', ['parcel_length_cm', 'parcel_width_cm', 'parcel_height_cm']);
        $packedCheckpoint = fn ($shipment): bool => $shipment && $shipment->events->contains(
            fn ($event): bool => $event->status === 'PREPARING' && str_starts_with((string) $event->notes, 'SELLER_PACKED_CHECKPOINT:'),
        );
        $shipmentsByItem = $shipments->whereNotNull('order_item_id')->groupBy('order_item_id');
        $parcelRows = $shipments->filter(fn ($shipment): bool => $shipment->order_item_id !== null)
            ->map(fn ($shipment): array => [
                'item' => $items->firstWhere('id', (int) $shipment->order_item_id),
                'shipment' => $shipment,
                'packed' => $packedCheckpoint($shipment),
                'can_mark_packed' => ! $parcelDimensionsReady || ($shipment->parcel_length_cm !== null && $shipment->parcel_width_cm !== null && $shipment->parcel_height_cm !== null),
            ])
            ->filter(fn (array $parcel): bool => $parcel['item'] !== null)
            ->values();
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
        $sellerDropoffShipmentIds = $shipments
            ->filter(fn ($shipment): bool => $shipment->current_status === 'READY_FOR_PICKUP'
                && $shipment->pickupRequests->contains(fn ($pickup): bool => in_array($pickup->status, ['PENDING', 'APPROVED'], true)
                    && str_starts_with((string) $pickup->notes, 'SELLER_DROPOFF:')))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
        $sellerDropoffPending = $sellerDropoffShipmentIds !== [];
        $pickupStatus = match (true) {
            $parcelStatuses->isNotEmpty() && $parcelStatuses->every(fn (string $status): bool => in_array($status, ['PICKED_UP', 'AT_SORTING_CENTER', 'SORTED', 'ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY', 'DELIVERED', 'COMPLETED'], true)) => 'Picked up',
            $sellerDropoffPending => 'Waiting for Intake',
            $pickupBatch?->status === 'EN_ROUTE_TO_HUB' => 'In transit to logistics',
            $pickupBatch?->status === 'IN_PROGRESS' => 'Pickup in Progress',
            $pickupAssignment && in_array($pickupAssignment->status, ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'], true) => 'Pickup scheduled',
            $parcelStatuses->contains('PICKUP_ASSIGNED') => 'Pickup scheduled',
            $parcelStatuses->contains('READY_FOR_PICKUP') => 'Waiting for Logistics',
            default => null,
        };

        $selectedPickupCenterNames = $shipments
            ->flatMap(function ($shipment): Collection {
                if (! $shipment->relationLoaded('sellerPickupMemberships')) {
                    return collect();
                }

                return $shipment->sellerPickupMemberships
                    ->filter(fn ($membership): bool => $membership->active_slot === true)
                    ->map(function ($membership): ?string {
                        if (! $membership->relationLoaded('pickupRequest')) {
                            return null;
                        }

                        $pickupRequest = $membership->pickupRequest;
                        if (! $pickupRequest?->relationLoaded('preferredLogisticsCenter')) {
                            return null;
                        }

                        return $pickupRequest->preferredLogisticsCenter?->business_name;
                    });
            })
            ->filter(fn ($name): bool => filled($name))
            ->unique()
            ->values();
        $assignedCenterNames = $shipments
            ->map(fn ($shipment) => $shipment->relationLoaded('logisticsCenter') ? $shipment->logisticsCenter?->business_name : null)
            ->filter(fn ($name): bool => filled($name))
            ->unique()
            ->values();
        $logisticsProviderNames = $selectedPickupCenterNames->isNotEmpty()
            ? $selectedPickupCenterNames
            : $assignedCenterNames;
        $logisticsProvider = $logisticsProviderNames->isNotEmpty()
            ? $logisticsProviderNames->implode(', ')
            : ($pickupStatus === 'Waiting for Logistics' ? 'Not selected' : 'Not assigned');
        $fallbackPickupRequests = $schema->sellerSelectedFallbackHubsReady() && $schema->sellerPickupCenterOffersReady()
            ? $shipments->flatMap(fn ($shipment) => $shipment->sellerPickupMemberships
                ->filter(fn ($membership): bool => $membership->active_slot === true)
                ->map(fn ($membership) => $membership->pickupRequest))
                ->filter(fn ($pickupRequest): bool => (bool) $pickupRequest?->seller_selected_fallback)
                ->unique('id')
            : collect();
        $pickupFallbackAcceptancePending = $fallbackPickupRequests->contains(fn ($pickupRequest): bool => ! in_array(
            $pickupRequest->status,
            ['REQUESTED', 'ASSIGNED', 'ACCEPTED', 'IN_PROGRESS', 'COMPLETED'],
            true,
        ));

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
            : ($shipments->contains(fn ($shipment): bool => $shipment->order_item_id !== null)
                ? $shipments->sum(fn ($shipment): int => $shipment->order_item_id !== null
                    ? (int) \App\Support\OrderItemParcelAmount::forShipment($order, $shipment)['total_cents']
                    : 0)
                : $items->sum(fn ($item): int => FinancialRules::cents(\App\Support\OrderItemParcelAmount::forItem($order, $item)['total'])));
        $paidOnline = $order->order?->payments?->contains(fn ($payment): bool => $payment->method === 'ONLINE' && $payment->status === 'PAID') ?? false;
        $pickupIrreversible = $assignments->contains(fn ($candidate): bool => $candidate->assignment_type === 'PICKUP'
            && in_array($candidate->status, ['IN_PROGRESS', 'COMPLETED'], true));
        $codCollected = $codCollections->contains(fn ($collection): bool => in_array($collection->status, ['COLLECTED', 'REMITTED', 'DISCREPANCY'], true));
        $allParcelsDelivered = $shipments->isNotEmpty()
            && $shipments->every(fn ($shipment): bool => in_array($shipment->current_status, ['DELIVERED', 'COMPLETED'], true));
        $allParcelsReceived = $shipments->isNotEmpty()
            && $shipments->every(fn ($shipment): bool => $shipment->current_status === 'COMPLETED');
        $buyerReceiptPending = $allParcelsDelivered
            && ! $allParcelsReceived
            && $order->status !== 'COMPLETED';
        $orderDisplayStatus = $buyerReceiptPending
            ? 'Delivered, awaiting buyer receipt'
            : ($allParcelsReceived
                ? 'Completed'
                : ($sellerDropoffPending ? 'Waiting for Intake' : str($order->status)->replace('_', ' ')->title()->toString()));

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
            'shipments_by_item' => $shipmentsByItem,
            'parcel_splitting_ready' => Schema::hasColumn('shipments', 'parcel_quantity'),
            'parcel_dimensions_ready' => $parcelDimensionsReady,
            'seller_dropoff_shipment_ids' => $sellerDropoffShipmentIds,
            'ready_parcels' => $parcelRows->filter(fn (array $parcel): bool => $parcel['packed']
                && $parcel['shipment']?->current_status === 'PREPARING')->values(),
            'unpacked_parcels' => $parcelRows->filter(fn (array $parcel): bool => ! $parcel['packed']
                && $parcel['shipment']?->current_status === 'PREPARING')->values(),
            'ready_parcel_count' => $shipments->whereIn('current_status', ['READY_FOR_PICKUP', 'PICKUP_ASSIGNED'])->count(),
            'unresolved_legacy_shipments' => $shipments->whereNull('order_item_id')->values(),
            'has_unresolved_legacy_shipment' => $items->count() > 1
                && $shipments->contains(fn ($shipment): bool => $shipment->order_item_id === null),
            'parcels_need_setup' => $items->contains(fn ($item): bool => ! $shipments->contains(fn ($shipment): bool => (int) $shipment->order_item_id === (int) $item->id))
                || $shipments->contains(fn ($shipment): bool => $shipment->order_item_id === null),
            'can_print_waybills' => $order->canPrintWaybills(),
            'packed' => $shipments->isNotEmpty() && $shipments->every($packedCheckpoint),
            'product_search' => $items->pluck('product_name')->filter()->implode(' '),
            'total' => (float) $order->grand_total,
            'item_subtotal' => (float) $order->item_subtotal,
            'voucher_discount' => (float) $order->voucher_discount,
            'seller_voucher_discount' => (int) ($order->funding_snapshot_version ?? 0) === 1 ? (float) $order->seller_voucher_discount : null,
            'platform_voucher_discount' => (int) ($order->funding_snapshot_version ?? 0) === 1 ? (float) $order->platform_voucher_discount : null,
            'funding_snapshot_version' => (int) ($order->funding_snapshot_version ?? 0),
            'seller_commission' => $order->relationLoaded('settlements') ? (float) $order->settlements->sum('platform_commission') : null,
            'seller_earnings' => $order->relationLoaded('settlements') && $order->settlements->every(fn ($row): bool => $row->seller_payable !== null)
                ? (float) $order->settlements->sum('seller_payable')
                : null,
            'settlement_status' => $order->relationLoaded('settlements') && $order->settlements->isNotEmpty()
                ? $order->settlements->pluck('payout_status')->unique()->implode(', ')
                : null,
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
            'logistics_provider' => $logisticsProvider,
            'has_selected_logistics_center' => $selectedPickupCenterNames->isNotEmpty(),
            'pickup_fallback_acceptance_pending' => $pickupFallbackAcceptancePending,
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
            'status' => $orderDisplayStatus,
            'status_key' => $this->statusKey($order->status),
            'buyer_receipt_pending' => $buyerReceiptPending,
            'delivery' => $shipments->first(),
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
