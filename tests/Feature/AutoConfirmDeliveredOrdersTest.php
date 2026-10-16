<?php

namespace Tests\Feature;

use App\Models\Admin\Dispute;
use App\Models\Admin\Notification;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Buyer\ReturnRefundRequest;
use App\Models\Logistics\DeliveryAttempt;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentEvent;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Marketplace\BuyerOrderActionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutoConfirmDeliveredOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_waits_until_exactly_72_hours_after_delivery(): void
    {
        $this->at('2026-10-16 20:30:00');
        [$order, , $shipments] = $this->createOrder([
            ['status' => 'DELIVERED', 'delivered_at' => now()->subHours(71)],
        ]);

        $this->artisan('likhae:auto-confirm-delivered-orders')->assertExitCode(0);
        $this->assertSame('PROCESSING', $order->fresh()->status);

        $this->travel(1)->hours();
        $this->artisan('likhae:auto-confirm-delivered-orders')->assertExitCode(0);

        $this->assertSame('COMPLETED', $order->fresh()->status);
        $this->assertSame(1, $shipments[0]->events()->where('status', 'COMPLETED')->count());
    }

    public function test_parent_order_uses_the_latest_delivery_across_all_sellers(): void
    {
        $this->at('2026-10-16 20:30:00');
        [$order] = $this->createOrder([
            ['status' => 'DELIVERED', 'delivered_at' => now()->subDays(5)],
            ['status' => 'DELIVERED', 'delivered_at' => now()->subDays(2)],
        ]);

        $this->artisan('likhae:auto-confirm-delivered-orders')->assertExitCode(0);
        $this->assertSame('PROCESSING', $order->fresh()->status);

        $this->travel(1)->day();
        $this->artisan('likhae:auto-confirm-delivered-orders')->assertExitCode(0);
        $this->assertSame('COMPLETED', $order->fresh()->status);
    }

    public function test_partially_delivered_orders_and_delivered_shipments_without_timestamps_are_skipped(): void
    {
        $this->at('2026-10-16 20:30:00');
        [$order, , $shipments] = $this->createOrder([
            ['status' => 'DELIVERED', 'delivered_at' => now()->subDays(5)],
            ['status' => 'OUT_FOR_DELIVERY', 'delivered_at' => null],
        ]);
        [$missingTimestampOrder] = $this->createOrder([
            ['status' => 'DELIVERED', 'delivered_at' => null],
        ]);

        $this->artisan('likhae:auto-confirm-delivered-orders')->assertExitCode(0);

        $this->assertSame('PROCESSING', $order->fresh()->status);
        $this->assertSame('OUT_FOR_DELIVERY', $shipments[1]->fresh()->current_status);
        $this->assertSame('PROCESSING', $missingTimestampOrder->fresh()->status);
    }

    public function test_delivery_failed_shipment_blocks_auto_receipt(): void
    {
        $this->at('2026-10-16 20:30:00');
        [$order] = $this->createOrder([
            ['status' => 'DELIVERED', 'delivered_at' => now()->subDays(5)],
            ['status' => 'DELIVERY_FAILED', 'delivered_at' => null],
        ]);

        $this->artisan('likhae:auto-confirm-delivered-orders')->assertExitCode(0);
        $this->assertSame('PROCESSING', $order->fresh()->status);
    }

    public function test_active_return_refund_request_blocks_auto_receipt(): void
    {
        $this->at('2026-10-16 20:30:00');
        [$order, , $shipments, $buyer] = $this->createOrder([
            ['status' => 'DELIVERED', 'delivered_at' => now()->subDays(4)],
        ]);
        $dispute = Dispute::query()->create([
            'dispute_number' => 'AUTO-RR-'.Str::upper(Str::random(8)),
            'opened_by_user_id' => $buyer->id,
            'order_id' => $order->id,
            'type' => 'RETURN_REFUND',
            'subject' => 'Return request for '.$order->order_number,
            'description' => 'Active return request.',
            'status' => 'RESOLVED',
            'opened_at' => now()->subDay(),
            'resolved_at' => now()->subHours(12),
        ]);
        ReturnRefundRequest::query()->create([
            'request_number' => 'AUTO-REQUEST-'.Str::upper(Str::random(8)),
            'dispute_id' => $dispute->id,
            'order_id' => $order->id,
            'buyer_user_id' => $buyer->id,
            'issue_category' => 'DAMAGED',
            'issue_reason' => 'Scratched item',
            'solution' => 'Return & Refund',
            'description' => 'The delivered item arrived scratched.',
            'refundable_amount' => 100,
            'requested_amount' => 100,
            'buyer_email' => $buyer->email,
            'status' => 'REQUEST_SUBMITTED',
            'submitted_at' => now()->subDay(),
        ]);

        $this->artisan('likhae:auto-confirm-delivered-orders')->assertExitCode(0);

        $this->assertSame('PROCESSING', $order->fresh()->status);
        $this->assertSame('DELIVERED', $shipments[0]->fresh()->current_status);
    }

    public function test_auto_receipt_completes_parent_seller_orders_and_shipments_with_system_events(): void
    {
        $this->at('2026-10-16 20:30:00');
        [$order, $sellerOrders, $shipments, $buyer] = $this->createOrder([
            ['status' => 'DELIVERED', 'delivered_at' => now()->subDays(4)],
            ['status' => 'DELIVERED', 'delivered_at' => now()->subDays(3)],
        ]);

        $this->artisan('likhae:auto-confirm-delivered-orders')->assertExitCode(0);

        $this->assertSame('COMPLETED', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->completed_at);
        $this->assertSame(['COMPLETED', 'COMPLETED'], $sellerOrders->map(fn ($sellerOrder) => $sellerOrder->fresh()->status)->all());
        $this->assertSame(['COMPLETED', 'COMPLETED'], $shipments->map(fn ($shipment) => $shipment->fresh()->current_status)->all());
        foreach ($shipments as $shipment) {
            $event = $shipment->events()->where('status', 'COMPLETED')->firstOrFail();
            $this->assertNull($event->actor_user_id);
            $this->assertSame(BuyerOrderActionService::AUTOMATIC_RECEIPT_NOTE, $event->notes);
        }
        $this->assertDatabaseHas('notifications', [
            'user_id' => $buyer->id,
            'type' => 'ORDER_COMPLETED',
            'reference_type' => Order::class,
            'reference_id' => $order->id,
        ]);
    }

    public function test_running_scheduler_twice_does_not_duplicate_completion_events_or_notifications(): void
    {
        $this->at('2026-10-16 20:30:00');
        [$order, , $shipments] = $this->createOrder([
            ['status' => 'DELIVERED', 'delivered_at' => now()->subDays(4)],
        ]);

        $this->artisan('likhae:auto-confirm-delivered-orders')->assertExitCode(0);
        $this->artisan('likhae:auto-confirm-delivered-orders')->assertExitCode(0);

        $this->assertSame(1, $shipments[0]->events()->where('status', 'COMPLETED')->count());
        $this->assertSame(1, Notification::query()->where('reference_type', Order::class)->where('reference_id', $order->id)->count());
    }

    public function test_manual_receipt_keeps_buyer_as_actor_and_scheduler_skips_it(): void
    {
        $this->at('2026-10-16 20:30:00');
        [$order, , $shipments, $buyer] = $this->createOrder([
            ['status' => 'DELIVERED', 'delivered_at' => now()->subDay()],
        ]);

        $this->actingAs($buyer)
            ->post(route('buyer.orders.received', $order), ['confirm_receipt' => '1'])
            ->assertRedirect();
        $this->artisan('likhae:auto-confirm-delivered-orders')->assertExitCode(0);

        $event = $shipments[0]->events()->where('status', 'COMPLETED')->firstOrFail();
        $this->assertSame($buyer->id, $event->actor_user_id);
        $this->assertSame('Buyer confirmed receipt.', $event->notes);
        $this->assertSame(1, $shipments[0]->events()->where('status', 'COMPLETED')->count());
    }

    public function test_api_receipt_uses_the_shared_completion_workflow_and_web_api_retries_are_idempotent(): void
    {
        [$order, $sellerOrders, $shipments, $buyer] = $this->createOrder([
            ['status' => 'DELIVERED', 'delivered_at' => now()->subDay()],
            ['status' => 'DELIVERED', 'delivered_at' => now()->subHours(12)],
        ]);
        $plainToken = Str::random(40);
        $buyer->forceFill([
            'api_token_hash' => hash('sha256', $plainToken),
            'api_token_expires_at' => now()->addHour(),
        ])->save();
        $api = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$plainToken,
        ]);

        $api->postJson(route('api.v1.buyer.orders.received', $order), ['confirm_receipt' => '1'])
            ->assertOk()
            ->assertJson(['success' => true, 'order_number' => $order->order_number, 'status' => 'COMPLETED']);

        $this->assertSame('COMPLETED', $order->fresh()->status);
        $this->assertSame(['COMPLETED', 'COMPLETED'], $sellerOrders->map(fn ($sellerOrder) => $sellerOrder->fresh()->status)->all());
        $this->assertSame(['COMPLETED', 'COMPLETED'], $shipments->map(fn ($shipment) => $shipment->fresh()->current_status)->all());
        foreach ($shipments as $shipment) {
            $this->assertSame(1, $shipment->events()->where('status', 'COMPLETED')->count());
        }

        $api->postJson(route('api.v1.buyer.orders.received', $order), ['confirm_receipt' => '1'])->assertOk();
        $this->flushHeaders();
        $this->actingAs($buyer)
            ->post(route('buyer.orders.received', $order), ['confirm_receipt' => '1'])
            ->assertRedirect();
        foreach ($shipments as $shipment) {
            $this->assertSame(1, $shipment->events()->where('status', 'COMPLETED')->count());
        }
    }

    public function test_api_receipt_rejects_undelivered_parcels_and_other_buyers(): void
    {
        [$order, , , $buyer] = $this->createOrder([
            ['status' => 'DELIVERY_FAILED', 'delivered_at' => null],
        ]);
        $plainToken = Str::random(40);
        $buyer->forceFill([
            'api_token_hash' => hash('sha256', $plainToken),
            'api_token_expires_at' => now()->addHour(),
        ])->save();
        $api = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$plainToken,
        ]);

        $api->postJson(route('api.v1.buyer.orders.received', $order), ['confirm_receipt' => '1'])->assertStatus(409);
        $this->assertSame('PROCESSING', $order->fresh()->status);

        $otherBuyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $otherToken = Str::random(40);
        $otherBuyer->forceFill([
            'api_token_hash' => hash('sha256', $otherToken),
            'api_token_expires_at' => now()->addHour(),
        ])->save();
        $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$otherToken,
        ])->postJson(route('api.v1.buyer.orders.received', $order), ['confirm_receipt' => '1'])->assertForbidden();
    }

    public function test_auto_receipt_does_not_shorten_the_existing_five_day_return_window(): void
    {
        $this->at('2026-10-16 20:30:00');
        [$order] = $this->createOrder([
            ['status' => 'DELIVERED', 'delivered_at' => now()->subDays(4)],
        ]);

        $this->artisan('likhae:auto-confirm-delivered-orders')->assertExitCode(0);
        $state = app(BuyerOrderActionService::class)->state($order->fresh(), true);

        $this->assertSame('COMPLETED', $order->fresh()->status);
        $this->assertTrue($state['can_request_return']);
        $this->assertTrue($state['return_deadline']->isFuture());
    }

    public function test_auto_completed_order_keeps_proof_access_and_allows_product_reviews(): void
    {
        $this->at('2026-10-16 20:30:00');
        Storage::fake('public');
        [$order, $sellerOrders, $shipments, $buyer] = $this->createOrder([
            ['status' => 'DELIVERED', 'delivered_at' => now()->subDays(4)],
        ]);
        $item = OrderItem::query()->create([
            'seller_order_id' => $sellerOrders[0]->id,
            'product_name' => 'Reviewable item',
            'sku' => 'AUTO-REVIEW-'.Str::upper(Str::random(8)),
            'unit_price' => 100,
            'quantity' => 1,
            'line_total' => 100,
        ]);
        $centerOwner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::query()->create([
            'owner_user_id' => $centerOwner->id,
            'code' => 'AUTO-CENTER-'.Str::upper(Str::random(6)),
            'business_name' => 'Automatic Receipt Center',
            'status' => 'ACTIVE',
        ]);
        $riderUser = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        $rider = RiderProfile::query()->create([
            'user_id' => $riderUser->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'AUTO-PLATE-'.Str::upper(Str::random(6)),
            'drivers_license_number' => 'AUTO-LICENSE-'.Str::upper(Str::random(6)),
            'status' => 'ACTIVE',
        ]);
        $assignment = RiderAssignment::query()->create([
            'shipment_id' => $shipments[0]->id,
            'rider_profile_id' => $rider->id,
            'assignment_type' => 'DELIVERY',
            'status' => 'COMPLETED',
            'completed_at' => now()->subDays(4),
        ]);
        Storage::disk('public')->put('delivery-proofs/auto-receipt.jpg', 'proof image');
        $attempt = DeliveryAttempt::query()->create([
            'shipment_id' => $shipments[0]->id,
            'rider_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'status' => 'DELIVERED',
            'proof_path' => 'delivery-proofs/auto-receipt.jpg',
            'attempted_at' => now()->subDays(4),
        ]);

        $this->artisan('likhae:auto-confirm-delivered-orders')->assertExitCode(0);
        $this->actingAs($buyer)->get(route('delivery-events.proof', $attempt))->assertOk();
        $this->actingAs($buyer)->postJson(route('buyer.orders.review.store', $item), [
            'rating' => 5,
            'comment' => 'The item arrived safely.',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('reviews', [
            'order_item_id' => $item->id,
            'buyer_user_id' => $buyer->id,
            'rating' => 5,
        ]);
    }

    public function test_order_details_show_server_calculated_auto_receipt_deadline(): void
    {
        $this->at('2026-10-16 20:30:00');
        $this->withoutVite();
        [$order, , , $buyer] = $this->createOrder([
            ['status' => 'DELIVERED', 'delivered_at' => now()->subDay()],
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertSee('Your order has been delivered.')
            ->assertSee('Order Received')
            ->assertSee('Oct 18, 2026 8:30 PM');
    }

    /** @return array{Order, \Illuminate\Support\Collection, \Illuminate\Support\Collection, User} */
    private function createOrder(array $shipmentSpecs): array
    {
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $category = Category::query()->firstOrCreate(
            ['slug' => 'automatic-receipt-tests'],
            ['name' => 'Automatic Receipt Tests', 'is_active' => true],
        );
        $order = Order::query()->create([
            'order_number' => 'AUTO-ORDER-'.Str::upper(Str::random(10)),
            'buyer_user_id' => $buyer->id,
            'status' => 'PROCESSING',
            'grand_total' => 100 * count($shipmentSpecs),
            'placed_at' => now()->subDays(7),
        ]);
        $sellerOrders = collect();
        $shipments = collect();

        foreach ($shipmentSpecs as $index => $spec) {
            $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
            $seller = SellerProfile::query()->create([
                'user_id' => $sellerUser->id,
                'primary_category_id' => $category->id,
                'business_name' => 'Automatic Receipt Seller '.Str::upper(Str::random(8)),
                'status' => 'ACTIVE',
            ]);
            $sellerOrder = SellerOrder::query()->create([
                'seller_order_number' => 'AUTO-SELLER-'.Str::upper(Str::random(10)),
                'order_id' => $order->id,
                'seller_profile_id' => $seller->id,
                'status' => 'READY_FOR_PICKUP',
                'grand_total' => 100,
            ]);
            $shipment = Shipment::query()->create([
                'seller_order_id' => $sellerOrder->id,
                'tracking_number' => 'AUTO-TRACK-'.Str::upper(Str::random(10)),
                'destination_province_code' => 'P-TEST',
                'destination_province_name' => 'Test Province',
                'destination_municipality_code' => 'M-TEST',
                'destination_municipality_name' => 'Test Municipality',
                'destination_barangay_code' => 'B-TEST',
                'destination_barangay_name' => 'Test Barangay',
                'current_status' => $spec['status'],
            ]);

            if ($spec['delivered_at'] instanceof Carbon) {
                ShipmentEvent::query()->create([
                    'shipment_id' => $shipment->id,
                    'status' => 'DELIVERED',
                    'notes' => 'Delivery rider delivered the parcel.',
                    'occurred_at' => $spec['delivered_at'],
                ]);
            }

            $sellerOrders->push($sellerOrder);
            $shipments->push($shipment);
        }

        return [$order, $sellerOrders, $shipments, $buyer];
    }

    private function at(string $timestamp): void
    {
        $this->travelTo(Carbon::parse($timestamp, 'Asia/Manila'));
    }
}
