<?php

namespace Tests\Feature;

use App\Models\Admin\Dispute;
use App\Models\Buyer\Order;
use App\Models\Buyer\BuyerVoucherClaim;
use App\Models\Buyer\OrderItem;
use App\Models\Buyer\Payment;
use App\Models\Buyer\ReturnRefundRequest;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentEvent;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Services\Marketplace\VoucherRedemptionCounter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyerOrderPage8WorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_tabs_map_cod_online_and_fulfillment_states_exclusively(): void
    {
        [$buyer, $variant] = $this->buyerAndVariant();
        [$codPlaced] = $this->makeOrder($buyer, $variant, 'PLACED', 'PLACED', 'PLACED', 'COD');
        [$toShip] = $this->makeOrder($buyer, $variant, 'PROCESSING', 'PREPARING', 'PREPARING', 'COD');
        [$toReceive] = $this->makeOrder($buyer, $variant, 'PROCESSING', 'PICKED_UP', 'OUT_FOR_DELIVERY', 'COD');
        [$toPay] = $this->makeOrder($buyer, $variant, 'PLACED', 'PLACED', 'PLACED', 'ONLINE');
        [$completed] = $this->makeOrder($buyer, $variant, 'COMPLETED', 'COMPLETED', 'COMPLETED', 'COD');
        [$cancelled] = $this->makeOrder($buyer, $variant, 'CANCELLED', 'CANCELLED', 'PLACED', 'COD');
        [$returnOrder, , , $returnItem] = $this->makeOrder($buyer, $variant, 'COMPLETED', 'COMPLETED', 'COMPLETED', 'COD');
        $dispute = Dispute::query()->create([
            'dispute_number' => 'P8-DSP-'.Str::upper(Str::random(8)),
            'opened_by_user_id' => $buyer->id,
            'order_id' => $returnOrder->id,
            'type' => 'RETURN_REFUND',
            'subject' => 'Return request',
            'description' => 'Buyer return request for tab mapping coverage.',
            'status' => 'OPEN',
            'opened_at' => now(),
        ]);
        ReturnRefundRequest::query()->create([
            'request_number' => 'P8-RET-'.Str::upper(Str::random(8)),
            'dispute_id' => $dispute->id,
            'order_id' => $returnOrder->id,
            'buyer_user_id' => $buyer->id,
            'issue_category' => 'DAMAGED',
            'issue_reason' => 'DAMAGED_ON_ARRIVAL',
            'solution' => 'REFUND',
            'description' => 'Buyer return request for tab mapping coverage.',
            'refund_method' => 'ORIGINAL_PAYMENT',
            'refundable_amount' => 10,
            'requested_amount' => 10,
            'buyer_email' => $buyer->email,
            'status' => 'REQUEST_SUBMITTED',
            'submitted_at' => now(),
        ]);

        $otherBuyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        [$foreignOrder] = $this->makeOrder($otherBuyer, $variant, 'PROCESSING', 'PREPARING', 'PREPARING', 'COD');

        $toShipResponse = $this->actingAs($buyer)
            ->get(route('buyer.orders', ['status' => 'to-ship']))
            ->assertOk();
        $this->assertEqualsCanonicalizing([$codPlaced->order_number, $toShip->order_number], $this->orderNumbersOnCards($toShipResponse));

        $toReceiveResponse = $this->get(route('buyer.orders', ['status' => 'to-receive']))->assertOk();
        $this->assertSame([$toReceive->order_number], $this->orderNumbersOnCards($toReceiveResponse));

        $toPayResponse = $this->get(route('buyer.orders', ['status' => 'to-pay']))->assertOk();
        $this->assertSame([$toPay->order_number], $this->orderNumbersOnCards($toPayResponse));

        $completedResponse = $this->get(route('buyer.orders', ['status' => 'completed']))->assertOk();
        $this->assertSame([$completed->order_number], $this->orderNumbersOnCards($completedResponse));

        $returnResponse = $this->get(route('buyer.orders', ['status' => 'returns']))->assertOk();
        $this->assertSame([$returnOrder->order_number], $this->orderNumbersOnCards($returnResponse));

        $cancelledResponse = $this->get(route('buyer.orders', ['status' => 'cancelled']))->assertOk();
        $this->assertSame([$cancelled->order_number], $this->orderNumbersOnCards($cancelledResponse));

        $allResponse = $this->get(route('buyer.orders', ['status' => 'all']))
            ->assertOk()
            ->assertSee('My Orders')
            ->assertSee($returnItem->product_name)
            ->assertSee('To Pay: 1 order', false)
            ->assertSee('To Ship: 2 orders', false)
            ->assertSee('To Receive: 1 order', false);
        $this->assertEqualsCanonicalizing([
            $toShip->order_number,
            $codPlaced->order_number,
            $toReceive->order_number,
            $toPay->order_number,
            $completed->order_number,
            $cancelled->order_number,
            $returnOrder->order_number,
        ], $this->orderNumbersOnCards($allResponse));
        $this->assertNotContains($foreignOrder->order_number, $this->orderNumbersOnCards($allResponse));
        $this->assertCount(7, $this->orderNumbersOnCards($allResponse));
    }

    public function test_ready_for_pickup_parent_order_is_in_to_ship(): void
    {
        [$buyer, $variant] = $this->buyerAndVariant();
        [$readyForPickup] = $this->makeOrder($buyer, $variant, 'PROCESSING', 'READY_FOR_PICKUP', 'READY_FOR_PICKUP', 'COD');

        $response = $this->actingAs($buyer)
            ->get(route('buyer.orders', ['status' => 'to-ship']))
            ->assertOk();

        $this->assertSame([$readyForPickup->order_number], $this->orderNumbersOnCards($response));
    }

    public function test_mixed_seller_statuses_and_multiple_parcels_render_once_per_parent_order(): void
    {
        [$buyer, $variant] = $this->buyerAndVariant();
        [$order, $sellerAOrder, $firstShipment, $firstItem] = $this->makeOrder(
            $buyer,
            $variant,
            'PROCESSING',
            'COMPLETED',
            'DELIVERED',
            'COD',
        );

        $secondItem = OrderItem::query()->create([
            'seller_order_id' => $sellerAOrder->id,
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'product_name' => 'Second parcel item',
            'sku' => 'P8-SKU-'.Str::upper(Str::random(8)),
            'variant_description' => 'Test variant',
            'unit_price' => 12.34,
            'quantity' => 1,
            'discount_amount' => 0,
            'line_total' => 12.34,
        ]);
        $secondShipment = Shipment::query()->create([
            'seller_order_id' => $sellerAOrder->id,
            'order_item_id' => $secondItem->id,
            'tracking_number' => 'P8-TRACK-'.Str::upper(Str::random(10)),
            'destination_province_code' => 'P8-PROVINCE',
            'destination_province_name' => 'Test Province',
            'destination_municipality_code' => 'P8-MUNICIPALITY',
            'destination_municipality_name' => 'Test Municipality',
            'destination_barangay_code' => 'P8-BARANGAY',
            'destination_barangay_name' => 'Test Barangay',
            'current_status' => 'DELIVERED',
        ]);

        $sellerBVariant = ProductVariant::query()
            ->with('product')
            ->whereHas('product', fn ($query) => $query->where('slug', 'test-amihan-home-goods-handwoven-abaca-storage-basket'))
            ->firstOrFail();
        $sellerBOrder = SellerOrder::query()->create([
            'seller_order_number' => 'P8-SELLER-'.Str::upper(Str::random(10)),
            'order_id' => $order->id,
            'seller_profile_id' => $sellerBVariant->product->seller_profile_id,
            'status' => 'PREPARING',
            'item_subtotal' => 20,
            'grand_total' => 20,
        ]);
        $sellerBItem = OrderItem::query()->create([
            'seller_order_id' => $sellerBOrder->id,
            'product_id' => $sellerBVariant->product_id,
            'product_variant_id' => $sellerBVariant->id,
            'product_name' => 'Seller B mixed-status item',
            'sku' => 'P8-SKU-'.Str::upper(Str::random(8)),
            'variant_description' => 'Test variant',
            'unit_price' => 20,
            'quantity' => 1,
            'discount_amount' => 0,
            'line_total' => 20,
        ]);
        $sellerBShipment = Shipment::query()->create([
            'seller_order_id' => $sellerBOrder->id,
            'order_item_id' => $sellerBItem->id,
            'tracking_number' => 'P8-TRACK-'.Str::upper(Str::random(10)),
            'destination_province_code' => 'P8-PROVINCE',
            'destination_province_name' => 'Test Province',
            'destination_municipality_code' => 'P8-MUNICIPALITY',
            'destination_municipality_name' => 'Test Municipality',
            'destination_barangay_code' => 'P8-BARANGAY',
            'destination_barangay_name' => 'Test Barangay',
            'current_status' => 'READY_FOR_PICKUP',
        ]);

        $response = $this->actingAs($buyer)
            ->get(route('buyer.orders', ['status' => 'to-receive']))
            ->assertOk()
            ->assertSee($firstShipment->tracking_number)
            ->assertSee($secondShipment->tracking_number)
            ->assertSee($sellerBShipment->tracking_number)
            ->assertSee('To Receive')
            ->assertSee('To Ship');

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $parentCards = $xpath->query('//article[@data-order-id="'.$order->id.'"]');
        $this->assertSame(1, $parentCards->length, 'Mixed seller states must still render one parent-order card.');
        $this->assertSame(1, (int) $response->viewData('orders')->total());
        $this->assertSame(2, $xpath->query('//article[@data-order-id="'.$order->id.'"]//*[@data-seller-order-status-id]')->length);
    }

    public function test_all_orders_pagination_keeps_parent_order_totals_and_card_counts(): void
    {
        [$buyer, $variant] = $this->buyerAndVariant();
        for ($index = 0; $index < 12; $index++) {
            $this->makeOrder($buyer, $variant, 'PROCESSING', 'PREPARING', 'PREPARING', 'COD');
        }

        $firstPage = $this->actingAs($buyer)
            ->get(route('buyer.orders', ['status' => 'all', 'page' => 1]))
            ->assertOk();
        $secondPage = $this->get(route('buyer.orders', ['status' => 'all', 'page' => 2]))
            ->assertOk();

        $this->assertSame(12, (int) $firstPage->viewData('orders')->total());
        $this->assertCount(10, $this->orderNumbersOnCards($firstPage));
        $this->assertCount(2, $this->orderNumbersOnCards($secondPage));
        $this->assertSame(1, (int) $firstPage->viewData('orders')->currentPage());
        $this->assertSame(2, (int) $secondPage->viewData('orders')->currentPage());
        $firstPageNumbers = $this->orderNumbersOnCards($firstPage);
        $this->assertCount(10, array_unique($firstPageNumbers), 'A shipment relationship must not multiply a parent-order card.');
    }

    public function test_cancellation_requires_reason_restores_stock_once_and_releases_voucher_usage(): void
    {
        [$buyer, $variant] = $this->buyerAndVariant();
        $variant->update(['stock' => 5]);
        $voucher = Voucher::query()->create([
            'seller_profile_id' => $variant->product->seller_profile_id,
            'code' => 'P8-'.Str::upper(Str::random(8)),
            'name' => 'Page 8 cancellation voucher',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);
        BuyerVoucherClaim::query()->create(['buyer_user_id' => $buyer->id, 'voucher_id' => $voucher->id]);
        [$order, $sellerOrder, $shipment] = $this->makeOrder($buyer, $variant, 'PROCESSING', 'PREPARING', 'PREPARING', 'COD', 2, $voucher);
        $this->assertSame(1, VoucherRedemptionCounter::count((int) $voucher->id, (int) $buyer->id));

        $this->actingAs($buyer)
            ->postJson(route('buyer.orders.cancel', $order), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->assertSame(5, (int) $variant->fresh()->stock);
        $this->assertSame('PROCESSING', $order->fresh()->status);

        $request = ['reason' => 'ORDERED_BY_MISTAKE'];
        $this->postJson(route('buyer.orders.cancel', $order), $request)
            ->assertOk()
            ->assertJson(['success' => true, 'status' => 'CANCELLED']);

        $this->assertSame(7, (int) $variant->fresh()->stock);
        $this->assertSame('CANCELLED', $order->fresh()->status);
        $this->assertSame('CANCELLED', $sellerOrder->fresh()->status);
        $this->assertSame('Ordered by mistake', $order->fresh()->cancellation_reason);
        $this->assertSame('PENDING', $order->payments()->firstOrFail()->status);
        $this->assertSame(0, VoucherRedemptionCounter::count((int) $voucher->id, (int) $buyer->id));
        $this->assertDatabaseHas('buyer_voucher_claims', ['buyer_user_id' => $buyer->id, 'voucher_id' => $voucher->id]);
        $this->assertSame(1, $shipment->events()->where('notes', 'like', 'BUYER_ORDER_CANCELLED:%')->count());

        $this->postJson(route('buyer.orders.cancel', $order), $request)
            ->assertOk()
            ->assertJson(['success' => true, 'already_cancelled' => true]);
        $this->assertSame(7, (int) $variant->fresh()->stock);
        $this->assertSame(1, $shipment->events()->where('notes', 'like', 'BUYER_ORDER_CANCELLED:%')->count());
    }

    public function test_cancellation_is_blocked_at_ready_for_pickup_picked_up_and_delivered(): void
    {
        [$buyer, $variant] = $this->buyerAndVariant();
        foreach ([
            ['READY_FOR_PICKUP', 'READY_FOR_PICKUP'],
            ['PICKED_UP', 'PICKED_UP'],
            ['PICKED_UP', 'DELIVERED'],
        ] as [$sellerStatus, $shipmentStatus]) {
            [$order] = $this->makeOrder($buyer, $variant, 'PROCESSING', $sellerStatus, $shipmentStatus, 'COD');
            $stockBefore = (int) $variant->fresh()->stock;

            $this->actingAs($buyer)
                ->get(route('buyer.orders.show', $order))
                ->assertOk()
                ->assertDontSee('Cancel Order');
            $this->postJson(route('buyer.orders.cancel', $order), ['reason' => 'CHANGED_MIND'])
                ->assertStatus(409);
            $this->assertSame($stockBefore, (int) $variant->fresh()->stock);
            $this->assertSame('PROCESSING', $order->fresh()->status);
        }
    }

    public function test_receipt_is_rejected_before_delivery_then_completes_idempotently_with_ownership_checks(): void
    {
        [$buyer, $variant] = $this->buyerAndVariant();
        $variant->update(['stock' => 5]);
        [$order, $sellerOrder, $shipment] = $this->makeOrder($buyer, $variant, 'PROCESSING', 'PICKED_UP', 'OUT_FOR_DELIVERY', 'COD');
        $this->actingAs($buyer)
            ->postJson(route('buyer.orders.received', $order), ['confirm_receipt' => '1'])
            ->assertStatus(409);

        $shipment->update(['current_status' => 'DELIVERED']);
        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'status' => 'DELIVERED',
            'notes' => 'Delivery complete.',
            'occurred_at' => now(),
        ]);
        $this->get(route('buyer.orders.show', $order))->assertOk()->assertSee('Order Received');
        $this->postJson(route('buyer.orders.received', $order), ['confirm_receipt' => '1'])
            ->assertOk()
            ->assertJson(['success' => true, 'status' => 'COMPLETED']);
        $this->postJson(route('buyer.orders.received', $order), ['confirm_receipt' => '1'])->assertOk();
        $this->assertSame('COMPLETED', $order->fresh()->status);
        $this->assertSame('COMPLETED', $sellerOrder->fresh()->status);
        $this->assertSame('COMPLETED', $shipment->fresh()->current_status);
        $this->assertSame(5, (int) $variant->fresh()->stock);
        $this->assertSame(1, $shipment->events()->where('status', 'COMPLETED')->count());

        $otherBuyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $this->actingAs($otherBuyer)->get(route('buyer.orders.show', $order))->assertForbidden();
        $this->postJson(route('buyer.orders.cancel', $order), ['reason' => 'CHANGED_MIND'])->assertForbidden();
        $this->postJson(route('buyer.orders.received', $order), ['confirm_receipt' => '1'])->assertForbidden();
    }

    public function test_purchase_lines_and_order_totals_use_historical_snapshots_and_timeline_hides_future_pending_steps(): void
    {
        [$buyer, $variant] = $this->buyerAndVariant();
        $variant->update(['price' => 999, 'stock' => 5]);
        [$order, , $shipment, $item] = $this->makeOrder($buyer, $variant, 'PROCESSING', 'PREPARING', 'READY_FOR_PICKUP', 'COD', 2);
        $order->update([
            'subtotal' => 24.68,
            'discount_total' => 2,
            'shipping_total' => 5,
            'protection_total' => 1,
            'grand_total' => 28.68,
        ]);
        $item->update(['product_name' => 'Historical snapshot product', 'unit_price' => 12.34, 'line_total' => 24.68]);
        $shipment->events()->delete();
        foreach ([['PLACED', 3], ['PREPARING', 2], ['READY_FOR_PICKUP', 1]] as [$status, $minutes]) {
            ShipmentEvent::query()->create([
                'shipment_id' => $shipment->id,
                'status' => $status,
                'notes' => $status.' internal note should not be shown.',
                'occurred_at' => now()->subMinutes($minutes),
            ]);
        }

        $this->actingAs($buyer)
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertSee('Historical snapshot product')
            ->assertSee('12.34')
            ->assertSee('24.68')
            ->assertSee('28.68')
            ->assertDontSee('999.00');

        $response = $this->get(route('buyer.orders.show', $order))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $timeline = $xpath->query('//ol[contains(@aria-label, "Tracking timeline")]')->item(0);
        $this->assertNotNull($timeline);
        $this->assertStringNotContainsString('Pending', $timeline->textContent);
        $this->assertStringContainsString('Order placed', $timeline->textContent);
        $this->assertStringContainsString('Ready for pickup', $timeline->textContent);
        $this->assertStringNotContainsString('internal note', $timeline->textContent);

        $publicTracking = $this->get(route('tracking.show', $shipment->tracking_number))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($publicTracking->getContent());
        $xpath = new \DOMXPath($document);
        $publicTimeline = $xpath->query('//ol[@aria-label="Shipment progress timeline"]')->item(0);
        $this->assertNotNull($publicTimeline);
        $this->assertStringNotContainsString('Pending', $publicTimeline->textContent);
        $this->assertStringNotContainsString('internal note', $publicTimeline->textContent);
    }

    /** @return array{User, ProductVariant} */
    private function buyerAndVariant(): array
    {
        $this->seed(DatabaseSeeder::class);
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $variant = ProductVariant::query()
            ->with('product.sellerProfile')
            ->whereHas('product', fn ($query) => $query->where('slug', 'sample-handmade-tote'))
            ->firstOrFail();

        return [$buyer, $variant];
    }

    /** @return list<string> */
    private function orderNumbersOnCards(\Illuminate\Testing\TestResponse $response): array
    {
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $cards = $xpath->query('//article[@data-order-id]');
        $numbers = [];
        foreach ($cards as $card) {
            $orderNumber = trim($card->getAttribute('data-order-number'));
            if ($orderNumber !== '') {
                $numbers[] = $orderNumber;

                continue;
            }
            if (preg_match('/P8-ORD-[A-Z0-9_-]+/', $card->textContent, $matches)) {
                $numbers[] = $matches[0];
            }
        }

        return $numbers;
    }

    /** @return array{Order, SellerOrder, Shipment, OrderItem} */
    private function makeOrder(
        User $buyer,
        ProductVariant $variant,
        string $orderStatus,
        string $sellerStatus,
        string $shipmentStatus,
        string $paymentMethod,
        int $quantity = 2,
        ?Voucher $voucher = null,
    ): array {
        $product = $variant->product;
        $order = Order::query()->create([
            'order_number' => 'P8-ORD-'.$orderStatus.'-'.$sellerStatus.'-'.$shipmentStatus.'-'.Str::upper(Str::random(6)),
            'buyer_user_id' => $buyer->id,
            'status' => $orderStatus,
            'subtotal' => 24.68,
            'discount_total' => 0,
            'shipping_total' => 0,
            'protection_total' => 0,
            'grand_total' => 24.68,
            'payment_status' => $paymentMethod === 'ONLINE' ? 'PENDING' : 'PENDING',
            'placed_at' => now()->subHour(),
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_number' => 'P8-SELLER-'.Str::upper(Str::random(10)),
            'order_id' => $order->id,
            'seller_profile_id' => $product->seller_profile_id,
            'voucher_id' => $voucher?->id,
            'status' => $sellerStatus,
            'item_subtotal' => 24.68,
            'grand_total' => 24.68,
        ]);
        $item = OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name' => 'Historical snapshot product',
            'sku' => 'P8-SKU-'.Str::upper(Str::random(8)),
            'variant_description' => 'Test variant',
            'unit_price' => 12.34,
            'quantity' => $quantity,
            'discount_amount' => 0,
            'line_total' => 24.68,
        ]);
        $shipment = Shipment::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'order_item_id' => $item->id,
            'tracking_number' => 'P8-TRACK-'.Str::upper(Str::random(10)),
            'destination_province_code' => 'P8-PROVINCE',
            'destination_province_name' => 'Test Province',
            'destination_municipality_code' => 'P8-MUNICIPALITY',
            'destination_municipality_name' => 'Test Municipality',
            'destination_barangay_code' => 'P8-BARANGAY',
            'destination_barangay_name' => 'Test Barangay',
            'current_status' => $shipmentStatus,
        ]);
        Payment::query()->create([
            'order_id' => $order->id,
            'method' => $paymentMethod,
            'provider' => $paymentMethod,
            'amount' => 24.68,
            'status' => 'PENDING',
            'initiated_at' => now()->subHour(),
        ]);
        if ($shipmentStatus !== 'PLACED') {
            ShipmentEvent::query()->create([
                'shipment_id' => $shipment->id,
                'status' => $shipmentStatus,
                'notes' => $shipmentStatus === 'COMPLETED' ? 'Buyer confirmed receipt.' : 'Current fulfillment stage.',
                'occurred_at' => now()->subMinutes(5),
            ]);
        }

        return [$order, $sellerOrder, $shipment, $item];
    }
}
