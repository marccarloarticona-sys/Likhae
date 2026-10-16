<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Admin\CommissionTransaction;
use App\Models\Finance\SellerSettlement;
use App\Models\Seller\Product;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\Voucher;
use App\Models\Buyer\OrderItem;
use App\Services\Fulfillment\ShipmentWorkflowService;
use App\Services\Finance\FinanceSchemaReadiness;
use App\Services\Reports\SellerReportPdfService;
use App\Support\InventoryStockStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use RuntimeException;

class SellerOperationsController extends Controller
{
    public function dashboard(Request $request): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

        $orders = SellerOrder::query()->where('seller_profile_id', $seller->id);

        $stats = [
            'orders' => (clone $orders)->count(),
            'placed' => (clone $orders)->where('status', 'PLACED')->count(),
            'ready' => (clone $orders)->where('status', 'READY_FOR_PICKUP')->count(),
            'completed' => (clone $orders)->where('status', 'COMPLETED')->count(),
            'sales' => (float) (clone $orders)->where('status', 'COMPLETED')->sum('grand_total'),
        ];
        $settlementSummary = [
            'schema_ready' => false,
            'pending' => 0.0,
            'authorized' => 0.0,
            'paid' => 0.0,
            'outstanding' => 0.0,
            'review_required' => 0,
        ];
        $financeSchema = app(FinanceSchemaReadiness::class);
        if ($financeSchema->step7Ready()) {
            $settlements = SellerSettlement::query()->where('seller_profile_id', $seller->id);
            $settlementSummary = [
                'schema_ready' => true,
                'pending' => (float) (clone $settlements)->whereIn('payout_status', ['PENDING', 'REVIEW_REQUIRED'])->sum('outstanding_balance'),
                'authorized' => (float) (clone $settlements)->where('payout_status', 'AUTHORIZED')->sum('seller_payable'),
                'paid' => (float) (clone $settlements)->where('payout_status', 'PAID')->sum('payout_amount'),
                'outstanding' => (float) (clone $settlements)->where('payout_status', '!=', 'PAID')->sum('outstanding_balance'),
                'review_required' => (clone $settlements)->where('payout_status', 'REVIEW_REQUIRED')->count(),
            ];
        }

        $recentOrders = (clone $orders)
            ->with(['order.buyer', 'items', 'shipment'])
            ->latest()
            ->limit(8)
            ->get();

        $completedSales = (clone $orders)
            ->where('status', 'COMPLETED')
            ->where('updated_at', '>=', today()->subDays(6)->startOfDay())
            ->get(['grand_total', 'updated_at']);

        $dailySales = collect(range(6, 0))->mapWithKeys(function (int $daysAgo) use ($completedSales): array {
            $date = today()->subDays($daysAgo);

            return [$date->toDateString() => (float) $completedSales
                ->filter(fn (SellerOrder $order): bool => $order->updated_at->isSameDay($date))
                ->sum('grand_total')];
        });
        $maximumDailySales = max(1, (float) $dailySales->max());
        $salesChart = $dailySales->map(fn (float $value, string $date): array => [
            'label' => \Illuminate\Support\Carbon::parse($date)->format('D'),
            'value' => $value,
            'height' => $value > 0 ? max(8, (int) round(($value / $maximumDailySales) * 100)) : 2,
        ])->values();

        $sellerOrders = $recentOrders->map(function (SellerOrder $order): array {
            $statusKey = match ($order->status) {
                'PLACED' => 'placed',
                'CONFIRMED' => 'confirmed',
                'PREPARING' => 'preparing',
                'READY_FOR_PICKUP' => 'ready-for-pickup',
                'PICKED_UP', 'SHIPPED' => 'shipping',
                'COMPLETED' => 'completed',
                'CANCELLED' => 'cancelled',
                default => strtolower(str_replace('_', '-', $order->status)),
            };

            return [
                'id' => $order->seller_order_number,
                'db_id' => $order->id,
                'buyer' => $order->order?->buyer?->name ?? 'Buyer',
                'product' => $order->items->pluck('product_name')->filter()->join(', '),
                'total' => (float) $order->grand_total,
                'status' => str($order->status)->replace('_', ' ')->title()->toString(),
                'status_key' => $statusKey,
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
                'name' => $product->name,
                'out_count' => $product->variants->filter(fn ($variant): bool => InventoryStockStatus::isOut((int) $variant->stock))->count(),
                'low_count' => $product->variants->filter(fn ($variant): bool => InventoryStockStatus::isLow((int) $variant->stock))->count(),
            ])
            ->sortByDesc('low_count')
            ->sortByDesc('out_count')
            ->take(6)
            ->values();

        return view('Seller.dashboard', [
            'seller' => $request->user(),
            'sellerProfile' => $seller,
            'dashboardStats' => [
                'today_sales' => (float) (clone $orders)->whereDate('created_at', today())->sum('grand_total'),
                'orders' => $stats['orders'],
                'revenue' => $stats['sales'],
                'weekly_revenue' => (float) $dailySales->sum(),
                'products_sold' => $seller->products()->count(),
                'pending_shipment' => $stats['ready'],
                'inventory_alerts' => $seller->products()->whereHas('variants', fn ($query) => $query
                    ->where('is_active', true)
                    ->where('stock', '<=', InventoryStockStatus::LOW_STOCK_THRESHOLD))->count(),
            ],
            'orderStatusCounts' => [
                'placed' => $stats['placed'],
                'confirmed' => (clone $orders)->where('status', 'CONFIRMED')->count(),
                'preparing' => (clone $orders)->where('status', 'PREPARING')->count(),
                'ready-for-pickup' => $stats['ready'],
                'shipping' => (clone $orders)->whereIn('status', ['PICKED_UP', 'SHIPPED'])->count(),
            ],
            'salesChart' => $salesChart,
            'sellerOrders' => $sellerOrders,
            'inventoryAlerts' => $inventoryAlerts,
            'settlementSummary' => $settlementSummary,
        ]);
    }

    public function logistics(Request $request): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

        $orders = SellerOrder::query()
            ->where('seller_profile_id', $seller->id)
            ->with(['order.address', 'items', 'shipments.orderItem', 'shipments.events', 'shipments.pickupRequests', 'shipments.riderAssignments.riderProfile.user'])
            ->whereHas('shipments')
            ->latest()
            ->paginate(15);

        return view('Seller.logistics', [
            'seller' => $seller,
            'orders' => $orders,
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

            return view('Seller.waybill', [
                'sellerOrder' => $sellerOrder,
                'shipment' => $legacyShipment->loadMissing($this->waybillRelations()),
                'item' => null,
                'waybill' => $legacyShipment->waybills()->latest('format_version')->first(),
            ]);
        }
        $sellerOrder->load(['items.shipment', 'sellerProfile.user', 'sellerProfile.businessAddress', 'order.address']);
        $shipments->each(fn ($shipment) => $shipment->loadMissing(['orderItem', ...$this->waybillRelations()]));

        return view('Seller.waybills', [
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

        return view('Seller.waybill', [
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

    public function exportOrders(Request $request)
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

        $rows = SellerOrder::query()
            ->where('seller_profile_id', $seller->id)
            ->with('shipments')
            ->latest()
            ->get()
            ->map(fn (SellerOrder $order): array => [
                $order->seller_order_number,
                $order->status,
                number_format((float) $order->grand_total, 2, '.', ''),
                $order->shipments->pluck('tracking_number')->join('|'),
                optional($order->created_at)->toDateTimeString(),
            ]);

        $csv = "seller_order,status,total,tracking,created_at\n";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn ($value) => '"'.str_replace('"', '""', (string) $value).'"', $row))."\n";
        }

        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="seller-orders.csv"',
        ]);
    }

    public function reports(Request $request): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);
        $report = $this->buildReport($request, $seller->id);

        return view('Seller.reports', [
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
        $report = $this->buildReport($request, $seller->id);
        $filename = 'likhae-'.str($report['title'])->slug('-').'-'.$report['range_start'].'.pdf';

        return Response::make($pdf->render($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function buildReport(Request $request, int $sellerId): array
    {
        $data = $request->validate([
            'report' => ['nullable', 'string', 'in:sales,profit,orders,products'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'product_id' => ['nullable', 'integer'],
        ]);
        $from = Carbon::parse($data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($data['to'] ?? now())->endOfDay();
        $availableProducts = Product::query()->where('seller_profile_id', $sellerId)->orderBy('name')->get(['id', 'name']);
        $selectedProduct = isset($data['product_id']) ? $availableProducts->firstWhere('id', (int) $data['product_id']) : null;
        abort_if(isset($data['product_id']) && ! $selectedProduct, 404);

        $orders = SellerOrder::query()
            ->where('seller_profile_id', $sellerId)
            ->whereBetween('created_at', [$from, $to])
            ->when($selectedProduct, fn ($query) => $query->whereHas('items', fn ($items) => $items->where('product_id', $selectedProduct->id)))
            ->with('items')
            ->latest()
            ->get();
        $completedOrders = $orders->where('status', 'COMPLETED');
        $completedItems = $completedOrders->flatMap(function (SellerOrder $order) use ($selectedProduct) {
            return $order->items->when($selectedProduct, fn ($items) => $items->where('product_id', $selectedProduct->id));
        });
        $scopeProducts = $selectedProduct ? collect([$selectedProduct]) : $availableProducts;
        $products = $scopeProducts->map(function (Product $product) use ($completedItems): array {
            $items = $completedItems->where('product_id', $product->id);

            return [
                'id' => $product->id,
                'name' => $product->name,
                'units' => (int) $items->sum('quantity'),
                'orders' => $items->pluck('seller_order_id')->unique()->count(),
                'revenue' => (float) $items->sum('line_total'),
            ];
        })->sortByDesc('revenue')->values();
        $products = $this->classifyProductPerformance($products);
        $gross = (float) $orders->sum('grand_total');
        $commission = (float) CommissionTransaction::query()
            ->whereHas('sellerOrder', function ($query) use ($sellerId, $selectedProduct): void {
                $query->where('seller_profile_id', $sellerId)
                    ->when($selectedProduct, fn ($orders) => $orders->whereHas('items', fn ($items) => $items->where('product_id', $selectedProduct->id)));
            })
            ->whereBetween('created_at', [$from, $to])
            ->sum('commission_amount');
        $financeSchemaReady = app(FinanceSchemaReadiness::class)->step7Ready();
        $settlementSummary = [
            'finance_schema_ready' => $financeSchemaReady,
            'original_product_amount' => 0.0,
            'discounts' => 0.0,
            'seller_payable' => 0.0,
            'settled_payouts' => 0.0,
            'outstanding_balance' => 0.0,
            'pending_settlement' => 0.0,
        ];
        if ($financeSchemaReady) {
            $settlements = SellerSettlement::query()->where('seller_profile_id', $sellerId)->whereBetween('created_at', [$from, $to]);
            $settlementSummary = [
                'original_product_amount' => (float) (clone $settlements)->sum('original_product_amount'),
                'discounts' => (float) (clone $settlements)->sum('discount_amount'),
                'seller_payable' => (float) (clone $settlements)->whereNotNull('seller_payable')->sum('seller_payable'),
                'settled_payouts' => (float) (clone $settlements)->where('payout_status', 'PAID')->sum('payout_amount'),
                'outstanding_balance' => (float) (clone $settlements)->sum('outstanding_balance'),
                'pending_settlement' => (float) (clone $settlements)->whereIn('payout_status', ['PENDING', 'AUTHORIZED', 'REVIEW_REQUIRED'])->count(),
            ];
        }
        $performance = [
            'high' => $products->where('tier', 'high')->count(),
            'mid' => $products->where('tier', 'mid')->count(),
            'low' => $products->where('tier', 'low')->count(),
            'no_sales' => $products->where('tier', 'no_sales')->count(),
        ];
        $summary = [
            'orders' => $orders->count(),
            'completed' => $completedOrders->count(),
            'cancelled' => $orders->where('status', 'CANCELLED')->count(),
            'gross' => $gross,
            'commission' => $commission,
            'net' => $gross - $commission,
            'completion_rate' => $orders->isEmpty() ? 0 : (int) round(($completedOrders->count() / $orders->count()) * 100),
            'cancellation_rate' => $orders->isEmpty() ? 0 : (int) round(($orders->where('status', 'CANCELLED')->count() / $orders->count()) * 100),
            'average_order' => $orders->isEmpty() ? 0 : $gross / $orders->count(),
            ...$settlementSummary,
        ];
        $scope = $selectedProduct ? 'Single product: '.$selectedProduct->name : 'All catalog products';

        return [
            'report' => $data['report'] ?? 'sales',
            'title' => str($data['report'] ?? 'sales')->headline().' Report',
            'scope' => $scope,
            'from' => $from,
            'to' => $to,
            'range_start' => $from->toDateString(),
            'range_label' => $from->format('M d, Y').' - '.$to->format('M d, Y'),
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
        $sellingCount = $products->filter(fn (array $product): bool => $product['revenue'] > 0)->count();
        $topCount = max(1, (int) ceil($sellingCount * 0.25));
        $bottomCount = max(1, (int) ceil($sellingCount * 0.25));
        $rank = 0;

        return $products->map(function (array $product) use (&$rank, $sellingCount, $topCount, $bottomCount): array {
            if ($product['revenue'] <= 0) {
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
            $insights[] = $top['name'].' is the strongest performer with PHP '.number_format($top['revenue'], 2).' from '.$top['units'].' completed units.';
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
