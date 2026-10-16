<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Admin\Notification;
use App\Models\Buyer\OrderItem;
use App\Models\Buyer\Review;
use App\Models\Communication\Conversation;
use App\Models\Communication\Message;
use App\Models\Seller\Voucher;
use App\Models\Seller\Product;
use App\Services\Communication\ConversationService;
use App\Services\Marketplace\VoucherRedemptionCounter;
use App\Services\ReviewImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

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
            'messageAttachmentsReady' => Schema::hasColumn('messages', 'attachment_path'),
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
        if (is_string($request->input('body'))) {
            $request->merge(['body' => $this->trimMessageWhitespace($request->input('body'))]);
        }

        $data = $request->validate([
            'recipient_user_id' => ['required', 'integer', 'exists:users,id'],
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
            'body' => ['required', 'string', 'max:1000'],
            'attachment' => $this->sellerChatImageRules(),
        ]);

        if ($request->hasFile('attachment') && ! Schema::hasColumn('messages', 'attachment_path')) {
            $message = 'Image attachments are unavailable until the message attachment schema is deployed.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 503);
            }

            return back()->withErrors(['attachment' => $message])->withInput();
        }

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

        $attachmentPath = null;
        try {
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $extension = strtolower($file->getClientOriginalExtension());
                $attachmentPath = Storage::disk('local')->putFileAs('message-attachments', $file, Str::uuid().'.'.$extension);
                if (! is_string($attachmentPath)) {
                    throw new \RuntimeException('The image attachment could not be stored.');
                }
            }

            $message = $conversationService->send(
                $request->user(),
                (int) $data['recipient_user_id'],
                $data['body'],
                $context,
                $conversation,
                $attachmentPath,
            );
        } catch (Throwable $exception) {
            if ($attachmentPath) {
                Storage::disk('local')->delete($attachmentPath);
            }

            throw $exception;
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $conversationService->messagePayload($message),
            ]);
        }

        return back()->with('status', 'Message sent.');
    }

    public function messageAttachment(Request $request, Message $message)
    {
        abort_unless(Schema::hasColumn('messages', 'attachment_path'), 404);
        abort_unless(Conversation::query()
            ->whereKey($message->conversation_id)
            ->whereHas('participants', fn ($query) => $query->where('users.id', $request->user()->id))
            ->exists(), 403);

        $path = (string) $message->attachment_path;
        abort_unless(preg_match('#^message-attachments/[A-Fa-f0-9-]+\.(jpg|jpeg|png|webp)$#D', $path) === 1, 404);
        $disk = Storage::disk('local');
        $absolutePath = realpath($disk->path($path));
        $directory = realpath($disk->path('message-attachments'));
        abort_unless($absolutePath && $directory && str_starts_with($absolutePath, $directory.DIRECTORY_SEPARATOR), 404);
        $mime = mime_content_type($absolutePath) ?: '';
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return response()->file($absolutePath, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<int, mixed> */
    private function sellerChatImageRules(): array
    {
        return [
            'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'extensions:jpg,jpeg,png,webp', 'max:5120',
            static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $value instanceof \Illuminate\Http\UploadedFile || ! $value->isValid()) {
                    return;
                }
                $image = @getimagesize($value->getRealPath());
                $mime = $image['mime'] ?? null;
                $extension = strtolower($value->getClientOriginalExtension());
                $extensionsByMime = [
                    'image/jpeg' => ['jpg', 'jpeg'],
                    'image/png' => ['png'],
                    'image/webp' => ['webp'],
                ];
                if (! isset($extensionsByMime[$mime]) || ! in_array($extension, $extensionsByMime[$mime], true)) {
                    $fail('Upload a valid JPEG, PNG, or WebP image with a matching file extension.');
                }
            },
        ];
    }

    private function trimMessageWhitespace(string $body): string
    {
        return preg_replace('/^[\\s\\p{Z}]+|[\\s\\p{Z}]+$/u', '', $body) ?? trim($body);
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
            'discount_value' => ['required', 'numeric', 'min:0'],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0'],
            'maximum_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

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
