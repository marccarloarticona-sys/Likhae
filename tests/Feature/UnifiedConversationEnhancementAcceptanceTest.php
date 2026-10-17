<?php

namespace Tests\Feature;

use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Communication\Conversation;
use App\Models\Communication\Message;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Communication\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UnifiedConversationEnhancementAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_seller_admin_logistics_and_rider_share_private_image_delivery_and_1000_character_validation(): void
    {
        Storage::fake('local');
        Event::fake();

        $buyer = $this->user(User::TYPE_BUYER);
        [$seller, $sellerProfile] = $this->seller('Shared Images Shop');
        $admin = $this->user(User::TYPE_ADMIN);
        [$logistics, $center] = $this->center($admin);
        $rider = $this->user(User::TYPE_RIDER);
        $this->riderProfile($rider, $center);

        $conversations = app(ConversationService::class);
        $threads = [
            [route('buyer.messages.send'), $buyer, $seller, 'recipient_id', User::TYPE_BUYER],
            [route('seller.messages.send'), $seller, $buyer, 'recipient_user_id', User::TYPE_SELLER],
            [route('admin.messages.send'), $admin, $buyer, 'recipient_user_id', User::TYPE_ADMIN],
            [route('logistics.messages.send'), $logistics, $rider, 'recipient_user_id', User::TYPE_LOGISTICS],
            [route('rider.messages.send'), $rider, $logistics, 'recipient_user_id', User::TYPE_RIDER],
        ];

        $urls = [];
        foreach ($threads as $index => [$uri, $sender, $recipient, $recipientField]) {
            $conversation = $conversations->start($sender, $recipient->id);
            $response = $this->actingAs($sender)->post($uri, [
                $recipientField => $recipient->id,
                'conversation_id' => $conversation->id,
                'body' => 'Attached image '.$index,
                'attachment' => UploadedFile::fake()->image('chat-'.$index.'.png'),
            ], ['Accept' => 'application/json'])->assertOk();

            $message = Message::query()->findOrFail((int) $response->json('message.id'));
            $this->assertSame((int) $conversation->id, (int) $message->conversation_id);
            $this->assertNotEmpty($message->attachment_path);
            Storage::disk('local')->assertExists($message->attachment_path);
            $url = $response->json('message.attachment_url');
            $this->assertStringContainsString('/messages/'.$message->id.'/attachment', $url);
            $this->actingAs($recipient)->get($url)->assertOk();
            $urls[] = $url;
        }

        $outsider = $this->user(User::TYPE_BUYER);
        $this->actingAs($outsider)->get($urls[0])->assertForbidden();

        $buyerThread = Conversation::query()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $buyer->id))
            ->whereHas('participants', fn ($query) => $query->where('users.id', $seller->id))
            ->firstOrFail();
        $base = ['recipient_id' => $seller->id, 'conversation_id' => $buyerThread->id];
        $unicodeBody = str_repeat('ñ界', 500);
        $this->actingAs($buyer)->postJson(route('buyer.messages.send'), $base + ['body' => $unicodeBody])
            ->assertOk();
        $this->assertSame(1000, mb_strlen(Message::query()->where('conversation_id', $buyerThread->id)->latest('id')->value('body')));
        $this->actingAs($buyer)->postJson(route('buyer.messages.send'), $base + ['body' => str_repeat('x', 1001)])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->actingAs($buyer)->postJson(route('buyer.messages.send'), $base + ['body' => " \u{00A0}\u{2003} "])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->actingAs($buyer)->post(route('buyer.messages.send'), $base + [
            'body' => 'Image at size limit',
            'attachment' => UploadedFile::fake()->image('at-limit.png')->size(5120),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->actingAs($buyer)->post(route('buyer.messages.send'), $base + [
            'body' => 'Oversized image',
            'attachment' => UploadedFile::fake()->image('too-large.png')->size(5121),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('attachment');
        $this->actingAs($buyer)->post(route('buyer.messages.send'), $base + [
            'body' => 'Invalid image payload',
            'attachment' => UploadedFile::fake()->create('not-an-image.jpg', 30, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('attachment');
        $this->actingAs($buyer)->get(route('buyer.messages', ['seller' => 'conversation-'.$buyerThread->id]))
            ->assertOk()->assertSee('maxlength="1000"', false)->assertSee('/1000');

        $buyerToken = 'buyer-api-unified-chat-token';
        $buyer->forceFill([
            'api_token_hash' => hash('sha256', $buyerToken),
            'api_token_expires_at' => now()->addHour(),
        ])->save();
        $this->withToken($buyerToken)->get(route('api.v1.auth.me'))->assertOk();
        $buyerApi = $this->withToken($buyerToken)->post(route('api.v1.buyer.messages.store'), [
            'recipient_id' => $seller->id,
            'body' => 'Buyer API photo',
            'attachment' => UploadedFile::fake()->image('buyer-api.png'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $buyerApiUrl = $buyerApi->json('data.attachment_url');
        $buyerApiMessage = Message::query()->findOrFail((int) $buyerApi->json('data.id'));
        $this->assertNotEmpty($buyerApiMessage->attachment_path);
        Storage::disk('local')->assertExists($buyerApiMessage->attachment_path);
        app(\App\Services\Communication\MessageAttachmentService::class)->authorizedFile($seller, $buyerApiMessage);
        app(\App\Services\Communication\MessageAttachmentService::class)->authorizedFile($buyer, $buyerApiMessage);
        $this->assertStringContainsString('/messages/'.$buyerApiMessage->id.'/attachment', $buyerApiUrl);

        $riderToken = 'rider-api-unified-chat-token';
        $rider->forceFill([
            'api_token_hash' => hash('sha256', $riderToken),
            'api_token_expires_at' => now()->addHour(),
        ])->save();
        $riderThread = Conversation::query()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $rider->id))
            ->whereHas('participants', fn ($query) => $query->where('users.id', $logistics->id))
            ->firstOrFail();
        $riderApi = $this->withToken($riderToken)->post(route('api.v1.rider.messages.store'), [
            'conversation_id' => $riderThread->id,
            'body' => 'Rider API photo',
            'attachment' => UploadedFile::fake()->image('rider-api.webp'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $riderApiMessage = Message::query()->findOrFail((int) $riderApi->json('data.id'));
        $this->assertNotEmpty($riderApiMessage->attachment_path);
        Storage::disk('local')->assertExists($riderApiMessage->attachment_path);
        $riderApiUrl = $riderApi->json('data.attachment_url');
        $this->assertStringContainsString('/messages/'.$riderApiMessage->id.'/attachment', $riderApiUrl);
    }

    public function test_buyer_api_token_can_fetch_its_private_attachment(): void
    {
        Storage::fake('local');

        $buyer = $this->user(User::TYPE_BUYER);
        [$seller] = $this->seller('API Attachment Shop');
        $token = 'standalone-buyer-api-attachment-token';
        $buyer->forceFill([
            'api_token_hash' => hash('sha256', $token),
            'api_token_expires_at' => now()->addHour(),
        ])->save();

        $messageResponse = $this->withToken($token)->post(route('api.v1.buyer.messages.store'), [
            'recipient_id' => $seller->id,
            'body' => 'Private API image',
            'attachment' => UploadedFile::fake()->image('private-api.png'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $messageId = (int) $messageResponse->json('data.id');
        $attachmentUrl = $messageResponse->json('data.attachment_url');
        $this->assertNotEmpty($attachmentUrl);
        $this->assertStringContainsString('/messages/'.$messageId.'/attachment', $attachmentUrl);

        Auth::logout();
        $this->withToken($token)->get($attachmentUrl)->assertOk();
    }

    public function test_rider_buyer_conversation_requires_and_displays_the_assigned_shipment_context(): void
    {
        $buyer = $this->user(User::TYPE_BUYER);
        [$seller, $sellerProfile] = $this->seller('Shipment Context Shop');
        $admin = $this->user(User::TYPE_ADMIN);
        [$logistics, $center] = $this->center($admin);
        $rider = $this->user(User::TYPE_RIDER);
        $riderProfile = $this->riderProfile($rider, $center);
        $order = Order::create([
            'order_number' => 'CTX-ORDER-100',
            'buyer_user_id' => $buyer->id,
            'status' => 'PROCESSING',
            'subtotal' => '20.00',
            'grand_total' => '20.00',
            'payment_status' => 'PENDING',
            'placed_at' => now(),
        ]);
        $sellerOrder = SellerOrder::create([
            'seller_order_number' => 'CTX-SELLER-100',
            'order_id' => $order->id,
            'seller_profile_id' => $sellerProfile->id,
            'status' => 'READY_FOR_PICKUP',
            'item_subtotal' => '20.00',
            'grand_total' => '20.00',
        ]);
        OrderItem::create([
            'seller_order_id' => $sellerOrder->id,
            'product_name' => 'Historical Context Item',
            'sku' => 'CTX-ITEM-100',
            'unit_price' => '20.00',
            'quantity' => 1,
            'line_total' => '20.00',
        ]);
        $shipment = Shipment::create([
            'seller_order_id' => $sellerOrder->id,
            'tracking_number' => 'CTX-TRACK-100',
            'logistics_center_id' => $center->id,
            'destination_province_code' => '0403400000',
            'destination_province_name' => 'Laguna',
            'destination_municipality_code' => '0403424000',
            'destination_municipality_name' => 'City of San Pablo',
            'destination_barangay_code' => '0403424001',
            'destination_barangay_name' => 'Bagong Bayan II-A',
            'current_status' => 'OUT_FOR_DELIVERY',
        ]);
        RiderAssignment::create([
            'shipment_id' => $shipment->id,
            'rider_profile_id' => $riderProfile->id,
            'assignment_type' => RiderAssignment::TYPE_DELIVERY,
            'status' => 'IN_PROGRESS',
            'assigned_by_user_id' => $logistics->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($rider)->get(route('rider.messages', [
            'shipment_id' => $shipment->id,
            'recipient_user_id' => $buyer->id,
        ]))->assertRedirect();
        $conversation = Conversation::query()->where('shipment_id', $shipment->id)->firstOrFail();
        $this->actingAs($rider)->get(route('rider.messages', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertSee('CTX-TRACK-100')
            ->assertSee('Historical Context Item')
            ->assertSee('Recipient: '.$buyer->name);

        $unassignedRider = $this->user(User::TYPE_RIDER);
        $this->riderProfile($unassignedRider, $center);
        $this->actingAs($unassignedRider)->get(route('rider.messages', [
            'shipment_id' => $shipment->id,
            'recipient_user_id' => $buyer->id,
        ]))->assertNotFound();
    }

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
        $category = Category::create([
            'name' => 'Messaging '.$name,
            'slug' => 'messaging-'.str()->slug($name).'-'.uniqid(),
            'is_active' => true,
        ]);
        $profile = SellerProfile::create([
            'user_id' => $user->id,
            'primary_category_id' => $category->id,
            'business_name' => $name,
            'status' => 'ACTIVE',
        ]);

        return [$user, $profile];
    }

    /** @return array{0: User, 1: LogisticsCenter} */
    private function center(User $approver): array
    {
        $owner = $this->user(User::TYPE_LOGISTICS);
        $center = LogisticsCenter::create([
            'owner_user_id' => $owner->id,
            'code' => 'MSG-CENTER-'.uniqid(),
            'business_name' => 'Messaging Logistics Center',
            'status' => 'ACTIVE',
            'approved_by_user_id' => $approver->id,
            'approved_at' => now(),
        ]);

        return [$owner, $center];
    }

    private function riderProfile(User $user, LogisticsCenter $center): RiderProfile
    {
        return RiderProfile::create([
            'user_id' => $user->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'approved_parcel_capacity' => 10,
            'plate_number' => 'MSG-'.uniqid(),
            'drivers_license_number' => 'MSG-LICENSE-'.uniqid(),
            'status' => 'ACTIVE',
        ]);
    }
}
