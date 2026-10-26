<?php

namespace Tests\Feature;

use App\Models\Buyer\Order;
use App\Models\Buyer\OrderAddress;
use App\Models\Buyer\OrderItem;
use App\Models\Buyer\Payment;
use App\Models\Buyer\Review;
use App\Models\Communication\Message;
use App\Models\Admin\AuditLog;
use App\Models\Buyer\Address;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Rider\RiderLocalTripItem;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Services\Communication\ConversationService;
use App\Services\Marketplace\VoucherRedemptionCounter;
use App\Support\OrderItemParcelAmount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SellerModuleAuditRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_name_and_logo_update_and_case_insensitive_store_name_uniqueness(): void
    {
        Storage::fake('public');
        [$seller, $profile] = $this->seller('Likhae Crafts');

        $this->actingAs($seller)->put(route('seller.store.update'), [
            'business_name' => '  New Craft Shop  ',
            'avatar' => UploadedFile::fake()->image('logo.png'),
            'avatar_original' => UploadedFile::fake()->image('logo-original.png'),
        ])->assertRedirect();

        $profile->refresh();
        $this->assertSame('New Craft Shop', $profile->business_name);
        $this->assertNotNull($profile->avatar_path);
        Storage::disk('public')->assertExists($profile->avatar_path);
        $this->assertNull($seller->fresh()->profile_photo_path);
        $this->actingAs($seller)->get(route('seller.store'))->assertOk()->assertSee('name="business_name"', false);

        $this->actingAs($seller)->putJson(route('seller.store.update'), [
            'business_name' => 'ab',
        ])->assertStatus(422)->assertJsonValidationErrors('business_name');
        $this->actingAs($seller)->putJson(route('seller.store.update'), [
            'business_name' => 'New',
        ])->assertRedirect();
        $this->actingAs($seller)->putJson(route('seller.store.update'), [
            'business_name' => str_repeat('S', 30),
        ])->assertRedirect();
        $this->actingAs($seller)->putJson(route('seller.store.update'), [
            'business_name' => 'New Craft Shop',
        ])->assertRedirect();
        $this->actingAs($seller)->putJson(route('seller.store.update'), [
            'business_name' => str_repeat('S', 31),
        ])->assertStatus(422)->assertJsonValidationErrors('business_name');

        [$otherSeller] = $this->seller('Another Shop');
        $this->actingAs($otherSeller)->put(route('seller.store.update'), [
            'business_name' => '  NEW CRAFT SHOP  ',
        ])->assertRedirect()->assertSessionHasErrors('business_name');

        $this->actingAs($otherSeller)->put(route('seller.store.update'), [
            'business_name' => '  New   Craft   Shop  ',
        ])->assertRedirect()->assertSessionHasErrors('business_name');

        $this->actingAs($otherSeller)->put(route('seller.store.update'), [
            'business_name' => " \t\n ",
        ])->assertRedirect()->assertSessionHasErrors('business_name');
        $this->assertSame('Another Shop', $otherSeller->sellerProfile->fresh()->business_name);
    }

    public function test_store_description_accepts_500_and_rejects_501_characters(): void
    {
        [$seller, $profile] = $this->seller('Description Shop');

        $this->actingAs($seller)->putJson(route('seller.store.update'), [
            'business_name' => 'Description Shop',
            'description' => str_repeat('x', 500),
        ])->assertRedirect();
        $this->assertSame(500, mb_strlen($profile->fresh()->description));

        $this->actingAs($seller)->putJson(route('seller.store.update'), [
            'business_name' => 'Description Shop',
            'description' => str_repeat('y', 501),
        ])->assertStatus(422)->assertJsonValidationErrors('description');
        $this->assertSame(500, mb_strlen($profile->fresh()->description));

        $this->actingAs($seller)->get(route('seller.store'))->assertOk()->assertSee('maxlength="500"', false);
    }

    public function test_logo_and_banner_reject_spoofed_and_oversized_original_images(): void
    {
        [$seller] = $this->seller('Image Rules Shop');
        $spoofed = fn (string $name): UploadedFile => UploadedFile::fake()->createWithContent($name, 'not an image');

        $this->actingAs($seller)->put(route('seller.store.update'), [
            'business_name' => 'Image Rules Shop',
            'avatar' => $spoofed('logo.jpg'),
            'avatar_original' => $spoofed('logo-original.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->actingAs($seller)->put(route('seller.store.update'), [
            'business_name' => 'Image Rules Shop',
            'banner' => UploadedFile::fake()->image('banner.webp'),
            'banner_original' => UploadedFile::fake()->image('banner-original.webp')->size(5121),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('banner_original');
    }

    public function test_order_list_combines_seller_scoped_status_search_and_inclusive_dates(): void
    {
        [$seller, $profile] = $this->seller('Order Filter Shop');
        $included = $this->orderFixture($profile, 'FILTER-IN', 'PLACED', '2026-10-12 23:59:00');
        $included['item']->update(['product_name' => 'Blue Widget']);
        $this->orderFixture($profile, 'FILTER-STATUS', 'CONFIRMED', '2026-10-12 10:00:00');
        $this->orderFixture($profile, 'FILTER-DATE', 'PLACED', '2026-10-13 00:01:00');
        [, $foreignProfile] = $this->seller('Foreign Filter Shop');
        $foreign = $this->orderFixture($foreignProfile, 'FILTER-FOREIGN', 'PLACED', '2026-10-12 12:00:00');
        $foreign['item']->update(['product_name' => 'Blue Widget']);

        $response = $this->actingAs($seller)->get(route('seller.orders', [
            'status' => 'placed',
            'q' => 'Blue Widget',
            'from_date' => '2026-10-12',
            'to_date' => '2026-10-12',
        ]));
        $response->assertOk()->assertSee('name="from_date"', false)->assertSee('name="to_date"', false);
        $this->assertSame(1, preg_match('/<div class="sl-order-list">(.*?)<div class="sl-no-results/s', $response->getContent(), $orderListMatch));
        $orderList = $orderListMatch[1];
        $this->assertStringContainsString('FILTER-IN', $orderList);
        $this->assertStringNotContainsString('FILTER-STATUS', $orderList);
        $this->assertStringNotContainsString('FILTER-DATE', $orderList);
        $this->assertStringNotContainsString('FILTER-FOREIGN', $orderList);

        $this->actingAs($seller)->getJson(route('seller.orders', [
            'from_date' => '2026-10-13',
            'to_date' => '2026-10-12',
        ]))->assertStatus(422)->assertJsonValidationErrors('to_date');
    }

    public function test_order_pagination_preserves_status_search_and_date_filters(): void
    {
        [$seller, $profile] = $this->seller('Paginated Order Shop');
        for ($number = 1; $number <= 21; $number++) {
            $fixture = $this->orderFixture($profile, sprintf('PAGE-%02d', $number), 'PLACED', '2026-10-12 12:00:00');
            $fixture['item']->update(['product_name' => 'Page Widget']);
            $fixture['sellerOrder']->forceFill([
                'created_at' => Carbon::parse('2026-10-12 12:00:00')->subMinutes($number),
            ])->save();
        }

        $filters = [
            'status' => 'placed',
            'q' => 'Page Widget',
            'from_date' => '2026-10-12',
            'to_date' => '2026-10-12',
        ];
        $response = $this->actingAs($seller)->get(route('seller.orders', $filters));
        $response->assertOk();
        $this->assertSame(1, preg_match('/href="([^"]*page=2[^"]*)"/', $response->getContent(), $matches));
        parse_str((string) parse_url(html_entity_decode($matches[1]), PHP_URL_QUERY), $pageTwoQuery);

        $this->assertSame('placed', $pageTwoQuery['status'] ?? null);
        $this->assertSame('Page Widget', $pageTwoQuery['q'] ?? null);
        $this->assertSame('2026-10-12', $pageTwoQuery['from_date'] ?? null);
        $this->assertSame('2026-10-12', $pageTwoQuery['to_date'] ?? null);
        $this->assertSame('2', $pageTwoQuery['page'] ?? null);

        $this->actingAs($seller)->get(route('seller.orders', $pageTwoQuery))
            ->assertOk()
            ->assertSee('PAGE-21');
    }

    public function test_packing_is_required_before_ready_or_direct_handover_and_waybill_uses_recorded_measurements(): void
    {
        [$seller, $profile] = $this->seller('Packing Shop');
        $fixture = $this->orderFixture($profile, 'PACK-ORDER', 'PLACED');
        Payment::create(['order_id' => $fixture['order']->id, 'method' => 'COD', 'amount' => '53.00']);
        $this->actingAs($seller);
        $statusRoute = route('seller.orders.status', $fixture['sellerOrder']);

        $this->patch($statusRoute, ['action' => 'confirm'])->assertRedirect();
        $this->patch($statusRoute, ['action' => 'prepare'])->assertRedirect();
        $this->patch($statusRoute, ['action' => 'ready'])->assertSessionHasErrors('shipment_ids');
        $this->post(route('seller.logistics.pickup', $fixture['sellerOrder']), [
            'handover_method' => 'logistics_pickup',
        ])->assertSessionHasErrors('shipment_ids');
        $this->assertSame('PREPARING', $fixture['sellerOrder']->fresh()->status);

        $shipmentId = $fixture['item']->shipment()->value('id');
        $this->patch($statusRoute, ['action' => 'pack', 'shipment_ids' => [$shipmentId]])->assertRedirect();

        $centerUser = $this->user(User::TYPE_LOGISTICS);
        $centerAddress = Address::create([
            'user_id' => $centerUser->id,
            'label' => 'Packing Center',
            'recipient_name' => $centerUser->name,
            'contact_number' => $centerUser->contact_number,
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403424000',
            'municipality_name' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay_name' => 'Bagong Bayan II-A',
            'street_address' => 'Packing Center Road',
        ]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerUser->id,
            'address_id' => $centerAddress->id,
            'code' => 'PACKING-CENTER-'.uniqid(),
            'business_name' => 'Packing Logistics Center',
            'status' => 'ACTIVE',
            'approved_by_user_id' => $centerUser->id,
            'approved_at' => now(),
        ]);
        $area = ServiceArea::create([
            'logistics_center_id' => $center->id,
            'code' => 'PACKING-AREA-'.uniqid(),
            'name' => 'Packing Delivery Area',
            'is_active' => true,
        ]);
        ServiceAreaLocation::create([
            'service_area_id' => $area->id,
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403424000',
            'municipality_name' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay_name' => 'Bagong Bayan II-A',
        ]);
        $fixture['item']->shipment()->update([
            'logistics_center_id' => $center->id,
            'origin_logistics_center_id' => $center->id,
            'destination_logistics_center_id' => $center->id,
            'service_area_id' => $area->id,
            'origin_province_code' => '0403400000',
            'origin_province_name' => 'Laguna',
            'origin_municipality_code' => '0403424000',
            'origin_municipality_name' => 'City of San Pablo',
            'origin_barangay_code' => '0403424001',
            'origin_barangay_name' => 'Bagong Bayan II-A',
        ]);
        $this->patch(route('seller.orders.parcels.measurements', [$fixture['sellerOrder'], $fixture['item']]), [
            'parcel_weight_kg' => '1.275',
            'parcel_volume_liters' => '2.500',
        ])->assertRedirect();
        $this->patch($statusRoute, ['action' => 'ready', 'shipment_ids' => [$shipmentId]])->assertRedirect();
        $this->assertSame('READY_FOR_PICKUP', $fixture['sellerOrder']->fresh()->status);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $fixture['item']->shipment()->value('id'),
            'status' => 'PREPARING',
            'notes' => 'SELLER_PACKED_CHECKPOINT: Seller marked this item parcel packed.',
        ]);

        $shipment = $fixture['item']->shipment()->firstOrFail();
        $this->get(route('seller.orders.waybill.item', [$fixture['sellerOrder'], $fixture['item']]))
            ->assertOk()->assertSee($shipment->tracking_number)->assertSee('1.275 kg')->assertSee('COD - PHP 53.00');

        $onlineFixture = $this->orderFixture($profile, 'UNWEIGHED-ORDER', 'PLACED');
        Payment::create(['order_id' => $onlineFixture['order']->id, 'method' => 'ONLINE', 'amount' => '53.00']);
        $this->get(route('seller.orders.waybill.item', [$onlineFixture['sellerOrder'], $onlineFixture['item']]))
            ->assertStatus(409);
        $onlineFixture['sellerOrder']->update(['status' => 'CONFIRMED']);
        $this->get(route('seller.orders.waybill.item', [$onlineFixture['sellerOrder'], $onlineFixture['item']]))
            ->assertOk()->assertSee('Weight not recorded')->assertSee('Prepaid');
    }

    public function test_cod_cancellation_requires_reason_restores_variant_stock_once_and_releases_voucher_usage(): void
    {
        [$seller, $profile] = $this->seller('Cancellation Shop');
        $fixture = $this->orderFixture($profile, 'CANCEL-ORDER', 'PLACED');
        $product = Product::create([
            'seller_profile_id' => $profile->id,
            'category_id' => $profile->primary_category_id,
            'name' => 'Stocked Craft',
            'slug' => 'stocked-craft-'.uniqid(),
            'status' => 'ACTIVE',
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'CANCEL-SKU-'.uniqid(),
            'price' => '25.00',
            'stock' => 3,
            'is_default' => true,
            'is_active' => true,
        ]);
        $fixture['item']->update(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 2]);
        $voucher = Voucher::create([
            'seller_profile_id' => $profile->id,
            'code' => 'CANCEL-VOUCHER',
            'name' => 'Cancellation voucher',
            'discount_type' => 'FIXED',
            'discount_value' => '2.00',
            'usage_limit' => 1,
            'per_user_limit' => 1,
            'is_active' => true,
        ]);
        $fixture['sellerOrder']->update(['voucher_id' => $voucher->id]);
        Payment::create(['order_id' => $fixture['order']->id, 'method' => 'COD', 'amount' => '53.00']);

        // Keep another seller order open on the same parent order to verify parent reconciliation.
        [, $otherProfile] = $this->seller('Other Cancellation Shop');
        SellerOrder::create([
            'seller_order_number' => 'CANCEL-OTHER-'.uniqid(),
            'order_id' => $fixture['order']->id,
            'seller_profile_id' => $otherProfile->id,
            'status' => 'PREPARING',
            'item_subtotal' => 20,
            'grand_total' => 20,
        ]);

        $this->actingAs($seller);
        $transition = route('seller.orders.status', $fixture['sellerOrder']);
        $this->patch($transition, ['action' => 'cancel', 'cancellation_reason' => '   '])->assertSessionHasErrors('cancellation_reason');
        $this->patch($transition, ['action' => 'confirm'])->assertRedirect();
        $this->patch($transition, ['action' => 'prepare'])->assertRedirect();
        $this->patch($transition, ['action' => 'pack'])->assertRedirect();
        $this->patch($transition, ['action' => 'ready'])->assertRedirect();
        $this->assertSame(1, VoucherRedemptionCounter::count($voucher->id));

        $centerUser = $this->user(User::TYPE_LOGISTICS);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerUser->id,
            'code' => 'CANCEL-CENTER-'.uniqid(),
            'business_name' => 'Cancellation Center',
            'status' => 'ACTIVE',
        ]);
        $riderUser = $this->user(User::TYPE_RIDER);
        $rider = RiderProfile::create([
            'user_id' => $riderUser->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'approved_parcel_capacity' => 10,
            'plate_number' => 'CANCEL-'.uniqid(),
            'drivers_license_number' => 'CANCEL-LICENSE-'.uniqid(),
            'status' => 'ACTIVE',
        ]);
        $shipment = $fixture['item']->shipment()->firstOrFail();
        $trip = RiderLocalTrip::create([
            'rider_profile_id' => $rider->id,
            'logistics_center_id' => $center->id,
            'seller_profile_id' => $profile->id,
            'trip_type' => RiderLocalTrip::TYPE_PICKUP,
            'province_code' => '0403400000',
            'municipality_code' => '0403424000',
            'barangay_code' => '0403424001',
            'approved_parcel_capacity' => 10,
            'status' => RiderLocalTrip::LOADING,
            'created_by_user_id' => $centerUser->id,
        ]);
        $assignment = RiderAssignment::create([
            'shipment_id' => $shipment->id,
            'rider_profile_id' => $rider->id,
            'assignment_type' => 'PICKUP',
            'status' => 'ASSIGNED',
            'assigned_by_user_id' => $centerUser->id,
            'assigned_at' => now(),
        ]);
        RiderLocalTripItem::create([
            'rider_local_trip_id' => $trip->id,
            'rider_assignment_id' => $assignment->id,
            'added_by_user_id' => $centerUser->id,
            'status' => RiderLocalTripItem::QUEUED,
        ]);

        $this->patch($transition, ['action' => 'cancel', 'cancellation_reason' => 'Buyer requested cancellation'])->assertRedirect();
        $this->assertSame('CANCELLED', $fixture['sellerOrder']->fresh()->status);
        $this->assertSame(5, $variant->fresh()->stock);
        $this->assertSame('PROCESSING', $fixture['order']->fresh()->status);
        $this->assertSame(0, VoucherRedemptionCounter::count($voucher->id));
        $this->assertSame('CANCELLED', $assignment->fresh()->status);
        $this->assertSame(RiderLocalTripItem::CANCELLED, $trip->items()->firstOrFail()->status);
        $this->assertSame(RiderLocalTrip::CANCELLED, $trip->fresh()->status);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $shipment->id,
            'actor_user_id' => $seller->id,
            'notes' => 'SELLER_ORDER_CANCELLED: Buyer requested cancellation',
        ]);

        $this->patch($transition, ['action' => 'cancel', 'cancellation_reason' => 'Retry'])->assertSessionHasErrors('parcels');
        $this->assertSame(5, $variant->fresh()->stock);
    }

    public function test_paid_online_order_cannot_be_seller_cancelled_without_refund_workflow(): void
    {
        [$seller, $profile] = $this->seller('Paid Online Shop');
        $fixture = $this->orderFixture($profile, 'PAID-ONLINE-ORDER', 'PLACED');
        $payment = Payment::create([
            'order_id' => $fixture['order']->id,
            'method' => 'ONLINE',
            'amount' => '53.00',
            'status' => 'PAID',
        ]);

        $this->actingAs($seller)->patch(route('seller.orders.status', $fixture['sellerOrder']), [
            'action' => 'cancel',
            'cancellation_reason' => 'Seller cannot fulfill this paid order.',
        ])->assertSessionHasErrors('parcels');

        $this->assertSame('PLACED', $fixture['sellerOrder']->fresh()->status);
        $this->assertSame('PAID', $payment->fresh()->status);
    }

    public function test_order_details_use_stored_financial_snapshots_and_reject_cross_store_order_lookup(): void
    {
        [$seller, $profile] = $this->seller('Detail Shop');
        $fixture = $this->orderFixture($profile, 'DETAIL-ORDER', 'PLACED');
        $fixture['sellerOrder']->update([
            'item_subtotal' => '72.00',
            'voucher_discount' => '7.00',
            'shipping_fee' => '10.00',
            'protection_total' => '2.00',
            'grand_total' => '77.00',
        ]);
        $fixture['order']->update(['grand_total' => '77.00']);
        $fixture['sellerOrder']->update(['buyer_note' => 'Please keep the parcel dry.']);
        $fixture['item']->update([
            'product_name' => 'Snapshot Vase',
            'variant_description' => 'Blue / Small',
            'unit_price' => '25.00',
            'original_unit_price' => '30.00',
            'quantity' => 2,
            'discount_amount' => '5.00',
            'line_total' => '45.00',
            'protection_fee' => '1.00',
        ]);
        $secondItem = OrderItem::create([
            'seller_order_id' => $fixture['sellerOrder']->id,
            'product_name' => 'Snapshot Bowl',
            'sku' => 'DETAIL-BOWL',
            'variant_description' => 'Sand',
            'unit_price' => '15.00',
            'original_unit_price' => '18.00',
            'quantity' => 2,
            'discount_amount' => '3.00',
            'line_total' => '27.00',
            'protection_fee' => '1.00',
        ]);
        Payment::create(['order_id' => $fixture['order']->id, 'method' => 'COD', 'amount' => '77.00']);
        $totals = OrderItemParcelAmount::forItem($fixture['sellerOrder']->fresh()->load('items'), $fixture['item']->fresh())['total']
            + OrderItemParcelAmount::forItem($fixture['sellerOrder']->fresh()->load('items'), $secondItem)['total'];
        $this->assertSame(77.0, $totals);

        $this->actingAs($seller)->get(route('seller.orders', [
            'mode' => 'show',
            'order' => 'DETAIL-ORDER',
        ]))->assertOk()
            ->assertSee('Snapshot Vase')
            ->assertSee('Blue / Small')
            ->assertSee('Original unit price: PHP 30.00')
            ->assertSee('Effective unit price: PHP 25.00')
            ->assertSee('Line discount: PHP 5.00')
            ->assertSee('Item subtotal')
            ->assertSee('Shipping fee')
            ->assertSee('Protection charge')
            ->assertSee('Final payable')
            ->assertSee('COD amount')
            ->assertSee('Please keep the parcel dry.');

        [, $foreignProfile] = $this->seller('Foreign Detail Shop');
        $foreign = $this->orderFixture($foreignProfile, 'FOREIGN-DETAIL', 'PLACED');
        $this->actingAs($seller)->get(route('seller.orders', ['mode' => 'show', 'order' => 'FOREIGN-DETAIL']))->assertForbidden();
        $this->actingAs($seller)->get(route('seller.orders', ['mode' => 'show', 'order' => 'DOES-NOT-EXIST']))->assertNotFound();
        $this->actingAs($seller)->getJson(route('seller.orders', ['mode' => 'show', 'order' => 'bad!identifier']))->assertStatus(422);
        $this->assertNotSame($fixture['sellerOrder']->id, $foreign['sellerOrder']->id);
    }

    public function test_parcel_waybill_print_controls_appear_only_after_seller_acceptance(): void
    {
        [$seller, $profile] = $this->seller('Waybill Gate Shop');
        $fixture = $this->orderFixture($profile, 'WAYBILL-GATE', 'PLACED');
        $secondItem = OrderItem::create([
            'seller_order_id' => $fixture['sellerOrder']->id,
            'product_name' => 'Second Waybill Item',
            'sku' => 'WAYBILL-GATE-SECOND',
            'unit_price' => '10.00',
            'quantity' => 1,
            'discount_amount' => '0.00',
            'line_total' => '10.00',
        ]);
        foreach ([$fixture['item'], $secondItem] as $index => $item) {
            Shipment::create([
                'seller_order_id' => $fixture['sellerOrder']->id,
                'order_item_id' => $item->id,
                'tracking_number' => 'WAYBILL-GATE-'.($index + 1),
                'current_status' => 'PLACED',
                'destination_province_code' => '0403400000',
                'destination_province_name' => 'Laguna',
                'destination_municipality_code' => '0403424000',
                'destination_municipality_name' => 'City of San Pablo',
                'destination_barangay_code' => '0403424001',
                'destination_barangay_name' => 'Bagong Bayan II-A',
            ]);
        }

        $orderUrl = route('seller.orders', ['mode' => 'show', 'order' => $fixture['sellerOrder']->seller_order_number]);
        $this->actingAs($seller)->get($orderUrl)
            ->assertOk()
            ->assertDontSee('Print all waybills')
            ->assertDontSee('Print this parcel waybill');

        $fixture['sellerOrder']->update(['status' => 'CONFIRMED']);
        $this->actingAs($seller)->get($orderUrl)
            ->assertOk()
            ->assertSee('Print all waybills')
            ->assertSee('Print this parcel waybill');
    }

    public function test_reviews_are_seller_scoped_filterable_and_show_persisted_replies(): void
    {
        Storage::fake('public');
        [$seller, $profile] = $this->seller('Review Shop');
        $fixture = $this->orderFixture($profile, 'REVIEW-OWN', 'COMPLETED');
        $product = Product::create([
            'seller_profile_id' => $profile->id,
            'category_id' => $profile->primary_category_id,
            'name' => 'Reviewed Vase',
            'slug' => 'reviewed-vase-'.uniqid(),
            'status' => 'ACTIVE',
        ]);
        $fixture['item']->update(['product_id' => $product->id, 'variant_description' => 'Blue / Small']);
        $review = Review::create([
            'order_item_id' => $fixture['item']->id,
            'buyer_user_id' => $fixture['buyer']->id,
            'rating' => 5,
            'comment' => 'Beautiful handmade work.',
            'status' => 'PUBLISHED',
        ]);
        $review->forceFill(['created_at' => Carbon::parse('2026-10-12 12:00:00')])->save();
        $ratingComments = [5 => 'Beautiful handmade work.'];
        foreach ([1, 2, 3, 4] as $rating) {
            $ratedFixture = $this->orderFixture($profile, 'REVIEW-RATING-'.$rating, 'COMPLETED');
            $ratingComments[$rating] = 'Own '.$rating.'-star review';
            $ratingReview = Review::create([
                'order_item_id' => $ratedFixture['item']->id,
                'buyer_user_id' => $ratedFixture['buyer']->id,
                'rating' => $rating,
                'comment' => $ratingComments[$rating],
                'status' => 'PUBLISHED',
            ]);
            if ($rating === 4) {
                $ratingReview->forceFill(['created_at' => Carbon::parse('2026-10-12 11:00:00')])->save();
            }
        }
        $imagePath = 'reviews/'.$review->id.'/buyer-photo.webp';
        Storage::disk('public')->put($imagePath, 'test-image');
        AuditLog::create([
            'actor_user_id' => $fixture['buyer']->id,
            'event' => 'buyer.review.image.updated',
            'auditable_type' => Review::class,
            'auditable_id' => $review->id,
            'new_values' => ['image_path' => $imagePath],
            'created_at' => now(),
        ]);
        $secondImagePath = 'reviews/'.$review->id.'/second-buyer-photo.webp';
        Storage::disk('public')->put($secondImagePath, 'second-test-image');
        AuditLog::create([
            'actor_user_id' => $fixture['buyer']->id,
            'event' => 'buyer.review.image.updated',
            'auditable_type' => Review::class,
            'auditable_id' => $review->id,
            'new_values' => ['image_path' => $secondImagePath],
            'created_at' => now(),
        ]);
        [, $foreignProfile] = $this->seller('Foreign Review Shop');
        $foreign = $this->orderFixture($foreignProfile, 'REVIEW-FOREIGN', 'COMPLETED');
        Review::create([
            'order_item_id' => $foreign['item']->id,
            'buyer_user_id' => $foreign['buyer']->id,
            'rating' => 1,
            'comment' => 'Private foreign review.',
            'status' => 'PUBLISHED',
        ]);

        foreach ($ratingComments as $rating => $expectedComment) {
            $filteredReviews = $this->actingAs($seller)
                ->get(route('seller.reviews', ['rating' => $rating]))
                ->assertOk()
                ->assertSee($expectedComment)
                ->assertDontSee('Private foreign review.');
            foreach ($ratingComments as $otherRating => $otherComment) {
                if ($otherRating !== $rating) {
                    $filteredReviews->assertDontSee($otherComment);
                }
            }
        }

        $this->actingAs($seller)->get(route('seller.reviews', [
            'rating' => 4,
            'from_date' => '2026-10-12',
            'to_date' => '2026-10-12',
        ]))->assertOk()
            ->assertSee('Own 4-star review')
            ->assertDontSee('Own 3-star review')
            ->assertDontSee('Beautiful handmade work.');

        $this->actingAs($seller)->get(route('seller.reviews'))
            ->assertOk()
            ->assertSee('Own 1-star review')
            ->assertSee('Own 2-star review')
            ->assertSee('Own 3-star review')
            ->assertSee('Own 4-star review')
            ->assertSee('Beautiful handmade work.')
            ->assertDontSee('Private foreign review.');

        $this->actingAs($seller)->get(route('seller.reviews', [
            'rating' => 5,
            'product_id' => $product->id,
            'variant' => 'Blue / Small',
            'from_date' => '2026-10-12',
            'to_date' => '2026-10-12',
        ]))->assertOk()
            ->assertSee('Beautiful handmade work.')
            ->assertSee('Reviewed Vase')
            ->assertSee('Blue / Small')
            ->assertSee(Storage::disk('public')->url($imagePath), false)
            ->assertSee(Storage::disk('public')->url($secondImagePath), false)
            ->assertDontSee($fixture['buyer']->name)
            ->assertDontSee('Private foreign review.');

        $this->actingAs($seller)->post(route('seller.reviews.reply', $review), [
            'seller_reply' => 'Thank you for supporting our shop.',
        ])->assertRedirect();
        $this->assertSame('Thank you for supporting our shop.', $review->fresh()->seller_reply);
        $this->assertNotNull($review->fresh()->seller_replied_at);
        $this->actingAs($seller)->post(route('seller.reviews.reply', $review), [
            'seller_reply' => 'Updated response.',
            'comment' => 'Attempt to replace buyer review.',
            'rating' => 1,
        ])->assertRedirect();
        $this->assertSame('Updated response.', $review->fresh()->seller_reply);
        $this->assertSame('Beautiful handmade work.', $review->fresh()->comment);
        $this->assertSame(5, (int) $review->fresh()->rating);
        $this->assertSame(1, Review::query()->where('order_item_id', $fixture['item']->id)->count());
        [, $foreignProfile] = $this->seller('Foreign Reply Shop');
        $foreignSeller = $foreignProfile->user;
        $this->actingAs($foreignSeller)->post(route('seller.reviews.reply', $review), [
            'seller_reply' => 'Unauthorized reply.',
        ])->assertForbidden();
        $this->assertSame('Updated response.', $review->fresh()->seller_reply);
        $this->actingAs($seller)->get(route('seller.reviews'))->assertOk()
            ->assertSee('Updated response.')
            ->assertSee('Edit reply');
    }

    public function test_seller_review_delete_and_hide_routes_return_403_without_mutating_reviews(): void
    {
        [$seller, $profile] = $this->seller('Review Safety Shop');
        $fixture = $this->orderFixture($profile, 'REVIEW-SAFE', 'COMPLETED');
        $review = Review::create([
            'order_item_id' => $fixture['item']->id,
            'buyer_user_id' => $fixture['buyer']->id,
            'rating' => 4,
            'comment' => 'Keep this buyer review.',
            'status' => 'PUBLISHED',
        ]);

        $this->actingAs($seller)->delete(route('seller.reviews.destroy', $review))->assertForbidden();
        $this->actingAs($seller)->patch(route('seller.reviews.hide', $review))->assertForbidden();
        $this->assertSame('PUBLISHED', $review->fresh()->status);
        $this->assertSame('Keep this buyer review.', $review->fresh()->comment);
        $this->assertNull($review->fresh()->seller_replied_at);
    }

    public function test_reviews_export_is_seller_scoped_filtered_private_and_pdf_safe(): void
    {
        [$seller, $profile] = $this->seller('Review Export Shop');
        $fixture = $this->orderFixture($profile, 'REVIEW-EXPORT-OWN', 'COMPLETED');
        $product = Product::create([
            'seller_profile_id' => $profile->id,
            'category_id' => $profile->primary_category_id,
            'name' => '=HYPERLINK("https://example.invalid")',
            'slug' => 'review-export-'.uniqid(),
            'status' => 'ACTIVE',
        ]);
        $fixture['item']->update(['product_id' => $product->id, 'product_name' => $product->name, 'variant_description' => '+SUM(1,1)']);
        $review = Review::create([
            'order_item_id' => $fixture['item']->id,
            'buyer_user_id' => $fixture['buyer']->id,
            'rating' => 5,
            'comment' => '=1+1',
            'status' => 'PUBLISHED',
            'seller_reply' => '@SUM(1,1)',
        ]);
        [, $foreignProfile] = $this->seller('Foreign Review Export Shop');
        $foreign = $this->orderFixture($foreignProfile, 'REVIEW-EXPORT-FOREIGN', 'COMPLETED');
        Review::create([
            'order_item_id' => $foreign['item']->id,
            'buyer_user_id' => $foreign['buyer']->id,
            'rating' => 5,
            'comment' => 'Foreign private feedback.',
            'status' => 'PUBLISHED',
        ]);

        $response = $this->actingAs($seller)
            ->get(route('seller.reviews.export', ['rating' => 5]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload();
        $pdf = $response->getContent();

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('Product reviews', $pdf);
        $this->assertStringNotContainsString('Foreign private feedback.', $pdf);
        $this->assertStringNotContainsString($fixture['buyer']->email, $pdf);
        $this->assertStringNotContainsString($fixture['buyer']->name, $pdf);
        $this->assertStringContainsString((string) $review->id, $pdf);
        $this->assertStringContainsString('=1+1', $pdf);
    }

    public function test_orders_export_is_pdf_and_contains_only_the_authenticated_sellers_orders(): void
    {
        [$seller, $profile] = $this->seller('Own Order Export Shop');
        $own = $this->orderFixture($profile, 'ORDER-PDF-OWN', 'PLACED');
        Shipment::create([
            'seller_order_id' => $own['sellerOrder']->id,
            'tracking_number' => 'TRACK-PDF-OWN',
            'current_status' => 'READY_FOR_PICKUP',
            'destination_province_code' => 'P1',
            'destination_province_name' => 'Province',
            'destination_municipality_code' => 'M1',
            'destination_municipality_name' => 'Municipality',
            'destination_barangay_code' => 'B1',
            'destination_barangay_name' => 'Barangay',
        ]);

        [, $foreignProfile] = $this->seller('Foreign Order Export Shop');
        $foreign = $this->orderFixture($foreignProfile, 'ORDER-PDF-FOREIGN', 'PLACED');
        Shipment::create([
            'seller_order_id' => $foreign['sellerOrder']->id,
            'tracking_number' => 'TRACK-PDF-FOREIGN',
            'current_status' => 'READY_FOR_PICKUP',
            'destination_province_code' => 'P1',
            'destination_province_name' => 'Province',
            'destination_municipality_code' => 'M1',
            'destination_municipality_name' => 'Municipality',
            'destination_barangay_code' => 'B1',
            'destination_barangay_name' => 'Barangay',
        ]);

        $response = $this->actingAs($seller)->get(route('seller.orders.export'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload();
        $pdf = $response->getContent();

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('ORDER-PDF-OWN', $pdf);
        $this->assertStringContainsString('TRACK-PDF-OWN', $pdf);
        $this->assertStringNotContainsString('ORDER-PDF-FOREIGN', $pdf);
        $this->assertStringNotContainsString('TRACK-PDF-FOREIGN', $pdf);
    }

    public function test_seller_chat_accepts_private_images_and_streams_attachment_urls_only_to_participants(): void
    {
        Storage::fake('local');
        Event::fake();
        [$seller] = $this->seller('Chat Shop');
        $buyer = $this->user(User::TYPE_BUYER);
        $conversation = app(ConversationService::class)->start($seller, $buyer->id);

        $response = $this->actingAs($seller)->post(route('seller.messages.send'), [
            'recipient_user_id' => $buyer->id,
            'conversation_id' => $conversation->id,
            'body' => 'Here is the parcel photo.',
            'attachment' => UploadedFile::fake()->image('parcel.png'),
        ], ['Accept' => 'application/json'])->assertOk();

        $message = Message::query()->firstOrFail();
        $this->assertNotNull($message->attachment_path);
        Storage::disk('local')->assertExists($message->attachment_path);
        $attachmentUrl = $response->json('message.attachment_url');
        $this->assertNotEmpty($attachmentUrl);
        $attachmentResponse = $this->get($attachmentUrl)->assertOk();
        $this->assertStringContainsString('private', (string) $attachmentResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $attachmentResponse->headers->get('Cache-Control'));
        $this->getJson(route('seller.messages.stream', ['conversation' => $conversation->id]))
            ->assertOk()->assertJsonPath('messages.0.attachment_url', $attachmentUrl);

        [$otherSeller] = $this->seller('Other Chat Shop');
        $this->actingAs($otherSeller)->get($attachmentUrl)->assertForbidden();
    }

    public function test_seller_chat_rejects_spoofed_and_oversized_image_attachments(): void
    {
        Event::fake();
        [$seller] = $this->seller('Chat Validation Shop');
        $buyer = $this->user(User::TYPE_BUYER);
        $conversation = app(ConversationService::class)->start($seller, $buyer->id);
        $uri = route('seller.messages.send');

        $this->actingAs($seller)->post($uri, [
            'recipient_user_id' => $buyer->id,
            'conversation_id' => $conversation->id,
            'body' => 'This file is not an image.',
            'attachment' => UploadedFile::fake()->createWithContent('malware.jpg', 'MZ executable bytes'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('attachment');

        $this->actingAs($seller)->post($uri, [
            'recipient_user_id' => $buyer->id,
            'conversation_id' => $conversation->id,
            'body' => 'This image is too large.',
            'attachment' => UploadedFile::fake()->image('oversized.png')->size(5121),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('attachment');

        $this->assertSame(0, Message::query()->count());
    }

    public function test_seller_chat_requires_trimmed_body_and_enforces_1000_character_boundary(): void
    {
        Event::fake();
        [$seller] = $this->seller('Chat Length Shop');
        $buyer = $this->user(User::TYPE_BUYER);
        $conversation = app(ConversationService::class)->start($seller, $buyer->id);
        $uri = route('seller.messages.send');
        $base = ['recipient_user_id' => $buyer->id, 'conversation_id' => $conversation->id];

        $unicodeBody = str_repeat('猫', 1000);
        $this->actingAs($seller)->post($uri, $base + ['body' => $unicodeBody], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('message.attachment_url', null);
        $this->assertDatabaseHas('messages', ['body' => $unicodeBody]);
        $this->actingAs($seller)->post($uri, $base + ['body' => str_repeat('B', 1001)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('body');
        $this->actingAs($seller)->post($uri, $base + ['body' => " \t "], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('body');
        $this->actingAs($seller)->post($uri, $base + ['body' => "\u{00A0}\u{2003}"], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('body');
        $this->actingAs($seller)->post($uri, $base, ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('body');
        $this->actingAs($seller)->get(route('seller.messages'))->assertOk()->assertSee('maxlength="1000"', false);
        $this->assertSame(1, Message::query()->count());
    }

    /** @return array{0: User, 1: SellerProfile} */
    private function seller(string $businessName): array
    {
        $user = $this->user(User::TYPE_SELLER);
        $category = Category::create([
            'name' => 'Test Category '.uniqid(),
            'slug' => 'test-category-'.uniqid(),
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

    /** @return array{buyer: User, order: Order, sellerOrder: SellerOrder, item: OrderItem} */
    private function orderFixture(SellerProfile $seller, string $number, string $status, ?string $placedAt = null): array
    {
        $buyer = $this->user(User::TYPE_BUYER);
        $order = Order::create([
            'order_number' => 'ORDER-'.$number,
            'buyer_user_id' => $buyer->id,
            'status' => 'PLACED',
            'subtotal' => '50.00',
            'grand_total' => '53.00',
            'payment_status' => 'PENDING',
            'placed_at' => $placedAt ? Carbon::parse($placedAt) : now(),
        ]);
        OrderAddress::create([
            'order_id' => $order->id,
            'recipient_name' => $buyer->name,
            'contact_number' => $buyer->contact_number,
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403424000',
            'municipality_name' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay_name' => 'Bagong Bayan II-A',
            'street_address' => 'Buyer Street',
        ]);
        $sellerOrder = SellerOrder::create([
            'seller_order_number' => $number,
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'buyer_note' => 'Leave safely.',
            'status' => $status,
            'item_subtotal' => '45.00',
            'voucher_discount' => '2.00',
            'shipping_fee' => '7.00',
            'protection_total' => '3.00',
            'grand_total' => '53.00',
        ]);
        $item = OrderItem::create([
            'seller_order_id' => $sellerOrder->id,
            'product_name' => 'Widget '.$number,
            'sku' => 'SKU-'.$number,
            'variant_description' => 'Standard',
            'unit_price' => '25.00',
            'original_unit_price' => '30.00',
            'quantity' => 2,
            'discount_amount' => '5.00',
            'line_total' => '45.00',
        ]);

        return compact('buyer', 'order', 'sellerOrder', 'item');
    }
}
