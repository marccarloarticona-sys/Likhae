<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Admin\Notification;
use App\Models\Buyer\OrderItem;
use App\Models\Buyer\Review;
use App\Models\Communication\Message;
use App\Models\Seller\Voucher;
use App\Models\Seller\Product;
use App\Services\Communication\ConversationService;
use App\Services\Communication\ConversationMessageSender;
use App\Services\Communication\MessageAttachmentService;
use App\Services\Communication\ConversationContextPresenter;
use App\Services\Marketplace\VoucherRedemptionCounter;
use App\Services\ReviewImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        return view('Seller.messages', [
            'conversations' => $conversations,
            'contacts' => $contacts,
            'messageAttachmentsReady' => app(MessageAttachmentService::class)->available(),
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

    public function sendMessage(Request $request, ConversationService $conversationService, ConversationMessageSender $sender): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'recipient_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $data += $sender->validate($request);
        $recipient = \App\Models\User::query()->whereKey($data['recipient_user_id'])->where('status', \App\Models\User::STATUS_ACTIVE)->firstOrFail();
        $message = $sender->send($request, $data, $recipient, $conversationService);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $conversationService->messagePayload($message),
            ]);
        }

        return back()->with('status', 'Message sent.');
    }

    public function messageAttachment(Request $request, Message $message, MessageAttachmentService $attachments): BinaryFileResponse
    {
        $file = $attachments->authorizedFile($request->user(), $message);
        $response = response()->file($file['path']);
        $response->headers->set('Content-Type', $file['mime']);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");

        return $response;
    }

    public function reviews(Request $request, ReviewImageService $reviewImages): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

        $filters = $request->validate([
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('seller_profile_id', $seller->id)],
            'variant' => ['nullable', 'string', 'max:500'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ]);

        $reviewQuery = Review::query()
            ->whereHas('orderItem.sellerOrder', fn ($query) => $query->where('seller_profile_id', $seller->id))
            ->with(['orderItem.product', 'orderItem.sellerOrder', 'buyer'])
            ->when(isset($filters['rating']), fn ($query) => $query->where('rating', $filters['rating']))
            ->when(isset($filters['product_id']), fn ($query) => $query->whereHas('orderItem', fn ($items) => $items->where('product_id', $filters['product_id'])))
            ->when(isset($filters['variant']), fn ($query) => $query->whereHas('orderItem', fn ($items) => $items->where('variant_description', $filters['variant'])));
        foreach ([['from_date', '>='], ['to_date', '<=']] as [$dateField, $operator]) {
            if (! empty($filters[$dateField])) {
                $reviewQuery->whereDate('created_at', $operator, $filters[$dateField]);
            }
        }
        $reviews = $reviewQuery
            ->latest()
            ->paginate(15)
            ->withQueryString();
        $reviewImagesById = $reviewImages->urlsFor($reviews->getCollection());
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

        return view('Seller.reviews', compact('reviews', 'seller', 'reviewImagesById', 'products', 'variants', 'filters'));
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

    public function exportReviews(Request $request, ReviewImageService $reviewImages)
    {
        return $this->reviews($request, $reviewImages);
    }

    public function marketing(Request $request): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

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

        return view('Seller.marketing', [
            'seller' => $seller,
            'vouchers' => $seller->vouchers()->withCount('sellerOrders')->latest()->paginate(15),
            'platformCampaigns' => $platformCampaigns,
            'platformCampaignsReady' => $platformCampaignsReady,
        ]);
    }

    public function storeCampaign(Request $request): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

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
            && Carbon::parse($data['ends_at'])->lessThan(Carbon::parse($data['starts_at']))) {
            throw ValidationException::withMessages([
                'ends_at' => 'The voucher end time must be after its start time.',
            ]);
        }

        $data['code'] = mb_strtoupper(trim($data['code']));
        if (Voucher::query()->where('seller_profile_id', $seller->id)->whereRaw('UPPER(code) = ?', [$data['code']])->exists()) {
            return back()->withErrors(['code' => 'You already have a voucher with this code.'])->withInput();
        }
        if (Schema::hasColumn('vouchers', 'platform_code_key')
            && Voucher::query()->whereNull('seller_profile_id')->whereRaw('UPPER(code) = ?', [$data['code']])->exists()) {
            return back()->withErrors(['code' => 'This code is reserved for a LIKHAE platform campaign.'])->withInput();
        }

        Voucher::create($data + [
            'seller_profile_id' => $seller->id,
            'is_active' => true,
        ]);

        return back()->with('status', 'Voucher saved.');
    }

    public function toggleCampaign(Request $request, Voucher $voucher): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $voucher->seller_profile_id === (int) $seller->id, 403);

        $voucher->update(['is_active' => ! $voucher->is_active]);

        return back()->with('status', 'Voucher updated.');
    }

    public function notifications(Request $request): View
    {
        $categoryFor = static function (string $type): string {
            $type = strtolower($type);

            return str_contains($type, 'order') ? 'orders'
                : (str_contains($type, 'inventory') || str_contains($type, 'stock') ? 'inventory'
                    : (str_contains($type, 'finance') || str_contains($type, 'payment') ? 'finance' : 'system'));
        };
        $notificationCounts = array_fill_keys(['all', 'orders', 'inventory', 'finance', 'system'], 0);
        $request->user()->notifications()->select(['type'])->get()->each(function ($notification) use (&$notificationCounts, $categoryFor): void {
            $notificationCounts['all']++;
            $notificationCounts[$categoryFor((string) $notification->type)]++;
        });
        $notifications = $request->user()->notifications()->latest()->paginate(20);
        $notifications->getCollection()->each(function ($notification) use ($categoryFor): void {
            $notification->setAttribute('category', $categoryFor((string) $notification->type));
        });

        return view('Seller.notifications', [
            'notifications' => $notifications,
            'notificationCounts' => $notificationCounts,
        ]);
    }

    public function readAll(Request $request): RedirectResponse
    {
        Notification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('status', 'Notifications marked as read.');
    }
}
