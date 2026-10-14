<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\Seller\SellerOrder;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\ShipmentWorkflowService;
use App\Services\Maps\MapDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SellerOrderController extends Controller
{
    public function index(Request $request, LogisticsSchemaReadiness $schema): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

        $orderRelations = ['order.buyer', 'order.address', 'order.payments', 'items.product.images', 'shipment.events', 'shipment.pickupRequests', 'shipment.riderAssignments.riderProfile.user', 'shipment.riderAssignments.liveLocation', 'shipment.logisticsCenter.address', 'shipment.sellerOrder.sellerProfile.businessAddress'];
        $pickupBatchSchemaReady = $schema->sellerPickupBatchesReady();
        if ($pickupBatchSchemaReady) {
            $orderRelations = [...$orderRelations,
                'shipment.riderAssignments.localTripItems.trip.seller',
                'shipment.riderAssignments.localTripItems.trip.logisticsCenter.address',
                'shipment.riderAssignments.localTripItems.trip.items',
            ];
        }
        $orders = SellerOrder::query()
            ->where('seller_profile_id', $seller->id)
            ->with($orderRelations)
            ->latest()
            ->get();

        $requestedStatus = strtolower((string) $request->query('status', 'all'));
        $refundSearch = trim((string) $request->query('q', ''));
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

        $counts = SellerOrder::query()
            ->where('seller_profile_id', $seller->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $readyPickupCount = $orders->filter(fn (SellerOrder $order): bool =>
            $order->status === 'READY_FOR_PICKUP' && $order->shipment?->current_status === 'READY_FOR_PICKUP')->count();

        return view('Seller.orders', [
            'seller' => $seller,
            'orders' => $orders,
            'counts' => $counts,
            'activeStatus' => strtoupper((string) $request->query('status', '')),
            'status' => $requestedStatus,
            'sellerOrders' => $orders->map(fn (SellerOrder $order): array => $this->viewOrder($order, $pickupBatchSchemaReady)),
            'refundRequests' => $refundRequests,
            'refundCounts' => $refundCounts,
            'refundStatus' => $refundStatus,
            'statusCounts' => $orders
                ->groupBy(fn (SellerOrder $order): string => $this->statusKey($order->status))
                ->map->count()
                ->put('returns', (int) $refundCounts->sum()),
            'readyPickupCount' => $readyPickupCount,
        ]);
    }

    public function transition(Request $request, SellerOrder $sellerOrder, ShipmentWorkflowService $workflow): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerOrder->seller_profile_id === (int) $seller->id, 403);

        $data = $request->validate([
            'action' => ['required', 'string', 'in:confirm,prepare,ready,handover_confirm,cancel'],
        ]);

        $workflow->sellerTransition($sellerOrder, $data['action'], $request->user());

        return back()->with('status', 'Seller order updated successfully.');
    }

    public function handover(Request $request, SellerOrder $sellerOrder, ShipmentWorkflowService $workflow): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerOrder->seller_profile_id === (int) $seller->id, 403);

        $data = $request->validate([
            'handover_method' => ['required', 'string', 'in:logistics_pickup,seller_dropoff'],
            'pickup_date' => ['nullable', 'date', 'after_or_equal:today'],
            'pickup_window' => ['nullable', 'string', 'max:80'],
            'pickup_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $workflow->requestSellerHandover(
            $sellerOrder,
            $request->user(),
            $data['handover_method'] === 'seller_dropoff',
            collect([$data['pickup_note'] ?? null, $data['pickup_date'] ?? null, $data['pickup_window'] ?? null])->filter()->implode(' · '),
        );

        return redirect()->route('seller.orders')->with('status', $data['handover_method'] === 'seller_dropoff'
            ? 'Drop-off registered. Bring the parcel and tracking number to your assigned logistics center.'
            : 'Logistics pickup requested.');
    }

    private function viewOrder(SellerOrder $order, bool $pickupBatchSchemaReady): array
    {
        $address = $order->order?->address;
        $items = $order->items;
        $assignment = $order->shipment?->riderAssignments
            ?->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])
            ->sortByDesc('id')
            ->first();
        $pickupAssignment = $order->shipment?->riderAssignments
            ?->where('assignment_type', 'PICKUP')
            ->sortByDesc('id')
            ->first();
        $pickupBatch = $pickupBatchSchemaReady
            ? $pickupAssignment?->localTripItems?->sortByDesc('id')->first()?->trip
            : null;
        $pickupStatus = match (true) {
            $order->shipment?->current_status === 'PICKED_UP' => 'Picked up',
            $pickupBatch?->status === 'EN_ROUTE_TO_HUB' => 'In transit to logistics',
            $pickupAssignment && in_array($pickupAssignment->status, ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'], true) => 'Pickup scheduled',
            $order->shipment?->current_status === 'READY_FOR_PICKUP' => 'Waiting for Logistics',
            default => null,
        };

        return [
            'id' => $order->seller_order_number,
            'db_id' => $order->id,
            'date' => $order->created_at?->format('M d, Y g:i A'),
            'buyer_id' => $order->order?->buyer_user_id,
            'buyer' => $order->order?->buyer?->name ?? 'Buyer',
            'buyer_note' => $order->buyer_note,
            'product' => $items->pluck('product_name')->filter()->join(', '),
            'first_item' => $items->first(),
            'variant' => $items->pluck('variant_description')->filter()->join(', ') ?: 'Standard',
            'quantity' => (int) $items->sum('quantity'),
            'total' => (float) $order->grand_total,
            'payment' => str($order->order?->payments?->first()?->method ?? 'PENDING')->headline()->toString(),
            'logistics_provider' => $order->shipment?->logisticsCenter?->business_name ?? 'Not assigned',
            'shipping' => $assignment?->riderProfile?->user?->name ?? $pickupAssignment?->riderProfile?->user?->name ?? 'Waiting for Logistics',
            'pickup_status' => $pickupStatus,
            'pickup_batch' => $pickupBatch ? 'P-'.$pickupBatch->id : null,
            'pickup_batch_size' => $pickupBatch?->items?->count() ?? ($pickupAssignment ? 1 : null),
            'pickup_rider' => $pickupAssignment?->riderProfile?->user?->name,
            'pickup_assigned_at' => $pickupAssignment?->assigned_at,
            'pickup_collected_at' => $pickupAssignment?->completed_at,
            'pickup_hub' => $pickupBatch?->logisticsCenter?->business_name ?? $order->shipment?->logisticsCenter?->business_name,
            'shipping_address' => $address?->formatted() ?? 'Address unavailable',
            'status' => str($order->status)->replace('_', ' ')->title()->toString(),
            'status_key' => $this->statusKey($order->status),
            'delivery' => $order->shipment,
            'map_markers' => app(MapDataService::class)->forShipment($order->shipment, true, true),
        ];
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
