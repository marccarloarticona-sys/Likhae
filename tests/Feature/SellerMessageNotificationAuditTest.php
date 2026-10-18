<?php

namespace Tests\Feature;

use App\Events\MessageSent;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Communication\Message;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Communication\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SellerMessageNotificationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_message_list_shows_other_thread_unread_count_and_marks_selected_thread_read(): void
    {
        [$seller] = $this->seller('Unread Counter Shop');
        $buyer = $this->user(User::TYPE_BUYER);
        $otherBuyer = $this->user(User::TYPE_BUYER);
        $threads = app(ConversationService::class);
        $selected = $threads->start($seller, $buyer->id);
        $unread = $threads->start($seller, $otherBuyer->id);

        Message::create([
            'conversation_id' => $unread->id,
            'sender_user_id' => $otherBuyer->id,
            'body' => 'New message for this shop',
            'sent_at' => now()->subMinute(),
        ]);

        $this->actingAs($seller)
            ->get(route('seller.messages', ['conversation' => $selected->id]))
            ->assertOk()
            ->assertSee('data-conversation-unread', false)
            ->assertSee('aria-label="1 unread messages"', false)
            ->assertSee('New message for this shop');

        $this->assertNull($unread->participantRecords()->where('user_id', $seller->id)->value('last_read_at'));
        $this->assertNotNull($selected->participantRecords()->where('user_id', $seller->id)->value('last_read_at'));

        $this->actingAs($seller)
            ->get(route('seller.messages', ['conversation' => $unread->id]))
            ->assertOk()
            ->assertDontSee('data-conversation-unread', false);

        $this->assertSame(0, app(ConversationService::class)
            ->unreadCountsFor($seller, collect([$unread]))
            ->get($unread->id, 0));
    }

    public function test_seller_cannot_stream_a_foreign_conversation_or_start_an_unrelated_buyer_chat(): void
    {
        [$seller] = $this->seller('Authorized Seller');
        [$otherSeller] = $this->seller('Other Seller');
        $buyer = $this->user(User::TYPE_BUYER);
        $foreignThread = app(ConversationService::class)->start($otherSeller, $buyer->id);

        $this->actingAs($seller)
            ->getJson(route('seller.messages.stream', ['conversation' => $foreignThread->id]))
            ->assertNotFound();

        $this->actingAs($seller)
            ->postJson(route('seller.messages.send'), [
                'recipient_user_id' => $buyer->id,
                'body' => 'Can I contact you?',
            ])
            ->assertForbidden();

        $this->assertSame(0, Message::query()->count());
    }

    public function test_one_persisted_message_dispatches_one_existing_realtime_event(): void
    {
        [$seller] = $this->seller('Realtime Count Shop');
        $buyer = $this->user(User::TYPE_BUYER);
        $thread = app(ConversationService::class)->start($seller, $buyer->id);
        Event::fake([MessageSent::class]);

        $this->actingAs($seller)
            ->postJson(route('seller.messages.send'), [
                'recipient_user_id' => $buyer->id,
                'conversation_id' => $thread->id,
                'body' => 'One persisted live message',
            ])
            ->assertOk();

        $this->assertSame(1, Message::query()->count());
        Event::assertDispatchedTimes(MessageSent::class, 1);
    }

    public function test_supported_seller_chat_image_formats_are_stored_privately_and_readable_by_both_participants(): void
    {
        Storage::fake('local');
        Event::fake([MessageSent::class]);
        [$seller] = $this->seller('Image Format Shop');
        $buyer = $this->user(User::TYPE_BUYER);
        $thread = app(ConversationService::class)->start($seller, $buyer->id);

        foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
            $response = $this->actingAs($seller)
                ->postJson(route('seller.messages.send'), [
                    'recipient_user_id' => $buyer->id,
                    'conversation_id' => $thread->id,
                    'body' => 'Supported image '.$extension,
                    'attachment' => UploadedFile::fake()->image('supported.'.$extension),
                ])
                ->assertOk();

            $message = Message::query()->findOrFail((int) $response->json('message.id'));
            $this->assertNotEmpty($message->attachment_path);
            Storage::disk('local')->assertExists($message->attachment_path);
            $this->assertMatchesRegularExpression('#^message-attachments/[A-Za-z0-9-]+\\.(jpg|jpeg|png|webp)$#D', $message->attachment_path);
            app(\App\Services\Communication\MessageAttachmentService::class)->authorizedFile($seller, $message);
            app(\App\Services\Communication\MessageAttachmentService::class)->authorizedFile($buyer, $message);
        }
    }

    public function test_message_html_is_escaped_in_the_seller_conversation_view(): void
    {
        [$seller] = $this->seller('Escaped Message Shop');
        $buyer = $this->user(User::TYPE_BUYER);
        $thread = app(ConversationService::class)->start($seller, $buyer->id);
        $payload = '<script>alert(1)</script><img src=x onerror=alert(1)>';
        Message::create([
            'conversation_id' => $thread->id,
            'sender_user_id' => $buyer->id,
            'body' => $payload,
            'sent_at' => now()->subMinute(),
        ]);

        $this->actingAs($seller)
            ->get(route('seller.messages', ['conversation' => $thread->id]))
            ->assertOk()
            ->assertSee(e($payload), false)
            ->assertDontSee($payload, false);
    }

    public function test_seller_sees_only_its_order_context_and_historical_item_snapshot(): void
    {
        [$seller, $profile] = $this->seller('Order Context Shop');
        $buyer = $this->user(User::TYPE_BUYER);
        $order = Order::create([
            'order_number' => 'SELLER-CONTEXT-100',
            'buyer_user_id' => $buyer->id,
            'status' => 'PROCESSING',
            'subtotal' => '24.00',
            'grand_total' => '24.00',
            'payment_status' => 'PENDING',
            'placed_at' => now(),
        ]);
        $sellerOrder = SellerOrder::create([
            'seller_order_number' => 'SELLER-CONTEXT-100-A',
            'order_id' => $order->id,
            'seller_profile_id' => $profile->id,
            'status' => 'PLACED',
            'item_subtotal' => '24.00',
            'grand_total' => '24.00',
        ]);
        OrderItem::create([
            'seller_order_id' => $sellerOrder->id,
            'product_name' => 'Historical Snapshot Item',
            'sku' => 'SNAPSHOT-100',
            'unit_price' => '24.00',
            'quantity' => 1,
            'line_total' => '24.00',
        ]);
        $thread = app(ConversationService::class)->start($buyer, $seller->id, [
            'type' => 'ORDER_SELLER',
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
        ]);

        $this->actingAs($seller)
            ->get(route('seller.messages', ['conversation' => $thread->id]))
            ->assertOk()
            ->assertSee('Order context')
            ->assertSee('SELLER-CONTEXT-100')
            ->assertSee('Historical Snapshot Item');

        [$otherSeller] = $this->seller('Unrelated Context Shop');
        $this->actingAs($otherSeller)
            ->getJson(route('seller.messages.stream', ['conversation' => $thread->id]))
            ->assertNotFound();
    }

    public function test_active_seller_thread_can_acknowledge_incoming_messages_but_not_a_foreign_thread(): void
    {
        [$seller] = $this->seller('Read Receipt Shop');
        [$otherSeller] = $this->seller('Foreign Read Receipt Shop');
        $buyer = $this->user(User::TYPE_BUYER);
        $otherBuyer = $this->user(User::TYPE_BUYER);
        $threads = app(ConversationService::class);
        $thread = $threads->start($seller, $buyer->id);
        $foreignThread = $threads->start($otherSeller, $otherBuyer->id);
        Message::create([
            'conversation_id' => $thread->id,
            'sender_user_id' => $buyer->id,
            'body' => 'Incoming while the conversation is open',
            'sent_at' => now(),
        ]);

        $this->actingAs($seller)
            ->postJson(route('seller.messages.read', $thread->id))
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertNotNull($thread->participantRecords()->where('user_id', $seller->id)->value('last_read_at'));

        $this->actingAs($seller)
            ->postJson(route('seller.messages.read', $foreignThread->id))
            ->assertNotFound();
    }

    public function test_seller_notification_open_marks_only_owned_record_read_and_uses_a_safe_scoped_destination(): void
    {
        [$seller] = $this->seller('Notification Shop');
        [$otherSeller] = $this->seller('Another Notification Shop');
        $orderNotification = $seller->notifications()->create([
            'type' => 'SELLER_ORDER_PLACED',
            'title' => 'New order received',
            'message' => 'Order ORD-100 is ready to process.',
            'action_url' => route('seller.orders'),
        ]);
        $externalNotification = $seller->notifications()->create([
            'type' => 'SELLER_ORDER_PLACED',
            'title' => 'Unsafe destination',
            'message' => 'This must return to the notification center.',
            'action_url' => 'https://outside.example/path',
        ]);
        $missingTarget = $seller->notifications()->create([
            'type' => 'RETURN_REFUND',
            'title' => 'Removed return request',
            'message' => 'The return request no longer exists.',
            'action_url' => url('/seller/orders/refunds/2147483647'),
        ]);

        $this->actingAs($seller)
            ->get(route('seller.notifications.open', $orderNotification))
            ->assertMethodNotAllowed();
        $this->assertNull($orderNotification->fresh()->read_at);

        $this->actingAs($seller)
            ->post(route('seller.notifications.open', $orderNotification))
            ->assertRedirect(route('seller.orders'));
        $this->assertNotNull($orderNotification->fresh()->read_at);

        $this->actingAs($seller)
            ->post(route('seller.notifications.open', $externalNotification))
            ->assertRedirect(route('notifications.index'));
        $this->assertNotNull($externalNotification->fresh()->read_at);

        $this->actingAs($seller)
            ->post(route('seller.notifications.open', $missingTarget))
            ->assertRedirect(route('notifications.index'));
        $this->assertNotNull($missingTarget->fresh()->read_at);

        $this->actingAs($otherSeller)
            ->post(route('seller.notifications.open', $orderNotification))
            ->assertNotFound();
    }

    public function test_seller_notification_center_shows_only_own_rows_and_current_unread_badge(): void
    {
        [$seller] = $this->seller('Private Notification Shop');
        [$otherSeller] = $this->seller('Other Private Notification Shop');
        $older = $seller->notifications()->create([
            'type' => 'SELLER_ORDER_PLACED',
            'title' => 'Older seller alert',
            'message' => 'Old activity for this Seller.',
            'created_at' => now()->subMinute(),
        ]);
        $newer = $seller->notifications()->create([
            'type' => 'SELLER_REVIEW_SUBMITTED',
            'title' => 'Newest seller alert',
            'message' => 'New activity for this Seller.',
        ]);
        $otherSeller->notifications()->create([
            'type' => 'SELLER_ORDER_PLACED',
            'title' => 'Foreign seller alert',
            'message' => 'This is private to another Seller.',
        ]);

        $this->actingAs($seller)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSeeInOrder([$newer->title, $older->title])
            ->assertDontSee('Foreign seller alert')
            ->assertSee('data-seller-notification-count', false)
            ->assertSee('>2</span>', false);
    }

    public function test_seller_notification_center_paginates_and_keeps_category_counts(): void
    {
        [$seller] = $this->seller('Paginated Notifications Shop');
        for ($index = 1; $index <= 21; $index++) {
            $seller->notifications()->create([
                'type' => $index % 2 === 0 ? 'SELLER_INVENTORY_ALERT' : 'SELLER_ORDER_PLACED',
                'title' => 'Seller alert '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'message' => 'Paginated seller notification.',
                'created_at' => now()->subMinutes(21 - $index),
            ]);
        }

        $pageOne = $this->actingAs($seller)
            ->get(route('seller.notifications'))
            ->assertOk();
        $pageOne
            ->assertSee('Seller alert 21')
            ->assertSee(route('seller.notifications', ['page' => 2]), false)
            ->assertSee('>21</span>', false)
            ->assertSee('>11</span>', false)
            ->assertSee('>10</span>', false);

        $pageTwo = $this->actingAs($seller)
            ->get(route('seller.notifications', ['page' => 2]))
            ->assertOk();
        $pageTwo->assertSee('Seller alert 01')->assertSee('method="POST"', false);
    }

    public function test_mark_all_read_only_changes_the_signed_in_sellers_notifications(): void
    {
        [$seller] = $this->seller('Read All Shop');
        [$otherSeller] = $this->seller('Other Read All Shop');
        $mine = $seller->notifications()->create([
            'type' => 'SELLER_REVIEW_SUBMITTED',
            'title' => 'New review',
            'message' => 'A buyer left a review.',
        ]);
        $theirs = $otherSeller->notifications()->create([
            'type' => 'SELLER_REVIEW_SUBMITTED',
            'title' => 'Private review',
            'message' => 'This belongs to another Seller.',
        ]);

        $this->actingAs($seller)->post(route('notifications.read-all'))->assertRedirect();

        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($theirs->fresh()->read_at);
    }

    /** @return array{0: User, 1: SellerProfile} */
    private function seller(string $businessName): array
    {
        $user = $this->user(User::TYPE_SELLER);
        $category = Category::create([
            'name' => 'Audit Category '.uniqid(),
            'slug' => 'audit-category-'.uniqid(),
            'is_active' => true,
        ]);
        $profile = SellerProfile::create([
            'user_id' => $user->id,
            'primary_category_id' => $category->id,
            'business_name' => $businessName,
            'status' => 'ACTIVE',
        ]);

        return [$user, $profile];
    }

    private function user(string $type): User
    {
        return User::factory()->create([
            'account_type' => $type,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
    }
}
