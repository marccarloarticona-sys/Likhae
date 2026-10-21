<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\SellerSettlement;
use App\Models\Logistics\Shipment;
use App\Models\Seller\Product;
use App\Models\Seller\SellerPickupRequest;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\Voucher;
use App\Models\Buyer\OrderItem;
use App\Services\Fulfillment\ShipmentWorkflowService;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\SellerPickupRequestService;
use App\Services\Finance\FinanceSchemaReadiness;
use App\Services\Maps\MapDataService;
use App\Services\Reports\SellerSalesSummaryService;
use App\Services\Reports\SellerOrderValueChartService;
use App\Services\Reports\SellerReportPdfService;
use App\Services\Reports\WorkspaceExportPdfService;
use App\Services\Seller\SellerDashboardOrderStatus;
use App\Support\InventoryStockStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use RuntimeException;

class SellerOperationsController extends Controller
{
    public function dashboard(
        Request $request,
        SellerSalesSummaryService $salesSummary,
        SellerOrderValueChartService $orderValueChartService,
        SellerDashboardOrderStatus $orderStatus,
    ): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

        $rangeData = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        [$financialFrom, $financialTo] = $salesSummary->reportingRange($rangeData['from'] ?? null, $rangeData['to'] ?? null);
        $financials = $salesSummary->summarize($seller->id, $financialFrom, $financialTo);
        $orderValueChart = $orderValueChartService->build($seller->id, $request->query('period'));

        $orders = SellerOrder::query()->where('seller_profile_id', $seller->id);
        $dashboardOrders = (clone $orders)
            ->withCount($orderStatus->shipmentCountRelations())
            ->get(['id', 'status']);
        $statusCounts = $dashboardOrders->countBy(fn (SellerOrder $order): string => $orderStatus->key($order));
        $statusCount = fn (string $status): int => (int) $statusCounts->get($status, 0);
        $stats = [
            'orders' => $dashboardOrders->count(),
            'placed' => $statusCount('placed'),
            'confirmed' => $statusCount('confirmed'),
            'preparing' => $statusCount('preparing'),
            'ready' => $statusCount('ready-for-pickup'),
            'picked_up' => $statusCount('picked-up'),
            'in_transit' => $statusCount('in-transit'),
            'delivered' => $statusCount('delivered'),
            'completed' => $statusCount('completed'),
            'cancelled' => $statusCount('cancelled'),
            'other' => $statusCount('other'),
            'completed_order_value_all_time' => (float) (clone $orders)->where('status', 'COMPLETED')->sum('grand_total'),
        ];
        $settlementSummary = [
            'schema_ready' => false,
            'pending' => 0.0,
            'authorized' => 0.0,
            'awaiting_ack' => 0.0,
            'paid' => 0.0,
            'outstanding' => 0.0,
            'review_required' => 0,
        ];
        $financeSchema = app(FinanceSchemaReadiness::class);
        if ($financeSchema->step7Ready()) {
            $settlements = SellerSettlement::query()->where('seller_profile_id', $seller->id);
            $settlementSummary = [
                'schema_ready' => true,
                'pending' => (float) (clone $settlements)->whereIn('payout_status', ['PENDING', 'PAYOUT_REQUESTED', 'REVIEW_REQUIRED'])->sum('outstanding_balance'),
                'authorized' => (float) (clone $settlements)->where('payout_status', 'AUTHORIZED')->sum('seller_payable'),
                'awaiting_ack' => (float) (clone $settlements)->where('payout_status', 'PAYOUT_PENDING_ACK')->sum('payout_amount'),
                'paid' => (float) (clone $settlements)->where('payout_status', 'PAID')->sum('payout_amount'),
                'outstanding' => (float) (clone $settlements)->where('payout_status', '!=', 'PAID')->sum('outstanding_balance'),
                'review_required' => (clone $settlements)->where('payout_status', 'REVIEW_REQUIRED')->count(),
            ];
        }

        $chartFrom = today()->subDays(6)->startOfDay();
        $chartTo = today()->endOfDay();
        $dailyFinancials = $salesSummary->daily($seller->id, $chartFrom, $chartTo);
        $dailySales = collect(range(6, 0))->mapWithKeys(function (int $daysAgo) use ($dailyFinancials): array {
            $date = today()->subDays($daysAgo)->toDateString();

            return [$date => (float) data_get($dailyFinancials->get($date), 'gross', 0)];
        });
        $maximumDailySales = max(1, (float) $dailySales->max());
        $salesChart = $dailySales->map(fn (float $value, string $date): array => [
            'label' => \Illuminate\Support\Carbon::parse($date)->format('D'),
            'value' => $value,
            'height' => $value > 0 ? max(8, (int) round(($value / $maximumDailySales) * 100)) : 2,
        ])->values();

        $recentOrders = (clone $orders)
            ->with(['order.buyer', 'items'])
            ->withCount($orderStatus->shipmentCountRelations())
            ->latest()
            ->limit(8)
            ->get();

        $sellerOrders = $recentOrders->map(function (SellerOrder $order) use ($orderStatus): array {
            return [
                'id' => $order->seller_order_number,
                'db_id' => $order->id,
                'buyer' => $order->order?->buyer?->name ?? 'Buyer',
                'product' => $order->items->pluck('product_name')->filter()->join(', '),
                'total' => (float) $order->grand_total,
                'status' => $orderStatus->label($order),
                'status_key' => $orderStatus->key($order),
            ];
        });

        $inventoryAlerts = $seller->products()
            ->whereHas('variants', fn ($query) => $query
                ->where('is_active', true)
                ->where('stock', '<=', InventoryStockStatus::LOW_STOCK_THRESHOLD))
            ->with(['variants' => fn ($query) => $query
                ->where('is_active', true)
                ->where('stock', '<=', InventoryStockStatus::LOW_STOCK_THRESHOLD)
                ->orderBy('stock')])
            ->get(['id', 'name'])
            ->map(fn (Product $product): array => [
                'product_id' => $product->id,
                'name' => $product->name,
                'out_count' => $product->variants->filter(fn ($variant): bool => InventoryStockStatus::isOut((int) $variant->stock))->count(),
                'low_count' => $product->variants->filter(fn ($variant): bool => InventoryStockStatus::isLow((int) $variant->stock))->count(),
            ])
            ->sortByDesc('low_count')
            ->sortByDesc('out_count')
            ->values();

        $lowStockProducts = $inventoryAlerts->where('low_count', '>', 0)->count();
        $outOfStockProducts = $inventoryAlerts->where('out_count', '>', 0)->count();
        $lowStockVariants = (int) $inventoryAlerts->sum('low_count');
        $outOfStockVariants = (int) $inventoryAlerts->sum('out_count');
        $inventoryAlertCount = $inventoryAlerts->count();
        $inventoryAlerts = $inventoryAlerts->take(6)->values();
        $productsCount = $seller->products()->count();
        $visibleProductsCount = $seller->products()
            ->where('status', 'ACTIVE')
            ->whereHas('variants', fn ($query) => $query->where('is_active', true))
            ->count();

        return view('seller.dashboard', [
            'seller' => $request->user(),
            'sellerProfile' => $seller,
            'financialFrom' => $financialFrom,
            'financialTo' => $financialTo,
            'financialSummary' => $financials,
            'dashboardStats' => [
                'orders_today' => (clone $orders)->whereBetween('created_at', [today()->startOfDay(), today()->endOfDay()])->count(),
                'orders' => $stats['orders'],
                'completed_order_value_all_time' => $stats['completed_order_value_all_time'],
                'weekly_revenue' => (float) $dailySales->sum(),
                'products_total' => $productsCount,
                'pending_shipment' => $stats['ready'],
                'inventory_alerts' => $inventoryAlertCount,
            ],
            'orderStatusCounts' => [
                'placed' => $stats['placed'],
                'confirmed' => $stats['confirmed'],
                'preparing' => $stats['preparing'],
                'ready-for-pickup' => $stats['ready'],
                'picked-up' => $stats['picked_up'],
                'in-transit' => $stats['in_transit'],
                'delivered' => $stats['delivered'],
                'completed' => $stats['completed'],
                'cancelled' => $stats['cancelled'],
                'other' => $stats['other'],
                'shipping' => $stats['picked_up'] + $stats['in_transit'],
            ],
            'inventorySummary' => [
                'visible_products' => $visibleProductsCount,
                'all_products' => $productsCount,
                'low_stock_products' => $lowStockProducts,
                'low_stock_variants' => $lowStockVariants,
                'out_of_stock_products' => $outOfStockProducts,
                'out_of_stock_variants' => $outOfStockVariants,
            ],
            'salesChart' => $salesChart,
            'orderValueChart' => $orderValueChart,
            'sellerOrders' => $sellerOrders,
            'inventoryAlerts' => $inventoryAlerts,
            'settlementSummary' => $settlementSummary,
        ]);
    }

    public function logistics(Request $request): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

        if ($request->query('view') === 'tracking') {
            $orderNumber = $request->validate([
                'order' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9-]+$/'],
            ])['order'];
            $trackingOrder = SellerOrder::query()
                ->where('seller_profile_id', $seller->id)
                ->where('seller_order_number', $orderNumber)
                ->with([
                    'order.address',
                    'sellerProfile.businessAddress',
                    'shipments.orderItem',
                    'shipments.events.actor',
                    'shipments.events.logisticsCenter',
                    'shipments.events.riderAssignment.riderProfile.user',
                    'shipments.riderAssignments.riderProfile.user',
                    'shipments.riderAssignments.liveLocation',
                    'shipments.logisticsCenter.address',
                    'shipments.serviceArea.logisticsCenter.address',
                ])
                ->firstOrFail();
            $mapMarkers = app(MapDataService::class)->forShipments($trackingOrder->shipments, includeSeller: true);

            return view('seller.logistics', [
                'seller' => $seller,
                'trackingOrder' => $trackingOrder,
                'mapMarkers' => $mapMarkers,
            ]);
        }

        $pickupRequestSchemaReady = app(LogisticsSchemaReadiness::class)->sellerPickupRequestsReady();

        $orders = SellerOrder::query()
            ->where('seller_profile_id', $seller->id)
            ->with(['order.address', 'items', 'shipments.orderItem', 'shipments.events', 'shipments.pickupRequests', 'shipments.riderAssignments.riderProfile.user'])
            ->whereHas('shipments')
            ->latest()
            ->paginate(15);

        $readyPickupParcels = collect();
        $pickupRequests = collect();
        if ($pickupRequestSchemaReady) {
            $readyPickupParcels = Shipment::query()
                ->where('current_status', 'READY_FOR_PICKUP')
                ->whereHas('sellerOrder', fn ($query) => $query->where('seller_profile_id', $seller->id))
                ->whereHas('waybills')
                ->whereHas('pickupRequests', fn ($query) => $query
                    ->whereIn('status', ['PENDING', 'APPROVED'])
                    ->where(fn ($notes) => $notes->whereNull('notes')->orWhere('notes', 'not like', 'SELLER_DROPOFF:%')))
                ->whereDoesntHave('riderAssignments', fn ($query) => $query
                    ->where('assignment_type', 'PICKUP')->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS', 'COMPLETED']))
                ->whereDoesntHave('sellerPickupMemberships', fn ($query) => $query->where('active_slot', true))
                ->with(['sellerOrder', 'orderItem', 'waybills'])
                ->orderBy('seller_order_id')
                ->orderBy('id')
                ->get();

            $pickupRequests = SellerPickupRequest::query()
                ->where('seller_profile_id', $seller->id)
                ->with([
                    'pickupAddress',
                    'riderLocalTrip.rider.user',
                    'memberships.sellerOrder',
                    'memberships.shipment.waybills',
                ])
                ->latest('id')
                ->limit(20)
                ->get();
            $pickupRequests->each(function (SellerPickupRequest $pickupRequest): void {
                $pickupRequest->setAttribute('progress_payload', app(SellerPickupRequestService::class)
                    ->progress($pickupRequest, (int) $pickupRequest->seller_profile_id));
            });
        }

        return view('seller.logistics', [
            'seller' => $seller,
            'trackingOrder' => null,
            'orders' => $orders,
            'pickupAddress' => $seller->businessAddress,
            'readyPickupParcels' => $readyPickupParcels,
            'pickupRequests' => $pickupRequests,
            'pickupRequestSchemaReady' => $pickupRequestSchemaReady,
        ]);
    }

    public function waybill(Request $request, SellerOrder $sellerOrder, ShipmentWorkflowService $workflow): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerOrder->seller_profile_id === (int) $seller->id, 403);

        try {
            $shipments = $workflow->ensureItemShipments($sellerOrder, $request->user());
        } catch (RuntimeException $exception) {
            $sellerOrder->load(['items', 'shipments.orderItem', 'sellerProfile.user', 'sellerProfile.businessAddress', 'order.address']);
            $legacyShipment = $sellerOrder->shipments->count() === 1 ? $sellerOrder->shipments->first() : null;
            if (! $legacyShipment || $legacyShipment->order_item_id !== null) {
                throw $exception;
            }

            return view('seller.waybill', [
                'sellerOrder' => $sellerOrder,
                'shipment' => $legacyShipment->loadMissing($this->waybillRelations()),
                'item' => null,
                'waybill' => $legacyShipment->waybills()->latest('format_version')->first(),
            ]);
        }
        $sellerOrder->load(['items.shipment', 'sellerProfile.user', 'sellerProfile.businessAddress', 'order.address']);
        $shipments->each(fn ($shipment) => $shipment->loadMissing(['orderItem', ...$this->waybillRelations()]));

        return view('seller.waybills', [
            'sellerOrder' => $sellerOrder,
            'shipments' => $shipments,
        ]);
    }

    public function waybillItem(Request $request, SellerOrder $sellerOrder, OrderItem $orderItem, ShipmentWorkflowService $workflow): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerOrder->seller_profile_id === (int) $seller->id, 403);
        abort_unless((int) $orderItem->seller_order_id === (int) $sellerOrder->id, 404);

        try {
            $shipments = $workflow->ensureItemShipments($sellerOrder, $request->user());
        } catch (RuntimeException $exception) {
            if ($sellerOrder->shipments()->whereNull('order_item_id')->exists()) {
                abort(409, $exception->getMessage());
            }

            throw $exception;
        }
        $shipment = $shipments->firstWhere('order_item_id', $orderItem->id);
        abort_unless($shipment, 404, 'A parcel label has not been linked to this order item.');
        $sellerOrder->load(['items.shipment', 'sellerProfile.user', 'sellerProfile.businessAddress', 'order.address']);
        $shipment->loadMissing(['orderItem', ...$this->waybillRelations()]);

        return view('seller.waybill', [
            'sellerOrder' => $sellerOrder,
            'shipment' => $shipment,
            'item' => $shipment->orderItem,
            'waybill' => $shipment->waybills()->latest('format_version')->first(),
        ]);
    }

    /** @return array<int, string> */
    private function waybillRelations(): array
    {
        $relations = ['waybills', 'events'];
        if (Schema::hasTable('cod_collections')) {
            $relations[] = 'codCollection';
        }

        return $relations;
    }

    public function exportOrders(Request $request, WorkspaceExportPdfService $pdf)
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

        $rows = SellerOrder::query()
            ->where('seller_profile_id', $seller->id)
            ->with('shipments')
            ->orderByDesc('id')
            ->lazyById(500)
            ->map(fn (SellerOrder $order): array => [
                (string) $order->seller_order_number,
                (string) $order->status,
                number_format((float) $order->grand_total, 2, '.', ''),
                $order->shipments->pluck('tracking_number')->filter()->join(' | '),
                (string) optional($order->created_at)->toDateTimeString(),
            ]);
        $filename = 'seller-orders-'.$seller->id.'-'.now()->format('Ymd-His').'.pdf';

        return Response::make($pdf->render([
            'workspace' => 'Seller Center',
            'title' => 'Seller orders',
            'description' => 'Order records for your Seller workspace. Totals are shown in PHP.',
            'generated_at' => now()->format('Y-m-d H:i:s T'),
            'orientation' => 'landscape',
            'columns' => [
                ['label' => 'Seller order', 'width' => 0.22],
                ['label' => 'Status', 'width' => 0.17],
                ['label' => 'Total (PHP)', 'width' => 0.16],
                ['label' => 'Tracking numbers', 'width' => 0.28],
                ['label' => 'Created at', 'width' => 0.17],
            ],
            'rows' => $rows,
            'empty_message' => 'No orders are available for this Seller workspace.',
        ]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function reports(Request $request): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);
        $report = $this->buildReport($request, $seller->id, (string) $seller->business_name, app(SellerSalesSummaryService::class));

        return view('seller.reports', [
            'report' => $report['report'],
            'reportFrom' => $report['from'],
            'reportTo' => $report['to'],
            'reportSummary' => $report['summary'],
            'reportPerformance' => $report['performance_counts'],
            'reportProducts' => $report['products'],
            'reportInsights' => $report['insights'],
            'reportScope' => $report['scope'],
            'sellerProducts' => $report['available_products'],
            'selectedProductId' => $report['selected_product_id'],
        ]);
    }

    public function downloadReport(Request $request, SellerReportPdfService $pdf)
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);
        $report = $this->buildReport($request, $seller->id, (string) $seller->business_name, app(SellerSalesSummaryService::class));
        $filename = 'likhae-'.str($report['title'])->slug('-').'-'.$report['range_start'].'.pdf';

        return Response::make($pdf->render($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function buildReport(Request $request, int $sellerId, string $sellerName, SellerSalesSummaryService $salesSummary): array
    {
        $data = $request->validate([
            'report' => ['nullable', 'string', 'in:sales,profit,orders,products'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'product_id' => ['nullable', 'integer'],
        ]);
        [$from, $to] = $salesSummary->reportingRange($data['from'] ?? null, $data['to'] ?? null);
        $availableProducts = Product::withTrashed()->where('seller_profile_id', $sellerId)->orderBy('name')->get(['id', 'name']);
        $selectedProduct = isset($data['product_id']) ? $availableProducts->firstWhere('id', (int) $data['product_id']) : null;
        abort_if(isset($data['product_id']) && ! $selectedProduct, 404);

        $orders = SellerOrder::query()
            ->where('seller_profile_id', $sellerId)
            ->whereBetween('created_at', [$from, $to])
            ->when($selectedProduct, fn ($query) => $query->whereHas('items', fn ($items) => $items->where('product_id', $selectedProduct->id)));
        $orderActivity = (clone $orders)->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN status = 'CANCELLED' THEN 1 ELSE 0 END) as cancelled")->first();
        $orderCount = (int) ($orderActivity->total ?? 0);
        $completedCount = (int) ($orderActivity->completed ?? 0);
        $cancelledCount = (int) ($orderActivity->cancelled ?? 0);
        $productBreakdown = $salesSummary->productBreakdown($sellerId, $from, $to, $selectedProduct?->id);
        $scopeProducts = $selectedProduct ? collect([$selectedProduct]) : $availableProducts;
        $products = $scopeProducts->map(function (Product $product) use ($productBreakdown): array {
            $financials = $productBreakdown->get((int) $product->id, []);

            return [
                'id' => $product->id,
                'name' => $product->name,
                'units' => (int) data_get($financials, 'units', 0),
                'orders' => (int) data_get($financials, 'orders', 0),
                'gross' => (float) data_get($financials, 'gross', 0),
                'commission' => (float) data_get($financials, 'commission', 0),
                'net' => (float) data_get($financials, 'net', 0),
            ];
        })->sortByDesc('gross')->values();
        $products = $this->classifyProductPerformance($products);
        if ($selectedProduct) {
            $selectedFinancials = $productBreakdown->get((int) $selectedProduct->id, []);
            $selectedGross = (float) data_get($selectedFinancials, 'gross', 0);
            $selectedCommission = (float) data_get($selectedFinancials, 'commission', 0);
            $financialSummary = [
                'gross' => $selectedGross,
                'commission' => $selectedCommission,
                'net' => $selectedGross - $selectedCommission,
                'orders' => (int) data_get($selectedFinancials, 'orders', 0),
            ];
        } else {
            $financialSummary = $salesSummary->summarize($sellerId, $from, $to);
        }
        $gross = $financialSummary['gross'];
        $commission = $financialSummary['commission'];
        $financeSchemaReady = app(FinanceSchemaReadiness::class)->step7Ready();
        $settlementSummary = [
            'finance_schema_ready' => $financeSchemaReady,
            'original_product_amount' => 0.0,
            'discounts' => 0.0,
            'seller_payable' => 0.0,
            'settled_payouts' => 0.0,
            'pending_balance' => 0.0,
            'authorized_balance' => 0.0,
            'paid_balance' => 0.0,
            'outstanding_balance' => 0.0,
            'pending_settlement' => 0.0,
            'review_required_count' => 0,
            'seller_refund_adjustment' => 0.0,
            'commission_refund_reversal' => 0.0,
        ];
        if ($financeSchemaReady) {
            $recognizedOrderIds = fn () => $salesSummary->recognizedOrderIdsQuery($sellerId, $from, $to, $selectedProduct?->id);
            $settlements = SellerSettlement::query()
                ->where('seller_profile_id', $sellerId)
                ->whereIn('seller_order_id', $recognizedOrderIds());
            $settlementTotals = (clone $settlements)->selectRaw(<<<'SQL'
                COALESCE(SUM(original_product_amount), 0) as original_product_amount,
                COALESCE(SUM(discount_amount), 0) as discounts,
                COALESCE(SUM(seller_payable), 0) as seller_payable,
                COALESCE(SUM(CASE WHEN payout_status = 'PAID' THEN payout_amount ELSE 0 END), 0) as settled_payouts,
                COALESCE(SUM(CASE WHEN payout_status IN ('PENDING', 'PAYOUT_REQUESTED', 'REVIEW_REQUIRED') THEN outstanding_balance ELSE 0 END), 0) as pending_balance,
                COALESCE(SUM(CASE WHEN payout_status = 'AUTHORIZED' THEN seller_payable ELSE 0 END), 0) as authorized_balance,
                COALESCE(SUM(CASE WHEN payout_status = 'PAYOUT_PENDING_ACK' THEN payout_amount ELSE 0 END), 0) as awaiting_ack_balance,
                COALESCE(SUM(CASE WHEN payout_status = 'PAID' THEN payout_amount ELSE 0 END), 0) as paid_balance,
                COALESCE(SUM(CASE WHEN payout_status != 'PAID' THEN outstanding_balance ELSE 0 END), 0) as outstanding_balance,
                SUM(CASE WHEN payout_status IN ('PENDING', 'PAYOUT_REQUESTED', 'AUTHORIZED', 'REVIEW_REQUIRED', 'PAYOUT_PENDING_ACK') THEN 1 ELSE 0 END) as pending_settlement,
                SUM(CASE WHEN payout_status = 'REVIEW_REQUIRED' THEN 1 ELSE 0 END) as review_required_count
                SQL)->first();
            $settlementSummary = [
                'finance_schema_ready' => true,
                'original_product_amount' => (float) ($settlementTotals->original_product_amount ?? 0),
                'discounts' => (float) ($settlementTotals->discounts ?? 0),
                'seller_payable' => (float) ($settlementTotals->seller_payable ?? 0),
                'settled_payouts' => (float) ($settlementTotals->settled_payouts ?? 0),
                'pending_balance' => (float) ($settlementTotals->pending_balance ?? 0),
                'authorized_balance' => (float) ($settlementTotals->authorized_balance ?? 0),
                'awaiting_ack_balance' => (float) ($settlementTotals->awaiting_ack_balance ?? 0),
                'paid_balance' => (float) ($settlementTotals->paid_balance ?? 0),
                'outstanding_balance' => (float) ($settlementTotals->outstanding_balance ?? 0),
                'pending_settlement' => (int) ($settlementTotals->pending_settlement ?? 0),
                'review_required_count' => (int) ($settlementTotals->review_required_count ?? 0),
                'seller_refund_adjustment' => 0.0,
                'commission_refund_reversal' => 0.0,
            ];

            if (Schema::hasTable('financial_ledger_entries')) {
                $refundTotals = FinancialLedgerEntry::query()
                    ->whereIn('seller_order_id', $recognizedOrderIds())
                    ->whereIn('entry_type', ['REFUND_SELLER_REVERSAL', 'REFUND_COMMISSION_REVERSAL'])
                    ->where('direction', 'DEBIT')
                    ->where('status', 'POSTED')
                    ->whereBetween('posted_at', [$from, $to])
                    ->selectRaw('entry_type, COALESCE(SUM(amount), 0) as amount')
                    ->groupBy('entry_type')
                    ->pluck('amount', 'entry_type');
                $settlementSummary['seller_refund_adjustment'] = (float) $refundTotals->get('REFUND_SELLER_REVERSAL', 0);
                $settlementSummary['commission_refund_reversal'] = (float) $refundTotals->get('REFUND_COMMISSION_REVERSAL', 0);
            }
        }
        $performance = [
            'high' => $products->where('tier', 'high')->count(),
            'mid' => $products->where('tier', 'mid')->count(),
            'low' => $products->where('tier', 'low')->count(),
            'no_sales' => $products->where('tier', 'no_sales')->count(),
        ];
        $summary = [
            'orders' => $orderCount,
            'completed' => $completedCount,
            'cancelled' => $cancelledCount,
            'financial_orders' => $financialSummary['orders'],
            'gross' => $gross,
            'commission' => $commission,
            'net' => $financialSummary['net'],
            'completion_rate' => $orderCount === 0 ? 0 : (int) round(($completedCount / $orderCount) * 100),
            'cancellation_rate' => $orderCount === 0 ? 0 : (int) round(($cancelledCount / $orderCount) * 100),
            'average_order' => $financialSummary['orders'] === 0 ? 0 : $gross / $financialSummary['orders'],
            ...$settlementSummary,
        ];
        $scope = $selectedProduct ? 'Single product: '.$selectedProduct->name : 'All catalog products';

        return [
            'report' => $data['report'] ?? 'sales',
            'title' => str($data['report'] ?? 'sales')->headline().' Report',
            'seller_name' => $sellerName,
            'scope' => $scope,
            'from' => $from,
            'to' => $to,
            'range_start' => $from->toDateString(),
            'range_label' => $from->format('M d, Y').' - '.$to->format('M d, Y').' ('.config('app.timezone', 'UTC').')',
            'timezone' => (string) config('app.timezone', 'UTC'),
            'generated_at' => now()->format('M d, Y g:i A'),
            'summary' => $summary,
            'performance_counts' => $performance,
            'products' => $products->all(),
            'insights' => $this->reportInsights($products, $summary, $scope),
            'available_products' => $availableProducts,
            'selected_product_id' => $selectedProduct?->id,
        ];
    }

    private function classifyProductPerformance($products)
    {
        $sellingCount = $products->filter(fn (array $product): bool => $product['gross'] > 0)->count();
        $topCount = max(1, (int) ceil($sellingCount * 0.25));
        $bottomCount = max(1, (int) ceil($sellingCount * 0.25));
        $rank = 0;

        return $products->map(function (array $product) use (&$rank, $sellingCount, $topCount, $bottomCount): array {
            if ($product['gross'] <= 0) {
                $product['tier'] = 'no_sales';
                return $product;
            }
            ++$rank;
            $product['tier'] = $rank <= $topCount ? 'high' : ($rank > $sellingCount - $bottomCount ? 'low' : 'mid');
            return $product;
        });
    }

    private function reportInsights($products, array $summary, string $scope): array
    {
        if ($products->isEmpty()) {
            return ['No products are in this report scope. Add catalog products to start measuring performance.'];
        }
        $insights = [];
        if ($top = $products->firstWhere('tier', 'high')) {
            $insights[] = $top['name'].' is the strongest performer with PHP '.number_format($top['gross'], 2).' in recognized gross sales from '.$top['units'].' units.';
        }
        if ($products->where('tier', 'no_sales')->isNotEmpty()) {
            $insights[] = $products->where('tier', 'no_sales')->count().' product(s) had no completed sales. Review listing quality, stock, price, or promotion.';
        } elseif ($low = $products->firstWhere('tier', 'low')) {
            $insights[] = $low['name'].' is in the low-sales group. Consider a bundle, promotion, or product-page refresh.';
        }
        $insights[] = $summary['completion_rate'].'% of orders in '.$scope.' were completed; '.$summary['cancellation_rate'].'% were cancelled.';
        return $insights;
    }
}
