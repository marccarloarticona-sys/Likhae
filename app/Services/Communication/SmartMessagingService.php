<?php

namespace App\Services\Communication;

use App\Models\Admin\Notification;
use App\Models\Buyer\Order;
use App\Models\Communication\Conversation;
use App\Models\Communication\ConversationSupportInteraction;
use App\Models\Communication\ConversationSupportRequest;
use App\Models\Communication\ConversationSupportSession;
use App\Models\Communication\MessagingFaq;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SmartMessagingService
{
    public function __construct(private readonly ConversationAuthorizationService $authorization) {}

    /** @return array<string, mixed> */
    public function bootstrapForBuyer(User $buyer, Conversation $conversation, ?int $productId = null, ?int $variantId = null): array
    {
        [$recipient, $recipientType] = $this->assertBuyerConversation($buyer, $conversation);
        $context = $this->verifiedContext($buyer, $conversation, $recipient, $productId, $variantId);
        $sessionQuery = ConversationSupportSession::query();
        if ($this->supportRequestHistoryReady()) {
            $sessionQuery->with('currentRequest');
        }
        $session = $sessionQuery->where('conversation_id', $conversation->id)->first();

        $suggestions = $recipientType === MessagingFaq::OWNER_ADMIN
            ? $this->adminQuestions($context)
            : $this->sellerQuestions($recipient, $context);

        return [
            'conversation_id' => (int) $conversation->id,
            'recipient_type' => $recipientType,
            'recipient_name' => $recipientType === MessagingFaq::OWNER_ADMIN
                ? 'LIKHAE Admin Support'
                : ($recipient->sellerProfile?->business_name ?: $recipient->name),
            'context_label' => $context['label'] ?? null,
            'product_id' => $context['product']['id'] ?? null,
            'variant_id' => $context['variant']['id'] ?? null,
            'suggestions' => $suggestions,
            'session' => $this->sessionPayload($session),
            'answer_url' => route('buyer.messages.smart-help.answer', ['conversation' => $conversation->id]),
            'feedback_url' => route('buyer.messages.smart-help.feedback', ['conversation' => $conversation->id]),
            'handoff_url' => route('buyer.messages.smart-help.handoff', ['conversation' => $conversation->id]),
        ];
    }

    /** @return array{0: User, 1: string} */
    public function assertBuyerConversation(User $buyer, Conversation $conversation): array
    {
        abort_unless($buyer->isAccountType(User::TYPE_BUYER), 403);
        $this->authorization->assertCanAccess($buyer, $conversation);

        $participants = $conversation->participantRecords()->whereNull('left_at')->with('user.sellerProfile')->get();
        abort_unless($participants->count() === 2, 403);
        $recipient = $participants->first(fn ($participant) => (int) $participant->user_id !== (int) $buyer->id)?->user;
        abort_unless($recipient?->isActive(), 403);

        if ($recipient->isAccountType(User::TYPE_ADMIN)) {
            return [$recipient, MessagingFaq::OWNER_ADMIN];
        }

        abort_unless($recipient->isAccountType(User::TYPE_SELLER)
            && $recipient->sellerProfile?->status === 'ACTIVE', 403);

        return [$recipient, MessagingFaq::OWNER_SELLER];
    }

    /** @return array<string, mixed> */
    public function answer(User $buyer, Conversation $conversation, string $questionKey, ?int $productId = null, ?int $variantId = null): array
    {
        $this->assertSupportRequestHistoryReady();
        [$recipient, $recipientType] = $this->assertBuyerConversation($buyer, $conversation);
        return DB::transaction(function () use ($buyer, $conversation, $recipient, $recipientType, $questionKey, $productId, $variantId): array {
            $session = $this->lockSession($buyer, $recipient, $conversation);
            abort_if(in_array($session->status, [ConversationSupportSession::HUMAN_ASSISTANCE_REQUESTED, ConversationSupportSession::HUMAN_CONVERSATION], true), 409, 'Automated help is paused while you are chatting with a person.');

            $context = $this->verifiedContext($buyer, $conversation, $recipient, $productId, $variantId);
            $faq = null;
            if (ctype_digit($questionKey)) {
                $faq = $this->faqQueryForRecipient($recipient, $recipientType, $context)
                    ->active()
                    ->whereKey((int) $questionKey)
                    ->firstOrFail();
                $faq->increment('selection_count');
                $faq->forceFill(['last_selected_at' => now()])->save();
                $question = $faq->question;
                $category = $faq->category;
                $answer = $faq->answer;
                $answerSource = $recipientType === MessagingFaq::OWNER_ADMIN ? 'LIKHAE Support approved answer' : 'Seller-approved answer';
            } else {
                $defaultKey = str_starts_with($questionKey, 'default:') ? substr($questionKey, 8) : $questionKey;
                $default = $this->defaultQuestion($recipientType, $defaultKey, $context);
                abort_unless($default, 404);
                $question = $default['question'];
                $category = $default['category'];
                [$answer, $answerSource] = $this->defaultAnswer($default, $context);
            }

            $contextSnapshot = $this->handoffContext($context, $question);
            $session->fill([
                'requester_user_id' => $buyer->id,
                'recipient_user_id' => $recipient->id,
                'status' => ConversationSupportSession::AUTOMATED_HELP,
                'selected_faq_id' => $faq?->id,
                'selected_question_key' => $questionKey,
                'selected_question_snapshot' => $question,
                'selected_category_snapshot' => $category,
                'answer_snapshot' => $answer,
                'answer_source_snapshot' => $answerSource,
                'last_feedback' => null,
                'context_snapshot' => $contextSnapshot,
                'current_request_id' => null,
                'selected_at' => now(),
                'requested_at' => null,
                'resolved_at' => null,
                'resolved_by_user_id' => null,
            ])->save();

            ConversationSupportInteraction::query()->create([
                'conversation_id' => $conversation->id,
                'conversation_support_session_id' => $session->id,
                'requester_user_id' => $buyer->id,
                'selected_faq_id' => $faq?->id,
                'question_key' => $questionKey,
                'category' => $category,
                'question_snapshot' => $question,
                'answer_snapshot' => $answer,
                'answer_source' => $answerSource,
                'context_snapshot' => $contextSnapshot,
            ]);

            return [
                'question' => $question,
                'answer' => $answer,
                'answer_source' => $answerSource,
                'feedback_url' => route('buyer.messages.smart-help.feedback', ['conversation' => $conversation->id]),
                'handoff_url' => route('buyer.messages.smart-help.handoff', ['conversation' => $conversation->id]),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function recordFeedback(User $buyer, Conversation $conversation, string $feedback): array
    {
        $this->assertSupportRequestHistoryReady();
        $this->assertBuyerConversation($buyer, $conversation);
        abort_unless(in_array($feedback, ['RESOLVED', 'UNRESOLVED'], true), 422);
        $session = ConversationSupportSession::query()->where('conversation_id', $conversation->id)->firstOrFail();
        abort_unless((int) $session->requester_user_id === (int) $buyer->id && filled($session->selected_question_snapshot), 403);
        abort_if(in_array($session->status, [ConversationSupportSession::HUMAN_ASSISTANCE_REQUESTED, ConversationSupportSession::HUMAN_CONVERSATION], true), 409, 'This conversation is already with a person.');
        $latestInteraction = ConversationSupportInteraction::query()
            ->where('conversation_support_session_id', $session->id)
            ->whereNull('support_request_id')
            ->latest('id')->first();
        abort_unless($latestInteraction, 409, 'There is no unanswered automated response to rate.');

        $session->update([
            'last_feedback' => $feedback,
            'status' => $feedback === 'RESOLVED' ? ConversationSupportSession::RESOLVED : ConversationSupportSession::AUTOMATED_HELP,
            'resolved_at' => $feedback === 'RESOLVED' ? now() : null,
            'resolved_by_user_id' => $feedback === 'RESOLVED' ? $buyer->id : null,
        ]);
        $latestInteraction->update(['feedback' => $feedback]);

        return ['status' => $session->status, 'last_feedback' => $session->last_feedback];
    }

    /** @return array<string, mixed> */
    public function requestHuman(User $buyer, Conversation $conversation, ?int $productId = null, ?int $variantId = null, ?string $concern = null): array
    {
        $this->assertSupportRequestHistoryReady();
        [$recipient, $recipientType] = $this->assertBuyerConversation($buyer, $conversation);

        return DB::transaction(function () use ($buyer, $conversation, $recipient, $recipientType, $productId, $variantId, $concern): array {
            $session = $this->lockSession($buyer, $recipient, $conversation);
            $existingRequest = ConversationSupportRequest::query()
                ->where('conversation_id', $conversation->id)
                ->whereIn('status', ConversationSupportRequest::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->latest('id')
                ->first();
            if ($existingRequest) {
                return ['status' => $existingRequest->status, 'request_id' => (int) $existingRequest->id, 'already_requested' => true];
            }

            $context = $this->verifiedContext($buyer, $conversation, $recipient, $productId, $variantId);
            $latestInteraction = ConversationSupportInteraction::query()
                ->where('conversation_support_session_id', $session->id)
                ->whereNull('support_request_id')
                ->latest('id')->first();
            $question = $latestInteraction?->question_snapshot;
            $feedback = $latestInteraction?->feedback;
            if ($feedback === 'UNRESOLVED' && ! $question && ! filled($concern)) {
                $question = 'The suggested answer did not resolve the Buyer’s concern.';
            }
            $contextSnapshot = array_merge(
                $latestInteraction?->context_snapshot ?? [],
                $this->handoffContext($context, $question),
            );
            $cleanConcern = trim(strip_tags((string) $concern));
            $category = $latestInteraction?->category ?: $this->categoryForContext($context, $recipientType);
            $supportRequest = ConversationSupportRequest::query()->create([
                'conversation_id' => $conversation->id,
                'requester_user_id' => $buyer->id,
                'recipient_user_id' => $recipient->id,
                'seller_profile_id' => $recipientType === MessagingFaq::OWNER_SELLER ? $recipient->sellerProfile?->id : null,
                'selected_faq_id' => $latestInteraction?->selected_faq_id,
                'category' => $category,
                'selected_question' => $question,
                'answer_snapshot' => $latestInteraction?->answer_snapshot,
                'answer_source' => $latestInteraction?->answer_source,
                'last_feedback' => $feedback,
                'unresolved_concern' => $cleanConcern !== '' ? mb_substr($cleanConcern, 0, 1000) : null,
                'order_reference' => $context['order']['number'] ?? null,
                'context_snapshot' => $contextSnapshot,
                'status' => ConversationSupportRequest::NEW,
                'requested_at' => now(),
            ]);

            ConversationSupportInteraction::query()
                ->where('conversation_support_session_id', $session->id)
                ->whereNull('support_request_id')
                ->update(['support_request_id' => $supportRequest->id]);

            $session->fill([
                'requester_user_id' => $buyer->id,
                'recipient_user_id' => $recipient->id,
                'status' => ConversationSupportSession::HUMAN_ASSISTANCE_REQUESTED,
                'selected_faq_id' => $latestInteraction?->selected_faq_id,
                'selected_question_key' => $latestInteraction?->question_key,
                'selected_question_snapshot' => $question,
                'selected_category_snapshot' => $latestInteraction?->category,
                'answer_snapshot' => $latestInteraction?->answer_snapshot,
                'answer_source_snapshot' => $latestInteraction?->answer_source,
                'last_feedback' => $feedback,
                'current_request_id' => $supportRequest->id,
                'context_snapshot' => $contextSnapshot,
                'requested_at' => now(),
                'resolved_at' => null,
                'resolved_by_user_id' => null,
            ])->save();

            $this->notifyRecipient($supportRequest, $recipientType, $recipient, $buyer, $conversation, $question, $contextSnapshot);

            return ['status' => $supportRequest->status, 'request_id' => (int) $supportRequest->id, 'already_requested' => false];
        });
    }

    /** @return array<string, mixed> */
    public function resolveByRecipient(User $recipient, Conversation $conversation): array
    {
        $this->assertSupportRequestHistoryReady();
        $this->authorization->assertCanAccess($recipient, $conversation);
        $session = ConversationSupportSession::query()->where('conversation_id', $conversation->id)->firstOrFail();
        abort_unless((int) $session->recipient_user_id === (int) $recipient->id, 403);
        abort_unless($session->current_request_id, 409);

        return $this->resolveRequestByRecipient($recipient, ConversationSupportRequest::query()->findOrFail($session->current_request_id));
    }

    public function acknowledgeRequest(User $recipient, ConversationSupportRequest $supportRequest): array
    {
        $this->assertSupportRequestHistoryReady();
        [$session] = $this->assertRecipientRequest($recipient, $supportRequest);

        return DB::transaction(function () use ($recipient, $supportRequest, $session): array {
            $request = ConversationSupportRequest::query()->whereKey($supportRequest->id)->lockForUpdate()->firstOrFail();
            if ($request->status === ConversationSupportRequest::AWAITING_RESPONSE) {
                return ['status' => $request->status];
            }
            abort_unless($request->status === ConversationSupportRequest::NEW, 409);
            $request->update([
                'status' => ConversationSupportRequest::AWAITING_RESPONSE,
                'acknowledged_at' => now(),
                'acknowledged_by_user_id' => $recipient->id,
            ]);

            return ['status' => $request->status];
        });
    }

    public function acceptRequest(User $recipient, ConversationSupportRequest $supportRequest): array
    {
        $this->assertSupportRequestHistoryReady();
        [$session] = $this->assertRecipientRequest($recipient, $supportRequest);

        return DB::transaction(function () use ($recipient, $supportRequest, $session): array {
            $request = ConversationSupportRequest::query()->whereKey($supportRequest->id)->lockForUpdate()->firstOrFail();
            if ($request->status === ConversationSupportRequest::IN_PROGRESS) {
                return ['status' => $request->status, 'conversation_id' => (int) $request->conversation_id];
            }
            abort_unless(in_array($request->status, [ConversationSupportRequest::NEW, ConversationSupportRequest::AWAITING_RESPONSE], true), 409);
            $request->update([
                'status' => ConversationSupportRequest::IN_PROGRESS,
                'accepted_at' => now(),
                'accepted_by_user_id' => $recipient->id,
            ]);
            $session->update(['status' => ConversationSupportSession::HUMAN_CONVERSATION]);

            return ['status' => $request->status, 'conversation_id' => (int) $request->conversation_id];
        });
    }

    public function resolveRequestByRecipient(User $recipient, ConversationSupportRequest $supportRequest): array
    {
        $this->assertSupportRequestHistoryReady();
        [$session] = $this->assertRecipientRequest($recipient, $supportRequest);

        return DB::transaction(function () use ($recipient, $supportRequest, $session): array {
            $request = ConversationSupportRequest::query()->whereKey($supportRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($request->status, ConversationSupportRequest::ACTIVE_STATUSES, true), 409);
            $request->update([
                'status' => ConversationSupportRequest::RESOLVED,
                'resolved_at' => now(),
                'resolved_by_user_id' => $recipient->id,
            ]);
            if ((int) $session->current_request_id === (int) $request->id) {
                $session->update([
                    'status' => ConversationSupportSession::RESOLVED,
                    'resolved_at' => $request->resolved_at,
                    'resolved_by_user_id' => $recipient->id,
                ]);
            }
            Notification::query()->where('user_id', $recipient->id)
                ->where('reference_type', ConversationSupportRequest::class)
                ->where('reference_id', $request->id)
                ->where('type', 'CONVERSATION_HUMAN_ASSISTANCE')
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            return ['status' => $request->status];
        });
    }

    /** @param Collection<int, Conversation> $conversations
     *  @return array<int, array<string, mixed>>
     */
    public function statesFor(Collection $conversations): array
    {
        $ids = $conversations->pluck('id')->map(fn ($id): int => (int) $id)->filter()->values();
        if ($ids->isEmpty() || ! Schema::hasTable('conversation_support_sessions')) {
            return [];
        }

        $sessions = ConversationSupportSession::query();
        if ($this->supportRequestHistoryReady()) {
            $sessions->with('currentRequest');
        }

        return $sessions->whereIn('conversation_id', $ids)->get()
            ->mapWithKeys(fn (ConversationSupportSession $session): array => [(int) $session->conversation_id => $this->sessionPayload($session)])
            ->all();
    }

    public function markHumanReply(Conversation $conversation, User $sender): void
    {
        if (! $this->supportRequestHistoryReady()) {
            return;
        }
        if (! $sender->isAccountType(User::TYPE_SELLER) && ! $sender->isAccountType(User::TYPE_ADMIN)) {
            return;
        }

        $supportRequest = ConversationSupportRequest::query()
            ->where('conversation_id', $conversation->id)
            ->where('recipient_user_id', $sender->id)
            ->whereIn('status', [ConversationSupportRequest::NEW, ConversationSupportRequest::AWAITING_RESPONSE])
            ->latest('id')
            ->first();
        if (! $supportRequest) {
            return;
        }

        $acceptedAt = $supportRequest->accepted_at ?: now();
        ConversationSupportRequest::query()->whereKey($supportRequest->id)->whereIn('status', [ConversationSupportRequest::NEW, ConversationSupportRequest::AWAITING_RESPONSE])
            ->update([
                'status' => ConversationSupportRequest::IN_PROGRESS,
                'accepted_at' => $acceptedAt,
                'accepted_by_user_id' => $sender->id,
            ]);
        ConversationSupportSession::query()->where('conversation_id', $conversation->id)
            ->where('current_request_id', $supportRequest->id)
            ->where('recipient_user_id', $sender->id)
            ->where('status', ConversationSupportSession::HUMAN_ASSISTANCE_REQUESTED)
            ->update(['status' => ConversationSupportSession::HUMAN_CONVERSATION]);
    }

    /** @return array<int, array<string, mixed>> */
    private function sellerQuestions(User $seller, array $context): array
    {
        $profileId = (int) $seller->sellerProfile->id;
        $productId = $context['product']['id'] ?? null;
        $variantId = $context['variant']['id'] ?? null;
        $faqs = MessagingFaq::query()->active()
            ->where('owner_type', MessagingFaq::OWNER_SELLER)
            ->where('seller_profile_id', $profileId)
            ->where(fn ($query) => $productId
                ? $query->whereNull('product_id')->orWhere('product_id', $productId)
                : $query->whereNull('product_id'))
            ->where(fn ($query) => $variantId
                ? $query->whereNull('product_variant_id')->orWhere('product_variant_id', $variantId)
                : $query->whereNull('product_variant_id'))
            ->orderBy('sort_order')->orderBy('id')->limit(16)->get();

        $defaults = $this->sellerDefaults($context);
        return $this->mergeSuggestions($faqs, $defaults);
    }

    /** @return array<int, array<string, mixed>> */
    private function adminQuestions(array $context): array
    {
        $faqs = MessagingFaq::query()->active()->where('owner_type', MessagingFaq::OWNER_ADMIN)
            ->whereNull('seller_profile_id')
            ->orderBy('sort_order')->orderBy('id')->limit(16)->get();

        return $this->mergeSuggestions($faqs, $this->adminDefaults($context));
    }

    /** @return array<int, array<string, mixed>> */
    private function mergeSuggestions(EloquentCollection $faqs, array $defaults): array
    {
        $suggestions = $faqs->map(fn (MessagingFaq $faq): array => [
            'key' => (string) $faq->id,
            'category' => $faq->category,
            'question' => $faq->question,
        ])->all();
        $seen = collect($suggestions)->pluck('question')->map(fn ($question) => mb_strtolower(trim($question)))->all();

        foreach ($defaults as $default) {
            if (in_array(mb_strtolower(trim($default['question'])), $seen, true)) {
                continue;
            }
            $suggestions[] = [
                'key' => 'default:'.$default['key'],
                'category' => $default['category'],
                'question' => $default['question'],
            ];
            $seen[] = mb_strtolower(trim($default['question']));
        }

        return array_slice($suggestions, 0, 8);
    }

    private function faqQueryForRecipient(User $recipient, string $recipientType, array $context)
    {
        $query = MessagingFaq::query();
        if ($recipientType === MessagingFaq::OWNER_ADMIN) {
            return $query->where('owner_type', MessagingFaq::OWNER_ADMIN)->whereNull('seller_profile_id')
                ->whereNull('product_id')->whereNull('product_variant_id');
        }

        return $query->where('owner_type', MessagingFaq::OWNER_SELLER)
            ->where('seller_profile_id', $recipient->sellerProfile?->id)
            ->where(fn ($query) => isset($context['product']['id'])
                ? $query->whereNull('product_id')->orWhere('product_id', $context['product']['id'])
                : $query->whereNull('product_id'))
            ->where(fn ($query) => isset($context['variant']['id'])
                ? $query->whereNull('product_variant_id')->orWhere('product_variant_id', $context['variant']['id'])
                : $query->whereNull('product_variant_id'));
    }

    /** @return array<string, mixed> */
    private function verifiedContext(User $buyer, Conversation $conversation, User $recipient, ?int $productId, ?int $variantId): array
    {
        $context = ['label' => null, 'order' => null, 'product' => null, 'variant' => null];

        if ($conversation->seller_order_id) {
            $sellerOrder = SellerOrder::query()->with(['order', 'items'])
                ->whereKey($conversation->seller_order_id)
                ->whereHas('order', fn ($query) => $query->where('buyer_user_id', $buyer->id))
                ->first();
            abort_unless($sellerOrder, 403);
            if ($recipient->isAccountType(User::TYPE_SELLER)) {
                abort_unless((int) $sellerOrder->seller_profile_id === (int) $recipient->sellerProfile?->id, 403);
            }
            $context['order'] = [
                'id' => (int) $sellerOrder->order_id,
                'seller_order_id' => (int) $sellerOrder->id,
                'number' => (string) ($sellerOrder->order?->order_number ?: $sellerOrder->seller_order_number),
                'status' => (string) $sellerOrder->status,
                'items' => $sellerOrder->items->pluck('product_name')->filter()->unique()->take(4)->values()->all(),
            ];
            $context['label'] = 'Order '.$context['order']['number'];
        } elseif ($conversation->order_id) {
            $order = Order::query()->with('sellerOrders.items')->whereKey($conversation->order_id)
                ->where('buyer_user_id', $buyer->id)->first();
            abort_unless($order, 403);
            if ($recipient->isAccountType(User::TYPE_SELLER)) {
                $sellerOrder = $order->sellerOrders->firstWhere('seller_profile_id', $recipient->sellerProfile?->id);
                abort_unless($sellerOrder, 403);
                $context['order'] = [
                    'id' => (int) $order->id,
                    'seller_order_id' => (int) $sellerOrder->id,
                    'number' => (string) ($order->order_number ?: $sellerOrder->seller_order_number),
                    'status' => (string) $sellerOrder->status,
                    'items' => $sellerOrder->items->pluck('product_name')->filter()->unique()->take(4)->values()->all(),
                ];
            } else {
                $context['order'] = [
                    'id' => (int) $order->id,
                    'number' => (string) $order->order_number,
                    'status' => (string) $order->status,
                    'items' => $order->sellerOrders->flatMap(fn ($row) => $row->items->pluck('product_name'))->filter()->unique()->take(6)->values()->all(),
                ];
            }
            $context['label'] = 'Order '.$context['order']['number'];
        }

        if ($recipient->isAccountType(User::TYPE_SELLER) && $productId) {
            $product = Product::query()->visible()->whereKey($productId)
                ->where('seller_profile_id', $recipient->sellerProfile?->id)
                ->first();
            if ($product) {
                $context['product'] = ['id' => (int) $product->id, 'name' => (string) $product->name];
                if ($variantId) {
                    $variant = ProductVariant::query()->whereKey($variantId)->where('product_id', $product->id)->where('is_active', true)->first();
                    if ($variant) {
                        $context['variant'] = ['id' => (int) $variant->id, 'sku' => (string) $variant->sku, 'stock' => (int) $variant->stock];
                    }
                }
                $context['label'] ??= 'Product '.$product->name;
            }
        }

        return $context;
    }

    /** @return array<int, array{key:string,category:string,question:string}> */
    private function sellerDefaults(array $context): array
    {
        if (! empty($context['order'])) {
            return [
                ['key' => 'seller_order_status', 'category' => 'Orders', 'question' => 'Has my order been processed?'],
                ['key' => 'seller_order_preparation', 'category' => 'Orders', 'question' => 'When will my order be prepared?'],
                ['key' => 'seller_order_change', 'category' => 'Orders', 'question' => 'Can I change my selected variant?'],
                ['key' => 'seller_order_cancel', 'category' => 'Orders', 'question' => 'Can I cancel my order?'],
            ];
        }

        if (! empty($context['product'])) {
            return [
                ['key' => 'product_stock', 'category' => 'Products', 'question' => 'Is this product available?'],
                ['key' => 'variant_stock', 'category' => 'Products', 'question' => 'Is this specific variant available?'],
                ['key' => 'product_warranty', 'category' => 'Products', 'question' => 'Does this product have a warranty?'],
                ['key' => 'product_restock', 'category' => 'Products', 'question' => 'When will this product be restocked?'],
                ['key' => 'seller_promotions', 'category' => 'Promotions', 'question' => 'Does this shop offer vouchers?'],
            ];
        }

        return [
            ['key' => 'store_hours', 'category' => 'Store information', 'question' => 'What are this shop’s operating hours?'],
            ['key' => 'seller_returns', 'category' => 'Store information', 'question' => 'Does this Seller have a return policy?'],
            ['key' => 'seller_promotions', 'category' => 'Promotions', 'question' => 'Does this shop offer vouchers?'],
            ['key' => 'seller_contact', 'category' => 'Store information', 'question' => 'How can I contact the Seller for assistance?'],
        ];
    }

    /** @return array<int, array{key:string,category:string,question:string}> */
    private function adminDefaults(array $context): array
    {
        $questions = [
            ['key' => 'admin_order_status', 'category' => 'Orders', 'question' => 'Where is my order?'],
            ['key' => 'admin_order_pending', 'category' => 'Orders', 'question' => 'Why is my order pending?'],
            ['key' => 'admin_delivery_tracking', 'category' => 'Delivery', 'question' => 'How do I track my delivery?'],
            ['key' => 'admin_cod_pending', 'category' => 'Payments', 'question' => 'Why is my COD payment pending?'],
            ['key' => 'admin_voucher', 'category' => 'Vouchers and rewards', 'question' => 'Why is my voucher not working?'],
            ['key' => 'admin_rewards', 'category' => 'Vouchers and rewards', 'question' => 'How do I use my rewards?'],
            ['key' => 'admin_refund', 'category' => 'Returns and refunds', 'question' => 'How do I request a refund?'],
            ['key' => 'admin_delivery_delay', 'category' => 'Delivery', 'question' => 'What should I do if my delivery is delayed?'],
            ['key' => 'admin_report_seller', 'category' => 'Complaints', 'question' => 'How do I report a Seller or Rider?'],
        ];

        return $questions;
    }

    /** @return array{question:string,category:string,key:string}|null */
    private function defaultQuestion(string $recipientType, string $key, array $context): ?array
    {
        $defaults = $recipientType === MessagingFaq::OWNER_ADMIN
            ? $this->adminDefaults($context)
            : $this->sellerDefaults($context);

        return collect($defaults)->first(fn (array $item): bool => $item['key'] === $key);
    }

    /** @return array{0:string,1:string} */
    private function defaultAnswer(array $question, array $context): array
    {
        $key = $question['key'];
        if ($key === 'product_stock' || $key === 'variant_stock') {
            $productId = $context['product']['id'] ?? null;
            if (! $productId) {
                return ['Open the product listing to check its current variants. The Seller can confirm any details that are not shown there.', 'Suggested answer'];
            }
            $variant = $context['variant']['id'] ?? null
                ? ProductVariant::query()->whereKey($context['variant']['id'])->where('product_id', $productId)->where('is_active', true)->first()
                : null;
            if ($key === 'variant_stock' && ! $variant) {
                return ['Select a variant on the product listing to check its current stock. The Seller can confirm availability if the listing needs an update.', 'Suggested answer'];
            }
            $stock = $variant ? (int) $variant->stock : (int) ProductVariant::query()->where('product_id', $productId)->where('is_active', true)->sum('stock');

            return $stock > 0
                ? ['The current listing shows '.number_format($stock).' unit(s) available'.($variant ? ' for the selected variant.' : ' across active variants.').' Stock can change before checkout.', 'Verified current listing stock']
                : ['The current listing shows no available stock'.($variant ? ' for the selected variant.' : ' across active variants.').' Ask the Seller whether it will be restocked.', 'Verified current listing stock'];
        }

        if ($key === 'seller_order_status' || $key === 'admin_order_status' || $key === 'admin_order_pending' || $key === 'admin_delivery_tracking') {
            if (! empty($context['order'])) {
                $status = str((string) $context['order']['status'])->replace('_', ' ')->title()->toString();
                return ['Order #'.$context['order']['number'].' is currently marked '.$status.'. Open your Orders page for the latest parcel details. If you need someone to review it, choose Chat Live below.', 'Verified current order record'];
            }

            return ['I don’t have a specific order linked to this conversation. Open the relevant order and select Message Support so we can check the correct record.', 'Suggested answer'];
        }

        $safeAnswers = [
            'seller_order_preparation' => 'The order page shows the latest Seller fulfillment stage. The Seller can confirm preparation timing; this suggested answer does not promise a dispatch date.',
            'seller_order_change' => 'Variant changes depend on the order stage and available stock. Ask the Seller before any change is made.',
            'seller_order_cancel' => 'Cancellation depends on the current order stage and payment workflow. Open the order page to review available actions or ask the Seller for help.',
            'product_warranty' => 'I don’t have confirmed warranty terms for this product. Please ask the Seller directly.',
            'product_restock' => 'I don’t have a confirmed restock date. The Seller can share an update if one is available.',
            'seller_promotions' => 'Check the shop and product pages for active Seller promotions. Voucher eligibility is shown at checkout.',
            'store_hours' => 'Shop operating hours have not been confirmed here. Please ask the Seller directly.',
            'seller_returns' => 'Return eligibility depends on the order and the applicable marketplace workflow. Open the order page to review the available return option.',
            'seller_contact' => 'You can continue this conversation using the message box below. The Seller will see your message in this existing thread.',
            'admin_cod_pending' => 'COD collection and confirmation are recorded through the delivery workflow. Open the relevant order for its current payment status, or choose Chat Live for a support review.',
            'admin_voucher' => 'Voucher eligibility depends on its active dates, terms, and the items in your cart. Review the voucher details and checkout summary; support can review a specific order.',
            'admin_rewards' => 'Your available rewards and eligible redemption options are shown in your account and checkout. Support can review a specific order if the balance looks incorrect.',
            'admin_refund' => 'Open the order and use the available return or refund action. The request page shows its current review status; support can help with a specific request.',
            'admin_delivery_delay' => 'Check the latest delivery status on your order page. If there is no recent scan or the status looks incorrect, use Chat Live to request a review.',
            'admin_report_seller' => 'Open the related order or support conversation and describe the issue. Do not share payment credentials or verification codes in chat.',
        ];

        return [$safeAnswers[$key] ?? 'I don’t have a confirmed answer for this question. You can continue this conversation with a person below.', 'Suggested answer'];
    }

    /** @return array<string, mixed> */
    private function handoffContext(array $context, ?string $question): array
    {
        return array_filter([
            'question' => $question,
            'product_id' => $context['product']['id'] ?? null,
            'product' => $context['product']['name'] ?? null,
            'variant_id' => $context['variant']['id'] ?? null,
            'variant' => $context['variant']['sku'] ?? null,
            'seller_order_id' => $context['order']['seller_order_id'] ?? null,
            'order_id' => $context['order']['id'] ?? null,
            'order_number' => $context['order']['number'] ?? null,
            'order_status' => $context['order']['status'] ?? null,
        ], fn ($value) => filled($value));
    }

    private function lockSession(User $buyer, User $recipient, Conversation $conversation): ConversationSupportSession
    {
        $timestamp = now();
        DB::table('conversation_support_sessions')->insertOrIgnore([
            'conversation_id' => $conversation->id,
            'requester_user_id' => $buyer->id,
            'recipient_user_id' => $recipient->id,
            'status' => ConversationSupportSession::AUTOMATED_HELP,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return ConversationSupportSession::query()->where('conversation_id', $conversation->id)->lockForUpdate()->firstOrFail();
    }

    /** @return array{0: ConversationSupportSession, 1: Conversation} */
    private function assertRecipientRequest(User $recipient, ConversationSupportRequest $supportRequest): array
    {
        abort_unless($recipient->isActive()
            && ($recipient->isAccountType(User::TYPE_SELLER) || $recipient->isAccountType(User::TYPE_ADMIN)), 403);
        $request = ConversationSupportRequest::query()->whereKey($supportRequest->id)
            ->where('recipient_user_id', $recipient->id)->firstOrFail();
        if ($recipient->isAccountType(User::TYPE_SELLER)) {
            $sellerProfile = $recipient->sellerProfile;
            abort_unless($sellerProfile?->status === 'ACTIVE' && (int) $request->seller_profile_id === (int) $sellerProfile->id, 403);
        } else {
            abort_unless($request->seller_profile_id === null, 403);
        }

        $conversation = Conversation::query()->findOrFail($request->conversation_id);
        $this->authorization->assertCanAccess($recipient, $conversation);
        $activeParticipants = $conversation->participantRecords()->whereNull('left_at')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        abort_unless(count($activeParticipants) === 2
            && in_array((int) $recipient->id, $activeParticipants, true)
            && in_array((int) $request->requester_user_id, $activeParticipants, true), 403);

        $session = ConversationSupportSession::query()->where('conversation_id', $conversation->id)
            ->where('current_request_id', $request->id)->firstOrFail();

        return [$session, $conversation];
    }

    private function categoryForContext(array $context, string $recipientType): string
    {
        if (! empty($context['order'])) {
            return 'Orders';
        }
        if (! empty($context['product'])) {
            return 'Products';
        }

        return $recipientType === MessagingFaq::OWNER_ADMIN ? 'Other concerns' : 'Store information';
    }

    private function notifyRecipient(
        ConversationSupportRequest $supportRequest,
        string $recipientType,
        User $recipient,
        User $buyer,
        Conversation $conversation,
        ?string $question,
        array $context,
    ): void {
        $users = collect([$recipient]);
        $summary = $question ?: 'The Buyer requested live help.';
        if (filled($supportRequest->unresolved_concern)) {
            $summary .= ' Buyer note: '.$supportRequest->unresolved_concern;
        }
        if (! empty($context['product'])) {
            $summary .= ' Product: '.$context['product'].'.';
        }
        if (! empty($context['order_number'])) {
            $summary .= ' Order #'.$context['order_number'].'.';
        }

        foreach ($users as $user) {
            $exists = Notification::query()
                ->where('user_id', $user->id)
                ->where('reference_type', ConversationSupportRequest::class)
                ->where('reference_id', $supportRequest->id)
                ->where('type', 'CONVERSATION_HUMAN_ASSISTANCE')
                ->whereNull('read_at')
                ->where('created_at', '>=', $supportRequest->requested_at)
                ->exists();
            if ($exists) {
                continue;
            }
            Notification::query()->create([
                'user_id' => $user->id,
                'type' => 'CONVERSATION_HUMAN_ASSISTANCE',
                'title' => 'Buyer requested live chat',
                'message' => $buyer->name.' requested help in conversation #'.$conversation->id.'. '.$summary,
                'reference_type' => ConversationSupportRequest::class,
                'reference_id' => $supportRequest->id,
                'action_url' => $recipientType === MessagingFaq::OWNER_ADMIN
                    ? route('admin.messages', ['section' => 'overview', 'request' => $supportRequest->id])
                    : route('seller.messages', ['section' => 'overview', 'request' => $supportRequest->id]),
            ]);
        }
    }

    /** @return array<string, mixed>|null */
    private function sessionPayload(?ConversationSupportSession $session): ?array
    {
        if (! $session) {
            return null;
        }

        $currentRequest = $this->supportRequestHistoryReady() ? $session->currentRequest : null;

        return [
            'status' => $session->status,
            'last_feedback' => $session->last_feedback,
            'question' => $session->selected_question_snapshot,
            'answer' => $session->answer_snapshot,
            'answer_source' => $session->answer_source_snapshot,
            'context' => $session->context_snapshot ?? [],
            'request_id' => $currentRequest?->id,
            'request_status' => $currentRequest?->status,
            'request_category' => $currentRequest?->category,
            'unresolved_concern' => $currentRequest?->unresolved_concern,
        ];
    }

    public function supportRequestHistoryReady(): bool
    {
        return Schema::hasTable('conversation_support_requests')
            && Schema::hasTable('conversation_support_interactions')
            && Schema::hasTable('conversation_support_sessions')
            && Schema::hasColumn('conversation_support_sessions', 'current_request_id')
            && Schema::hasColumn('conversation_support_sessions', 'selected_category_snapshot');
    }

    private function assertSupportRequestHistoryReady(): void
    {
        abort_unless($this->supportRequestHistoryReady(), 503, 'Support history is temporarily unavailable. Please try again later.');
    }
}
