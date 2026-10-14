<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\SellerProductSuspended;
use App\Mail\SellerProductWarning;
use App\Models\Admin\Announcement;
use App\Models\Admin\AuditLog;
use App\Models\Admin\CommissionTransaction;
use App\Models\Admin\Dispute;
use App\Models\Admin\Notification;
use App\Models\Admin\PlatformPolicy;
use App\Models\Admin\PlatformSetting;
use App\Models\Admin\SellerComplianceAction;
use App\Models\Admin\SellerComplianceCase;
use App\Models\Admin\SellerComplianceResponse;
use App\Models\Buyer\Payment;
use App\Models\Communication\Conversation;
use App\Models\Finance\CodCollection;
use App\Models\Finance\RiderRemittance;
use App\Models\Finance\SellerSettlement;
use App\Models\Finance\ShipmentFinancialAllocation;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\Seller\RefundPayout;
use App\Models\Buyer\ReturnRefundEvidence;
use App\Services\ReturnRefundWorkflowService;
use App\Models\User;
use App\Services\Communication\ConversationService;
use App\Services\Marketplace\ProductCatalogService;
use App\Services\Reports\AdminReportPdfService;
use App\Services\RefundProofStorageService;
use App\Services\Finance\FinanceSchemaReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Illuminate\Database\Query\Builder as QueryBuilder;

class AdminOperationsController extends Controller
{
    private const PRODUCT_STATUSES = ['ACTIVE', 'SUSPENDED'];

    private const CATEGORY_ARCHIVE_SUPPORTED = true;

    private const PRODUCT_WARNING_CATEGORIES = [
        'INCORRECT_CATEGORY' => 'Incorrect category',
        'MISLEADING_DESCRIPTION' => 'Misleading product description',
        'INAPPROPRIATE_IMAGES' => 'Inappropriate product images',
        'MISLEADING_DETAILS' => 'Incorrect or misleading product details',
        'SUSPECTED_PROHIBITED_PRODUCT' => 'Suspected prohibited product',
        'REPEATED_LISTING_VIOLATIONS' => 'Repeated listing violations',
        'OTHER' => 'Other',
    ];

    private const UNRESOLVED_COMPLIANCE_STATUSES = ['OPEN', 'UNDER_REVIEW'];

    public function products(Request $request): View
    {
        $status = $this->productStatus($request->query('status'));
        $search = $this->productSearch($request->query('search'));
        $products = $this->productQuery($status, $search)
            ->paginate(20)
            ->withQueryString();
        $products->getCollection()->transform(function (Product $product): Product {
            $product->setAttribute('admin_category_mismatch', $this->productCategoryMismatch($product));

            return $product;
        });

        return view('Admin.products.index', [
            'products' => $products,
            'productStats' => $this->productStats(),
            'status' => $status,
            'search' => $search,
        ]);
    }

    public function productDetails(Request $request, Product $product, ProductCatalogService $catalog): View
    {
        $product->load([
            'sellerProfile.user',
            'sellerProfile.primaryCategory.parent',
            'sellerProfile.primaryCategory.children',
            'category.parent',
            'images.optionValue.option',
            'variants.optionValues.option',
            'variants.productImage',
            'complianceCases.openedByAdmin',
            'complianceCases.actions.performedBy',
        ]);
        $product->setAttribute('admin_category_mismatch', $this->productCategoryMismatch($product));

        $imageGallery = $product->images
            ->map(function ($image) use ($catalog, $product): array {
                return [
                    'url' => $catalog->publicUrl($image->file_path) ?: asset('images/product-placeholder.svg'),
                    'alt' => $image->alt_text ?: $product->name,
                    'is_primary' => (bool) $image->is_primary,
                ];
            })
            ->values();

        return view('Admin.products.details', [
            'product' => $product,
            'imageGallery' => $imageGallery,
            'backQuery' => $request->only(['status', 'search', 'page']),
            'warningCategories' => self::PRODUCT_WARNING_CATEGORIES,
        ]);
    }

    public function compliance(Request $request): View
    {
        $query = $this->complianceCasesQuery($request);
        $cases = (clone $query)->paginate(20)->withQueryString();
        $stats = [
            'open' => (int) SellerComplianceCase::query()->where('status', 'OPEN')->count(),
            'under_review' => (int) SellerComplianceCase::query()->where('status', 'UNDER_REVIEW')->count(),
            'resolved' => (int) SellerComplianceCase::query()->whereIn('status', ['RESOLVED', 'CLOSED'])->count(),
            'suspended_products' => (int) SellerComplianceCase::query()
                ->whereNotNull('product_id')
                ->whereHas('product', fn ($productQuery) => $productQuery->where('status', 'SUSPENDED'))
                ->distinct()
                ->count('product_id'),
        ];

        return view('Admin.compliance.index', [
            'cases' => $cases,
            'stats' => $stats,
            'search' => $this->complianceSearch($request->query('search')),
            'status' => $this->complianceStatus($request->query('status')),
        ]);
    }

    public function showComplianceCase(SellerComplianceCase $case): View
    {
        $case->load([
            'sellerProfile.user',
            'sellerProfile.primaryCategory.parent',
            'product.category.parent',
            'product.images',
            'product.variants',
            'openedByAdmin',
            'actions.performedBy',
            'responses.sellerProfile.user',
        ]);
        $previousCaseCount = SellerComplianceCase::query()
            ->where('seller_profile_id', $case->seller_profile_id)
            ->whereKeyNot($case->id)
            ->count();
        $previousCases = SellerComplianceCase::query()
            ->where('seller_profile_id', $case->seller_profile_id)
            ->whereKeyNot($case->id)
            ->latest('opened_at')
            ->limit(5)
            ->get(['id', 'case_number', 'violation_type', 'status', 'opened_at']);

        return view('Admin.compliance.show', [
            'case' => $case,
            'previousCaseCount' => $previousCaseCount,
            'previousCases' => $previousCases,
        ]);
    }

    public function downloadComplianceResponseEvidence(SellerComplianceCase $case, SellerComplianceResponse $response)
    {
        abort_unless((int) $response->seller_compliance_case_id === (int) $case->id, 404);
        abort_unless($response->evidence_path && Storage::disk('local')->exists($response->evidence_path), 404);

        return Storage::disk('local')->download(
            $response->evidence_path,
            $response->evidence_original_name ?: 'compliance-evidence',
            array_filter(['Content-Type' => $response->evidence_mime]),
        );
    }

    public function exportCompliance(Request $request, AdminReportPdfService $pdf)
    {
        $cases = $this->complianceCasesQuery($request)->get();
        $summary = [
            ['label' => 'Matching cases', 'value' => number_format($cases->count())],
            ['label' => 'Open', 'value' => number_format($cases->where('status', 'OPEN')->count())],
            ['label' => 'Under review', 'value' => number_format($cases->where('status', 'UNDER_REVIEW')->count())],
            ['label' => 'Resolved / closed', 'value' => number_format($cases->whereIn('status', ['RESOLVED', 'CLOSED'])->count())],
        ];
        $rows = $cases->map(function (SellerComplianceCase $case): array {
            return [
                $case->case_number,
                $case->sellerProfile?->business_name ?: 'Seller unavailable',
                $case->product?->name ?: 'Account-level seller case',
                str($case->violation_type)->replace('_', ' ')->headline(),
                str($case->status)->replace('_', ' ')->headline(),
                $case->opened_at?->format('M d, Y') ?: '-',
                $case->resolved_at?->format('M d, Y') ?: '-',
            ];
        })->all();

        return $this->pdfDownload(
            $pdf->render([
                'title' => 'Seller Compliance Cases',
                'description' => 'Compliance cases, warnings, and recorded resolution status.',
                'generated_at' => now()->format('M d, Y H:i'),
                'generated_by' => $request->user()?->name ?: 'Authorized Admin',
                'filters' => [
                    ['label' => 'Search', 'value' => $this->complianceSearch($request->query('search')) ?: 'All cases'],
                    ['label' => 'Status', 'value' => $this->complianceStatus($request->query('status')) ?: 'All statuses'],
                ],
                'summary' => $summary,
                'columns' => [
                    ['label' => 'Case', 'width' => 62],
                    ['label' => 'Seller / store', 'width' => 84],
                    ['label' => 'Product', 'width' => 100],
                    ['label' => 'Violation', 'width' => 82],
                    ['label' => 'Status', 'width' => 72],
                    ['label' => 'Opened', 'width' => 70],
                    ['label' => 'Resolved', 'width' => 70],
                ],
                'rows' => $rows,
                'empty_message' => 'No compliance cases match the applied filters.',
                'footnote' => 'Private investigation notes and sensitive attachments are excluded from this report.',
            ]),
            'likhae-admin-compliance-'.now()->format('Ymd-His').'.pdf',
        );
    }

    public function updateComplianceCase(Request $request, SellerComplianceCase $case): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:OPEN,UNDER_REVIEW,RESOLVED,CLOSED'],
            'note' => ['nullable', 'string', 'max:5000'],
            'reinstate_product' => ['nullable', 'boolean'],
        ]);
        if (in_array($data['status'], ['RESOLVED', 'CLOSED'], true) && blank(trim((string) ($data['note'] ?? '')))) {
            return back()->withErrors(['note' => 'Add a documented reason before resolving or closing this case.'])->withInput();
        }
        $case->loadMissing(['product', 'sellerProfile.user']);
        $oldStatus = $case->status;
        $reinstated = false;
        $case->update([
            'status' => $data['status'],
            'resolved_at' => in_array($data['status'], ['RESOLVED', 'CLOSED'], true) ? now() : null,
        ]);

        if (filled($data['note'] ?? null)) {
            SellerComplianceAction::create([
                'seller_compliance_case_id' => $case->id,
                'performed_by_user_id' => $request->user()->id,
                'action_type' => 'NOTE',
                'reason' => trim((string) $data['note']),
                'performed_at' => now(),
            ]);
        }

        $product = $case->product;
        if ($product && $product->status === 'SUSPENDED' && $request->boolean('reinstate_product')) {
            abort_unless(in_array($data['status'], ['RESOLVED', 'CLOSED'], true), 422, 'Resolve the compliance case before reinstating the product.');
            $reinstated = true;
            $previousStatus = $product->status;
            $product->update(['status' => 'ACTIVE', 'archived_at' => null, 'published_at' => $product->published_at ?: now()]);
            SellerComplianceAction::create([
                'seller_compliance_case_id' => $case->id,
                'performed_by_user_id' => $request->user()->id,
                'action_type' => 'NOTE',
                'reason' => 'Product reinstated after Admin compliance review.',
                'performed_at' => now(),
            ]);
            $this->audit(
                $request,
                'PRODUCT_REINSTATED',
                Product::class,
                $product->id,
                ['status' => $previousStatus],
                ['status' => $product->status, 'compliance_case_id' => $case->id],
            );
            if ($product->sellerProfile?->user) {
                Notification::create([
                    'user_id' => $product->sellerProfile->user->id,
                    'type' => 'PRODUCT_REINSTATED',
                    'title' => 'Product reinstated: '.$product->name,
                    'message' => 'Your product "'.$product->name.'" was reinstated by Admin after compliance review. Case '.$case->case_number.'.',
                    'reference_type' => Product::class,
                    'reference_id' => $product->id,
                    'action_url' => route('seller.compliance.show', ['case' => $case->id]),
                ]);
            }
        }

        if ($oldStatus !== $case->status && ! $reinstated && $case->sellerProfile?->user) {
            Notification::create([
                'user_id' => $case->sellerProfile->user->id,
                'type' => 'COMPLIANCE_CASE_UPDATED',
                'title' => 'Compliance case updated: '.$case->case_number,
                'message' => 'Case '.$case->case_number.' is now '.str($case->status)->replace('_', ' ')->headline().'. Review the recorded case details for the latest decision.',
                'reference_type' => SellerComplianceCase::class,
                'reference_id' => $case->id,
                'action_url' => route('seller.compliance.show', ['case' => $case->id]),
            ]);
        }

        $this->audit(
            $request,
            'COMPLIANCE_CASE_UPDATED',
            SellerComplianceCase::class,
            $case->id,
            ['status' => $oldStatus],
            ['status' => $case->status, 'product_id' => $case->product_id, 'seller_profile_id' => $case->seller_profile_id],
        );

        return back()->with('status', 'Compliance case updated.');
    }

    public function complaints(): View
    {
        return view('Admin.complaints.index', [
            'disputes' => Dispute::with(['openedBy', 'assignedAdmin', 'order', 'sellerOrder.sellerProfile', 'shipment', 'evidence', 'returnRefundRequest', 'sellerReturnRefundRequest.request.evidence', 'sellerReturnRefundRequest.request', 'sellerReturnRefundRequest.sellerProfile', 'sellerReturnRefundRequest.items.orderItem', 'sellerReturnRefundRequest.payouts'])->latest()->paginate(20),
        ]);
    }

    public function showDispute(Dispute $dispute): View
    {
        $dispute->load([
            'openedBy',
            'assignedAdmin',
            'order.buyer',
            'order.address',
            'sellerOrder.sellerProfile.user',
            'shipment',
            'evidence',
            'returnRefundRequest.items.orderItem',
            'returnRefundRequest.evidence',
            'returnRefundRequest.events.actor',
            'sellerReturnRefundRequest.request.order.buyer',
            'sellerReturnRefundRequest.request.evidence',
            'sellerReturnRefundRequest.request.events.actor',
            'sellerReturnRefundRequest.sellerOrder.sellerProfile.user',
            'sellerReturnRefundRequest.items.orderItem',
            'sellerReturnRefundRequest.payouts.submittedBy',
        ]);

        return view('Admin.complaints.show', compact('dispute'));
    }

    public function finance(FinanceSchemaReadiness $schema): View
    {
        $financialTotals = [
            'schema_ready' => $schema->ready(),
            'expected_cod' => 0.0,
            'cash_collected' => 0.0,
            'cash_submitted' => 0.0,
            'cash_verified' => 0.0,
            'platform_commission' => 0.0,
            'seller_payable' => 0.0,
            'logistics_allocation' => 0.0,
            'rider_allocation' => 0.0,
            'unresolved_settlements' => 0,
        ];
        $sellerSettlements = null;
        if ($schema->ready()) {
            $financialTotals['expected_cod'] = (float) CodCollection::query()->sum('expected_amount');
            $financialTotals['cash_collected'] = (float) CodCollection::query()
                ->whereIn('status', [CodCollection::COLLECTED, CodCollection::REMITTED, CodCollection::DISCREPANCY])
                ->sum('collected_amount');
            $financialTotals['cash_submitted'] = (float) RiderRemittance::query()
                ->whereIn('status', [RiderRemittance::SUBMITTED, RiderRemittance::VERIFIED, RiderRemittance::RECONCILED, RiderRemittance::DISCREPANCY])
                ->sum('submitted_amount');
            $financialTotals['cash_verified'] = (float) RiderRemittance::query()
                ->whereIn('status', [RiderRemittance::VERIFIED, RiderRemittance::RECONCILED, RiderRemittance::DISCREPANCY])
                ->sum('verified_amount');
            $financialTotals['platform_commission'] = (float) ShipmentFinancialAllocation::query()->sum('platform_commission');
            $financialTotals['seller_payable'] = (float) ShipmentFinancialAllocation::query()->sum('seller_payable');
            $financialTotals['logistics_allocation'] = (float) ShipmentFinancialAllocation::query()->sum('logistics_allocation');
            $financialTotals['rider_allocation'] = (float) ShipmentFinancialAllocation::query()->sum('rider_allocation');
            $financialTotals['unresolved_settlements'] = SellerSettlement::query()->where('payout_status', 'REVIEW_REQUIRED')->count();
            $sellerSettlements = SellerSettlement::query()
                ->with('sellerProfile')
                ->latest()
                ->paginate(15, ['*'], 'settlements_page');
        }

        return view('Admin.finance.index', [
            'payments' => Payment::with('order.buyer')->latest()->paginate(15),
            'commissions' => CommissionTransaction::with('sellerOrder.sellerProfile.user')->latest()->paginate(15),
            'sellerSettlements' => $sellerSettlements,
            'financialTotals' => $financialTotals,
            'totals' => [
                'payments' => (float) Payment::where('status', 'PAID')->sum('amount'),
                'commission' => (float) CommissionTransaction::sum('commission_amount'),
                'pending_commission' => (float) CommissionTransaction::where('status', 'PENDING')->sum('commission_amount'),
            ],
        ]);
    }

    public function reports(Request $request): View
    {
        $filters = $this->reportFilters($request);
        $items = $this->reportItemsQuery($filters);
        $salesExpression = $this->reportItemSalesExpression();
        $commissionExpression = $this->reportAllocatedCommissionExpression();

        $summary = (clone $items)->selectRaw("\n            COALESCE(SUM($salesExpression), 0) AS sales,\n            COALESCE(SUM($commissionExpression), 0) AS commission,\n            COALESCE(SUM(oi.quantity), 0) AS units_sold,\n            COUNT(DISTINCT so.id) AS order_count\n        ")->first();

        $sortColumn = match ($filters['sort'] ?? 'sales') {
            'units' => 'units_sold',
            'commission' => 'commission',
            'orders' => 'order_count',
            default => 'sales',
        };

        $shops = (clone $items)
            ->selectRaw("sp.id, sp.business_name AS label, shop_category.name AS shop_category, COUNT(DISTINCT so.id) AS order_count, SUM(oi.quantity) AS units_sold, SUM($salesExpression) AS sales, SUM($commissionExpression) AS commission")
            ->groupBy('sp.id', 'sp.business_name', 'shop_category.name')
            ->orderByDesc($sortColumn)
            ->orderBy('label')
            ->limit(10)
            ->get();

        $categories = (clone $items)
            ->selectRaw("c.id, COALESCE(c.name, 'Uncategorized') AS category_name, parent_category.name AS parent_category_name, COUNT(DISTINCT so.id) AS order_count, SUM(oi.quantity) AS units_sold, SUM($salesExpression) AS sales, SUM($commissionExpression) AS commission")
            ->groupBy('c.id', 'c.name', 'parent_category.name')
            ->orderByDesc($sortColumn)
            ->orderBy('category_name')
            ->limit(10)
            ->get();

        $products = (clone $items)
            ->selectRaw("oi.product_id AS id, oi.product_name AS label, sp.business_name AS shop_name, COALESCE(c.name, 'Uncategorized') AS category_name, parent_category.name AS parent_category_name, COUNT(DISTINCT so.id) AS order_count, SUM(oi.quantity) AS units_sold, SUM($salesExpression) AS sales, SUM($commissionExpression) AS commission")
            ->groupBy('oi.product_id', 'oi.product_name', 'sp.business_name', 'c.name', 'parent_category.name')
            ->orderByDesc($sortColumn)
            ->orderBy('label')
            ->limit(10)
            ->get();

        $orderItemRecords = (clone $items)
            ->join('orders as o', 'o.id', '=', 'so.order_id')
            ->leftJoin('users as buyer', 'buyer.id', '=', 'o.buyer_user_id')
            ->select([
                'oi.id',
                'oi.product_name',
                'oi.sku',
                'oi.quantity',
                'so.seller_order_number',
                'so.status',
                'so.created_at',
                'sp.business_name',
                'c.name as category_name',
                'parent_category.name as parent_category_name',
                'buyer.first_name as buyer_first_name',
                'buyer.middle_initial as buyer_middle_initial',
                'buyer.last_name as buyer_last_name',
                'ct.status as commission_status',
            ])
            ->selectRaw("$salesExpression AS sales, $commissionExpression AS commission")
            ->orderByDesc('so.created_at')
            ->orderByDesc('oi.id')
            ->paginate(20)
            ->withQueryString();

        return view('Admin.reports.index', [
            'orderItemRecords' => $orderItemRecords,
            'summary' => $summary,
            'shops' => $shops,
            'categories' => $categories,
            'products' => $products,
            'shopOptions' => SellerProfile::query()->with('primaryCategory')->orderBy('business_name')->get(['id', 'business_name', 'primary_category_id']),
            'categoryOptions' => Category::query()->with('parent')->where('is_active', true)->whereNull('archived_at')->orderBy('parent_id')->orderBy('name')->get(),
            'productOptions' => Product::query()->with('sellerProfile')->orderBy('name')->get(['id', 'name', 'seller_profile_id']),
            'filters' => $filters,
        ]);
    }

    /** @return array<string, mixed> */
    private function reportFilters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', 'string', 'in:PLACED,CONFIRMED,PREPARING,READY_FOR_PICKUP,PICKED_UP,COMPLETED,CANCELLED'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'seller_profile_id' => ['nullable', 'integer', 'exists:seller_profiles,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'sort' => ['nullable', 'string', 'in:sales,units,commission,orders'],
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function reportItemsQuery(array $filters): QueryBuilder
    {
        return DB::table('order_items as oi')
            ->join('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->join('seller_profiles as sp', 'sp.id', '=', 'so.seller_profile_id')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('categories as parent_category', 'parent_category.id', '=', 'c.parent_id')
            ->leftJoin('categories as shop_category', 'shop_category.id', '=', 'sp.primary_category_id')
            ->leftJoin('commission_transactions as ct', 'ct.seller_order_id', '=', 'so.id')
            ->when($filters['status'] ?? null, fn (QueryBuilder $query, string $status) => $query->where('so.status', $status))
            ->when($filters['date_from'] ?? null, fn (QueryBuilder $query, string $date) => $query->whereDate('so.created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (QueryBuilder $query, string $date) => $query->whereDate('so.created_at', '<=', $date))
            ->when($filters['seller_profile_id'] ?? null, fn (QueryBuilder $query, int $sellerId) => $query->where('so.seller_profile_id', $sellerId))
            ->when($filters['category_id'] ?? null, fn (QueryBuilder $query, int $categoryId) => $query->where(function (QueryBuilder $categoryQuery) use ($categoryId): void {
                $categoryQuery->where('p.category_id', $categoryId)->orWhere('c.parent_id', $categoryId);
            }))
            ->when($filters['product_id'] ?? null, fn (QueryBuilder $query, int $productId) => $query->where('oi.product_id', $productId));
    }

    private function reportItemSalesExpression(): string
    {
        return 'CASE WHEN so.item_subtotal > 0 THEN oi.line_total * GREATEST(so.item_subtotal - so.voucher_discount, 0) / so.item_subtotal ELSE 0 END';
    }

    private function reportAllocatedCommissionExpression(): string
    {
        return "CASE WHEN ct.status IS NOT NULL AND ct.status <> 'VOID' AND so.item_subtotal > 0 THEN ct.commission_amount * oi.line_total / so.item_subtotal ELSE 0 END";
    }

    public function messages(Request $request, ConversationService $conversationService): View
    {
        $conversations = $conversationService->workspaceThreads(
            $request->user(),
            $request->integer('conversation') ?: null,
        );

        return view('Admin.messages.index', [
            'conversations' => $conversations,
            'contacts' => User::query()->where('status', 'ACTIVE')->whereKeyNot($request->user()->id)->orderBy('first_name')->limit(100)->get(['id', 'first_name', 'middle_initial', 'last_name', 'name_extension', 'account_type']),
            'announcements' => Announcement::query()->latest()->paginate(10),
        ]);
    }

    public function messageStream(Request $request, ConversationService $conversationService): JsonResponse
    {
        return response()->json([
            'success' => true,
            'messages' => $conversationService->streamPayload(
                $request->user(),
                $request->integer('conversation'),
                $request->integer('after_id') ?: null,
            ),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function settings(): View
    {
        return view('Admin.settings.index', [
            'settings' => [
                'platform_name' => PlatformSetting::valueOf('platform_name', 'LIKHAE'),
                'support_email' => PlatformSetting::valueOf('support_email', ''),
                'commission_rate' => PlatformSetting::commissionRate(),
                'transfer_commission_type' => PlatformSetting::valueOf('transfer_commission_type', 'PERCENT'),
                'transfer_commission_value' => PlatformSetting::valueOf('transfer_commission_value', 0),
                'registration_enabled' => (bool) PlatformSetting::valueOf('registration_enabled', true),
            ],
            'policies' => PlatformPolicy::latest()->get(),
            'announcements' => Announcement::latest()->get(),
        ]);
    }

    public function account(Request $request): View
    {
        $adminUser = $request->user();

        return view('Admin.account.index', [
            'adminUser' => $adminUser,
            'preferences' => $adminUser,
        ]);
    }

    public function notifications(Request $request): View
    {
        return view('Admin.notifications.index', [
            'notifications' => $request->user()->notifications()->latest()->paginate(20),
        ]);
    }

    public function markNotificationsRead(Request $request): RedirectResponse
    {
        $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('status', 'Notifications marked as read.');
    }

    public function markNotificationRead(Request $request, Notification $notification): RedirectResponse
    {
        abort_unless((int) $notification->user_id === (int) $request->user()->id, 403);
        $notification->update(['read_at' => now()]);

        return back()->with('status', 'Notification marked as read.');
    }

    public function moderateProduct(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(self::PRODUCT_STATUSES)],
            'reason' => ['required_if:status,SUSPENDED', 'nullable', 'string', 'max:1000'],
        ]);

        abort_unless(in_array($product->status, self::PRODUCT_STATUSES, true), 422, 'Draft and archived products remain Seller-managed.');
        if ($product->status === 'SUSPENDED' && $data['status'] === 'ACTIVE' && blank($data['reason'] ?? null)) {
            return back()->withErrors(['reason' => 'A reason is required before reinstating a suspended product.'])->withInput();
        }

        $oldStatus = $product->status;

        $mailDetails = null;

        if ($data['status'] === 'SUSPENDED') {
            $product->loadMissing(['sellerProfile.user', 'category', 'variants']);
            $reason = trim((string) $data['reason']);
            $seller = $product->sellerProfile;
            $sellerUser = $seller?->user;
            $actionUrl = null;

            $case = SellerComplianceCase::query()
                ->where('product_id', $product->id)
                ->whereIn('status', self::UNRESOLVED_COMPLIANCE_STATUSES)
                ->latest('id')
                ->first();

            if (! $case) {
                $case = SellerComplianceCase::create([
                    'case_number' => 'CMP-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4)),
                    'seller_profile_id' => $product->seller_profile_id,
                    'product_id' => $product->id,
                    'opened_by_admin_user_id' => $request->user()->id,
                    'violation_type' => 'PRODUCT_MODERATION',
                    'description' => $reason,
                    'status' => 'OPEN',
                    'opened_at' => now(),
                ]);
            }
            $actionUrl = route('seller.compliance.show', ['case' => $case->id]);

            SellerComplianceAction::create([
                'seller_compliance_case_id' => $case->id,
                'performed_by_user_id' => $request->user()->id,
                'action_type' => 'PRODUCT_SUSPEND',
                'reason' => $data['reason'] ?? null,
                'performed_at' => now(),
            ]);

            $product->update(['status' => 'SUSPENDED']);

            if ($sellerUser) {
                $productName = $product->name;
                $caseNumber = $case->case_number;
                $notificationMessage = 'Your product "'.$productName.'" (Product ID #'.$product->id.') was suspended on '
                    .now()->format('M j, Y \\a\\t g:i A').'. Reason: '.$reason
                    .'. Case '.$caseNumber.'. The listing is unavailable to buyers while suspended. Review and correct the listing, then contact LIKHAE support with the case number if you need clarification or a review.';

                Notification::create([
                    'user_id' => $sellerUser->id,
                    'type' => 'PRODUCT_SUSPENDED',
                    'title' => 'Product suspended: '.$productName,
                    'message' => $notificationMessage,
                    'reference_type' => 'PRODUCT',
                    'reference_id' => $product->id,
                    'action_url' => $actionUrl,
                ]);

                $mailDetails = [
                    'sellerUser' => $sellerUser,
                    'product' => $product,
                    'case' => $case,
                    'reason' => $reason,
                    'actionUrl' => $actionUrl,
                ];
            }
            $this->audit(
                $request,
                'PRODUCT_SUSPENDED',
                Product::class,
                $product->id,
                ['status' => $oldStatus],
                ['status' => $product->status, 'seller_profile_id' => $product->seller_profile_id, 'reason' => $reason, 'compliance_case_id' => $case->id],
            );
        } elseif ($oldStatus === 'SUSPENDED' && $data['status'] === 'ACTIVE') {
            $product->loadMissing('sellerProfile.user');
            $product->update(['status' => 'ACTIVE', 'archived_at' => null, 'published_at' => $product->published_at ?: now()]);
            $case = SellerComplianceCase::query()
                ->where('product_id', $product->id)
                ->whereIn('status', self::UNRESOLVED_COMPLIANCE_STATUSES)
                ->latest('id')
                ->first();
            if ($case) {
                $case->update(['status' => 'RESOLVED', 'resolved_at' => now()]);
                SellerComplianceAction::create([
                    'seller_compliance_case_id' => $case->id,
                    'performed_by_user_id' => $request->user()->id,
                    'action_type' => 'NOTE',
                    'reason' => trim((string) $data['reason']),
                    'performed_at' => now(),
                ]);
            }
            $this->audit(
                $request,
                'PRODUCT_REINSTATED',
                Product::class,
                $product->id,
                ['status' => $oldStatus],
                ['status' => $product->status, 'reason' => trim((string) $data['reason']), 'compliance_case_id' => $case?->id],
            );
            if ($product->sellerProfile?->user) {
                Notification::create([
                    'user_id' => $product->sellerProfile->user->id,
                    'type' => 'PRODUCT_REINSTATED',
                    'title' => 'Product reinstated: '.$product->name,
                    'message' => 'Your product "'.$product->name.'" was reinstated by Admin after compliance review. Reason: '.trim((string) $data['reason']),
                    'reference_type' => Product::class,
                    'reference_id' => $product->id,
                    'action_url' => $case
                        ? route('seller.compliance.show', ['case' => $case->id])
                        : route('seller.products', ['mode' => 'edit', 'product' => $product->id]),
                ]);
            }
        } else {
            $product->update(['status' => $data['status']]);
            if ($oldStatus !== $product->status) {
                $this->audit(
                    $request,
                    'PRODUCT_MODERATION_UPDATED',
                    Product::class,
                    $product->id,
                    ['status' => $oldStatus],
                    ['status' => $product->status, 'reason' => $data['reason'] ?? null],
                );
            }
        }

        $emailSent = false;

        if ($mailDetails && filled($mailDetails['sellerUser']->email)) {
            try {
                Mail::to($mailDetails['sellerUser']->email)->send(new SellerProductSuspended(
                    $mailDetails['product'],
                    $mailDetails['case'],
                    $mailDetails['reason'],
                    $mailDetails['actionUrl'],
                ));
                $emailSent = true;
            } catch (\Throwable $exception) {
                Log::warning('Product suspension email could not be sent.', [
                    'product_id' => $mailDetails['product']->id,
                    'seller_user_id' => $mailDetails['sellerUser']->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        $statusMessage = ! $mailDetails
            ? 'Product moderation updated.'
            : ($emailSent
                ? 'Product suspended. The seller was sent an in-app notification and email.'
                : 'Product suspended and in-app notification saved. The seller email could not be sent; check the mail configuration.');

        return back()->with('status', $statusMessage);
    }

    public function categories(Request $request): View
    {
        $tab = in_array($request->query('tab'), ['all', 'archived'], true)
            ? (string) $request->query('tab')
            : 'all';
        $search = $this->categorySearch($request->query('search'));
        $status = $tab === 'all' && in_array($request->query('status'), ['active', 'inactive'], true)
            ? (string) $request->query('status')
            : null;

        $categoryStats = [
            'main' => (int) Category::query()->whereNull('parent_id')->whereNull('archived_at')->count(),
            'subcategories' => (int) Category::query()->whereNotNull('parent_id')->whereNull('archived_at')->count(),
            'inactive' => (int) Category::query()->where('is_active', false)->whereNull('archived_at')->count(),
            'archived' => (int) Category::query()->whereNotNull('archived_at')->count(),
        ];

        $rootsQuery = Category::query()->whereNull('parent_id');
        if ($tab === 'archived') {
            $rootsQuery
                ->withCount([
                    'children as children_count' => fn ($query) => $query->whereNotNull('archived_at'),
                    'products',
                    'sellerProfiles',
                    'sellerApplications',
                ])
                ->with(['children' => fn ($query) => $query
                    ->whereNotNull('archived_at')
                    ->withCount(['products', 'sellerProfiles', 'sellerApplications'])
                    ->orderBy('name')])
                ->where(fn ($query) => $query
                    ->whereNotNull('archived_at')
                    ->orWhereHas('children', fn ($children) => $children->whereNotNull('archived_at')));
        } else {
            $rootsQuery
                ->whereNull('archived_at')
                ->withCount(['children', 'products', 'sellerProfiles', 'sellerApplications'])
                ->with(['children' => fn ($query) => $query
                    ->whereNull('archived_at')
                    ->withCount(['products', 'sellerProfiles', 'sellerApplications'])
                    ->orderBy('name')]);
        }

        $roots = $rootsQuery->orderBy('name')->get();
        $filteredRoots = $roots->map(function (Category $root) use ($search, $status, $tab): ?Category {
            $children = $root->children;
            $isArchivedTab = $tab === 'archived';
            $statusChildren = $isArchivedTab || $status === null
                ? $children
                : $children->filter(fn (Category $child): bool => $status === 'active'
                    ? (bool) $child->is_active
                    : ! $child->is_active);
            $needle = mb_strtolower($search);
            $rootMatches = $search === '' || str_contains(mb_strtolower($root->name), $needle);
            $matchingChildren = $search === ''
                ? $statusChildren
                : $statusChildren->filter(fn (Category $child): bool => str_contains(mb_strtolower($child->name), $needle));

            $rootMatchesStatus = $isArchivedTab || $status === null
                || ($status === 'active' ? (bool) $root->is_active : ! $root->is_active);
            $visible = $search === ''
                ? ($rootMatchesStatus || $statusChildren->isNotEmpty())
                : ($rootMatches && $rootMatchesStatus) || $matchingChildren->isNotEmpty();

            if (! $visible) {
                return null;
            }

            $visibleChildren = $search !== '' && ! $rootMatches ? $matchingChildren : $statusChildren;
            $copy = clone $root;
            $copy->setRelation('children', $visibleChildren->values());

            return $copy;
        })->filter()->values();

        $perPage = 20;
        $page = max(1, $request->integer('page', 1));
        $categories = new LengthAwarePaginator(
            $filteredRoots->forPage($page, $perPage)->values(),
            $filteredRoots->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('Admin.categories.index', [
            'categories' => $categories,
            'categoryStats' => $categoryStats,
            'tab' => $tab,
            'status' => $status,
            'search' => $search,
            'archiveSupported' => self::CATEGORY_ARCHIVE_SUPPORTED,
        ]);
    }

    public function archiveCategory(Request $request, Category $category): RedirectResponse
    {
        abort_unless(self::CATEGORY_ARCHIVE_SUPPORTED, 404);

        if ($category->archived_at !== null) {
            return back()->withErrors(['category' => 'This category is already archived.']);
        }

        $activeChildren = $category->children()->whereNull('archived_at')->count();
        $products = $category->products()->count();
        $sellerProfiles = $category->sellerProfiles()->count();
        $sellerApplications = $category->sellerApplications()->count();
        $dependencies = collect([
            $activeChildren > 0 ? $activeChildren.' active subcategories' : null,
            $products > 0 ? $products.' products' : null,
            $sellerProfiles > 0 ? $sellerProfiles.' Seller registrations' : null,
            $sellerApplications > 0 ? $sellerApplications.' Seller applications' : null,
        ])->filter()->implode(', ');

        if ($dependencies !== '') {
            return back()->withErrors(['category' => 'Category cannot be archived while it has '.$dependencies.'. Deactivate it instead, or move the linked records first.']);
        }

        $category->archived_at = now();
        $category->save();

        return back()->with('status', ($category->parent_id === null ? 'Main category' : 'Subcategory').' archived. Existing relationships were preserved.');
    }

    public function restoreCategory(Request $request, Category $category): RedirectResponse
    {
        abort_unless(self::CATEGORY_ARCHIVE_SUPPORTED, 404);

        if ($category->archived_at === null) {
            return back()->withErrors(['category' => 'This category is not archived.']);
        }

        $parentId = $category->parent_id !== null ? (int) $category->parent_id : null;
        if ($parentId !== null) {
            $parent = $category->parent()->first();
            if (! $parent || $parent->archived_at !== null) {
                return back()->withErrors(['category' => 'Restore the archived parent category before restoring this subcategory.']);
            }
        }

        if ($this->categoryNameTaken($category->name, $parentId, $category->id)) {
            return back()->withErrors(['category' => 'A non-archived category with this name already exists in the same group. Rename that category or resolve the conflict first.']);
        }

        $category->archived_at = null;
        $category->save();

        return back()->with('status', ($category->parent_id === null ? 'Main category' : 'Subcategory').' restored.');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        if ($request->filled('parent_id')) {
            return back()->withErrors(['parent_id' => 'Main categories cannot have a parent category.'])->withInput();
        }
        $request->merge(['name' => $this->normalizeCategoryName($request->input('name'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
        ]);

        if ($this->categoryNameTaken($data['name'], null)) {
            return back()->withErrors(['name' => 'A main category with this name already exists.'])->withInput();
        }

        Category::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.strtolower(Str::random(4)),
            'parent_id' => null,
            'description' => $data['description'] ?? null,
            'is_active' => true,
        ]);

        return back()->with('status', 'Category added.');
    }

    public function updateCategory(Request $request, Category $category): RedirectResponse
    {
        abort_unless($category->parent_id === null, 422, 'Use the subcategory editor for child categories.');
        abort_unless($category->archived_at === null, 422, 'Restore the category before editing it.');
        $request->merge(['name' => $this->normalizeCategoryName($request->input('name'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($this->categoryNameTaken($data['name'], null, $category->id)) {
            return back()->withErrors(['name' => 'A main category with this name already exists.'])->withInput();
        }

        $category->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('status', 'Category updated.');
    }

    public function updateCategoryStatus(Request $request, Category $category): RedirectResponse
    {
        abort_unless($category->parent_id === null, 422, 'Use the subcategory status action for child categories.');
        abort_unless($category->archived_at === null, 422, 'Restore the category before changing its status.');
        $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $category->update(['is_active' => $request->boolean('is_active')]);

        return back()->with('status', $this->categoryStatusMessage($category, $request->boolean('is_active')));
    }

    public function storeSubcategory(Request $request): RedirectResponse
    {
        $request->merge(['name' => $this->normalizeCategoryName($request->input('name'))]);
        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->whereNull('parent_id')->where('is_active', true)->whereNull('archived_at')],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
        ]);

        if ($this->categoryNameTaken($data['name'], (int) $data['category_id'])) {
            return back()->withErrors(['name' => 'A subcategory with this name already exists under that main category.'])->withInput();
        }

        Category::create([
            'parent_id' => $data['category_id'],
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.strtolower(Str::random(4)),
            'description' => $data['description'] ?? null,
            'is_active' => true,
        ]);

        return back()->with('status', 'Subcategory added.');
    }

    public function updateSubcategory(Request $request, Category $category): RedirectResponse
    {
        abort_unless($category->parent_id !== null, 422, 'This action is only available for subcategories.');
        abort_unless($category->archived_at === null, 422, 'Restore the subcategory before editing it.');
        $request->merge(['name' => $this->normalizeCategoryName($request->input('name'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($this->categoryNameTaken($data['name'], (int) $category->parent_id, $category->id)) {
            return back()->withErrors(['name' => 'A subcategory with this name already exists under that main category.'])->withInput();
        }

        $category->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('status', 'Subcategory updated.');
    }

    public function updateSubcategoryStatus(Request $request, Category $category): RedirectResponse
    {
        abort_unless($category->parent_id !== null, 422, 'This action is only available for subcategories.');
        abort_unless($category->archived_at === null, 422, 'Restore the subcategory before changing its status.');
        $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $category->update(['is_active' => $request->boolean('is_active')]);

        return back()->with('status', $this->categoryStatusMessage($category, $request->boolean('is_active')));
    }

    public function sendMessage(Request $request, ConversationService $conversationService): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'recipient_user_id' => ['required', 'integer', 'exists:users,id'],
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $context = [];
        $conversation = null;
        if (! empty($data['conversation_id'])) {
            $conversation = Conversation::query()
                ->whereKey((int) $data['conversation_id'])
                ->whereHas('participants', fn ($query) => $query->where('users.id', $request->user()->id))
                ->whereHas('participants', fn ($query) => $query->where('users.id', (int) $data['recipient_user_id']))
                ->firstOrFail();
            $context = $conversationService->contextFor($conversation);
        }

        $message = $conversationService->send($request->user(), (int) $data['recipient_user_id'], trim($data['body']), $context, $conversation);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $conversationService->messagePayload($message),
            ]);
        }

        return back()->with('status', 'Message sent.');
    }

    public function storeAnnouncement(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ]);

        Announcement::create($data + [
            'created_by_user_id' => $request->user()->id,
            'is_active' => true,
        ]);

        return back()->with('status', 'Announcement saved.');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'commission_rate' => ['required', 'numeric', 'in:0.10'],
            'transfer_commission_type' => ['required', Rule::in(['PERCENT', 'FIXED'])],
            'transfer_commission_value' => ['required', 'numeric', 'min:0', 'max:10000'],
            'registration_enabled' => ['required', 'boolean'],
        ]);
        if ($data['transfer_commission_type'] === 'PERCENT' && (float) $data['transfer_commission_value'] > 100) {
            return back()
                ->withErrors(['transfer_commission_value' => 'The transfer commission percentage cannot exceed 100%.'])
                ->withInput();
        }
        PlatformSetting::put('commission_rate', $data['commission_rate'], 'decimal', $request->user()->id);
        PlatformSetting::put('transfer_commission_type', $data['transfer_commission_type'], 'string', $request->user()->id);
        PlatformSetting::put('transfer_commission_value', $data['transfer_commission_value'], 'decimal', $request->user()->id);
        PlatformSetting::put('registration_enabled', (bool) $data['registration_enabled'], 'boolean', $request->user()->id);

        return back()->with('status', 'Settings updated.');
    }

    public function storePolicy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'version' => ['required', 'string', 'max:50'],
            'body' => ['required', 'string'],
            'effective_at' => ['nullable', 'date'],
        ]);

        PlatformPolicy::create($data + [
            'published_by_user_id' => $request->user()->id,
            'published_at' => now(),
            'is_active' => true,
        ]);

        return back()->with('status', 'Policy saved.');
    }

    public function updatePolicy(Request $request, PlatformPolicy $policy): RedirectResponse
    {
        $policy->update($request->validate([
            'title' => ['required', 'string', 'max:255'],
            'version' => ['required', 'string', 'max:50'],
            'body' => ['required', 'string'],
            'effective_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]));

        return back()->with('status', 'Policy updated.');
    }

    public function updateAccount(Request $request): RedirectResponse
    {
        $request->user()->update($request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_initial' => ['nullable', 'string', 'max:10'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($request->user()->id)],
            'contact_number' => ['required', 'string', 'max:30'],
        ]));

        return back()->with('status', 'Account updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $request->user()->update(['password' => Hash::make($data['password'])]);

        return back()->with('status', 'Password updated.');
    }

    public function warnProduct(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', Rule::in(array_keys(self::PRODUCT_WARNING_CATEGORIES))],
            'reason' => ['required', 'string', 'min:10', 'max:5000'],
            'corrective_action' => ['required', 'string', 'min:5', 'max:5000'],
            'evidence' => ['nullable', 'string', 'max:5000'],
        ]);

        abort_unless($product->status === 'ACTIVE', 422, 'Only active products can receive a warning.');
        $product->loadMissing(['sellerProfile.user', 'category', 'sellerProfile.primaryCategory.children']);

        if ($data['category'] === 'INCORRECT_CATEGORY' && ! $this->productCategoryMismatch($product)) {
            return back()->withErrors(['category' => 'This product matches the Seller\'s permitted category and should not be warned for a category mismatch.'])->withInput();
        }

        $categoryLabel = self::PRODUCT_WARNING_CATEGORIES[$data['category']];
        $reason = trim($data['reason']);
        $correctiveAction = trim($data['corrective_action']);
        $evidence = filled($data['evidence'] ?? null) ? trim((string) $data['evidence']) : null;
        $warningRecord = "Warning category: {$categoryLabel}\nReason: {$reason}\nRequired corrective action: {$correctiveAction}";
        if ($evidence) {
            $warningRecord .= "\nSupporting evidence/reference: {$evidence}";
        }

        $case = SellerComplianceCase::query()
            ->where('product_id', $product->id)
            ->whereIn('status', self::UNRESOLVED_COMPLIANCE_STATUSES)
            ->latest('id')
            ->first();

        if (! $case) {
            $case = SellerComplianceCase::create([
                'case_number' => 'CMP-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4)),
                'seller_profile_id' => $product->seller_profile_id,
                'product_id' => $product->id,
                'opened_by_admin_user_id' => $request->user()->id,
                'violation_type' => $data['category'],
                'description' => $warningRecord,
                'status' => 'OPEN',
                'opened_at' => now(),
            ]);
        }

        $duplicateWarning = SellerComplianceAction::query()
            ->where('seller_compliance_case_id', $case->id)
            ->where('action_type', 'WARNING')
            ->where('reason', $warningRecord)
            ->exists();

        if ($duplicateWarning) {
            return back()->with('status', 'This warning is already recorded for the current compliance case.');
        }

        if (! str_contains((string) $case->description, $warningRecord)) {
            $case->update(['description' => trim($case->description."\n\n{$warningRecord}")]);
        }

        SellerComplianceAction::create([
            'seller_compliance_case_id' => $case->id,
            'performed_by_user_id' => $request->user()->id,
            'action_type' => 'WARNING',
            'reason' => $warningRecord,
            'performed_at' => now(),
        ]);
        $this->audit(
            $request,
            'PRODUCT_WARNING_ISSUED',
            Product::class,
            $product->id,
            null,
            [
                'seller_profile_id' => $product->seller_profile_id,
                'compliance_case_id' => $case->id,
                'category' => $data['category'],
                'reason' => $reason,
                'corrective_action' => $correctiveAction,
                'evidence' => $evidence,
            ],
        );

        $sellerUser = $product->sellerProfile?->user;
        $actionUrl = route('seller.compliance.show', ['case' => $case->id]);
        if ($sellerUser) {
            Notification::create([
                'user_id' => $sellerUser->id,
                'type' => 'PRODUCT_WARNING',
                'title' => 'Product warning: '.$product->name,
                'message' => 'Your product "'.$product->name.'" received an Admin warning for '.$categoryLabel.'. Reason: '.$reason.' Case '.$case->case_number.'. Corrective action: '.$correctiveAction,
                'reference_type' => Product::class,
                'reference_id' => $product->id,
                'action_url' => $actionUrl,
            ]);
        }

        $emailSent = false;
        if ($sellerUser && filled($sellerUser->email)) {
            try {
                Mail::to($sellerUser->email)->send(new SellerProductWarning(
                    $product,
                    $case,
                    $categoryLabel,
                    $reason,
                    $correctiveAction,
                    $evidence,
                    $actionUrl,
                ));
                $emailSent = true;
            } catch (\Throwable $exception) {
                Log::warning('Product warning email could not be sent.', [
                    'product_id' => $product->id,
                    'seller_user_id' => $sellerUser->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return back()->with('status', $emailSent
            ? 'Warning recorded. The Seller was notified in-app and by email.'
            : 'Warning recorded. The Seller was notified in-app; email delivery was unavailable.');
    }

    public function updateDispute(Request $request, Dispute $dispute, ReturnRefundWorkflowService $workflow): RedirectResponse
    {
        $adminAction = $request->input('admin_action');
        $data = $request->validate([
            'status' => ['required', 'string', 'in:OPEN,UNDER_REVIEW,RESOLVED,REJECTED'],
            'resolution' => [in_array($request->input('status'), ['RESOLVED', 'REJECTED'], true) || in_array($adminAction, ['RETURN_TO_SELLER_REFUND', 'REJECT_ESCALATION'], true) ? 'required' : 'nullable', 'string', 'max:5000'],
            'admin_action' => ['nullable', 'string', Rule::in(['RETURN_TO_SELLER_REFUND', 'REJECT_ESCALATION'])],
        ]);
        abort_unless(
            ($adminAction === null)
                || ($adminAction === 'RETURN_TO_SELLER_REFUND' && $data['status'] === 'RESOLVED')
                || ($adminAction === 'REJECT_ESCALATION' && $data['status'] === 'REJECTED'),
            422,
            'Choose a valid Admin decision for this dispute.',
        );
        $resolution = trim((string) ($data['resolution'] ?? ''));
        $isRefundRequired = $adminAction === 'RETURN_TO_SELLER_REFUND';
        $isEscalationRejected = $adminAction === 'REJECT_ESCALATION';
        $dispute->update([
            'assigned_admin_user_id' => $request->user()->id,
            'status' => $data['status'],
            'resolution' => $resolution ?: null,
            'resolved_at' => in_array($data['status'], ['RESOLVED', 'REJECTED'], true) ? now() : null,
        ]);
        $dispute->load(['returnRefundRequest', 'sellerReturnRefundRequest.request', 'sellerReturnRefundRequest.sellerOrder.sellerProfile.user']);
        if ($dispute->returnRefundRequest) {
            $dispute->returnRefundRequest->update([
                'status' => match ($data['status']) {
                    'UNDER_REVIEW' => 'UNDER_REVIEW',
                    'RESOLVED' => 'RESOLVED',
                    'REJECTED' => 'REJECTED',
                    default => 'REQUEST_SUBMITTED',
                },
            ]);
        }

        if ($dispute->sellerReturnRefundRequest) {
            $sellerRequest = $dispute->sellerReturnRefundRequest;
            $from = $sellerRequest->status;
            $sellerStatus = $isRefundRequired
                ? SellerReturnRefundRequest::STATUS_ADMIN_REFUND_REQUIRED
                : ($isEscalationRejected
                    ? SellerReturnRefundRequest::STATUS_REJECTED
                    : (in_array($data['status'], ['RESOLVED', 'REJECTED'], true)
                        ? SellerReturnRefundRequest::STATUS_DISPUTE_RESOLVED
                        : SellerReturnRefundRequest::STATUS_ESCALATED));
            $sellerRequest->update(array_filter([
                'status' => $sellerStatus,
                'approved_amount' => $isRefundRequired ? $sellerRequest->requested_amount : ($isEscalationRejected ? null : $sellerRequest->approved_amount),
                'reviewed_by_user_id' => $isRefundRequired || $isEscalationRejected ? $request->user()->id : $sellerRequest->reviewed_by_user_id,
                'reviewed_at' => $isRefundRequired || $isEscalationRejected ? now() : $sellerRequest->reviewed_at,
                'decision_reason' => $resolution ?: 'Admin updated the escalated dispute.',
            ], static fn (mixed $value): bool => $value !== null));
            $workflow->recordEvent(
                $sellerRequest->request,
                $isRefundRequired ? 'ADMIN_REFUND_REQUIRED' : ($isEscalationRejected ? 'ADMIN_ESCALATION_REJECTED' : 'ADMIN_DECISION_RECORDED'),
                $request->user(),
                $sellerRequest,
                $from,
                $sellerRequest->status,
                $resolution ?: 'Admin updated the escalated dispute.',
                ['dispute_id' => $dispute->id, 'dispute_status' => $data['status'], 'admin_action' => $adminAction],
            );
            $workflow->syncParentStatus($sellerRequest->request);

            foreach ([$sellerRequest->request->buyer_user_id, $sellerRequest->sellerOrder?->sellerProfile?->user_id] as $recipientId) {
                if (! $recipientId) {
                    continue;
                }
                $isBuyer = $recipientId === $sellerRequest->request->buyer_user_id;
                Notification::create([
                    'user_id' => $recipientId,
                    'type' => 'RETURN_REFUND_ESCALATION',
                    'title' => $isRefundRequired
                        ? ($isBuyer ? 'Admin approved your refund appeal' : 'Admin requires this refund')
                        : ($isEscalationRejected ? 'Admin rejected the refund appeal' : 'Admin updated a refund escalation'),
                    'message' => $isRefundRequired
                        ? ($isBuyer
                            ? 'Admin returned request '.$sellerRequest->request->request_number.' to the Seller and required a refund of PHP '.number_format((float) $sellerRequest->approved_amount, 2).'.'
                            : 'Admin returned request '.$sellerRequest->request->request_number.' to you and requires the approved amount to be refunded to the Buyer.')
                        : ($isEscalationRejected
                            ? 'Admin rejected the escalation for request '.$sellerRequest->request->request_number.'.'
                            : 'Admin recorded a '.$data['status'].' decision for request '.$sellerRequest->request->request_number.'.'),
                    'reference_type' => Dispute::class,
                    'reference_id' => $dispute->id,
                    'action_url' => $isBuyer
                        ? route('buyer.returns.show', $sellerRequest)
                        : route('seller.orders.refunds.show', $sellerRequest),
                ]);
            }
        }

        if ($dispute->openedBy && in_array($data['status'], ['RESOLVED', 'REJECTED'], true)) {
            Notification::create([
                'user_id' => $dispute->opened_by_user_id,
                'type' => 'RETURN_REFUND',
                'title' => $isRefundRequired ? 'Refund appeal approved' : ($data['status'] === 'RESOLVED' ? 'Return / refund request resolved' : 'Return / refund request declined'),
                'message' => 'Request '.$dispute->dispute_number.' was '.$data['status'].'. '.$resolution,
                'reference_type' => Dispute::class,
                'reference_id' => $dispute->id,
                'action_url' => $dispute->order ? route('buyer.orders.show', $dispute->order) : null,
            ]);
        }

        return back()->with('status', $isRefundRequired
            ? 'Escalation returned to the Seller. The Seller is required to send the approved GCash refund.'
            : ($isEscalationRejected ? 'Refund escalation rejected and recorded.' : 'Dispute updated.'));
    }

    public function refundPayoutProof(Request $request, RefundPayout $payout, RefundProofStorageService $proofs)
    {
        $payout->load('sellerRequest.dispute');
        abort_unless($payout->sellerRequest?->dispute, 404);
        AuditLog::create(['actor_user_id' => $request->user()->id, 'event' => 'REFUND_PROOF_VIEWED', 'auditable_type' => RefundPayout::class, 'auditable_id' => $payout->id, 'new_values' => ['scope' => 'admin'], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response($proofs->read($payout->proof_disk, $payout->proof_path), 200, [
            'Content-Type' => $payout->proof_mime ?: 'image/webp',
            'Content-Disposition' => 'inline; filename="refund-proof.webp"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function refundEvidence(Request $request, ReturnRefundEvidence $evidence, RefundProofStorageService $proofs)
    {
        $evidence->load(['sellerRequest.dispute', 'request.sellerRequests.dispute']);
        $belongsToEscalatedChild = $evidence->sellerRequest?->dispute !== null;
        $belongsToEscalatedParent = $evidence->request?->sellerRequests
            ->contains(fn ($sellerRequest): bool => $sellerRequest->dispute !== null);
        abort_unless($belongsToEscalatedChild || $belongsToEscalatedParent, 404);
        AuditLog::create(['actor_user_id' => $request->user()->id, 'event' => 'REFUND_EVIDENCE_VIEWED', 'auditable_type' => ReturnRefundEvidence::class, 'auditable_id' => $evidence->id, 'new_values' => ['scope' => 'admin'], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response($proofs->read($evidence->disk, $evidence->file_path), 200, [
            'Content-Type' => $evidence->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="refund-evidence"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $keys = ['registrations', 'risk', 'finance', 'messages'];
        $data = $request->validate(collect($keys)->mapWithKeys(fn (string $key): array => [
            $key => ['nullable', 'boolean'],
        ])->all());
        $preferences = collect($keys)->mapWithKeys(fn (string $key): array => [
            $key => (bool) ($data[$key] ?? false),
        ])->all();

        $request->user()->forceFill(['notification_preferences' => $preferences])->save();

        return back()->with('status', 'Notification preferences saved.');
    }

    public function exportProducts(Request $request, AdminReportPdfService $pdf)
    {
        $status = $this->productStatus($request->query('status'));
        $search = $this->productSearch($request->query('search'));
        $products = $this->productQuery($status, $search)->get();
        $rows = $products->map(fn (Product $product): array => $this->productExportRow($product))->all();
        $exportStats = [
            'total' => $products->count(),
            'active' => $products->where('status', 'ACTIVE')->count(),
            'suspended' => $products->where('status', 'SUSPENDED')->count(),
            'flagged' => $products->filter(fn (Product $product): bool => $product->complianceCases->isNotEmpty())->count(),
        ];

        return $this->pdfDownload(
            $pdf->render([
                'title' => 'Products catalog report',
                'description' => 'Canonical product records and unresolved compliance indicators.',
                'generated_at' => now()->format('M j, Y g:i A T'),
                'generated_by' => $request->user()?->name ?: 'Authorized Admin',
                'filters' => [
                    ['label' => 'Search', 'value' => $search ?: 'All products'],
                    ['label' => 'Status', 'value' => $status ?: 'All statuses'],
                ],
                'summary' => $this->productSummaryCards($exportStats),
                'table_title' => 'Product detail',
                'columns' => [
                    ['label' => 'ID', 'width' => 32],
                    ['label' => 'Product', 'width' => 110],
                    ['label' => 'Seller / shop', 'width' => 100],
                    ['label' => 'Product category', 'width' => 85],
                    ['label' => 'Registered category', 'width' => 90],
                    ['label' => 'Stock', 'width' => 35],
                    ['label' => 'Status', 'width' => 52],
                    ['label' => 'Flagged', 'width' => 36],
                ],
                'rows' => $rows,
                'empty_message' => 'No products match the selected filter.',
                'footnote' => 'Flagged means the product has at least one unresolved OPEN or UNDER_REVIEW compliance case.',
            ]),
            'likhae-admin-products-'.now()->format('Ymd-His').'.pdf',
        );
    }

    public function exportFinance(Request $request, AdminReportPdfService $pdf)
    {
        $commissions = CommissionTransaction::query()
            ->with(['sellerOrder.sellerProfile'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
        $settled = $commissions->where('status', 'SETTLED')->sum(fn ($commission): float => (float) $commission->commission_amount);
        $pending = $commissions->where('status', 'PENDING')->sum(fn ($commission): float => (float) $commission->commission_amount);
        $rows = $commissions->map(fn (CommissionTransaction $commission): array => [
            $commission->sellerOrder?->seller_order_number ?: '-',
            $commission->sellerOrder?->sellerProfile?->business_name ?: '-',
            number_format((float) $commission->commission_rate * 100, 1).'%',
            'PHP '.number_format((float) $commission->commissionable_amount, 2),
            'PHP '.number_format((float) $commission->commission_amount, 2),
            str($commission->status)->replace('_', ' ')->headline(),
        ])->all();

        return $this->pdfDownload(
            $pdf->render([
                'title' => 'Finance commission report',
                'description' => 'Platform commission transactions using the existing 10% commission rule.',
                'generated_at' => now()->format('M j, Y g:i A T'),
                'generated_by' => $request->user()?->name ?: 'Authorized Admin',
                'filters' => [['label' => 'Scope', 'value' => 'All commission transactions']],
                'summary' => [
                    ['label' => 'Settled commission', 'value' => 'PHP '.number_format($settled, 2)],
                    ['label' => 'Pending / accrued', 'value' => 'PHP '.number_format($pending, 2)],
                    ['label' => 'Transactions', 'value' => number_format($commissions->count())],
                ],
                'table_title' => 'Commission detail',
                'columns' => [
                    ['label' => 'Order reference', 'width' => 90],
                    ['label' => 'Seller', 'width' => 110],
                    ['label' => 'Rate', 'width' => 56],
                    ['label' => 'Calculation base', 'width' => 92],
                    ['label' => 'Commission', 'width' => 92],
                    ['label' => 'Status', 'width' => 100],
                ],
                'rows' => $rows,
                'empty_message' => 'No commission transactions found.',
                'footnote' => 'Pending / accrued commission is not classified as settled revenue.',
            ]),
            'likhae-admin-finance-'.now()->format('Ymd-His').'.pdf',
        );
    }

    public function exportReport(Request $request, AdminReportPdfService $pdf)
    {
        $filters = $this->reportFilters($request);
        $salesExpression = $this->reportItemSalesExpression();
        $commissionExpression = $this->reportAllocatedCommissionExpression();
        $baseItems = $this->reportItemsQuery($filters);
        $summary = (clone $baseItems)->selectRaw("\n            COALESCE(SUM($salesExpression), 0) AS sales,\n            COALESCE(SUM($commissionExpression), 0) AS commission,\n            COALESCE(SUM(oi.quantity), 0) AS units_sold,\n            COUNT(DISTINCT so.id) AS order_count\n        ")->first();
        $items = (clone $baseItems)
            ->join('orders as o', 'o.id', '=', 'so.order_id')
            ->leftJoin('users as buyer', 'buyer.id', '=', 'o.buyer_user_id')
            ->select([
                'so.seller_order_number',
                'so.status as order_status',
                'so.created_at as order_date',
                'sp.business_name as shop',
                'oi.product_name',
                'oi.sku',
                'c.name as category',
                'parent_category.name as parent_category',
                'oi.quantity',
                'ct.status as commission_status',
                'buyer.first_name as buyer_first_name',
                'buyer.middle_initial as buyer_middle_initial',
                'buyer.last_name as buyer_last_name',
            ])
            ->selectRaw("$salesExpression AS sales, $commissionExpression AS commission")
            ->orderByDesc('so.created_at')
            ->orderByDesc('oi.id');
        $rows = $items->cursor()->map(function ($item): array {
            $buyer = collect([$item->buyer_first_name, $item->buyer_middle_initial, $item->buyer_last_name])->filter()->implode(' ');
            $category = $item->parent_category
                ? $item->parent_category.' / '.($item->category ?: 'Uncategorized')
                : ($item->category ?: 'Uncategorized');

            return [
                $item->seller_order_number,
                $buyer ?: '-',
                $item->shop ?: '-',
                $item->product_name.($item->sku ? ' | SKU '.$item->sku : ''),
                $category,
                number_format((int) $item->quantity),
                'PHP '.number_format((float) $item->sales, 2),
                'PHP '.number_format((float) $item->commission, 2).' ('.($item->commission_status ? str($item->commission_status)->headline() : 'Not calculated').')',
                str($item->order_status)->replace('_', ' ')->headline(),
                $item->order_date ? \Illuminate\Support\Carbon::parse($item->order_date)->format('M j, Y H:i') : '-',
            ];
        })->all();

        return $this->pdfDownload(
            $pdf->render([
                'title' => 'Sales and commission report',
                'description' => 'Filtered marketplace sales, order-item allocation, and platform commission.',
                'orientation' => 'landscape',
                'generated_at' => now()->format('M j, Y g:i A T'),
                'generated_by' => $request->user()?->name ?: 'Authorized Admin',
                'filters' => $this->reportFilterSummary($filters),
                'summary' => [
                    ['label' => 'Item sales', 'value' => 'PHP '.number_format((float) ($summary->sales ?? 0), 2)],
                    ['label' => 'Commission', 'value' => 'PHP '.number_format((float) ($summary->commission ?? 0), 2)],
                    ['label' => 'Units sold', 'value' => number_format((int) ($summary->units_sold ?? 0))],
                    ['label' => 'Seller orders', 'value' => number_format((int) ($summary->order_count ?? 0))],
                ],
                'table_title' => 'Filtered transaction detail',
                'columns' => [
                    ['label' => 'Seller order', 'width' => 72],
                    ['label' => 'Buyer', 'width' => 70],
                    ['label' => 'Shop', 'width' => 80],
                    ['label' => 'Product / SKU', 'width' => 115],
                    ['label' => 'Category', 'width' => 85],
                    ['label' => 'Qty', 'width' => 32],
                    ['label' => 'Item sales', 'width' => 72],
                    ['label' => 'Commission', 'width' => 80],
                    ['label' => 'Status', 'width' => 62],
                    ['label' => 'Date', 'width' => 52],
                ],
                'rows' => $rows,
                'empty_message' => 'No sales match the selected report filters.',
                'footnote' => 'Item sales exclude shipping and allocate seller voucher discounts by item share. Void commissions are excluded.',
            ]),
            'likhae-admin-sales-commission-'.now()->format('Ymd-His').'.pdf',
        );
    }

    public function exportAuditLogs(Request $request, AdminReportPdfService $pdf)
    {
        $logs = AuditLog::query()
            ->with('actor')
            ->latest('created_at')
            ->latest('id')
            ->limit(1000)
            ->get();
        $rows = $logs->map(function (AuditLog $log): array {
            $recordType = $log->auditable_type ? class_basename($log->auditable_type) : '-';
            $reference = $log->auditable_id ? $recordType.' #'.$log->auditable_id : $recordType;

            return [
                str($log->event)->replace(['.', '_'], ' ')->headline(),
                $log->actor?->name ?: 'System',
                $reference,
                $log->created_at?->format('M j, Y g:i A') ?: '-',
            ];
        })->all();

        return $this->pdfDownload(
            $pdf->render([
                'title' => 'Audit log report',
                'description' => 'Administrative activity history for operational review.',
                'generated_at' => now()->format('M j, Y g:i A T'),
                'generated_by' => $request->user()?->name ?: 'Authorized Admin',
                'filters' => [['label' => 'Record limit', 'value' => 'Most recent 1,000 entries']],
                'summary' => [
                    ['label' => 'Entries included', 'value' => number_format($logs->count())],
                ],
                'table_title' => 'Audit events',
                'columns' => [
                    ['label' => 'Event', 'width' => 145],
                    ['label' => 'Actor', 'width' => 100],
                    ['label' => 'Record / reference', 'width' => 170],
                    ['label' => 'Timestamp', 'width' => 125],
                ],
                'rows' => $rows,
                'empty_message' => 'No audit events found.',
                'footnote' => 'Private documents, passwords, tokens, and unnecessary personal data are excluded from this export.',
            ]),
            'likhae-admin-audit-logs-'.now()->format('Ymd-His').'.pdf',
        );
    }

    private function categorySearch(mixed $search): string
    {
        if (! is_string($search)) {
            return '';
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($search));

        return is_string($normalized) ? $normalized : trim($search);
    }

    private function normalizeCategoryName(mixed $name): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $name));

        return is_string($normalized) ? $normalized : trim((string) $name);
    }

    private function categoryNameTaken(string $name, ?int $parentId, ?int $ignoreId = null): bool
    {
        return Category::query()
            ->whereNull('archived_at')
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($this->normalizeCategoryName($name))])
            ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parentId))
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '<>', $ignoreId))
            ->exists();
    }

    private function categoryStatusMessage(Category $category, bool $active): string
    {
        if ($active) {
            return $category->parent_id === null ? 'Main category activated.' : 'Subcategory activated.';
        }

        $impact = collect([
            $category->children()->count() > 0 ? $category->children()->count().' subcategories' : null,
            $category->products()->count() > 0 ? $category->products()->count().' products' : null,
            $category->sellerProfiles()->count() > 0 ? $category->sellerProfiles()->count().' Seller registrations' : null,
        ])->filter()->implode(', ');

        return ($category->parent_id === null ? 'Main category deactivated.' : 'Subcategory deactivated.')
            .($impact !== '' ? ' Existing records remain assigned; impact: '.$impact.'.' : ' Existing records remain unchanged.');
    }

    private function productStatus(mixed $status): ?string
    {
        if (! is_string($status)) {
            return null;
        }

        $status = strtoupper(trim($status));

        return in_array($status, self::PRODUCT_STATUSES, true) ? $status : null;
    }

    private function productSearch(mixed $search): ?string
    {
        if (! is_string($search)) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($search));
        $normalized = is_string($normalized) ? $normalized : trim($search);

        return $normalized !== '' ? $normalized : null;
    }

    private function productQuery(?string $status = null, ?string $search = null)
    {
        return Product::query()
            ->with([
                'sellerProfile.primaryCategory.parent',
                'sellerProfile.primaryCategory.children',
                'category.parent',
                'variants',
                'complianceCases' => fn ($query) => $query->whereIn('status', self::UNRESOLVED_COMPLIANCE_STATUSES),
            ])
            ->whereIn('products.status', self::PRODUCT_STATUSES)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($search, function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $tokens = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];

                $query->where(function ($searchQuery) use ($like, $search, $tokens): void {
                    $searchQuery
                        ->where('products.name', 'like', $like)
                        ->orWhereHas('sellerProfile', function ($sellerQuery) use ($like, $tokens): void {
                            $sellerQuery
                                ->where('business_name', 'like', $like)
                                ->orWhereHas('user', function ($userQuery) use ($like, $tokens): void {
                                    $userQuery->where(function ($nameQuery) use ($like, $tokens): void {
                                        $nameQuery
                                            ->where('first_name', 'like', $like)
                                            ->orWhere('middle_initial', 'like', $like)
                                            ->orWhere('last_name', 'like', $like)
                                            ->orWhere('name_extension', 'like', $like);

                                        if (count($tokens) > 1) {
                                            $nameQuery->orWhere(function ($fullNameQuery) use ($tokens): void {
                                                foreach ($tokens as $token) {
                                                    $tokenLike = '%'.$token.'%';
                                                    $fullNameQuery->where(function ($tokenQuery) use ($tokenLike): void {
                                                        $tokenQuery
                                                            ->where('first_name', 'like', $tokenLike)
                                                            ->orWhere('middle_initial', 'like', $tokenLike)
                                                            ->orWhere('last_name', 'like', $tokenLike)
                                                            ->orWhere('name_extension', 'like', $tokenLike);
                                                    });
                                                }
                                            });
                                        }
                                    });
                                });
                        })
                        ->orWhereHas('variants', fn ($variantQuery) => $variantQuery->where('sku', 'like', $like));

                    if (ctype_digit($search)) {
                        $searchQuery->orWhere('products.id', (int) $search);
                    }
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private function complianceCasesQuery(Request $request)
    {
        $search = $this->complianceSearch($request->query('search'));
        $status = $this->complianceStatus($request->query('status'));

        return SellerComplianceCase::query()
            ->with([
                'sellerProfile.user',
                'product',
                'openedByAdmin',
                'actions.performedBy',
            ])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($search, function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function ($searchQuery) use ($like): void {
                    $searchQuery
                        ->where('case_number', 'like', $like)
                        ->orWhere('violation_type', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhereHas('product', fn ($productQuery) => $productQuery->where('name', 'like', $like))
                        ->orWhereHas('sellerProfile', function ($sellerQuery) use ($like): void {
                            $sellerQuery
                                ->where('business_name', 'like', $like)
                                ->orWhereHas('user', function ($userQuery) use ($like): void {
                                    $userQuery->where(function ($nameQuery) use ($like): void {
                                        $nameQuery
                                            ->where('name', 'like', $like)
                                            ->orWhere('first_name', 'like', $like)
                                            ->orWhere('middle_initial', 'like', $like)
                                            ->orWhere('last_name', 'like', $like)
                                            ->orWhere('name_extension', 'like', $like);
                                    });
                                });
                        });
                });
            })
            ->orderByRaw("CASE status WHEN 'OPEN' THEN 0 WHEN 'UNDER_REVIEW' THEN 1 WHEN 'RESOLVED' THEN 2 WHEN 'CLOSED' THEN 3 ELSE 4 END")
            ->orderByDesc('opened_at')
            ->orderByDesc('id');
    }

    private function complianceSearch(mixed $search): ?string
    {
        if (! is_string($search)) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($search));
        $normalized = is_string($normalized) ? $normalized : trim($search);

        return $normalized !== '' ? $normalized : null;
    }

    private function complianceStatus(mixed $status): ?string
    {
        if (! is_string($status)) {
            return null;
        }

        $status = strtoupper(trim($status));

        return in_array($status, ['OPEN', 'UNDER_REVIEW', 'RESOLVED', 'CLOSED'], true) ? $status : null;
    }

    /** @return array<string, int> */
    private function productStats(): array
    {
        $statusCounts = Product::query()
            ->whereIn('status', self::PRODUCT_STATUSES)
            ->select('status')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) Product::query()->whereIn('status', self::PRODUCT_STATUSES)->count(),
            'active' => (int) ($statusCounts['ACTIVE'] ?? 0),
            'suspended' => (int) ($statusCounts['SUSPENDED'] ?? 0),
            'flagged' => (int) SellerComplianceCase::query()
                ->whereIn('status', self::UNRESOLVED_COMPLIANCE_STATUSES)
                ->whereNotNull('product_id')
                ->whereHas('product', fn ($query) => $query->whereIn('status', self::PRODUCT_STATUSES))
                ->distinct()
                ->count('product_id'),
        ];
    }

    /** @param array<string, int> $stats */
    private function productSummaryCards(array $stats): array
    {
        return [
            ['label' => 'Monitored products', 'value' => number_format($stats['total'])],
            ['label' => 'Active products', 'value' => number_format($stats['active'])],
            ['label' => 'Suspended products', 'value' => number_format($stats['suspended'])],
            ['label' => 'Flagged for review', 'value' => number_format($stats['flagged'])],
        ];
    }

    /** @return array<int, string> */
    private function productExportRow(Product $product): array
    {
        return [
            '#'.$product->id,
            $product->name,
            $product->sellerProfile?->business_name ?: '-',
            $this->categoryLabel($product->category),
            $this->categoryLabel($product->sellerProfile?->primaryCategory),
            number_format((int) $product->variants->sum('stock')),
            str($product->status)->replace('_', ' ')->headline(),
            $product->complianceCases->isNotEmpty() ? 'Yes' : 'No',
        ];
    }

    private function categoryLabel(?Category $category): string
    {
        if (! $category) {
            return 'Uncategorized';
        }

        return $category->parent?->name ? $category->parent->name.' / '.$category->name : $category->name;
    }

    private function productCategoryMismatch(Product $product): bool
    {
        $registered = $product->sellerProfile?->primaryCategory;
        if (! $registered || ! $product->category_id) {
            return false;
        }

        // Seller product creation permits the registered primary category and
        // any active direct subcategory. Keep Admin's compliance check aligned
        // with that same eligibility rule; a warning must not be raised for a
        // valid primary-category listing merely because subcategories exist.
        $allowedIds = collect();
        if ($registered->is_active && $registered->archived_at === null) {
            $allowedIds->push($registered->id);
        }
        $allowedIds = $allowedIds->merge(
            $registered->children->where('is_active', true)->filter(fn (Category $category): bool => $category->archived_at === null)->pluck('id')
        );

        return ! $allowedIds->contains((int) $product->category_id);
    }

    /** @param array<string, mixed>|null $oldValues @param array<string, mixed>|null $newValues */
    private function audit(
        Request $request,
        string $event,
        string $auditableType,
        int $auditableId,
        ?array $oldValues,
        ?array $newValues,
    ): void {
        AuditLog::create([
            'actor_user_id' => $request->user()?->id,
            'event' => $event,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function reportFilterSummary(array $filters): array
    {
        return [
            ['label' => 'Shop', 'value' => ($filters['seller_profile_id'] ?? null) ? 'Seller profile #'.$filters['seller_profile_id'] : 'All shops'],
            ['label' => 'Category', 'value' => ($filters['category_id'] ?? null) ? 'Category #'.$filters['category_id'] : 'All categories'],
            ['label' => 'Product', 'value' => ($filters['product_id'] ?? null) ? 'Product #'.$filters['product_id'] : 'All products'],
            ['label' => 'Order status', 'value' => ($filters['status'] ?? null) ? str($filters['status'])->replace('_', ' ')->headline() : 'All statuses'],
            ['label' => 'Date range', 'value' => ($filters['date_from'] ?? 'Any').' to '.($filters['date_to'] ?? 'Any')],
            ['label' => 'Rank', 'value' => str($filters['sort'] ?? 'sales')->replace('_', ' ')->headline()],
        ];
    }

    private function pdfDownload(string $content, string $filename)
    {
        return Response::make($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($content),
        ]);
    }
}
