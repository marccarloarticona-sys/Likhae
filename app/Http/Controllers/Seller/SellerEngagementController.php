<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Admin\Notification;
use App\Models\Buyer\Review;
use App\Models\Communication\Conversation;
use App\Models\Seller\Voucher;
use App\Services\Communication\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SellerEngagementController extends Controller
{
    public function messages(Request $request, ConversationService $conversationService): View
    {
        $conversations = $conversationService->workspaceThreads(
            $request->user(),
            $request->integer('conversation') ?: null,
        );

        return view('Seller.messages', [
            'conversations' => $conversations,
            'contacts' => \App\Models\User::query()->where('status', 'ACTIVE')->whereKeyNot($request->user()->id)->orderBy('first_name')->limit(100)->get(['id', 'first_name', 'middle_initial', 'last_name', 'name_extension', 'account_type']),
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

    public function reviews(Request $request): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

        $reviews = Review::query()
            ->whereHas('orderItem.sellerOrder', fn ($query) => $query->where('seller_profile_id', $seller->id))
            ->with(['orderItem', 'buyer'])
            ->latest()
            ->paginate(15);

        return view('Seller.reviews', compact('reviews', 'seller'));
    }

    public function replyReview(Request $request, Review $review): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $review->orderItem?->sellerOrder?->seller_profile_id === (int) $seller->id, 403);

        $data = $request->validate(['seller_reply' => ['required', 'string', 'max:2000']]);
        $review->update(['seller_reply' => $data['seller_reply'], 'seller_replied_at' => now()]);

        return back()->with('status', 'Review reply saved.');
    }

    public function exportReviews(Request $request)
    {
        return $this->reviews($request);
    }

    public function marketing(Request $request): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

        return view('Seller.marketing', [
            'seller' => $seller,
            'vouchers' => $seller->vouchers()->withCount('sellerOrders')->latest()->paginate(15),
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
            'discount_value' => ['required', 'numeric', 'min:0'],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0'],
            'maximum_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

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
