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
use App\Models\Seller\SellerProfile;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\User;
use App\Services\Communication\ConversationService;
use App\Services\Communication\ConversationMessageSender;
use App\Services\Communication\MessageAttachmentService;
use App\Services\Communication\ConversationContextPresenter;
use App\Services\Marketplace\VoucherRedemptionCounter;
use App\Services\Reports\WorkspaceExportPdfService;
use App\Services\ReviewImageService;
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
    public function messages(Request $request, ConversationService $conversationService): View
    {
        $conversations = $conversationService->workspaceThreads(
            $request->user(),
            $request->integer('conversation') ?: null,
        );
        $contacts = \App\Models\User::query()
            ->where('status', 'ACTIVE')
            ->whereKeyNot($request->user()->id)
            ->orderBy('first_name')
            ->limit(100)
            ->get(['id', 'first_name', 'middle_initial', 'last_name', 'name_extension', 'account_type']);
        $contacts = app(\App\Services\Communication\ConversationStartAuthorizationService::class)
            ->filterRecipients($request->user(), $contacts);

        return view('seller.messages', [
            'conversations' => $conversations,
            'contacts' => $contacts,
            'messageAttachmentsReady' => app(MessageAttachmentService::class)->available(),
            'messageReadRouteTemplate' => route('seller.messages.read', ['conversation' => '__conversation__']),
            'messageContext' => app(ConversationContextPresenter::class)->forUser(
                $request->user(),
                $conversations->firstWhere('id', $request->integer('conversation')) ?: $conversations->first(),
            ),
        ]);
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

    public function marketing(Request $request): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

        $now = now();
        $vouchers = $seller->vouchers()
            ->withCount(['sellerOrders' => fn ($query) => $query->where('status', '<>', 'CANCELLED')])
            ->latest()
            ->paginate(15);
        $vouchers->getCollection()->each(function (Voucher $voucher) use ($now): void {
            $used = (int) $voucher->seller_orders_count;
            $status = ! $voucher->is_active ? 'Paused'
                : ($voucher->starts_at && $voucher->starts_at->greaterThan($now) ? 'Upcoming'
                    : ($voucher->ends_at && $voucher->ends_at->lessThan($now) ? 'Expired'
                        : ($voucher->usage_limit !== null && $used >= (int) $voucher->usage_limit ? 'Usage exhausted' : 'Active')));

            $voucher->setAttribute('redemption_count', $used);
            $voucher->setAttribute('remaining_capacity', $voucher->usage_limit === null
                ? null
                : max(0, (int) $voucher->usage_limit - $used));
            $voucher->setAttribute('marketing_status', $status);
        });

        $activeVoucherCount = $seller->vouchers()
            ->where('is_active', true)
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
                ->filter(fn (Voucher $voucher): bool => $voucher->usage_limit === null
                    || $redemptionCounts->get($voucher->id, 0) < $voucher->usage_limit)
                ->each(fn (Voucher $voucher) => $voucher->setAttribute('redemption_count', $redemptionCounts->get($voucher->id, 0)))
                ->values();
        }

        return view('seller.marketing', [
            'seller' => $seller,
            'vouchers' => $vouchers,
            'voucherStats' => [
                'total' => $vouchers->total(),
                'active' => $activeVoucherCount,
                'redemptions' => $totalRedemptions,
                'capped' => $seller->vouchers()->whereNotNull('usage_limit')->count(),
            ],
            'platformCampaigns' => $platformCampaigns,
            'platformCampaignsReady' => $platformCampaignsReady,
        ]);
    }

    public function storeCampaign(Request $request): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

        $data = $this->validatedMarketingVoucher($request, $seller);

        Voucher::create($data + [
            'seller_profile_id' => $seller->id,
            'is_active' => true,
        ]);

        return back()->with('status', 'Voucher saved.');
    }

    public function updateCampaign(Request $request, Voucher $voucher): RedirectResponse
    {
        $seller = $this->sellerForOwnedVoucher($request, $voucher);
        $voucher->update($this->validatedMarketingVoucher($request, $seller, $voucher));

        return back()->with('status', 'Voucher updated. Existing redemptions and order totals are unchanged.');
    }

    private function validatedMarketingVoucher(Request $request, SellerProfile $seller, ?Voucher $voucher = null): array
    {
        $request->merge([
            'name' => trim((string) $request->input('name', '')),
            'code' => trim((string) $request->input('code', '')),
        ]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:150'],
            'discount_type' => ['required', 'string', 'in:PERCENT,FIXED'],
            'discount_value' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'maximum_discount_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999.99'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

        if ($data['discount_type'] === 'PERCENT' && (float) $data['discount_value'] > 100) {
            throw ValidationException::withMessages([
                'discount_value' => 'Percentage discounts cannot exceed 100%.',
            ]);
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

        return $data;
    }

    public function toggleCampaign(Request $request, Voucher $voucher): RedirectResponse
    {
        $this->sellerForOwnedVoucher($request, $voucher);

        $voucher->update(['is_active' => ! $voucher->is_active]);

        return back()->with('status', 'Voucher updated.');
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

        return view('seller.notifications', [
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
