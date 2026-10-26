<?php

namespace Tests\Feature;

use App\Models\Admin\Notification;
use App\Models\Communication\Conversation;
use App\Models\Communication\ConversationSupportSession;
use App\Models\Communication\ConversationSupportRequest;
use App\Models\Communication\ConversationSupportInteraction;
use App\Models\Communication\ConversationParticipant;
use App\Models\Communication\MessagingFaq;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Communication\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SmartMessagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_can_read_seller_approved_answer_and_feedback_does_not_create_chat_messages(): void
    {
        Event::fake();
        $buyer = $this->user(User::TYPE_BUYER);
        [$seller, $profile] = $this->seller('Approved Answer Shop');
        $conversation = app(ConversationService::class)->start($buyer, $seller->id, ['type' => 'PRODUCT_SELLER']);
        $faq = MessagingFaq::query()->create([
            'owner_type' => MessagingFaq::OWNER_SELLER,
            'seller_profile_id' => $profile->id,
            'created_by_user_id' => $seller->id,
            'category' => 'Delivery',
            'question' => 'How long does delivery take?',
            'answer' => 'The Seller will confirm the delivery estimate for your address.',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->actingAs($buyer)->get(route('buyer.messages', ['seller' => 'conversation-'.$conversation->id]))
            ->assertOk()->assertSee('Suggested questions for Approved Answer Shop')->assertSee('How long does delivery take?');

        $answer = $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.answer', $conversation), [
            'question_key' => (string) $faq->id,
        ])->assertOk()->assertJsonPath('question', $faq->question)->assertJsonPath('answer_source', 'Seller-approved answer');
        $this->assertStringContainsString('delivery estimate', $answer->json('answer'));
        $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.feedback', $conversation), ['feedback' => 'RESOLVED'])
            ->assertOk()->assertJsonPath('status', ConversationSupportSession::RESOLVED);

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseHas('messaging_faqs', ['id' => $faq->id, 'selection_count' => 1]);
        $this->assertDatabaseHas('conversation_support_sessions', [
            'conversation_id' => $conversation->id,
            'requester_user_id' => $buyer->id,
            'last_feedback' => 'RESOLVED',
        ]);
    }

    public function test_product_stock_answer_uses_current_active_variant_data_and_validates_seller_ownership(): void
    {
        Event::fake();
        $buyer = $this->user(User::TYPE_BUYER);
        [$seller, $profile] = $this->seller('Stock Answer Shop');
        [, $otherProfile] = $this->seller('Different Shop');
        $category = Category::query()->create(['name' => 'Messaging Products', 'slug' => 'messaging-products-'.uniqid(), 'is_active' => true]);
        $product = Product::query()->create([
            'seller_profile_id' => $profile->id,
            'category_id' => $category->id,
            'name' => 'Current Stock Product',
            'slug' => 'current-stock-'.uniqid(),
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'STOCK-'.uniqid(),
            'price' => 100,
            'stock' => 3,
            'is_default' => true,
            'is_active' => true,
        ]);
        $otherProduct = Product::query()->create([
            'seller_profile_id' => $otherProfile->id,
            'category_id' => $category->id,
            'name' => 'Private Product',
            'slug' => 'private-product-'.uniqid(),
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        ProductVariant::query()->create([
            'product_id' => $otherProduct->id,
            'sku' => 'PRIVATE-'.uniqid(),
            'price' => 900,
            'stock' => 99,
            'is_default' => true,
            'is_active' => true,
        ]);
        $conversation = app(ConversationService::class)->start($buyer, $seller->id, ['type' => 'PRODUCT_SELLER']);

        $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.answer', $conversation), [
            'question_key' => 'default:variant_stock',
            'product_id' => $product->id,
            'variant_id' => $variant->id,
        ])->assertOk()->assertJsonPath('answer_source', 'Verified current listing stock')->assertJsonPath('answer', 'The current listing shows 3 unit(s) available for the selected variant. Stock can change before checkout.');

        $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.answer', $conversation), [
            'question_key' => 'default:variant_stock',
            'product_id' => $otherProduct->id,
            'variant_id' => ProductVariant::query()->where('product_id', $otherProduct->id)->value('id'),
        ])->assertNotFound();
    }

    public function test_live_handoff_reuses_thread_notifies_recipient_and_stops_automated_answers_after_human_reply(): void
    {
        Event::fake();
        $buyer = $this->user(User::TYPE_BUYER);
        [$seller] = $this->seller('Live Handoff Shop');
        $conversation = app(ConversationService::class)->start($buyer, $seller->id, ['type' => 'PRODUCT_SELLER']);

        $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.handoff', $conversation))
            ->assertOk()->assertJsonPath('status', ConversationSupportRequest::NEW);
        $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.handoff', $conversation))
            ->assertOk()->assertJsonPath('already_requested', true);

        $this->assertSame(1, Conversation::query()->count());
        $this->assertSame(1, Notification::query()->where('user_id', $seller->id)->where('type', 'CONVERSATION_HUMAN_ASSISTANCE')->count());
        $this->assertDatabaseCount('messages', 0);

        $this->actingAs($seller)->get(route('seller.messages', ['conversation' => $conversation->id]))
            ->assertOk()->assertSee('Buyer requested live help')->assertSee('Mark resolved');
        $this->actingAs($seller)->postJson(route('seller.messages.send'), [
            'recipient_user_id' => $buyer->id,
            'conversation_id' => $conversation->id,
            'body' => 'I can help with your order question.',
        ])->assertOk();

        $this->assertDatabaseHas('conversation_support_sessions', [
            'conversation_id' => $conversation->id,
            'status' => ConversationSupportSession::HUMAN_CONVERSATION,
        ]);
        $this->assertDatabaseHas('conversation_support_requests', [
            'conversation_id' => $conversation->id,
            'status' => ConversationSupportRequest::IN_PROGRESS,
        ]);
        $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.answer', $conversation), [
            'question_key' => 'default:seller_contact',
        ])->assertStatus(409);
        $this->actingAs($seller)->postJson(route('seller.messages.smart-help.resolve', $conversation))->assertOk();
        $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.handoff', $conversation))
            ->assertOk()->assertJsonPath('status', ConversationSupportRequest::NEW);
        $this->assertSame(2, Notification::query()->where('user_id', $seller->id)->where('type', 'CONVERSATION_HUMAN_ASSISTANCE')->count());
    }

    public function test_seller_faq_is_scoped_to_its_owner_and_admin_faqs_are_role_protected(): void
    {
        $seller = $this->seller('FAQ Owner Shop');
        $otherSeller = $this->seller('Other FAQ Shop');
        $faqResponse = $this->actingAs($seller[0])->post(route('seller.messages.faqs.store'), [
            'category' => 'Returns',
            'question' => 'Can I return this?',
            'answer' => 'Check the order page for the current return options.',
            'is_active' => 1,
            'sort_order' => 3,
        ]);
        $faqResponse->assertRedirect();
        $faq = MessagingFaq::query()->where('seller_profile_id', $seller[1]->id)->firstOrFail();

        $this->actingAs($otherSeller[0])->patch(route('seller.messages.faqs.update', $faq), [
            'category' => 'Changed', 'question' => 'Can I return this?', 'answer' => 'Changed', 'is_active' => 1,
        ])->assertNotFound();
        $this->actingAs($seller[0])->get(route('admin.support-faqs'))->assertForbidden();
        $this->actingAs($seller[0])->get(route('seller.messages.faqs'))->assertOk()->assertSee('Suggested answers');

        $this->assertDatabaseHas('messaging_faqs', ['id' => $faq->id, 'seller_profile_id' => $seller[1]->id, 'is_active' => 1]);
    }

    public function test_buyer_cannot_use_smart_help_on_another_buyers_conversation(): void
    {
        $buyer = $this->user(User::TYPE_BUYER);
        $otherBuyer = $this->user(User::TYPE_BUYER);
        [$seller] = $this->seller('Private Conversation Shop');
        $conversation = app(ConversationService::class)->start($otherBuyer, $seller->id, ['type' => 'PRODUCT_SELLER']);

        $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.handoff', $conversation))
            ->assertForbidden();
        $this->assertDatabaseCount('conversation_support_sessions', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_admin_support_faq_is_visible_only_in_the_admin_conversation_and_admin_receives_handoff(): void
    {
        Event::fake();
        $buyer = $this->user(User::TYPE_BUYER);
        $admin = $this->user(User::TYPE_ADMIN);
        $otherBuyer = $this->user(User::TYPE_BUYER);
        $conversation = app(ConversationService::class)->start($buyer, $admin->id, ['type' => 'SUPPORT']);
        $faqResponse = $this->actingAs($admin)->post(route('admin.support-faqs.store'), [
            'category' => 'Payments',
            'question' => 'How do I check a COD payment?',
            'answer' => 'Open the order page to review the latest payment status.',
            'is_active' => 1,
            'sort_order' => 2,
        ]);
        $faqResponse->assertRedirect();
        $faq = MessagingFaq::query()->where('owner_type', MessagingFaq::OWNER_ADMIN)->firstOrFail();
        $this->actingAs($admin)->get(route('admin.support-faqs'))->assertOk()->assertSee('How do I check a COD payment?');

        $this->actingAs($buyer)->get(route('buyer.messages', ['seller' => 'conversation-'.$conversation->id]))
            ->assertOk()->assertSee('LIKHAE Admin Support')->assertSee($faq->question);
        $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.answer', $conversation), [
            'question_key' => (string) $faq->id,
        ])->assertOk()->assertJsonPath('answer_source', 'LIKHAE Support approved answer');
        $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.handoff', $conversation))
            ->assertOk()->assertJsonPath('status', ConversationSupportRequest::NEW);
        $this->actingAs($admin)->get(route('admin.messages', ['conversation' => $conversation->id]))
            ->assertOk()->assertSee('Buyer requested live help')->assertSee('Mark resolved');
        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'CONVERSATION_HUMAN_ASSISTANCE',
            'reference_type' => ConversationSupportRequest::class,
        ]);

        $otherThread = app(ConversationService::class)->start($otherBuyer, $admin->id, ['type' => 'SUPPORT']);
        $otherBootstrap = app(\App\Services\Communication\SmartMessagingService::class)
            ->bootstrapForBuyer($otherBuyer, $otherThread);
        $this->assertContains($faq->question, array_column($otherBootstrap['suggestions'], 'question'));
    }

    public function test_admin_overview_is_the_default_and_tracks_filtered_requests_without_marking_messages_read(): void
    {
        Event::fake();
        $buyer = $this->user(User::TYPE_BUYER);
        $admin = $this->user(User::TYPE_ADMIN);
        [$seller] = $this->seller('Private Seller Only Shop');
        $conversationService = app(ConversationService::class);
        $conversation = $conversationService->start($buyer, $admin->id, ['type' => 'SUPPORT']);
        $faq = MessagingFaq::query()->create([
            'owner_type' => MessagingFaq::OWNER_ADMIN,
            'created_by_user_id' => $admin->id,
            'category' => 'Payments',
            'question' => 'How do I check COD?',
            'answer' => 'Open the order payment section for its current status.',
            'is_active' => true,
        ]);
        $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.answer', $conversation), ['question_key' => (string) $faq->id])->assertOk();
        $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.feedback', $conversation), ['feedback' => 'UNRESOLVED'])->assertOk();
        $this->assertDatabaseCount('conversation_support_requests', 0);
        $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.handoff', $conversation), ['concern' => 'The payment still appears pending.'])->assertOk();
        $supportRequest = ConversationSupportRequest::query()->firstOrFail();
        $this->assertDatabaseHas('conversation_support_interactions', [
            'conversation_id' => $conversation->id,
            'support_request_id' => $supportRequest->id,
            'feedback' => 'UNRESOLVED',
        ]);
        $conversationService->send($buyer, $admin->id, 'I still have a payment question.', [], $conversation);
        $adminReadAtBefore = ConversationParticipant::query()->where('conversation_id', $conversation->id)->where('user_id', $admin->id)->value('last_read_at');

        $overview = $this->actingAs($admin)->get(route('admin.messages'))
            ->assertOk()->assertSee('Admin Support Overview')->assertSee('New requests')->assertSee('How do I check COD?')
            ->assertSee('The payment still appears pending.')->assertSee('Still needs help')->assertSee('I still have a payment question.');
        $overview->assertSee('FAQ unresolved');
        $this->assertSame(1, $overview->viewData('supportOverview')['stats']['new']);
        $this->assertSame(1, $overview->viewData('supportOverview')['stats']['faq_unresolved']);
        $this->assertSame($adminReadAtBefore, ConversationParticipant::query()->where('conversation_id', $conversation->id)->where('user_id', $admin->id)->value('last_read_at'));

        $this->actingAs($admin)->get(route('admin.messages', ['section' => 'overview', 'status' => 'NEW', 'q' => 'COD']))
            ->assertOk()->assertSee('How do I check COD?');
        $this->actingAs($admin)->get(route('admin.messages', ['section' => 'overview', 'request' => $supportRequest->id]))
            ->assertOk()->assertSee('Automated assistance history')->assertSee('Open Live Chat')->assertSee('Mark Resolved');

        $this->actingAs($admin)->post(route('admin.messages.support-requests.acknowledge', $supportRequest))
            ->assertRedirect();
        $this->assertDatabaseHas('conversation_support_requests', ['id' => $supportRequest->id, 'status' => ConversationSupportRequest::AWAITING_RESPONSE, 'acknowledged_by_user_id' => $admin->id]);
        $this->actingAs($admin)->post(route('admin.messages.support-requests.accept', $supportRequest))
            ->assertRedirect(route('admin.messages', ['section' => 'chats', 'conversation' => $conversation->id]));
        $this->assertDatabaseHas('conversation_support_requests', ['id' => $supportRequest->id, 'status' => ConversationSupportRequest::IN_PROGRESS, 'accepted_by_user_id' => $admin->id]);
        $this->actingAs($admin)->post(route('admin.messages.support-requests.resolve', $supportRequest))->assertRedirect();
        $this->assertDatabaseHas('conversation_support_requests', ['id' => $supportRequest->id, 'status' => ConversationSupportRequest::RESOLVED, 'resolved_by_user_id' => $admin->id]);

        $privateBuyer = $this->user(User::TYPE_BUYER);
        $sellerConversation = $conversationService->start($privateBuyer, $seller->id, ['type' => 'PRODUCT_SELLER']);
        $this->actingAs($privateBuyer)->postJson(route('buyer.messages.smart-help.handoff', $sellerConversation), ['concern' => 'Seller private concern text'])->assertOk();
        $this->actingAs($admin)->get(route('admin.messages'))->assertOk()->assertDontSee('Seller private concern text');
    }

    public function test_seller_overview_is_scoped_and_open_chat_accepts_before_resolution(): void
    {
        Event::fake();
        $buyer = $this->user(User::TYPE_BUYER);
        [$sellerA, $profileA] = $this->seller('Support Queue Seller A');
        [$sellerB] = $this->seller('Support Queue Seller B');
        $category = Category::query()->create(['name' => 'Queue Products', 'slug' => 'queue-products-'.uniqid(), 'is_active' => true]);
        $product = Product::query()->create([
            'seller_profile_id' => $profileA->id,
            'category_id' => $category->id,
            'name' => 'Queue Product',
            'slug' => 'queue-product-'.uniqid(),
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'QUEUE-'.uniqid(),
            'price' => 10,
            'stock' => 5,
            'is_default' => true,
            'is_active' => true,
        ]);
        $conversation = app(ConversationService::class)->start($buyer, $sellerA->id, ['type' => 'PRODUCT_SELLER']);
        $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.answer', $conversation), [
            'question_key' => 'default:variant_stock', 'product_id' => $product->id, 'variant_id' => $variant->id,
        ])->assertOk();
        $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.feedback', $conversation), ['feedback' => 'UNRESOLVED'])->assertOk();
        app(ConversationService::class)->send($buyer, $sellerA->id, 'Can you confirm this option?', [], $conversation);
        $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.handoff', $conversation), ['concern' => 'Please confirm the exact option.'])->assertOk();
        $supportRequest = ConversationSupportRequest::query()->firstOrFail();

        $sellerOverview = $this->actingAs($sellerA)->get(route('seller.messages'))
            ->assertOk()->assertSee('Seller Support Overview')->assertSee('Queue Product')->assertSee($variant->sku)->assertSee('Can you confirm this option?');
        $this->assertSame(1, $sellerOverview->viewData('supportOverview')['stats']['new']);
        $this->actingAs($sellerB)->get(route('seller.messages'))->assertOk()->assertDontSee('Please confirm the exact option.');
        $this->actingAs($sellerB)->post(route('seller.messages.support-requests.accept', $supportRequest))->assertNotFound();

        $this->actingAs($sellerA)->post(route('seller.messages.support-requests.accept', $supportRequest))
            ->assertRedirect(route('seller.messages', ['section' => 'chats', 'conversation' => $conversation->id]));
        $this->assertDatabaseHas('conversation_support_requests', ['id' => $supportRequest->id, 'accepted_by_user_id' => $sellerA->id, 'status' => ConversationSupportRequest::IN_PROGRESS]);
        $this->actingAs($sellerA)->post(route('seller.messages.support-requests.resolve', $supportRequest))->assertRedirect();
        $this->assertDatabaseHas('conversation_support_requests', ['id' => $supportRequest->id, 'status' => ConversationSupportRequest::RESOLVED]);

        $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.answer', $conversation), [
            'question_key' => 'default:variant_stock', 'product_id' => $product->id, 'variant_id' => $variant->id,
        ])->assertOk();
        $this->assertDatabaseHas('conversation_support_sessions', [
            'conversation_id' => $conversation->id,
            'current_request_id' => null,
            'status' => ConversationSupportSession::AUTOMATED_HELP,
        ]);
        $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.feedback', $conversation), ['feedback' => 'UNRESOLVED'])->assertOk();
        $this->actingAs($buyer)->postJson(route('buyer.messages.smart-help.handoff', $conversation), ['concern' => 'A new concern after the earlier resolution.'])->assertOk();
        $this->assertSame(2, ConversationSupportRequest::query()->where('conversation_id', $conversation->id)->count());
        $newRequest = ConversationSupportRequest::query()->where('conversation_id', $conversation->id)->latest('id')->firstOrFail();
        $this->assertSame('UNRESOLVED', $newRequest->last_feedback);
        $this->assertSame('Is this specific variant available?', $newRequest->selected_question);
        $this->actingAs($sellerA)->get(route('seller.messages'))->assertOk()->assertSee('A new concern after the earlier resolution.');
    }

    public function test_support_history_migration_backfills_legacy_handoffs_without_duplicate_requests(): void
    {
        $buyer = $this->user(User::TYPE_BUYER);
        [$seller, $profile] = $this->seller('Legacy Support Shop');
        $conversation = app(ConversationService::class)->start($buyer, $seller->id, ['type' => 'PRODUCT_SELLER']);
        $faq = MessagingFaq::query()->create([
            'owner_type' => MessagingFaq::OWNER_SELLER,
            'seller_profile_id' => $profile->id,
            'created_by_user_id' => $seller->id,
            'category' => 'Products',
            'question' => 'Is this item available?',
            'answer' => 'The listing has limited stock.',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $requestedAt = now()->subHour();
        $sessionId = DB::table('conversation_support_sessions')->insertGetId([
            'conversation_id' => $conversation->id,
            'requester_user_id' => $buyer->id,
            'recipient_user_id' => $seller->id,
            'status' => ConversationSupportSession::HUMAN_CONVERSATION,
            'selected_faq_id' => $faq->id,
            'selected_question_key' => (string) $faq->id,
            'selected_question_snapshot' => $faq->question,
            'answer_snapshot' => $faq->answer,
            'answer_source_snapshot' => 'Seller-approved answer',
            'last_feedback' => 'UNRESOLVED',
            'context_snapshot' => json_encode(['product' => 'Legacy support product', 'variant' => 'LEGACY-SKU']),
            'selected_at' => $requestedAt,
            'requested_at' => $requestedAt,
            'created_at' => $requestedAt,
            'updated_at' => $requestedAt,
        ]);

        $migration = require database_path('migrations/2026_10_27_000100_add_messaging_support_requests_and_faq_history.php');
        $migration->up();

        $supportRequest = ConversationSupportRequest::query()->where('conversation_id', $conversation->id)->firstOrFail();
        $this->assertSame(ConversationSupportRequest::IN_PROGRESS, $supportRequest->status);
        $this->assertSame($profile->id, $supportRequest->seller_profile_id);
        $this->assertSame($faq->question, $supportRequest->selected_question);
        $this->assertSame('UNRESOLVED', $supportRequest->last_feedback);
        $this->assertSame('Legacy support product', $supportRequest->context_snapshot['product']);
        $this->assertDatabaseHas('conversation_support_sessions', ['id' => $sessionId, 'current_request_id' => $supportRequest->id]);
        $this->assertDatabaseHas('conversation_support_interactions', [
            'conversation_id' => $conversation->id,
            'support_request_id' => $supportRequest->id,
            'selected_faq_id' => $faq->id,
            'feedback' => 'UNRESOLVED',
        ]);

        $migration->up();
        $this->assertSame(1, ConversationSupportRequest::query()->where('conversation_id', $conversation->id)->count());
        $this->assertDatabaseCount('messages', 0);
    }

    /** @return User */
    private function user(string $type): User
    {
        return User::factory()->create([
            'account_type' => $type,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    /** @return array{0: User, 1: SellerProfile} */
    private function seller(string $name): array
    {
        $user = $this->user(User::TYPE_SELLER);
        $category = Category::query()->create(['name' => $name.' Category', 'slug' => 'faq-'.str()->slug($name).'-'.uniqid(), 'is_active' => true]);
        $profile = SellerProfile::query()->create([
            'user_id' => $user->id,
            'primary_category_id' => $category->id,
            'business_name' => $name,
            'status' => 'ACTIVE',
            'store_visibility' => true,
        ]);

        return [$user, $profile];
    }
}
