<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Admin\Notification;
use App\Models\Admin\SellerComplianceCase;
use App\Models\Buyer\OrderItem;
use App\Models\Buyer\Review;
use App\Models\Communication\Conversation;
use App\Models\Communication\Message;
use App\Models\Seller\Voucher;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\SellerPromotion;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\User;
use App\Services\Buyer\BuyerStoreNotificationService;
use App\Services\Communication\ConversationService;
use App\Services\Communication\ConversationMessageSender;
use App\Services\Communication\MessageAttachmentService;
use App\Services\Communication\ConversationContextPresenter;
use App\Services\Communication\SmartMessagingService;
use App\Services\Communication\SupportOverviewService;
use App\Services\Marketplace\VoucherRedemptionCounter;
use App\Services\Marketplace\VoucherDiscountCalculator;
use App\Services\Reports\WorkspaceExportPdfService;
use App\Services\Seller\SellerPromotionConflictService;
use App\Services\ReviewImageService;
use App\Support\FinancialRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;
use Illuminate\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SellerEngagementController extends Controller
{
    public function messages(Request $request, ConversationService $conversationService, SupportOverviewService $supportOverview): View
    {
        $conversationId = $request->integer('conversation') ?: null;
        $section = (string) $request->query('section', '');
        if (! in_array($section, ['overview', 'chats'], true)) {
            $section = $conversationId ? 'chats' : 'overview';
        }
        $conversations = $section === 'chats'
            ? $conversationService->workspaceThreads($request->user(), $conversationId)
            : collect();
        $contacts = collect();
        if ($section === 'chats') {
            $contacts = \App\Models\User::query()
                ->where('status', 'ACTIVE')
                ->whereKeyNot($request->user()->id)
                ->orderBy('first_name')
                ->limit(100)
                ->get(['id', 'first_name', 'middle_initial', 'last_name', 'name_extension', 'account_type']);
            $contacts = app(\App\Services\Communication\ConversationStartAuthorizationService::class)
                ->filterRecipients($request->user(), $contacts);
        }
        $selectedRequest = $request->integer('request') ?: null;
        $statusFilter = $request->query('status');
        $statusFilter = is_string($statusFilter) ? $statusFilter : null;
        $search = $request->query('q');
        $search = is_string($search) ? mb_substr(trim($search), 0, 120) : '';
        $supportOverviewReady = $supportOverview->schemaReady();
        $overview = $section === 'overview' && $supportOverviewReady
            ? $supportOverview->overview($request->user(), $statusFilter, $search, $selectedRequest)
            : null;
        $activeConversation = $section === 'chats' ? $conversations->firstWhere('id', $conversationId) : null;
        $activeRecipient = $activeConversation?->participants?->first(fn ($participant) => (int) $participant->id !== (int) $request->user()->id);
        $smartHelpBootstrap = $activeConversation && $activeRecipient?->isAccountType(User::TYPE_ADMIN)
            ? app(SmartMessagingService::class)->bootstrapForRequester($request->user(), $activeConversation)
            : null;

        return view('seller.messages', [
            'section' => $section,
            'supportOverview' => $overview,
            'supportOverviewReady' => $supportOverviewReady,
            'conversations' => $conversations,
            'contacts' => $contacts,
            'messageAttachmentsReady' => app(MessageAttachmentService::class)->available(),
            'messageReadRouteTemplate' => route('seller.messages.read', ['conversation' => '__conversation__']),
            'messageContext' => app(ConversationContextPresenter::class)->forUser(
                $request->user(),
                $section === 'chats' ? $conversations->firstWhere('id', $conversationId) : null,
            ),
            'supportStates' => $section === 'chats' ? app(SmartMessagingService::class)->statesFor($conversations) : [],
            'smartHelpBootstrap' => $smartHelpBootstrap,
        ]);
    }

    public function messagePreview(Request $request, ConversationService $conversationService): JsonResponse
    {
        if (! Schema::hasTable('messages')
            || ! Schema::hasTable('conversation_participants')
            || ! Schema::hasTable('conversations')) {
            return response()->json(['items' => [], 'unread_count' => 0])
                ->header('Cache-Control', 'no-store, private');
        }

        return response()->json($conversationService->headerPreviewFor($request->user()))
            ->header('Cache-Control', 'no-store, private');
    }

    public function stream(Request $request, ConversationService $conversationService): JsonResponse
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

    public function markConversationRead(Request $request, int $conversation, ConversationService $conversationService): JsonResponse
    {
        $thread = Conversation::query()
            ->whereKey($conversation)
            ->whereHas('participantRecords', fn ($query) => $query
                ->where('user_id', $request->user()->id)
                ->whereNull('left_at'))
            ->firstOrFail();
        app(\App\Services\Communication\ConversationAuthorizationService::class)->assertCanAccess($request->user(), $thread);
        $conversationService->markRead($thread, $request->user());

        return response()->json(['success' => true])->header('Cache-Control', 'no-store, private');
    }

    public function sendMessage(Request $request, ConversationService $conversationService, ConversationMessageSender $sender): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'recipient_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $data += $sender->validate($request);
        $recipient = \App\Models\User::query()->whereKey($data['recipient_user_id'])->where('status', \App\Models\User::STATUS_ACTIVE)->firstOrFail();
        $messages = $sender->sendMany($request, $data, $recipient, $conversationService);

        if ($request->expectsJson()) {
            $messagePayloads = array_map(
                fn (Message $message): array => $conversationService->messagePayload($message),
                $messages,
            );

            return response()->json([
                'success' => true,
                'message' => $messagePayloads[0] ?? null,
                'messages' => $messagePayloads,
            ]);
        }

        return back()->with('status', 'Message sent.');
    }

    public function messageAttachment(Request $request, Message $message, MessageAttachmentService $attachments): BinaryFileResponse
    {
        $file = $attachments->authorizedFile($request->user(), $message);
        $headers = [
            'Content-Type' => $file['mime'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];
        $response = $file['is_image']
            ? response()->file($file['path'], $headers)
            : response()->download($file['path'], $file['name'], $headers);

        return $response;
    }

    public function reviews(Request $request, ReviewImageService $reviewImages): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

        $filters = $this->reviewFilters($request, $seller);
        $reviews = $this->filteredReviews($seller, $filters)
            ->latest()
            ->paginate(15)
            ->withQueryString();
        $reviewImagesById = $reviewImages->photoUrlsFor($reviews->getCollection());
        $reviews->getCollection()->each(function (Review $review): void {
            $review->setAttribute('buyer_display_name', $this->maskedBuyerName($review->buyer?->name));
        });
        $products = Product::query()->where('seller_profile_id', $seller->id)->orderBy('name')->get(['id', 'name']);
        $variants = OrderItem::query()
            ->join('seller_orders', 'seller_orders.id', '=', 'order_items.seller_order_id')
            ->join('reviews', 'reviews.order_item_id', '=', 'order_items.id')
            ->where('seller_orders.seller_profile_id', $seller->id)
            ->whereNotNull('order_items.variant_description')
            ->where('order_items.variant_description', '<>', '')
            ->distinct()
            ->orderBy('order_items.variant_description')
            ->pluck('order_items.variant_description');

        return view('seller.reviews', compact('reviews', 'seller', 'reviewImagesById', 'products', 'variants', 'filters'));
    }

    public function replyReview(Request $request, Review $review): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $review->orderItem?->sellerOrder?->seller_profile_id === (int) $seller->id, 403);

        $data = $request->validate(['seller_reply' => ['required', 'string', 'max:2000']]);
        $review->update(['seller_reply' => $data['seller_reply'], 'seller_replied_at' => now()]);

        return back()->with('status', 'Review reply saved.');
    }

    public function denyReviewModeration(Review $review): never
    {
        abort(403);
    }

    public function exportReviews(Request $request, WorkspaceExportPdfService $pdf)
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);
        $filters = $this->reviewFilters($request, $seller);
        $rows = $this->filteredReviews($seller, $filters)
            ->orderBy('reviews.id')
            ->lazyById(500, 'reviews.id', 'id')
            ->map(fn (Review $review): array => [
                (string) $review->id,
                (string) ($review->orderItem?->product_name ?? $review->orderItem?->product?->name ?? 'Product'),
                (string) ($review->orderItem?->variant_description ?? ''),
                (string) $review->rating,
                $this->maskedBuyerName($review->buyer?->name),
                (string) ($review->comment ?? ''),
                (string) ($review->seller_reply ?? ''),
                (string) ($review->created_at?->toISOString() ?? ''),
            ]);
        $filename = 'likhae-seller-reviews-'.$seller->id.'-'.now()->format('Ymd-His').'.pdf';
        $filterLabels = [];
        foreach ([
            'rating' => 'Rating',
            'product_id' => 'Product ID',
            'variant' => 'Variant',
            'from_date' => 'From',
            'to_date' => 'To',
        ] as $key => $label) {
            if (isset($filters[$key])) {
                $filterLabels[$label] = (string) $filters[$key];
            }
        }

        return response($pdf->render([
            'workspace' => 'Seller Center',
            'title' => 'Product reviews',
            'description' => 'Filtered review records for '.$seller->business_name.'. Buyer names are masked and contact details are excluded.',
            'generated_at' => now()->format('Y-m-d H:i:s T'),
            'orientation' => 'landscape',
            'filters' => $filterLabels,
            'columns' => [
                ['label' => 'Review ID', 'width' => 0.07],
                ['label' => 'Product', 'width' => 0.16],
                ['label' => 'Variant', 'width' => 0.12],
                ['label' => 'Rating', 'width' => 0.07],
                ['label' => 'Buyer', 'width' => 0.12],
                ['label' => 'Comment', 'width' => 0.21],
                ['label' => 'Seller reply', 'width' => 0.16],
                ['label' => 'Created at', 'width' => 0.09],
            ],
            'rows' => $rows,
            'empty_message' => 'No reviews match the selected filters.',
        ]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** @return array<string, mixed> */
    private function reviewFilters(Request $request, SellerProfile $seller): array
    {
        return $request->validate([
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('seller_profile_id', $seller->id)],
            'variant' => ['nullable', 'string', 'max:500'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function filteredReviews(SellerProfile $seller, array $filters): Builder
    {
        $query = Review::query()
            ->whereHas('orderItem.sellerOrder', fn ($orders) => $orders->where('seller_profile_id', $seller->id))
            ->with(['orderItem.product', 'orderItem.sellerOrder', 'buyer'])
            ->when(isset($filters['rating']), fn ($reviews) => $reviews->where('rating', $filters['rating']))
            ->when(isset($filters['product_id']), fn ($reviews) => $reviews->whereHas('orderItem', fn ($items) => $items->where('product_id', $filters['product_id'])))
            ->when(isset($filters['variant']), fn ($reviews) => $reviews->whereHas('orderItem', fn ($items) => $items->where('variant_description', $filters['variant'])));

        foreach ([['from_date', '>='], ['to_date', '<=']] as [$dateField, $operator]) {
            if (! empty($filters[$dateField])) {
                $query->whereDate('reviews.created_at', $operator, $filters[$dateField]);
            }
        }

        return $query;
    }

    private function maskedBuyerName(?string $name): string
    {
        $name = preg_replace('/\\s+/u', '', trim((string) $name));
        $length = mb_strlen($name);

        if ($length === 0) {
            return 'Verified Buyer';
        }

        if ($length <= 4) {
            return mb_substr($name, 0, 1).str_repeat('*', 3);
        }

        return mb_substr($name, 0, 2).'****'.mb_substr($name, -2);
    }

    public function marketing(Request $request, SellerPromotionConflictService $promotionConflictService, \App\Services\Reports\SellerMarketingOverviewService $marketingOverviewService): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

        $now = now();
        $sections = ['product-discounts', 'flash-sales', 'vouchers', 'bundle-deals', 'buy-x-get-y', 'overview', 'special-offers'];
        $section = in_array($request->query('section'), $sections, true) ? $request->query('section') : 'product-discounts';
        if ($section === 'special-offers') {
            $section = 'bundle-deals';
        }
        $voucherProductTargetingReady = $this->voucherProductTargetingReady();
        $voucherVariantTargetingReady = Schema::hasColumn('vouchers', 'applies_to')
            && Schema::hasTable('voucher_variants');
        $sellerVoucherBudgetReady = Schema::hasColumn('vouchers', 'seller_discount_budget_total')
            && Schema::hasTable('financial_ledger_entries')
            && Schema::hasColumn('financial_ledger_entries', 'voucher_id')
            && Schema::hasColumn('financial_ledger_entries', 'order_item_id');
        $voucherVisibilityReady = Schema::hasColumn('vouchers', 'visibility');
        $vouchersQuery = $seller->vouchers()
            ->withCount(['sellerOrders' => fn ($query) => $query->where('status', '<>', 'CANCELLED')]);
        if ($voucherProductTargetingReady) {
            $vouchersQuery->with('eligibleProducts:id');
        }
        if ($voucherVariantTargetingReady) {
            $vouchersQuery->with(['eligibleVariants:id,product_id,sku,price,stock,flash_sale_price,flash_sale_allocation,flash_sale_sold', 'eligibleVariants.optionValues.option']);
        }
        $vouchers = $vouchersQuery->latest()->paginate(15);
        $vouchers->getCollection()->each(function (Voucher $voucher) use ($now): void {
            $used = (int) $voucher->seller_orders_count;
            $status = $voucher->requiresReview() ? 'Review required'
                : (! $voucher->is_active ? 'Paused'
                : ($voucher->starts_at && $voucher->starts_at->greaterThan($now) ? 'Upcoming'
                    : ($voucher->ends_at && $voucher->ends_at->lessThan($now) ? 'Expired'
                        : ($voucher->usage_limit !== null && $used >= (int) $voucher->usage_limit ? 'Usage exhausted' : 'Active'))));

            $voucher->setAttribute('redemption_count', $used);
            $voucher->setAttribute('remaining_capacity', $voucher->usage_limit === null
                ? null
                : max(0, (int) $voucher->usage_limit - $used));
            $voucher->setAttribute('marketing_status', $status);
            $voucher->setAttribute('seller_budget_summary', app(\App\Services\Finance\SellerVoucherBudgetService::class)->summary($voucher));
        });
        if (! $voucherProductTargetingReady) {
            $vouchers->getCollection()->each(function (Voucher $voucher): void {
                $voucher->setAttribute('applies_to', 'STORE');
                $voucher->setRelation('eligibleProducts', collect());
            });
        }

        $activeVoucherCount = $seller->vouchers()
            ->where('is_active', true)
            ->when(Schema::hasColumn('vouchers', 'requires_review'), fn ($query) => $query->where('requires_review', false))
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->where(fn ($query) => $query->whereNull('usage_limit')->orWhereRaw(
                'vouchers.usage_limit > (SELECT COUNT(*) FROM seller_orders WHERE seller_orders.voucher_id = vouchers.id AND seller_orders.status <> ?)',
                ['CANCELLED'],
            ))
            ->count();
        $totalRedemptions = DB::table('seller_orders')
            ->join('vouchers', 'vouchers.id', '=', 'seller_orders.voucher_id')
            ->where('vouchers.seller_profile_id', $seller->id)
            ->where('seller_orders.status', '<>', 'CANCELLED')
            ->distinct()
            ->count('seller_orders.order_id');

        $platformCampaignsReady = Schema::hasColumn('vouchers', 'platform_code_key');
        $platformCampaigns = collect();
        if ($platformCampaignsReady) {
            $platformCampaigns = Voucher::query()
                ->whereNull('seller_profile_id')
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                ->latest()
                ->get();
            $redemptionCounts = VoucherRedemptionCounter::countsFor($platformCampaigns);
            $platformCampaigns = $platformCampaigns
                ->filter(fn (Voucher $voucher): bool => ! $voucher->requiresReview()
                    && ($voucher->usage_limit === null
                        || $redemptionCounts->get($voucher->id, 0) < $voucher->usage_limit))
                ->filter(fn (Voucher $voucher): bool => app(\App\Services\Finance\PlatformCampaignBudgetService::class)->selectable($voucher))
                ->each(fn (Voucher $voucher) => $voucher->setAttribute('redemption_count', $redemptionCounts->get($voucher->id, 0)))
                ->values();
        }

        $eligibleProducts = $seller->products()
            ->where('status', 'ACTIVE')
            ->when($request->integer('product_id') > 0, fn ($query) => $query->whereKey($request->integer('product_id')))
            ->with(['variants' => fn ($query) => $query->where('is_active', true)->with('optionValues.option')])
            ->orderBy('name')
            ->get();
        $promotionConflicts = $promotionConflictService->forProducts($seller, $eligibleProducts);
        $promotionStorageReady = Schema::hasTable('seller_promotions')
            && Schema::hasTable('seller_promotion_components')
            && Schema::hasColumn('seller_promotions', 'reward_product_id')
            && Schema::hasColumn('seller_promotions', 'reward_discount_percent')
            && Schema::hasColumn('seller_promotions', 'max_redemptions')
            && Schema::hasColumn('seller_promotions', 'max_per_buyer')
            && Schema::hasColumn('seller_promotions', 'redemptions_used')
            && Schema::hasColumn('seller_promotion_components', 'choice_group');
        $promotionVariants = collect();
        $promotions = collect();
        $activeSpecialOffers = 0;
        $scheduledSpecialOffers = 0;
        if ($promotionStorageReady) {
            $promotionVariants = $eligibleProducts->flatMap(fn (Product $product) => $product->variants)
                ->sortBy(fn (ProductVariant $variant) => $variant->product->name.' '.$variant->description)
                ->values();
            $promotionQuery = $seller->promotions()
                ->with(['components.productVariant.product', 'buyVariant.product', 'rewardVariant.product', 'rewardProduct'])
                ->latest('id');
            if ($section === 'bundle-deals') {
                $promotionQuery->where('promotion_type', SellerPromotion::TYPE_BUNDLE);
            } elseif ($section === 'buy-x-get-y') {
                $promotionQuery->where('promotion_type', SellerPromotion::TYPE_BUY_X_GET_Y);
            }
            $promotions = $promotionQuery->paginate(15)->withQueryString();
            $activeSpecialOffers = $seller->promotions()
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
                ->count();
            $scheduledSpecialOffers = $seller->promotions()
                ->where('is_active', true)
                ->where('starts_at', '>', $now)
                ->count();
        }

        $flashSaleSchemaReady = Schema::hasColumn('products', 'flash_sale_starts_at')
            && Schema::hasColumn('products', 'flash_sale_ends_at')
            && Schema::hasColumn('products', 'flash_sale_is_paused')
            && Schema::hasColumn('products', 'flash_sale_limit_per_buyer')
            && Schema::hasColumn('products', 'flash_sale_campaign_key')
            && Schema::hasColumn('product_variants', 'flash_sale_price')
            && Schema::hasColumn('product_variants', 'flash_sale_allocation')
            && Schema::hasColumn('product_variants', 'flash_sale_sold')
            && Schema::hasColumn('order_items', 'flash_sale_quantity');
        $flashVariants = $eligibleProducts->flatMap(fn (Product $product) => $product->variants);
        $activeFlashSales = $flashSaleSchemaReady
            ? $flashVariants->filter(fn (ProductVariant $variant): bool => $variant->active_flash_sale_price !== null)->count()
            : 0;
        $scheduledFlashSales = $flashSaleSchemaReady
            ? $flashVariants->filter(fn (ProductVariant $variant): bool => $variant->flash_sale_price !== null
                && $variant->flash_sale_remaining > 0
                && $variant->product?->flash_sale_starts_at?->isFuture())->count()
            : 0;

        $hasDiscountSnapshot = Schema::hasColumn('order_items', 'seller_voucher_discount')
            && Schema::hasColumn('order_items', 'promotion_discount_amount')
            && Schema::hasColumn('order_items', 'discount_amount');
        $estimatedPromotionalDiscount = 0.0;
        $confirmedPromotionalDiscount = 0.0;
        $topPromotedProducts = collect();
        $topPromotedVariants = collect();
        if ($hasDiscountSnapshot) {
            $discountSql = 'COALESCE(order_items.discount_amount, 0) + COALESCE(order_items.promotion_discount_amount, 0) + COALESCE(order_items.seller_voucher_discount, 0)';
            $estimatedQuery = DB::table('order_items')
                ->join('seller_orders', 'seller_orders.id', '=', 'order_items.seller_order_id')
                ->where('seller_orders.seller_profile_id', $seller->id)
                ->where('seller_orders.status', '<>', 'CANCELLED');
            $estimatedPromotionalDiscount = (float) $estimatedQuery->sum(DB::raw($discountSql));

            if (Schema::hasTable('commission_transactions')) {
                $recognizedOrderIds = DB::table('commission_transactions')
                    ->whereNotNull('calculated_at')
                    ->whereIn('status', ['PENDING', 'SETTLED'])
                    ->select('seller_order_id');
                $confirmedQuery = DB::table('order_items')
                    ->join('seller_orders', 'seller_orders.id', '=', 'order_items.seller_order_id')
                    ->where('seller_orders.seller_profile_id', $seller->id)
                    ->where('seller_orders.status', '<>', 'CANCELLED')
                    ->whereIn('seller_orders.id', $recognizedOrderIds);
                $confirmedPromotionalDiscount = (float) (clone $confirmedQuery)->sum(DB::raw($discountSql));
                $topPromotedProducts = (clone $confirmedQuery)
                    ->select('order_items.product_id', 'order_items.product_name')
                    ->selectRaw('SUM(order_items.quantity) as units, SUM('.$discountSql.') as discount_total')
                    ->whereRaw('('.$discountSql.') > 0')
                    ->groupBy('order_items.product_id', 'order_items.product_name')
                    ->orderByDesc('discount_total')
                    ->limit(5)
                    ->get();
                if (Schema::hasColumn('order_items', 'product_variant_id')) {
                    $topPromotedVariants = (clone $confirmedQuery)
                        ->select('order_items.product_variant_id', 'order_items.product_name', 'order_items.variant_description')
                        ->selectRaw('SUM(order_items.quantity) as units, SUM('.$discountSql.') as discount_total')
                        ->whereRaw('('.$discountSql.') > 0')
                        ->groupBy('order_items.product_variant_id', 'order_items.product_name', 'order_items.variant_description')
                        ->orderByDesc('discount_total')
                        ->limit(5)
                        ->get();
                }
            }
        }
        $redemptionQuery = DB::table('seller_orders')
            ->where('seller_profile_id', $seller->id)
            ->whereNotNull('voucher_id')
            ->where('status', '<>', 'CANCELLED');
        $voucherRedemptions = $redemptionQuery->count();
        $flashUnitsSold = Schema::hasColumn('order_items', 'flash_sale_quantity')
            ? (int) DB::table('order_items')
                ->join('seller_orders', 'seller_orders.id', '=', 'order_items.seller_order_id')
                ->where('seller_orders.seller_profile_id', $seller->id)
                ->where('seller_orders.status', '<>', 'CANCELLED')
                ->sum('order_items.flash_sale_quantity')
            : 0;

        return view('seller.marketing', [
            'section' => $section,
            'seller' => $seller,
            'vouchers' => $vouchers,
            'eligibleProducts' => $eligibleProducts,
            'promotionConflicts' => $promotionConflicts,
            'voucherStats' => [
                'total' => $vouchers->total(),
                'active' => $activeVoucherCount,
                'redemptions' => $totalRedemptions,
                'capped' => $seller->vouchers()->whereNotNull('usage_limit')->count(),
                'scheduled' => $seller->vouchers()->where('is_active', true)->where('starts_at', '>', $now)->count(),
            ],
            'platformCampaigns' => $platformCampaigns,
            'platformCampaignsReady' => $platformCampaignsReady,
            'voucherProductTargetingReady' => $voucherProductTargetingReady,
            'voucherVariantTargetingReady' => $voucherVariantTargetingReady,
            'sellerVoucherBudgetReady' => $sellerVoucherBudgetReady,
            'voucherVisibilityReady' => $voucherVisibilityReady,
            'promotionStorageReady' => $promotionStorageReady,
            'promotionVariants' => $promotionVariants,
            'promotions' => $promotions,
            'activeSpecialOffers' => $activeSpecialOffers,
            'scheduledSpecialOffers' => $scheduledSpecialOffers,
            'flashSaleSchemaReady' => $flashSaleSchemaReady,
            'activeFlashSales' => $activeFlashSales,
            'scheduledFlashSales' => $scheduledFlashSales,
            'overview' => [
                'voucher_redemptions' => $voucherRedemptions,
                'flash_units_sold' => $flashUnitsSold,
                'estimated_discount' => $estimatedPromotionalDiscount,
                'confirmed_discount' => $confirmedPromotionalDiscount,
                'pending_discount' => max(0, $estimatedPromotionalDiscount - $confirmedPromotionalDiscount),
                'snapshot_ready' => $hasDiscountSnapshot && Schema::hasTable('commission_transactions'),
                'top_products' => $topPromotedProducts,
                'top_variants' => $topPromotedVariants,
            ],
            'marketingOverview' => $section === 'overview'
                ? $marketingOverviewService->forSeller((int) $seller->id)
                : [],
        ]);
    }

    public function storeCampaign(Request $request, SellerPromotionConflictService $promotionConflictService): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

        $data = $this->validatedMarketingVoucher($request, $seller, $promotionConflictService);
        $productIds = $data['product_ids'] ?? [];
        $variantIds = $data['variant_ids'] ?? [];
        unset($data['product_ids'], $data['variant_ids']);

        DB::transaction(function () use ($data, $productIds, $variantIds, $seller): void {
            $voucher = Voucher::create($data + [
                'seller_profile_id' => $seller->id,
                'is_active' => true,
            ]);
            if ($this->voucherProductTargetingReady()) {
                $voucher->eligibleProducts()->sync($productIds);
            }
            if ($this->voucherVariantTargetingReady()) {
                $voucher->eligibleVariants()->sync($variantIds);
            }
        });

        return back()->with('status', 'Voucher saved.');
    }

    public function updateCampaign(Request $request, Voucher $voucher, SellerPromotionConflictService $promotionConflictService): RedirectResponse
    {
        $seller = $this->sellerForOwnedVoucher($request, $voucher);
        $data = $this->validatedMarketingVoucher($request, $seller, $promotionConflictService, $voucher);
        $productIds = $data['product_ids'] ?? [];
        $variantIds = $data['variant_ids'] ?? [];
        unset($data['product_ids'], $data['variant_ids']);
        DB::transaction(function () use ($voucher, $data, $productIds, $variantIds): void {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            $newBudget = $data['seller_discount_budget_total'] ?? null;
            if ($locked->seller_discount_budget_total !== null) {
                $summary = app(\App\Services\Finance\SellerVoucherBudgetService::class)->summary($locked, lockLedgerRows: true);
                if ($newBudget === null && $summary['reserved_cents'] + $summary['spent_cents'] > 0) {
                    throw ValidationException::withMessages([
                        'seller_discount_budget_total' => 'This voucher already has reserved or confirmed discounts, so its Seller budget cannot be removed.',
                    ]);
                }
                if ($newBudget !== null && FinancialRules::cents($newBudget) < $summary['reserved_cents'] + $summary['spent_cents']) {
                    throw ValidationException::withMessages([
                        'seller_discount_budget_total' => 'The budget cannot be lower than reserved plus confirmed Seller voucher discounts.',
                    ]);
                }
            }
            $locked->update($data);
            if ($this->voucherProductTargetingReady()) {
                $locked->eligibleProducts()->sync($productIds);
            }
            if ($this->voucherVariantTargetingReady()) {
                $locked->eligibleVariants()->sync($variantIds);
            }
        });
        app(BuyerStoreNotificationService::class)->sellerVoucherChanged((int) $voucher->id);

        return back()->with('status', 'Voucher updated. Existing redemptions and order totals are unchanged.');
    }

    private function validatedMarketingVoucher(Request $request, SellerProfile $seller, SellerPromotionConflictService $promotionConflictService, ?Voucher $voucher = null): array
    {
        $request->merge([
            'name' => trim((string) $request->input('name', '')),
            'code' => trim((string) $request->input('code', '')),
            'applies_to' => strtoupper(trim((string) $request->input('applies_to', 'STORE'))),
            'visibility' => strtoupper(trim((string) $request->input('visibility', 'PUBLIC'))),
        ]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:150'],
            'discount_type' => ['required', 'string', 'in:PERCENT,FIXED'],
            'discount_value' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'applies_to' => ['required', Rule::in(['STORE', 'PRODUCTS', 'VARIANTS'])],
            'visibility' => ['required', Rule::in(['PUBLIC', 'CODE_ONLY', 'FOLLOWERS'])],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')],
            'variant_ids' => ['nullable', 'array'],
            'variant_ids.*' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'maximum_discount_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999.99'],
            'seller_discount_budget_total' => ['nullable', 'numeric', 'gt:0', 'max:9999999999.99'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

        if (! $this->voucherProductTargetingReady()) {
            if ($data['applies_to'] === 'PRODUCTS' || $data['applies_to'] === 'VARIANTS') {
                throw ValidationException::withMessages([
                    'applies_to' => 'Selected product vouchers are temporarily unavailable until the voucher targeting schema is installed.',
                ]);
            }
            unset($data['applies_to']);
        }
        if (! $this->voucherVariantTargetingReady() && $data['applies_to'] === 'VARIANTS') {
            throw ValidationException::withMessages([
                'applies_to' => 'Selected variant vouchers are temporarily unavailable until the voucher variant migration is installed.',
            ]);
        }
        if (! Schema::hasColumn('vouchers', 'seller_discount_budget_total')) {
            if (filled($data['seller_discount_budget_total'] ?? null)) {
                throw ValidationException::withMessages([
                    'seller_discount_budget_total' => 'Seller voucher budgets are temporarily unavailable until their database migration is installed.',
                ]);
            }
            unset($data['seller_discount_budget_total']);
        } elseif (! Schema::hasTable('financial_ledger_entries')
            || ! Schema::hasColumn('financial_ledger_entries', 'voucher_id')
            || ! Schema::hasColumn('financial_ledger_entries', 'order_item_id')) {
            if (filled($data['seller_discount_budget_total'] ?? null)
                && (! $voucher || $voucher->seller_discount_budget_total === null)) {
                throw ValidationException::withMessages([
                    'seller_discount_budget_total' => 'Seller voucher budgets are unavailable until the financial ledger migration is installed.',
                ]);
            }
            if ($voucher && $voucher->seller_discount_budget_total !== null) {
                $data['seller_discount_budget_total'] = $voucher->seller_discount_budget_total;
            } else {
                unset($data['seller_discount_budget_total']);
            }
        }
        if (! Schema::hasColumn('vouchers', 'visibility')) {
            if (in_array($data['visibility'], ['CODE_ONLY', 'FOLLOWERS'], true)) {
                throw ValidationException::withMessages([
                    'visibility' => 'Private and follower rewards are temporarily unavailable until the voucher visibility migration is installed.',
                ]);
            }
            unset($data['visibility']);
        }
        if ($data['visibility'] === 'FOLLOWERS' && ! Schema::hasTable('seller_followers')) {
            throw ValidationException::withMessages([
                'visibility' => 'Follower rewards are unavailable until Seller follow support is installed.',
            ]);
        }

        if ($data['discount_type'] === 'PERCENT' && (float) $data['discount_value'] > 99.99) {
            throw ValidationException::withMessages([
                'discount_value' => 'A percentage voucher must be 99.99% or less so every discounted product keeps a positive price.',
            ]);
        }

        if (Schema::hasColumn('vouchers', 'requires_review')) {
            $data['requires_review'] = false;
            $data['review_reason'] = null;
        }

        if (Schema::hasColumn('vouchers', 'requires_review')) {
            $data['requires_review'] = false;
            $data['review_reason'] = null;
        }

        if (! empty($data['starts_at']) && ! empty($data['ends_at'])
            && Carbon::parse($data['ends_at'])->lessThanOrEqualTo(Carbon::parse($data['starts_at']))) {
            throw ValidationException::withMessages([
                'ends_at' => 'The voucher end time must be later than its start time.',
            ]);
        }

        $data['code'] = mb_strtoupper(trim($data['code']));
        $sameSellerCode = Voucher::query()
            ->where('seller_profile_id', $seller->id)
            ->whereRaw('UPPER(code) = ?', [$data['code']])
            ->when($voucher, fn ($query) => $query->whereKeyNot($voucher->getKey()));
        if ($sameSellerCode->exists()) {
            throw ValidationException::withMessages(['code' => 'You already have a voucher with this code.']);
        }
        if (Schema::hasColumn('vouchers', 'platform_code_key')
            && Voucher::query()->whereNull('seller_profile_id')->whereRaw('UPPER(code) = ?', [$data['code']])->exists()) {
            throw ValidationException::withMessages(['code' => 'This code is reserved for a LIKHAE platform campaign.']);
        }

        $voucherProductTargetingReady = $this->voucherProductTargetingReady();
        $voucherVariantTargetingReady = $this->voucherVariantTargetingReady();
        $productIds = collect($data['product_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        if ($voucherProductTargetingReady && $data['applies_to'] === 'PRODUCTS') {
            if ($productIds === []) {
                throw ValidationException::withMessages([
                    'product_ids' => 'Select at least one product for this voucher.',
                ]);
            }
            $ownedActiveIds = $seller->products()
                ->where('status', 'ACTIVE')
                ->whereIn('id', $productIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->sort()
                ->values()
                ->all();
            if ($ownedActiveIds !== $productIds) {
                throw ValidationException::withMessages([
                    'product_ids' => 'Choose only active products from your own store.',
                ]);
            }
        } else {
            $productIds = [];
        }

        $variantIds = collect($data['variant_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        if ($voucherVariantTargetingReady && $data['applies_to'] === 'VARIANTS') {
            if ($variantIds === []) {
                throw ValidationException::withMessages(['variant_ids' => 'Select at least one variant for this voucher.']);
            }
            $ownedActiveVariantIds = ProductVariant::query()
                ->whereIn('id', $variantIds)
                ->where('is_active', true)
                ->whereHas('product', fn ($query) => $query
                    ->where('seller_profile_id', $seller->id)
                    ->where('status', 'ACTIVE'))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->sort()
                ->values()
                ->all();
            if ($ownedActiveVariantIds !== $variantIds) {
                throw ValidationException::withMessages([
                    'variant_ids' => 'Choose only active variants from your own published products.',
                ]);
            }
        } else {
            $variantIds = [];
        }

        if ($voucherProductTargetingReady) {
            $data['product_ids'] = $productIds;
        }
        if ($voucherVariantTargetingReady) {
            $data['variant_ids'] = $variantIds;
        }

        $voucherTargets = match ($data['applies_to'] ?? 'STORE') {
            'PRODUCTS' => ProductVariant::query()->whereIn('product_id', $productIds)
                ->where('is_active', true)
                ->whereHas('product', fn ($query) => $query->where('seller_profile_id', $seller->id)->where('status', 'ACTIVE'))
                ->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'VARIANTS' => $variantIds,
            default => ProductVariant::query()
                ->where('is_active', true)
                ->whereHas('product', fn ($query) => $query->where('seller_profile_id', $seller->id)->where('status', 'ACTIVE'))
                ->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        };
        $voucherDraft = new Voucher([
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'maximum_discount_amount' => $data['maximum_discount_amount'] ?? null,
            'applies_to' => $data['applies_to'] ?? 'STORE',
        ]);
        $this->assertVoucherLeavesPositivePrice($voucherDraft, $seller, $voucherTargets, $promotionConflictService, $voucher?->id, ! $voucher || $voucher->is_active);

        if ($voucher && $voucher->seller_discount_budget_total === null && filled($data['seller_discount_budget_total'] ?? null)
            && ($voucher->buyerClaims()->exists() || $voucher->sellerOrders()->exists())) {
            throw ValidationException::withMessages([
                'seller_discount_budget_total' => 'Set a Seller discount budget before a voucher is claimed or redeemed. This historical voucher remains uncapped.',
            ]);
        }
        if ($voucher && $voucher->seller_discount_budget_total !== null && filled($data['seller_discount_budget_total'] ?? null)) {
            $summary = app(\App\Services\Finance\SellerVoucherBudgetService::class)->summary($voucher);
            if (FinancialRules::cents($data['seller_discount_budget_total']) < $summary['reserved_cents'] + $summary['spent_cents']) {
                throw ValidationException::withMessages([
                    'seller_discount_budget_total' => 'The budget cannot be lower than reserved plus confirmed Seller voucher discounts.',
                ]);
            }
        }

        if ($voucher && $voucher->buyerClaims()->exists()) {
            $currentProductIds = $voucherProductTargetingReady
                ? $voucher->eligibleProducts()->pluck('products.id')->map(fn ($id): int => (int) $id)->sort()->values()->all()
                : [];
            $currentVariantIds = $voucherVariantTargetingReady
                ? $voucher->eligibleVariants()->pluck('product_variants.id')->map(fn ($id): int => (int) $id)->sort()->values()->all()
                : [];
            $protectedValues = [
                'code' => [mb_strtoupper((string) $voucher->code), $data['code']],
                'discount_type' => [strtoupper((string) $voucher->discount_type), strtoupper((string) $data['discount_type'])],
                'discount_value' => [round((float) $voucher->discount_value, 2), round((float) $data['discount_value'], 2)],
                'minimum_order_amount' => [round((float) $voucher->minimum_order_amount, 2), round((float) ($data['minimum_order_amount'] ?? 0), 2)],
                'maximum_discount_amount' => [$voucher->maximum_discount_amount === null ? null : round((float) $voucher->maximum_discount_amount, 2), isset($data['maximum_discount_amount']) ? round((float) $data['maximum_discount_amount'], 2) : null],
                'usage_limit' => [$voucher->usage_limit === null ? null : (int) $voucher->usage_limit, isset($data['usage_limit']) ? (int) $data['usage_limit'] : null],
                'per_user_limit' => [$voucher->per_user_limit === null ? null : (int) $voucher->per_user_limit, isset($data['per_user_limit']) ? (int) $data['per_user_limit'] : null],
                'starts_at' => [$voucher->starts_at?->format('Y-m-d H:i'), ! empty($data['starts_at']) ? Carbon::parse($data['starts_at'])->format('Y-m-d H:i') : null],
                'ends_at' => [$voucher->ends_at?->format('Y-m-d H:i'), ! empty($data['ends_at']) ? Carbon::parse($data['ends_at'])->format('Y-m-d H:i') : null],
            ];
            if ($voucherProductTargetingReady) {
                $protectedValues['applies_to'] = [strtoupper((string) $voucher->applies_to), strtoupper((string) $data['applies_to'])];
                $protectedValues['product_ids'] = [$currentProductIds, $productIds];
                if ($voucherVariantTargetingReady) {
                    $protectedValues['variant_ids'] = [$currentVariantIds, $variantIds];
                }
            }
            if (Schema::hasColumn('vouchers', 'visibility')) {
                $protectedValues['visibility'] = [strtoupper((string) $voucher->visibility), strtoupper((string) $data['visibility'])];
            }
            $changedTerms = collect($protectedValues)
                ->filter(fn (array $values): bool => $values[0] !== $values[1])
                ->keys();
            if ($changedTerms->isNotEmpty()) {
                $errors = $changedTerms->mapWithKeys(fn (string $field): array => [$field => 'This voucher has already been claimed. Create a new voucher to change its terms; you can pause it instead.'])->all();
                throw ValidationException::withMessages($errors);
            }
        }

        return $data;
    }

    private function voucherProductTargetingReady(): bool
    {
        return Schema::hasColumn('vouchers', 'applies_to') && Schema::hasTable('voucher_products');
    }

    /** @return array<int, int> */
    private function voucherVariantIds(SellerProfile $seller, Voucher $voucher): array
    {
        $scope = $this->voucherProductTargetingReady() ? strtoupper((string) $voucher->applies_to) : 'STORE';
        if ($scope === 'PRODUCTS') {
            return $voucher->eligibleProducts()->where('products.seller_profile_id', $seller->id)
                ->where('products.status', 'ACTIVE')->with('variants')
                ->get()->flatMap(fn (Product $product) => $product->variants->where('is_active', true))
                ->pluck('id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        }
        if ($scope === 'VARIANTS' && $this->voucherVariantTargetingReady()) {
            return $voucher->eligibleVariants()
                ->where('product_variants.is_active', true)
                ->whereHas('product', fn ($query) => $query->where('seller_profile_id', $seller->id)->where('status', 'ACTIVE'))
                ->pluck('product_variants.id')->map(fn ($id): int => (int) $id)->all();
        }

        return ProductVariant::query()->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('seller_profile_id', $seller->id)->where('status', 'ACTIVE'))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @param array<int, int> $variantIds */
    private function assertVoucherLeavesPositivePrice(
        Voucher $voucher,
        SellerProfile $seller,
        array $variantIds,
        SellerPromotionConflictService $promotionConflictService,
        ?int $excludeVoucherId = null,
        bool $checkConflicts = true,
    ): void {
        $variants = ProductVariant::query()->whereIn('id', $variantIds)
            ->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('seller_profile_id', $seller->id)->where('status', 'ACTIVE'))
            ->with('product')->get();
        $calculator = app(VoucherDiscountCalculator::class);
        foreach ($variants as $variant) {
            $price = round((float) ($variant->active_flash_sale_price ?? $variant->final_price), 2);
            $discount = $calculator->calculate($voucher, $price);
            if ($price < 0.01 || round($price - $discount, 2) < 0.01) {
                throw ValidationException::withMessages([
                    'discount_value' => 'This voucher would make '.$variant->product?->name.' ('.($variant->description ?: $variant->sku).') free. Reduce the discount so the selling price stays at least PHP 0.01.',
                ]);
            }
        }

        if ($checkConflicts && $variants->isNotEmpty()) {
            $conflicts = $promotionConflictService->conflictsForVariantIds($seller, $variants->modelKeys(), [
                'exclude_kinds' => ['flash_sale'],
                'exclude_ids' => ['voucher' => $excludeVoucherId === null ? [] : [$excludeVoucherId]],
            ]);
            if ($conflicts->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'applies_to' => 'This voucher overlaps an active or scheduled Seller product discount or special offer. Clear the existing offer first: '.$conflicts->pluck('label')->unique()->implode(', ').'.',
                ]);
            }
        }
    }

    private function voucherVariantTargetingReady(): bool
    {
        return $this->voucherProductTargetingReady() && Schema::hasTable('voucher_variants');
    }

    public function toggleCampaign(Request $request, Voucher $voucher, SellerPromotionConflictService $promotionConflictService): RedirectResponse
    {
        $seller = $this->sellerForOwnedVoucher($request, $voucher);
        DB::transaction(function () use ($voucher, $seller, $promotionConflictService): void {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            if (! $locked->is_active) {
                if ($locked->requiresReview()) {
                    throw ValidationException::withMessages([
                        'voucher' => 'This voucher needs review. Correct or replace its discount terms before activating it.',
                    ]);
                }
                $targets = $this->voucherVariantIds($seller, $locked);
                $this->assertVoucherLeavesPositivePrice($locked, $seller, $targets, $promotionConflictService, $locked->id, true);
            }
            $locked->update(['is_active' => ! $locked->is_active]);
        }, attempts: 3);

        return back()->with('status', 'Voucher updated.');
    }

    public function updateProductDiscounts(Request $request, Product $product, SellerPromotionConflictService $promotionConflictService): RedirectResponse
    {
        $seller = $request->user()?->sellerProfile;
        abort_unless($seller && (int) $product->seller_profile_id === (int) $seller->id, 404);
        abort_unless(Schema::hasColumn('products', 'discounts_enabled')
            && Schema::hasColumn('products', 'discount_starts_at')
            && Schema::hasColumn('products', 'discount_ends_at'), 503, 'Product discounts are unavailable while their database migration is pending.');

        $data = $request->validate([
            'discount_starts_at' => ['nullable', 'date'],
            'discount_ends_at' => ['nullable', 'date'],
            'discounts_enabled' => ['required', 'boolean'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            'variants.*.discount_type' => ['required', Rule::in(['none', 'percentage', 'fixed'])],
            'variants.*.discount_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);
        if (! empty($data['discount_starts_at']) && ! empty($data['discount_ends_at'])
            && Carbon::parse($data['discount_ends_at'])->lessThanOrEqualTo(Carbon::parse($data['discount_starts_at']))) {
            throw ValidationException::withMessages(['discount_ends_at' => 'The discount end time must be later than its start time.']);
        }

        DB::transaction(function () use ($data, $product, $seller, $promotionConflictService): void {
            $lockedProduct = Product::query()->whereKey($product->id)
                ->where('seller_profile_id', $seller->id)->where('status', 'ACTIVE')
                ->lockForUpdate()->firstOrFail();
            $variants = ProductVariant::query()->where('product_id', $lockedProduct->id)
                ->where('is_active', true)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $requested = collect($data['variants'])->pluck('id')->map(fn ($id): int => (int) $id)->unique()->sort()->values();
            if ($requested->all() !== $variants->keys()->map(fn ($id): int => (int) $id)->sort()->values()->all()) {
                throw ValidationException::withMessages(['variants' => 'Refresh the page and update all currently active variants for this product.']);
            }

            $discountedIds = [];
            foreach ($data['variants'] as $index => $row) {
                $variant = $variants->get((int) $row['id']);
                $type = strtolower((string) $row['discount_type']);
                $value = round((float) ($row['discount_value'] ?? 0), 2);
                if ($type === 'percentage' && $value > 100) {
                    throw ValidationException::withMessages(["variants.{$index}.discount_value" => 'Percentage discounts cannot exceed 100%.']);
                }
                if ($type === 'none' || $value <= 0) {
                    continue;
                }

                $base = round((float) $variant->price, 2);
                $amount = $type === 'fixed' ? $value : $base * min(100, $value) / 100;
                if ($base < 0.01 || round($base - $amount, 2) < 0.01) {
                    throw ValidationException::withMessages(["variants.{$index}.discount_value" => 'This discount would make the selling price free. Leave at least PHP 0.01 after the discount.']);
                }
                $discountedIds[] = (int) $variant->id;
            }
            if ($discountedIds === [] && (filled($data['discount_starts_at'] ?? null) || filled($data['discount_ends_at'] ?? null))) {
                throw ValidationException::withMessages(['discount_starts_at' => 'Set a product or variant discount before adding its schedule.']);
            }

            if ($discountedIds !== [] && (bool) $data['discounts_enabled']) {
                $conflicts = $promotionConflictService->conflictsForVariantIds($seller, $discountedIds, [
                    'exclude_kinds' => ['discount', 'flash_sale'],
                ]);
                if ($conflicts->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'variants' => 'This discount overlaps a Seller voucher or bundle deal. Change the other offer first: '.$conflicts->pluck('label')->unique()->implode(', ').'.',
                    ]);
                }
            }

            foreach ($data['variants'] as $row) {
                $variant = $variants->get((int) $row['id']);
                $value = round((float) ($row['discount_value'] ?? 0), 2);
                $type = $value > 0 ? strtolower((string) $row['discount_type']) : 'none';
                $variant->update(['discount_type' => $type, 'discount_value' => $type === 'none' ? 0 : $value]);
            }
            $lockedProduct->update([
                'discounts_enabled' => (bool) $data['discounts_enabled'],
                'discount_starts_at' => $data['discount_starts_at'] ?? null,
                'discount_ends_at' => $data['discount_ends_at'] ?? null,
            ]);
        }, attempts: 3);

        return back()->with('status', 'Product discounts and schedule saved. Flash sales take priority when both prices are active.');
    }

    public function toggleProductDiscounts(Request $request, Product $product, SellerPromotionConflictService $promotionConflictService): RedirectResponse
    {
        $seller = $request->user()?->sellerProfile;
        abort_unless($seller && (int) $product->seller_profile_id === (int) $seller->id, 404);
        abort_unless(Schema::hasColumn('products', 'discounts_enabled'), 503, 'Product discounts are unavailable while their database migration is pending.');

        DB::transaction(function () use ($product, $seller, $promotionConflictService): void {
            $lockedProduct = Product::query()->whereKey($product->id)
                ->where('seller_profile_id', $seller->id)->where('status', 'ACTIVE')
                ->lockForUpdate()->firstOrFail();
            $enabling = ! (bool) $lockedProduct->discounts_enabled;
            $discountedVariants = ProductVariant::query()->where('product_id', $lockedProduct->id)
                ->where('is_active', true)->where('discount_value', '>', 0)
                ->where(fn ($query) => $query->whereNull('discount_type')->orWhereRaw("LOWER(TRIM(discount_type)) <> 'none'"))
                ->get();
            if ($enabling && $discountedVariants->isEmpty()) {
                throw ValidationException::withMessages(['discounts_enabled' => 'Add a product or variant discount before resuming this campaign.']);
            }
            if ($enabling) {
                $conflicts = $promotionConflictService->conflictsForVariantIds($seller, $discountedVariants->modelKeys(), [
                    'exclude_kinds' => ['discount', 'flash_sale'],
                ]);
                if ($conflicts->isNotEmpty()) {
                    throw ValidationException::withMessages(['discounts_enabled' => 'This discount overlaps a Seller voucher or bundle deal. Change the other offer first: '.$conflicts->pluck('label')->unique()->implode(', ').'.']);
                }
            }
            $lockedProduct->update(['discounts_enabled' => $enabling]);
        }, attempts: 3);

        return back()->with('status', (bool) $product->discounts_enabled ? 'Product discount campaign paused.' : 'Product discount campaign resumed.');
    }

    public function updateFlashSales(Request $request, Product $product, SellerPromotionConflictService $promotionConflictService): RedirectResponse
    {
        $seller = $request->user()?->sellerProfile;
        abort_unless($seller && (int) $product->seller_profile_id === (int) $seller->id, 404);
        abort_unless($this->flashSaleStorageReady(), 503, 'Flash sales are unavailable while their database migration is pending.');

        $data = $request->validate([
            'flash_sale_starts_at' => ['nullable', 'date'],
            'flash_sale_ends_at' => ['nullable', 'date'],
            'flash_sale_limit_per_buyer' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            'variants.*.flash_sale_price' => ['nullable', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'variants.*.flash_sale_allocation' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
        ]);
        if (! empty($data['flash_sale_starts_at']) && ! empty($data['flash_sale_ends_at'])
            && Carbon::parse($data['flash_sale_ends_at'])->lessThanOrEqualTo(Carbon::parse($data['flash_sale_starts_at']))) {
            throw ValidationException::withMessages(['flash_sale_ends_at' => 'The flash sale end time must be later than its start time.']);
        }

        DB::transaction(function () use ($data, $product, $seller, $promotionConflictService): void {
            $lockedProduct = Product::query()
                ->whereKey($product->id)
                ->where('seller_profile_id', $seller->id)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->firstOrFail();
            $variants = ProductVariant::query()
                ->where('product_id', $lockedProduct->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $requestedIds = collect($data['variants'])->pluck('id')->map(fn ($id): int => (int) $id)->unique()->sort()->values();
            if ($requestedIds->all() !== $variants->keys()->map(fn ($id): int => (int) $id)->sort()->values()->all()) {
                throw ValidationException::withMessages(['variants' => 'Refresh the page and update all currently active variants for this product.']);
            }

            $flashVariantIds = collect($data['variants'])
                ->filter(fn (array $row): bool => filled($row['flash_sale_price'] ?? null))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            if ($flashVariantIds !== []) {
                $conflicts = $promotionConflictService->conflictsForVariantIds($seller, $flashVariantIds, [
                    'exclude_kinds' => ['flash_sale', 'discount', 'voucher'],
                ]);
                if ($conflicts->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'variants' => 'A flash sale cannot overlap with an existing bundle deal. Clear these offers first: '.$conflicts->pluck('label')->unique()->implode(', ').'. Flash Sale has priority over Product Discount; discounts are not compounded.',
                    ]);
                }
            }

            $hasOpenFlashOrder = OrderItem::query()
                ->whereIn('product_variant_id', $requestedIds)
                ->where('flash_sale_quantity', '>', 0)
                ->whereHas('sellerOrder', fn ($query) => $query->whereNotIn('status', ['CANCELLED', 'COMPLETED']))
                ->exists();
            $existingStart = $lockedProduct->flash_sale_starts_at?->format('Y-m-d H:i');
            $existingEnd = $lockedProduct->flash_sale_ends_at?->format('Y-m-d H:i');
            $incomingStart = ! empty($data['flash_sale_starts_at']) ? Carbon::parse($data['flash_sale_starts_at'])->format('Y-m-d H:i') : null;
            $incomingEnd = ! empty($data['flash_sale_ends_at']) ? Carbon::parse($data['flash_sale_ends_at'])->format('Y-m-d H:i') : null;
            $changesExistingSale = $existingStart !== $incomingStart || $existingEnd !== $incomingEnd;

            foreach ($data['variants'] as $row) {
                /** @var ProductVariant $variant */
                $variant = $variants->get((int) $row['id']);
                $price = $row['flash_sale_price'] ?? null;
                $allocation = $row['flash_sale_allocation'] ?? null;
                if (($price === null) !== ($allocation === null)) {
                    throw ValidationException::withMessages([
                        'variants' => 'Set both a flash price and a unit allocation, or clear both for a variant.',
                    ]);
                }
                if ($price !== null) {
                    if ((float) $price >= round((float) $variant->price, 2)) {
                        throw ValidationException::withMessages([
                            'variants' => 'Each flash price must be below the variant’s regular catalog price.',
                        ]);
                    }
                    if ((int) $allocation < (int) $variant->flash_sale_sold) {
                        throw ValidationException::withMessages([
                            'variants' => 'Allocation cannot be lower than flash-sale units already sold.',
                        ]);
                    }
                    if ((int) $allocation > (int) $variant->stock + (int) $variant->flash_sale_sold) {
                        throw ValidationException::withMessages([
                            'variants' => 'Allocation cannot exceed the available stock plus units already sold in this sale.',
                        ]);
                    }
                }
                $existingPrice = $variant->flash_sale_price === null ? null : round((float) $variant->flash_sale_price, 2);
                $newPrice = $price === null ? null : round((float) $price, 2);
                $variantChanged = $existingPrice !== $newPrice
                    || ($variant->flash_sale_allocation === null ? null : (int) $variant->flash_sale_allocation) !== ($allocation === null ? null : (int) $allocation);
                $changesExistingSale = $changesExistingSale || $variantChanged;
            }
            if ($hasOpenFlashOrder && $changesExistingSale) {
                throw ValidationException::withMessages([
                    'variants' => 'This flash sale has orders still in progress. Finish or cancel them before changing its schedule, prices, or allocations.',
                ]);
            }

            $campaignKey = $lockedProduct->flash_sale_campaign_key;
            if ($changesExistingSale) {
                $campaignKey = $flashVariantIds === [] ? null : (string) Str::uuid();
            }
            $lockedProduct->update([
                'flash_sale_starts_at' => $data['flash_sale_starts_at'] ?? null,
                'flash_sale_ends_at' => $data['flash_sale_ends_at'] ?? null,
                'flash_sale_is_paused' => $changesExistingSale ? false : (bool) $lockedProduct->flash_sale_is_paused,
                'flash_sale_limit_per_buyer' => $data['flash_sale_limit_per_buyer'] ?? null,
                'flash_sale_campaign_key' => $campaignKey,
            ]);
            foreach ($data['variants'] as $row) {
                $variant = $variants->get((int) $row['id']);
                $price = $row['flash_sale_price'] ?? null;
                $allocation = $row['flash_sale_allocation'] ?? null;
                $changed = ($variant->flash_sale_price === null ? null : round((float) $variant->flash_sale_price, 2)) !== ($price === null ? null : round((float) $price, 2))
                    || ($variant->flash_sale_allocation === null ? null : (int) $variant->flash_sale_allocation) !== ($allocation === null ? null : (int) $allocation)
                    || $changesExistingSale;
                $variant->update([
                    'flash_sale_price' => $price,
                    'flash_sale_allocation' => $allocation,
                    'flash_sale_sold' => $changed ? 0 : $variant->flash_sale_sold,
                ]);
            }
        }, attempts: 3);

        app(\App\Services\Marketplace\FlashSaleReminderService::class)->syncProduct($product->fresh());

        return back()->with('status', 'Flash sale prices, allocations, and schedule were saved. Existing order prices remain unchanged.');
    }

    public function toggleFlashSales(Request $request, Product $product): RedirectResponse
    {
        $seller = $request->user()?->sellerProfile;
        abort_unless($seller && (int) $product->seller_profile_id === (int) $seller->id, 404);
        abort_unless($this->flashSaleStorageReady(), 503, 'Flash sales are unavailable while their database migration is pending.');

        $paused = DB::transaction(function () use ($product, $seller): bool {
            $lockedProduct = Product::query()
                ->whereKey($product->id)
                ->where('seller_profile_id', $seller->id)
                ->lockForUpdate()
                ->firstOrFail();
            $variants = ProductVariant::query()
                ->where('product_id', $lockedProduct->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            abort_if($variants->every(fn (ProductVariant $variant): bool => $variant->flash_sale_price === null), 409, 'Configure at least one flash-sale variant before changing its status.');

            $paused = ! (bool) $lockedProduct->flash_sale_is_paused;
            $lockedProduct->update(['flash_sale_is_paused' => $paused]);

            return $paused;
        }, attempts: 3);

        app(\App\Services\Marketplace\FlashSaleReminderService::class)->syncProduct($product->fresh());

        return back()->with('status', $paused ? 'Flash sale paused. Buyer prices have returned to the regular price.' : 'Flash sale resumed.');
    }

    public function endFlashSales(Request $request, Product $product): RedirectResponse
    {
        $seller = $request->user()?->sellerProfile;
        abort_unless($seller && (int) $product->seller_profile_id === (int) $seller->id, 404);
        abort_unless($this->flashSaleStorageReady(), 503, 'Flash sales are unavailable while their database migration is pending.');

        DB::transaction(function () use ($product, $seller): void {
            $lockedProduct = Product::query()->whereKey($product->id)->where('seller_profile_id', $seller->id)->lockForUpdate()->firstOrFail();
            $variants = ProductVariant::query()->where('product_id', $lockedProduct->id)->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
            $hasOpenFlashOrder = OrderItem::query()
                ->whereIn('product_variant_id', $variants->modelKeys())
                ->where('flash_sale_quantity', '>', 0)
                ->whereHas('sellerOrder', fn ($query) => $query->whereNotIn('status', ['CANCELLED', 'COMPLETED']))
                ->exists();
            if ($hasOpenFlashOrder) {
                throw ValidationException::withMessages([
                    'flash_sale' => 'This flash sale has orders still in progress. Finish or cancel them before ending the sale.',
                ]);
            }

            $lockedProduct->update([
                'flash_sale_starts_at' => null,
                'flash_sale_ends_at' => null,
                'flash_sale_is_paused' => false,
                'flash_sale_limit_per_buyer' => null,
                'flash_sale_campaign_key' => null,
            ]);
            foreach ($variants as $variant) {
                $variant->update(['flash_sale_price' => null, 'flash_sale_allocation' => null, 'flash_sale_sold' => 0]);
            }
        }, attempts: 3);

        return back()->with('status', 'Flash sales for this product have ended.');
    }

    private function flashSaleStorageReady(): bool
    {
        return Schema::hasColumn('products', 'flash_sale_starts_at')
            && Schema::hasColumn('products', 'flash_sale_ends_at')
            && Schema::hasColumn('products', 'flash_sale_is_paused')
            && Schema::hasColumn('products', 'flash_sale_limit_per_buyer')
            && Schema::hasColumn('products', 'flash_sale_campaign_key')
            && Schema::hasColumn('product_variants', 'flash_sale_price')
            && Schema::hasColumn('product_variants', 'flash_sale_allocation')
            && Schema::hasColumn('product_variants', 'flash_sale_sold')
            && Schema::hasColumn('order_items', 'flash_sale_quantity')
            && Schema::hasColumn('order_items', 'flash_sale_campaign_key');
    }

    private function sellerForOwnedVoucher(Request $request, Voucher $voucher): SellerProfile
    {
        $seller = $request->user()?->sellerProfile;
        abort_unless($seller && $voucher->seller_profile_id !== null
            && (int) $voucher->seller_profile_id === (int) $seller->id, 403);

        return $seller;
    }

    public function notifications(Request $request): View
    {
        $categoryFor = static function (string $type): string {
            $type = strtolower($type);

            return str_contains($type, 'order') ? 'orders'
                : (str_contains($type, 'inventory') || str_contains($type, 'stock') ? 'inventory'
                    : (str_contains($type, 'finance') || str_contains($type, 'payment') ? 'finance' : 'system'));
        };
        $categorySql = "CASE WHEN LOWER(type) LIKE '%order%' THEN 'orders' "
            ."WHEN LOWER(type) LIKE '%inventory%' OR LOWER(type) LIKE '%stock%' THEN 'inventory' "
            ."WHEN LOWER(type) LIKE '%finance%' OR LOWER(type) LIKE '%payment%' THEN 'finance' "
            ."ELSE 'system' END";
        $notificationCounts = array_fill_keys(['all', 'orders', 'inventory', 'finance', 'system'], 0);
        $categoryCounts = $request->user()->notifications()
            ->selectRaw($categorySql.' AS category, COUNT(*) AS aggregate')
            ->groupByRaw($categorySql)
            ->pluck('aggregate', 'category');
        foreach ($categoryCounts as $category => $count) {
            $notificationCounts[$category] = (int) $count;
            $notificationCounts['all'] += (int) $count;
        }

        $notifications = $request->user()->notifications()->latest('created_at')->latest('id')->paginate(20)->withQueryString();
        $notifications->getCollection()->each(function ($notification) use ($categoryFor): void {
            $notification->setAttribute('category', $categoryFor((string) $notification->type));
        });

        return view('seller.notifications.index', [
            'notifications' => $notifications,
            'notificationCounts' => $notificationCounts,
        ]);
    }

    public function readAll(Request $request): RedirectResponse
    {
        Notification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('status', 'Notifications marked as read.');
    }

    public function openNotification(Request $request, Notification $notification): RedirectResponse
    {
        $ownedNotification = $request->user()->notifications()->whereKey($notification->id)->firstOrFail();
        if ($ownedNotification->read_at === null) {
            $ownedNotification->forceFill(['read_at' => now()])->save();
        }

        return redirect()->to($this->sellerNotificationDestination($request, $request->user(), $ownedNotification->action_url));
    }

    private function sellerNotificationDestination(Request $request, User $sellerUser, ?string $url): string
    {
        $fallback = route('notifications.index');
        $url = trim((string) $url);
        if ($url === '' || str_starts_with($url, '//') || str_contains($url, '\\') || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return $fallback;
        }

        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return $fallback;
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
            $allowedHosts = array_filter([strtolower((string) $request->getHost()), $appHost]);
            if (! isset($parts['scheme'], $parts['host'])
                || ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
                || ! in_array(strtolower((string) $parts['host']), $allowedHosts, true)) {
                return $fallback;
            }
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        return match ($path) {
            '/seller/dashboard' => route('seller.dashboard'),
            '/seller/orders' => route('seller.orders'),
            '/seller/reviews' => route('seller.reviews'),
            '/seller/products' => route('seller.products'),
            '/seller/compliance' => route('seller.compliance'),
            default => $this->sellerRecordNotificationDestination($sellerUser, $path, $fallback),
        };
    }

    private function sellerRecordNotificationDestination(User $sellerUser, string $path, string $fallback): string
    {
        $sellerProfileId = (int) ($sellerUser->sellerProfile?->id ?? 0);
        if ($sellerProfileId < 1) {
            return $fallback;
        }

        if (preg_match('#^/seller/orders/refunds/([1-9][0-9]*)$#D', $path, $matches) === 1) {
            $sellerRequest = SellerReturnRefundRequest::query()
                ->whereKey((int) $matches[1])
                ->where('seller_profile_id', $sellerProfileId)
                ->first();

            return $sellerRequest ? route('seller.orders.refunds.show', $sellerRequest) : $fallback;
        }

        if (preg_match('#^/seller/compliance/([1-9][0-9]*)$#D', $path, $matches) === 1) {
            $case = SellerComplianceCase::query()
                ->whereKey((int) $matches[1])
                ->where('seller_profile_id', $sellerProfileId)
                ->first();

            return $case ? route('seller.compliance.show', $case) : $fallback;
        }

        return $fallback;
    }
}
