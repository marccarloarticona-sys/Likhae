<?php

namespace Tests\Feature;

use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentEvent;
use App\Models\Logistics\ShipmentTransferLeg;
use App\Models\Logistics\TransferManifest;
use App\Models\Logistics\TransferManifestItem;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\Finance\CodCollection;
use App\Models\Finance\RiderRemittance;
use App\Models\Finance\RiderRemittanceItem;
use App\Models\User;
use App\Services\Finance\CodCollectionService;
use App\Services\Fulfillment\ShipmentWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Run only after a disposable test database and reset permission are verified. */
class LogisticsDashboardAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private SellerProfile $seller;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(12, 0));

        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER, 'status' => User::STATUS_ACTIVE]);
        $this->buyer = User::factory()->create(['account_type' => User::TYPE_BUYER, 'status' => User::STATUS_ACTIVE]);
        $category = Category::create(['name' => 'Dashboard fixture', 'slug' => 'dashboard-fixture', 'is_active' => true]);
        $this->seller = SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_name' => 'Dashboard fixture seller',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_log_dash_001_cards_chart_and_paginated_lists_count_each_parcel_once(): void
    {
        [$user, $center] = $this->center('Origin');
        [, $destination] = $this->center('Destination');
        $sellerOrder = $this->sellerOrder();
        $firstItem = $this->item($sellerOrder);
        $secondItem = $this->item($sellerOrder);
        $this->shipment($center, 'READY_FOR_PICKUP', $sellerOrder, $firstItem);
        $this->shipment($center, 'READY_FOR_PICKUP', $sellerOrder, $secondItem);
        for ($index = 0; $index < 16; $index++) {
            $this->shipment($center, 'READY_FOR_PICKUP');
        }
        $area = ServiceArea::create([
            'logistics_center_id' => $center->id,
            'code' => 'DASH-AREA-'.uniqid(), 'name' => 'Dashboard service area', 'is_active' => true,
        ]);
        $unassigned = $this->shipment($center, 'READY_FOR_PICKUP');
        $unassigned->update(['logistics_center_id' => null, 'service_area_id' => $area->id]);
        $moving = $this->shipment($center, 'SORTED');
        $this->shipment($center, 'AT_SORTING_CENTER');
        $this->shipment($center, 'DELIVERED');
        $this->shipment($center, 'COMPLETED');
        $this->shipment($center, 'DELIVERY_FAILED');
        $this->shipment($center, 'RETURNED');
        $this->shipment($center, 'PREPARING');
        $cancelled = $this->shipment($center, 'READY_FOR_PICKUP');
        $cancelled->sellerOrder->update(['status' => 'CANCELLED']);
        ShipmentEvent::create(['shipment_id' => $moving->id, 'status' => 'SORTED', 'occurred_at' => now()]);
        ShipmentEvent::create(['shipment_id' => $moving->id, 'status' => 'SORTED', 'occurred_at' => now()]);
        $this->manifestFor($moving, $center, $destination, $user);

        $dashboard = $this->actingAs($user)->get(route('logistics.dashboard'))->assertOk();
        $this->assertSame(['pending' => 19, 'in_transit' => 2, 'delivered' => 2, 'failed' => 2], $dashboard->viewData('counts'));
        foreach ($dashboard->viewData('counts') as $category => $count) {
            $list = $this->actingAs($user)->get(route('logistics.parcels', ['status' => $category]))->assertOk();
            $this->assertSame($count, $list->viewData('shipments')->total());
            $dashboard->assertSee(route('logistics.parcels', ['status' => $category]), false);
        }
        $secondPage = $this->actingAs($user)->get(route('logistics.parcels', ['status' => 'pending', 'page' => 2]))->assertOk();
        $this->assertSame(19, $secondPage->viewData('shipments')->total());
        $this->assertCount(4, $secondPage->viewData('shipments')->items());
    }

    public function test_log_dash_002_delivery_transition_refreshes_persisted_counts(): void
    {
        [$user, $center] = $this->center('Delivery');
        $moving = $this->shipment($center, 'OUT_FOR_DELIVERY');
        $this->shipment($center, 'DELIVERED');
        $this->shipment($center, 'READY_FOR_PICKUP');
        $riderUser = User::factory()->create(['account_type' => User::TYPE_RIDER, 'status' => User::STATUS_ACTIVE]);
        $rider = RiderProfile::create([
            'user_id' => $riderUser->id, 'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle', 'plate_number' => 'DASH-'.uniqid(),
            'drivers_license_number' => 'DASH-LICENSE-'.uniqid(), 'status' => 'ACTIVE',
        ]);
        $assignment = RiderAssignment::create([
            'shipment_id' => $moving->id, 'rider_profile_id' => $rider->id,
            'assignment_type' => 'DELIVERY', 'status' => 'IN_PROGRESS',
        ]);
        app(CodCollectionService::class)->attachDeliveryRider($moving, $assignment);

        $before = $this->actingAs($user)->get(route('logistics.dashboard'))->assertOk()->viewData('counts');
        app(ShipmentWorkflowService::class)->riderTransition($assignment, 'delivery_success', $riderUser, [
            'scanned_code' => $moving->tracking_number,
            'proof_path' => 'proofs/dashboard-acceptance.jpg',
            'collected_amount' => '100.00',
        ]);
        $after = $this->actingAs($user)->get(route('logistics.dashboard'))->assertOk()->viewData('counts');

        $this->assertSame('DELIVERED', $moving->fresh()->current_status);
        $this->assertSame($before['in_transit'] - 1, $after['in_transit']);
        $this->assertSame($before['delivered'] + 1, $after['delivered']);
        $this->assertSame($before['pending'], $after['pending']);
        $this->assertSame($before['failed'], $after['failed']);
    }

    public function test_log_dash_003_creation_dates_include_both_day_boundaries_and_match_list(): void
    {
        [$user, $center] = $this->center('Dates');
        foreach (['2026-10-14 23:59:59', '2026-10-15 00:00:00', '2026-10-15 23:59:59', '2026-10-16 00:00:00'] as $timestamp) {
            $shipment = $this->shipment($center, 'READY_FOR_PICKUP');
            DB::table('shipments')->where('id', $shipment->id)->update(['created_at' => $timestamp]);
        }
        $query = ['from_date' => '2026-10-15', 'to_date' => '2026-10-15'];
        $dashboard = $this->actingAs($user)->get(route('logistics.dashboard', $query))->assertOk();
        $this->assertSame(2, $dashboard->viewData('counts')['pending']);
        $dashboard->assertSee('value="2026-10-15"', false);
        $list = $this->actingAs($user)->get(route('logistics.parcels', ['status' => 'pending', ...$query]))->assertOk();
        $this->assertSame(2, $list->viewData('shipments')->total());
        $today = $this->actingAs($user)->get(route('logistics.dashboard', ['from_date' => '2026-10-16', 'to_date' => '2026-10-16']))->assertOk();
        $this->assertSame(1, $today->viewData('counts')['pending']);
        $this->actingAs($user)->from(route('logistics.dashboard'))->get(route('logistics.dashboard', ['from_date' => '2026-10-16', 'to_date' => '2026-10-15']))->assertRedirect()->assertSessionHasErrors('to_date');
        $this->actingAs($user)->from(route('logistics.dashboard'))->get(route('logistics.dashboard', ['from_date' => '2026-02-30']))->assertRedirect()->assertSessionHasErrors('from_date');
    }

    public function test_log_dash_004_company_scope_transfer_hubs_and_detail_access(): void
    {
        [$originUser, $origin] = $this->center('JNT');
        [$destinationUser, $destination] = $this->center('Pagsanjan');
        [$otherUser, $other] = $this->center('Unrelated');
        for ($index = 0; $index < 10; $index++) {
            $this->shipment($origin, 'READY_FOR_PICKUP');
        }
        for ($index = 0; $index < 5; $index++) {
            $this->shipment($destination, 'READY_FOR_PICKUP');
        }
        $transferred = $this->shipment($origin, 'SORTED');
        $unrelated = $this->shipment($other, 'PICKED_UP');
        $this->manifestFor($transferred, $origin, $destination, $originUser);

        $originDashboard = $this->actingAs($originUser)->get(route('logistics.dashboard'))->assertOk();
        $destinationDashboard = $this->actingAs($destinationUser)->get(route('logistics.dashboard'))->assertOk();
        $otherDashboard = $this->actingAs($otherUser)->get(route('logistics.dashboard'))->assertOk();
        $this->assertSame(['pending' => 10, 'in_transit' => 1, 'delivered' => 0, 'failed' => 0], $originDashboard->viewData('counts'));
        $this->assertSame(['pending' => 5, 'in_transit' => 1, 'delivered' => 0, 'failed' => 0], $destinationDashboard->viewData('counts'));
        $this->assertSame(['pending' => 0, 'in_transit' => 1, 'delivered' => 0, 'failed' => 0], $otherDashboard->viewData('counts'));
        $this->assertSame(1, $originDashboard->viewData('operational')['outgoing']);
        $this->assertSame(1, $destinationDashboard->viewData('operational')['incoming']);
        $this->assertSame(0, $destinationDashboard->viewData('operational')['pickup'], 'An incoming hub transfer is not an active seller pickup.');
        foreach ([[$originUser, $originDashboard], [$destinationUser, $destinationDashboard], [$otherUser, $otherDashboard]] as [$user, $dashboard]) {
            foreach ($dashboard->viewData('counts') as $category => $count) {
                $list = $this->actingAs($user)->get(route('logistics.parcels', ['status' => $category]))->assertOk();
                $this->assertSame($count, $list->viewData('shipments')->total(), $category.' dashboard count matches its authorized parcel list.');
            }
        }
        $this->actingAs($originUser)->get(route('logistics.parcels.show', $unrelated))->assertForbidden();
        $this->actingAs($otherUser)->get(route('logistics.parcels.show', $transferred))->assertForbidden();
        $this->actingAs($originUser)->get(route('logistics.parcels', ['status' => 'in_transit', 'company_id' => $other->id]))->assertForbidden();
        $this->actingAs($originUser)->get(route('logistics.dashboard', ['hub_id' => $other->id]))->assertForbidden();

        $transferred->update(['logistics_center_id' => $destination->id]);
        $this->actingAs($originUser)->get(route('logistics.parcels.show', $transferred))->assertOk();
        $this->actingAs($destinationUser)->get(route('logistics.parcels.show', $transferred))->assertOk();
        $this->assertSame(1, $this->actingAs($originUser)->get(route('logistics.parcels', ['status' => 'in_transit']))->assertOk()->viewData('shipments')->total());
    }

    public function test_dashboard_shows_center_scoped_rider_and_cod_reconciliation_totals(): void
    {
        [$user, $center] = $this->center('Finance dashboard');
        [, $otherCenter] = $this->center('Other finance dashboard');
        $activeUser = User::factory()->create(['account_type' => User::TYPE_RIDER, 'status' => User::STATUS_ACTIVE]);
        $activeRider = RiderProfile::create([
            'user_id' => $activeUser->id, 'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle', 'plate_number' => 'FIN-DASH-ACTIVE-'.uniqid(),
            'drivers_license_number' => 'FIN-DASH-ACTIVE-LICENSE-'.uniqid(), 'status' => 'ACTIVE',
        ]);
        $suspendedUser = User::factory()->create(['account_type' => User::TYPE_RIDER, 'status' => User::STATUS_ACTIVE]);
        RiderProfile::create([
            'user_id' => $suspendedUser->id, 'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle', 'plate_number' => 'FIN-DASH-SUSPENDED-'.uniqid(),
            'drivers_license_number' => 'FIN-DASH-SUSPENDED-LICENSE-'.uniqid(), 'status' => 'SUSPENDED',
        ]);
        $foreignUser = User::factory()->create(['account_type' => User::TYPE_RIDER, 'status' => User::STATUS_ACTIVE]);
        RiderProfile::create([
            'user_id' => $foreignUser->id, 'logistics_center_id' => $otherCenter->id,
            'vehicle_type' => 'motorcycle', 'plate_number' => 'FIN-DASH-FOREIGN-'.uniqid(),
            'drivers_license_number' => 'FIN-DASH-FOREIGN-LICENSE-'.uniqid(), 'status' => 'ACTIVE',
        ]);

        $awaitingShipment = $this->shipment($center, 'DELIVERED');
        CodCollection::create([
            'shipment_id' => $awaitingShipment->id, 'seller_order_id' => $awaitingShipment->seller_order_id,
            'order_id' => $awaitingShipment->sellerOrder->order_id, 'rider_profile_id' => $activeRider->id,
            'logistics_center_id' => $center->id, 'expected_amount' => '42.00', 'collected_amount' => '40.00',
            'status' => CodCollection::COLLECTED, 'collection_reference' => 'DASH-COD-COLLECTED-'.uniqid(), 'collected_at' => now(),
        ]);
        $reconciledShipment = $this->shipment($center, 'DELIVERED');
        $reconciledCollection = CodCollection::create([
            'shipment_id' => $reconciledShipment->id, 'seller_order_id' => $reconciledShipment->seller_order_id,
            'order_id' => $reconciledShipment->sellerOrder->order_id, 'rider_profile_id' => $activeRider->id,
            'logistics_center_id' => $center->id, 'expected_amount' => '30.00', 'collected_amount' => '30.00',
            'status' => CodCollection::REMITTED, 'collection_reference' => 'DASH-COD-REMITTED-'.uniqid(), 'collected_at' => now(),
        ]);
        $remittance = RiderRemittance::create([
            'rider_profile_id' => $activeRider->id, 'logistics_center_id' => $center->id,
            'remittance_reference' => 'DASH-REM-'.uniqid(), 'expected_amount' => '30.00',
            'submitted_amount' => '30.00', 'verified_amount' => '30.00', 'status' => RiderRemittance::RECONCILED,
            'submitted_at' => now(), 'verified_at' => now(),
        ]);
        RiderRemittanceItem::create(['rider_remittance_id' => $remittance->id, 'cod_collection_id' => $reconciledCollection->id]);

        $dashboard = $this->actingAs($user)->get(route('logistics.dashboard'))->assertOk();

        $this->assertSame(1, $dashboard->viewData('operational')['active_riders']);
        $this->assertSame(1, $dashboard->viewData('operational')['suspended_riders']);
        $this->assertSame(70.0, $dashboard->viewData('financeSummary')['cod_collected']);
        $this->assertSame(40.0, $dashboard->viewData('financeSummary')['cod_awaiting_remittance']);
        $this->assertSame(30.0, $dashboard->viewData('financeSummary')['cod_reconciled']);
    }

    private function center(string $name): array
    {
        $user = User::factory()->create(['account_type' => User::TYPE_LOGISTICS, 'status' => User::STATUS_ACTIVE]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $user->id, 'code' => strtoupper(str_replace(' ', '-', $name)).'-'.uniqid(),
            'business_name' => $name, 'status' => 'ACTIVE',
            'approved_by_user_id' => $user->id, 'approved_at' => now(),
        ]);

        return [$user, $center];
    }

    private function sellerOrder(): SellerOrder
    {
        $order = Order::create([
            'order_number' => 'DASH-ORDER-'.uniqid(), 'buyer_user_id' => $this->buyer->id,
            'status' => 'PLACED', 'grand_total' => 100, 'placed_at' => now(),
        ]);

        return SellerOrder::create([
            'seller_order_number' => 'DASH-SO-'.uniqid(), 'order_id' => $order->id,
            'seller_profile_id' => $this->seller->id, 'status' => 'PREPARING',
            'item_subtotal' => 100, 'grand_total' => 100,
        ]);
    }

    private function item(SellerOrder $order): OrderItem
    {
        return OrderItem::create([
            'seller_order_id' => $order->id, 'product_name' => 'Dashboard parcel',
            'sku' => 'DASH-'.uniqid(), 'unit_price' => 50,
            'quantity' => 1, 'line_total' => 50,
        ]);
    }

    private function shipment(LogisticsCenter $center, string $status, ?SellerOrder $order = null, ?OrderItem $item = null): Shipment
    {
        $order ??= $this->sellerOrder();

        return Shipment::create([
            'seller_order_id' => $order->id, 'order_item_id' => $item?->id,
            'tracking_number' => 'DASH-TRACK-'.uniqid(),
            'logistics_center_id' => $center->id,
            'destination_province_code' => '0403400000', 'destination_province_name' => 'Laguna',
            'destination_municipality_code' => '0403419000', 'destination_municipality_name' => 'Pagsanjan',
            'destination_barangay_code' => '0403419001', 'destination_barangay_name' => 'Anibong',
            'current_status' => $status,
        ]);
    }

    private function manifestFor(Shipment $shipment, LogisticsCenter $origin, LogisticsCenter $destination, User $owner): void
    {
        $leg = ShipmentTransferLeg::create([
            'shipment_id' => $shipment->id, 'sequence_no' => 1,
            'origin_logistics_center_id' => $origin->id,
            'destination_logistics_center_id' => $destination->id,
            'origin_province_code' => '0403400000',
            'origin_municipality_code' => '0403419000',
            'destination_province_code' => '0403400000',
            'destination_municipality_code' => '0403422000',
            'status' => ShipmentTransferLeg::IN_TRANSIT,
        ]);
        $manifest = TransferManifest::create([
            'manifest_number' => 'DASH-MANIFEST-'.uniqid(),
            'origin_logistics_center_id' => $origin->id,
            'destination_logistics_center_id' => $destination->id,
            'created_by_user_id' => $owner->id,
            'status' => TransferManifest::IN_TRANSIT,
        ]);
        TransferManifestItem::create([
            'manifest_id' => $manifest->id, 'transfer_leg_id' => $leg->id,
            'added_by_user_id' => $owner->id, 'status' => TransferManifestItem::EXPECTED,
        ]);
    }
}
