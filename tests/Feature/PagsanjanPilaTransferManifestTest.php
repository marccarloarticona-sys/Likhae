<?php

namespace Tests\Feature;

use App\Models\Buyer\Address;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderAddress;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentTransferLeg;
use App\Models\Logistics\TransferManifest;
use App\Models\Logistics\TransferManifestItem;
use App\Models\Rider\RiderProfile;
use App\Models\Rider\RiderAreaAssignment;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Fulfillment\FinalMileDispatchService;
use App\Services\Fulfillment\ShipmentTransferService;
use App\Services\Fulfillment\ShipmentWorkflowService;
use App\Services\Fulfillment\TransferManifestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PagsanjanPilaTransferManifestTest extends TestCase
{
    use RefreshDatabase;

    private const PAGSANJAN = [
        'province_code' => '0403400000', 'province_name' => 'Laguna',
        'municipality_code' => '0403419000', 'municipality_name' => 'Pagsanjan',
        'barangay_code' => '0403419001', 'barangay_name' => 'Anibong',
    ];

    private const PILA = [
        'province_code' => '0403400000', 'province_name' => 'Laguna',
        'municipality_code' => '0403422000', 'municipality_name' => 'Pila',
        'barangay_code' => '0403422015', 'barangay_name' => 'San Miguel',
    ];

    public function test_draft_assignment_requires_approved_origin_authorized_four_wheel_driver_and_can_be_changed(): void
    {
        $fixture = $this->fixture();
        $service = app(TransferManifestService::class);
        $manifest = $service->create($fixture['originUser'], $fixture['origin'], $fixture['destination'], 'assigned at creation', $fixture['driver']);

        $this->assertSame($fixture['driver']->id, (int) $manifest->assigned_driver_rider_profile_id);
        $manifest = $service->assignDriver($fixture['originUser'], $manifest, $fixture['wrongDriver']);
        $this->assertSame($fixture['wrongDriver']->id, (int) $manifest->assigned_driver_rider_profile_id);
        $this->assertRuntimeFailure(
            fn () => $service->assignDriver($fixture['wrongCenterUser'], $manifest, $fixture['driver']),
            'not authorized for this logistics center',
        );

        $unapprovedUser = User::factory()->create(['account_type' => User::TYPE_RIDER, 'status' => User::STATUS_ACTIVE]);
        $unapproved = $this->driverProfile($fixture, $unapprovedUser, 'van', false);
        $this->assertRuntimeFailure(
            fn () => $service->assignDriver($fixture['originUser'], $manifest, $unapproved),
            'approved, active, and authorized',
        );

        foreach (['motorcycle', 'tricycle'] as $vehicleType) {
            $localUser = User::factory()->create(['account_type' => User::TYPE_RIDER, 'status' => User::STATUS_ACTIVE]);
            $localDriver = $this->driverProfile($fixture, $localUser, $vehicleType, true);
            $this->assertRuntimeFailure(
                fn () => $service->assignDriver($fixture['originUser'], $manifest, $localDriver),
                'Only an approved four-wheel inter-hub vehicle',
            );
        }

        $carUser = User::factory()->create(['account_type' => User::TYPE_RIDER, 'status' => User::STATUS_ACTIVE]);
        $carDriver = $this->driverProfile($fixture, $carUser, 'car', true);
        $manifest = $service->assignDriver($fixture['originUser'], $manifest, $carDriver);
        $this->assertSame($carDriver->id, (int) $manifest->assigned_driver_rider_profile_id);
    }

    public function test_a_driver_with_another_active_manifest_is_not_available_for_assignment(): void
    {
        $fixture = $this->fixture();
        $service = app(TransferManifestService::class);
        $first = $service->create($fixture['originUser'], $fixture['origin'], $fixture['destination'], null, $fixture['driver']);
        $second = $service->create($fixture['originUser'], $fixture['origin'], $fixture['destination']);

        $this->assertRuntimeFailure(
            fn () => $service->assignDriver($fixture['originUser'], $second, $fixture['driver']),
            'already has an active transfer manifest',
        );
        $eligible = $service->eligibleDriversForCenter((int) $fixture['origin']->id);
        $this->assertFalse($eligible->contains('id', $fixture['driver']->id));
        $this->assertTrue($eligible->contains('id', $fixture['wrongDriver']->id));
        $this->assertSame(TransferManifest::DRAFT, $first->fresh()->status);
    }

    public function test_manifest_creation_rolls_back_when_optional_driver_assignment_fails(): void
    {
        $fixture = $this->fixture();
        $unapprovedUser = User::factory()->create(['account_type' => User::TYPE_RIDER, 'status' => User::STATUS_ACTIVE]);
        $unapproved = $this->driverProfile($fixture, $unapprovedUser, 'van', false);
        $before = TransferManifest::query()->count();

        $this->assertRuntimeFailure(
            fn () => app(TransferManifestService::class)->create(
                $fixture['originUser'],
                $fixture['origin'],
                $fixture['destination'],
                'atomic assignment failure',
                $unapproved,
            ),
            'approved, active, and authorized',
        );

        $this->assertSame($before, TransferManifest::query()->count());
        $this->assertDatabaseMissing('transfer_manifests', ['notes' => 'atomic assignment failure']);
    }

    public function test_sealing_requires_a_driver_and_an_eligible_ready_parcel(): void
    {
        $fixture = $this->fixture();
        $service = app(TransferManifestService::class);
        $empty = $service->create($fixture['originUser'], $fixture['origin'], $fixture['destination'], null, $fixture['driver']);
        $this->assertRuntimeFailure(fn () => $service->seal($fixture['originUser'], $empty), 'at least one transfer leg');

        $shipment = $this->preparedShipment($fixture);
        $unassigned = $this->manifestWithLegs($fixture, [$shipment]);
        $this->assertRuntimeFailure(fn () => $service->seal($fixture['originUser'], $unassigned), 'Assign an approved inter-logistics driver');
    }

    public function test_packed_measurements_are_persisted_and_invalid_or_missing_values_block_transfer(): void
    {
        $fixture = $this->fixture();
        $shipment = $this->preparedShipment($fixture);
        $workflow = app(ShipmentWorkflowService::class);

        $this->assertRuntimeFailure(fn () => $workflow->recordPackedMeasurements($shipment, $fixture['sellerUser'], '0', '1'), 'greater than zero');
        $this->assertRuntimeFailure(fn () => $workflow->recordPackedMeasurements($shipment, $fixture['sellerUser'], '-1', '1'), 'greater than zero');
        $shipment = $workflow->recordPackedMeasurements($shipment, $fixture['sellerUser'], '0.275', '1.500');
        $this->assertSame('0.275', (string) $shipment->fresh()->parcel_weight_kg);
        $this->assertSame('1.500', (string) $shipment->fresh()->parcel_volume_liters);

        $missing = $this->preparedShipment($fixture);
        $manifest = $this->manifestWithLegs($fixture, [$missing]);
        $this->assertRuntimeFailure(
            fn () => app(TransferManifestService::class)->assignDriver($fixture['originUser'], $manifest, $fixture['driver']),
            'a parcel weight is missing',
        );
        $manifest->update(['assigned_driver_rider_profile_id' => $fixture['driver']->id]);
        $this->assertRuntimeFailure(
            fn () => app(TransferManifestService::class)->seal($fixture['originUser'], $manifest->fresh()),
            'a parcel weight is missing',
        );
        $this->assertSame('DRAFT', $manifest->fresh()->status);
    }

    public function test_origin_hub_can_measure_a_draft_manifest_parcel_before_sealing(): void
    {
        $fixture = $this->fixture();
        $shipment = $this->preparedShipment($fixture);
        $manifest = $this->manifestWithLegs($fixture, [$shipment]);
        $item = $manifest->items()->firstOrFail();
        $service = app(TransferManifestService::class);

        $this->assertRuntimeFailure(
            fn () => $service->recordItemMeasurements($fixture['wrongCenterUser'], $manifest, $item, '1.250', '3.500'),
            'not authorized for this logistics center',
        );
        $this->assertNull($shipment->fresh()->parcel_weight_kg);

        $this->actingAs($fixture['originUser'])
            ->get(route('logistics.transfers.show', $manifest))
            ->assertOk()
            ->assertSee('Save measurements');
        $this->patch(route('logistics.transfers.items.measurements', [$manifest, $item]), [
            'parcel_weight_kg' => '1.250',
            'parcel_volume_liters' => '3.500',
        ])->assertRedirect();
        $this->assertSame('1.250', $shipment->fresh()->parcel_weight_kg);
        $this->assertSame('3.500', $shipment->fresh()->parcel_volume_liters);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $shipment->id,
            'actor_user_id' => $fixture['originUser']->id,
            'logistics_center_id' => $fixture['origin']->id,
        ]);

        $service->assignDriver($fixture['originUser'], $manifest, $fixture['driver']);
        $service->seal($fixture['originUser'], $manifest);
        $this->assertSame(TransferManifest::SEALED, $manifest->fresh()->status);
        $this->actingAs($fixture['driverUser'])
            ->get(route('rider.transfers.show', $manifest))
            ->assertOk()
            ->assertSee($manifest->manifest_number);
        $this->assertRuntimeFailure(
            fn () => $service->recordItemMeasurements($fixture['originUser'], $manifest, $item, '2.000', '4.000'),
            'draft manifest',
        );
    }

    public function test_measured_correction_above_assigned_driver_capacity_is_rolled_back(): void
    {
        $fixture = $this->fixture();
        $shipment = $this->preparedShipment($fixture);
        $manifest = $this->manifestWithLegs($fixture, [$shipment]);
        $item = $manifest->items()->firstOrFail();
        $service = app(TransferManifestService::class);
        $service->recordItemMeasurements($fixture['originUser'], $manifest, $item, '1.000', '2.000');
        $service->assignDriver($fixture['originUser'], $manifest, $fixture['driver']);

        $this->assertRuntimeFailure(
            fn () => $service->recordItemMeasurements($fixture['originUser'], $manifest, $item, '101.000', '2.000'),
            'exceeds the driver',
        );
        $this->assertSame('1.000', $shipment->fresh()->parcel_weight_kg);
    }

    public function test_handoff_scans_release_one_parcel_at_a_time(): void
    {
        $fixture = $this->fixture();
        $shipments = [$this->preparedShipment($fixture), $this->preparedShipment($fixture)];
        $workflow = app(ShipmentWorkflowService::class);
        foreach ($shipments as $shipment) {
            $workflow->recordPackedMeasurements($shipment, $fixture['sellerUser'], '1.000', '2.000');
        }

        $service = app(TransferManifestService::class);
        $manifest = $this->manifestWithLegs($fixture, $shipments);
        $service->assignDriver($fixture['originUser'], $manifest, $fixture['driver']);
        $service->seal($fixture['originUser'], $manifest);
        $service->driverAccept($fixture['driverUser'], $manifest);
        $items = $manifest->items()->with('transferLeg.shipment')->orderBy('id')->get();

        $this->actingAs($fixture['originUser'])->postJson(route('logistics.transfers.items.release', [$manifest, $items[0]]), [
            'scanned_code' => $items[0]->transferLeg->shipment->tracking_number,
            'scan_method' => 'BARCODE',
        ])->assertOk()->assertJsonPath('released_count', 1)->assertJsonPath('manifest_status', TransferManifest::SEALED);
        $this->assertSame(ShipmentTransferLeg::RELEASED, $items[0]->transferLeg->fresh()->status);
        $this->assertSame(ShipmentTransferLeg::READY, $items[1]->transferLeg->fresh()->status);
        $this->assertDatabaseHas('parcel_scans', [
            'transfer_leg_id' => $items[0]->transfer_leg_id,
            'scan_type' => 'TRANSFER_RELEASE',
            'scan_method' => 'BARCODE',
        ]);

        $this->postJson(route('logistics.transfers.items.release', [$manifest, $items[1]]), [
            'scanned_code' => $items[0]->transferLeg->shipment->tracking_number,
        ])->assertUnprocessable();
        $this->assertSame(TransferManifest::SEALED, $manifest->fresh()->status);

        $this->postJson(route('logistics.transfers.items.release', [$manifest, $items[1]]), [
            'scanned_code' => $items[1]->transferLeg->shipment->tracking_number,
            'scan_method' => 'QR',
        ])->assertOk()->assertJsonPath('released_count', 2)->assertJsonPath('manifest_status', TransferManifest::RELEASED);
        $this->assertSame(ShipmentTransferLeg::RELEASED, $items[1]->transferLeg->fresh()->status);
        $this->assertSame(TransferManifest::RELEASED, $manifest->fresh()->status);
    }

    public function test_pagsanjan_to_pila_transfer_enforces_acceptance_scans_custody_and_partial_arrival(): void
    {
        $fixture = $this->fixture();
        $shipments = [$this->preparedShipment($fixture), $this->preparedShipment($fixture), $this->preparedShipment($fixture)];
        $workflow = app(ShipmentWorkflowService::class);
        foreach ($shipments as $index => $shipment) {
            $workflow->recordPackedMeasurements($shipment, $fixture['sellerUser'], (string) (0.5 + $index), (string) (2 + $index));
        }

        $manifest = $this->manifestWithLegs($fixture, $shipments);
        foreach ($shipments as $shipment) {
            $statuses = $shipment->events()->pluck('status')->all();
            $this->assertContains('AT_SORTING_CENTER', $statuses);
            $this->assertContains('SORTED', $statuses);
            $this->assertDatabaseHas('parcel_scans', [
                'shipment_id' => $shipment->id,
                'scan_type' => 'CENTER_RECEIVE',
                'scanned_code' => $shipment->tracking_number,
                'result' => 'SUCCESS',
            ]);
            $this->assertSame(ShipmentTransferLeg::READY, $shipment->transferLegs()->sole()->status);
        }
        $service = app(TransferManifestService::class);
        $manifest = $service->assignDriver($fixture['originUser'], $manifest, $fixture['driver']);
        $manifest = $service->seal($fixture['originUser'], $manifest);
        $items = $manifest->items()->orderBy('id')->get();
        $codes = $items->mapWithKeys(fn (TransferManifestItem $item): array => [
            $item->id => $item->transferLeg->shipment->tracking_number,
        ])->all();

        $this->assertRuntimeFailure(fn () => $service->release($fixture['originUser'], $manifest, $codes), 'must accept the sealed manifest');
        $this->assertRuntimeFailure(fn () => $service->driverAccept($fixture['wrongDriverUser'], $manifest), 'assigned active inter-logistics driver');
        $service->driverAccept($fixture['driverUser'], $manifest);
        $manifest = $service->release($fixture['originUser'], $manifest->fresh(), $codes);
        $this->assertSame('RELEASED', $manifest->status);
        foreach ($items as $item) {
            $this->assertSame(ShipmentTransferLeg::RELEASED, $item->transferLeg->fresh()->status);
        }

        $this->actingAs($fixture['driverUser'])
            ->get(route('rider.transfers.show', $manifest))
            ->assertOk()
            ->assertSee('Scan each parcel as it is loaded for departure');
        $this->post(route('rider.transfers.start', $manifest), ['scanned_codes' => []])
            ->assertSessionHasErrors('scanned_codes');
        $wrongDepartureCodes = $codes;
        $wrongDepartureCodes[$items[0]->id] = 'WRONG-TRACKING-CODE';
        $this->assertRuntimeFailure(
            fn () => $service->driverStart($fixture['driverUser'], $manifest, $wrongDepartureCodes),
            'does not match the shipment assigned to this transfer leg',
        );
        $this->post(route('rider.transfers.start', $manifest), [
            'scanned_codes' => $codes,
            'scan_method' => 'BARCODE',
        ])->assertRedirect();
        $manifest = $manifest->fresh();
        $this->assertSame('IN_TRANSIT', $manifest->status);
        foreach ($items as $item) {
            $this->assertSame(ShipmentTransferLeg::IN_TRANSIT, $item->transferLeg->fresh()->status);
            $this->assertDatabaseHas('parcel_scans', [
                'transfer_leg_id' => $item->transfer_leg_id,
                'scan_type' => 'TRANSFER_DEPARTURE',
                'scanned_code' => $codes[$item->id],
                'result' => 'SUCCESS',
            ]);
        }

        $this->assertRuntimeFailure(fn () => $service->arrive($fixture['originUser'], $manifest), 'not authorized for this logistics center');
        $this->assertRuntimeFailure(
            fn () => app(FinalMileDispatchService::class)->dispatch(
                $fixture['destinationUser'],
                $fixture['destination'],
                $shipments[0],
                $fixture['destinationRider'],
            ),
            'must be sorted into this center and in its custody',
        );

        $manifest = $service->arrive($fixture['destinationUser'], $manifest);
        $this->assertSame('ARRIVED', $manifest->status);
        $this->assertRuntimeFailure(
            fn () => $service->receiveItem($fixture['wrongCenterUser'], $items[0], TransferManifestItem::RECEIVED, $codes[$items[0]->id]),
            'not authorized for this logistics center',
        );
        $service->receiveItem($fixture['destinationUser'], $items[0], TransferManifestItem::RECEIVED, $codes[$items[0]->id]);
        $service->receiveItem($fixture['destinationUser'], $items[1], TransferManifestItem::RECEIVED, $codes[$items[1]->id]);

        $this->assertSame('PARTIAL', $manifest->fresh()->status);
        $this->assertSame(2, $manifest->items()->where('status', TransferManifestItem::RECEIVED)->count());
        $this->assertSame(1, $manifest->items()->where('status', TransferManifestItem::EXPECTED)->count());
        $this->assertSame($fixture['destination']->id, (int) $shipments[0]->fresh()->logistics_center_id);
        $this->assertSame($fixture['destination']->id, (int) $shipments[1]->fresh()->logistics_center_id);
        $this->assertSame($fixture['origin']->id, (int) $shipments[2]->fresh()->logistics_center_id);
        $this->assertSame(ShipmentTransferLeg::RECEIVED, $items[0]->transferLeg->fresh()->status);
        $this->assertSame(ShipmentTransferLeg::RECEIVED, $items[1]->transferLeg->fresh()->status);
        $this->assertSame(ShipmentTransferLeg::IN_TRANSIT, $items[2]->transferLeg->fresh()->status);

        $service->receiveItem($fixture['destinationUser'], $items[2], TransferManifestItem::RECEIVED, $codes[$items[2]->id]);
        $this->assertSame('RECEIVED', $manifest->fresh()->status);
        foreach ($shipments as $shipment) {
            $this->assertSame($fixture['destination']->id, (int) $shipment->fresh()->logistics_center_id);
            $this->assertSame('AT_SORTING_CENTER', $shipment->fresh()->current_status);
        }
        foreach ($items as $item) {
            $this->assertSame(ShipmentTransferLeg::RECEIVED, $item->transferLeg->fresh()->status);
        }

        $workflow->sortShipment($shipments[0]->fresh(), $fixture['destination'], $fixture['destinationUser'], $fixture['area']);
        $finalMileTrip = app(FinalMileDispatchService::class)->dispatch(
            $fixture['destinationUser'],
            $fixture['destination'],
            $shipments[0]->fresh(),
            $fixture['destinationRider'],
        );
        $finalMileItem = $finalMileTrip->items()->with('riderAssignment')->sole();
        app(\App\Services\Fulfillment\LocalRiderTripService::class)->loadTrip(
            $fixture['destinationUser'],
            $finalMileTrip,
            [$finalMileItem->id => $shipments[0]->tracking_number],
        );
        $workflow->riderTransition($finalMileItem->riderAssignment, 'accept', $fixture['destinationRider']->user);
        $workflow->riderTransition($finalMileItem->riderAssignment->fresh(), 'start', $fixture['destinationRider']->user);
        $workflow->riderTransition($finalMileItem->riderAssignment->fresh(), 'delivery_success', $fixture['destinationRider']->user, [
            'scanned_code' => $shipments[0]->tracking_number,
            'scan_method' => 'BARCODE',
            'proof_path' => 'proofs/pagsanjan-pila-e2e.jpg',
            'collected_amount' => '100.00',
        ]);
        $this->assertSame('DELIVERED', $shipments[0]->fresh()->current_status);

        $this->actingAs($fixture['buyer'])->post(route('buyer.orders.received', $shipments[0]->sellerOrder->order), ['confirm_receipt' => '1'])->assertRedirect();
        $this->assertSame('COMPLETED', $shipments[0]->sellerOrder->order->fresh()->status);
        $this->assertSame('COMPLETED', $shipments[0]->fresh()->current_status);
    }

    private function fixture(): array
    {
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $originUser = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $destinationUser = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $driverUser = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        $wrongDriverUser = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        $category = Category::create(['name' => 'Transfer Test', 'slug' => 'transfer-test', 'is_active' => true]);
        $sellerAddress = $this->address($sellerUser, self::PAGSANJAN, 'Seller packing address');
        $seller = SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_address_id' => $sellerAddress->id,
            'business_name' => 'Pagsanjan Transfer Seller',
            'status' => 'ACTIVE',
        ]);
        $origin = $this->center($originUser, 'Pagsanjan Logistics', self::PAGSANJAN);
        $destination = $this->center($destinationUser, 'Pila Logistics', self::PILA);
        $wrongCenterUser = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $this->center($wrongCenterUser, 'Other Logistics', self::PAGSANJAN);
        $driver = RiderProfile::create([
            'user_id' => $driverUser->id,
            'logistics_center_id' => $origin->id,
            'vehicle_type' => 'van',
            'approved_parcel_capacity' => 3,
            'approved_weight_capacity_kg' => 100,
            'approved_volume_capacity_liters' => 500,
            'plate_number' => 'TRANSFER-'.uniqid(),
            'drivers_license_number' => 'LICENSE-'.uniqid(),
            'status' => 'ACTIVE',
            'approved_by_user_id' => $originUser->id,
            'approved_at' => now(),
        ]);
        $wrongDriver = RiderProfile::create([
            'user_id' => $wrongDriverUser->id,
            'logistics_center_id' => $origin->id,
            'vehicle_type' => 'van',
            'approved_parcel_capacity' => 3,
            'approved_weight_capacity_kg' => 100,
            'approved_volume_capacity_liters' => 500,
            'plate_number' => 'WRONG-TRANSFER-'.uniqid(),
            'drivers_license_number' => 'WRONG-LICENSE-'.uniqid(),
            'status' => 'ACTIVE',
            'approved_by_user_id' => $originUser->id,
            'approved_at' => now(),
        ]);
        $destinationRiderUser = User::factory()->create(['account_type' => User::TYPE_RIDER, 'status' => User::STATUS_ACTIVE]);
        $destinationRider = RiderProfile::create([
            'user_id' => $destinationRiderUser->id,
            'logistics_center_id' => $destination->id,
            'vehicle_type' => 'motorcycle',
            'approved_parcel_capacity' => 5,
            'plate_number' => 'PILA-RIDER-'.uniqid(),
            'drivers_license_number' => 'PILA-LICENSE-'.uniqid(),
            'status' => 'ACTIVE',
            'is_online' => true,
        ]);
        $area = ServiceArea::create([
            'logistics_center_id' => $destination->id,
            'code' => 'PILA-AREA-'.uniqid(),
            'name' => 'San Miguel Delivery Area',
            'is_active' => true,
        ]);
        ServiceAreaLocation::create(['service_area_id' => $area->id, ...self::PILA]);
        RiderAreaAssignment::create([
            'rider_profile_id' => $destinationRider->id,
            'service_area_id' => $area->id,
            'assigned_by_user_id' => $destinationUser->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        return compact('sellerUser', 'buyer', 'seller', 'originUser', 'destinationUser', 'driverUser', 'wrongDriverUser', 'wrongCenterUser', 'origin', 'destination', 'driver', 'wrongDriver', 'destinationRider', 'area');
    }

    private function preparedShipment(array $fixture): Shipment
    {
        $order = Order::create([
            'order_number' => 'TRANSFER-ORDER-'.uniqid(),
            'buyer_user_id' => $fixture['buyer']->id,
            'status' => 'PLACED',
            'grand_total' => 100,
            'placed_at' => now(),
        ]);
        OrderAddress::create([
            'order_id' => $order->id,
            'recipient_name' => $fixture['buyer']->name,
            'contact_number' => $fixture['buyer']->contact_number,
            ...self::PILA,
            'street_address' => 'Buyer address '.$order->order_number,
        ]);
        $sellerOrder = SellerOrder::create([
            'seller_order_number' => 'TRANSFER-SO-'.uniqid(),
            'order_id' => $order->id,
            'seller_profile_id' => $fixture['seller']->id,
            'status' => 'PREPARING',
            'item_subtotal' => 100,
            'grand_total' => 100,
        ]);

        return Shipment::create([
            'seller_order_id' => $sellerOrder->id,
            'tracking_number' => 'PAGSANJAN-PILA-'.uniqid(),
            'logistics_center_id' => $fixture['origin']->id,
            'origin_logistics_center_id' => $fixture['origin']->id,
            'destination_logistics_center_id' => $fixture['destination']->id,
            'origin_province_code' => self::PAGSANJAN['province_code'],
            'origin_province_name' => self::PAGSANJAN['province_name'],
            'origin_municipality_code' => self::PAGSANJAN['municipality_code'],
            'origin_municipality_name' => self::PAGSANJAN['municipality_name'],
            'origin_barangay_code' => self::PAGSANJAN['barangay_code'],
            'origin_barangay_name' => self::PAGSANJAN['barangay_name'],
            'destination_province_code' => self::PILA['province_code'],
            'destination_province_name' => self::PILA['province_name'],
            'destination_municipality_code' => self::PILA['municipality_code'],
            'destination_municipality_name' => self::PILA['municipality_name'],
            'destination_barangay_code' => self::PILA['barangay_code'],
            'destination_barangay_name' => self::PILA['barangay_name'],
            'current_status' => 'PREPARING',
        ]);
    }

    private function markSortedAndReady(Shipment $shipment, array $fixture): ShipmentTransferLeg
    {
        $shipment->update(['current_status' => 'PICKED_UP']);
        $workflow = app(ShipmentWorkflowService::class);
        $workflow->receiveAtCenter($shipment->fresh(), $fixture['origin'], $fixture['originUser'], $shipment->tracking_number, 'BARCODE');
        $transfers = app(ShipmentTransferService::class);
        $leg = $transfers->createPlannedLeg($shipment->fresh()->load('originLogisticsCenter.address', 'destinationLogisticsCenter.address'), $fixture['origin'], $fixture['destination']);
        $this->assertNotNull($leg);
        $workflow->sortShipment($shipment->fresh(), $fixture['origin'], $fixture['originUser']);

        return $leg->fresh();
    }

    private function manifestWithLegs(array $fixture, array $shipments): \App\Models\Logistics\TransferManifest
    {
        $service = app(TransferManifestService::class);
        $manifest = $service->create($fixture['originUser'], $fixture['origin'], $fixture['destination']);
        foreach ($shipments as $shipment) {
            $leg = $this->markSortedAndReady($shipment, $fixture);
            $service->addLeg($fixture['originUser'], $manifest, $leg);
        }

        return $manifest->fresh();
    }

    private function driverProfile(array $fixture, User $user, string $vehicleType, bool $approved): RiderProfile
    {
        return RiderProfile::create([
            'user_id' => $user->id,
            'logistics_center_id' => $fixture['origin']->id,
            'vehicle_type' => $vehicleType,
            'approved_parcel_capacity' => 3,
            'approved_weight_capacity_kg' => 100,
            'approved_volume_capacity_liters' => 500,
            'plate_number' => strtoupper($vehicleType).'-'.uniqid(),
            'drivers_license_number' => strtoupper($vehicleType).'-LICENSE-'.uniqid(),
            'status' => 'ACTIVE',
            'approved_by_user_id' => $approved ? $fixture['originUser']->id : null,
            'approved_at' => $approved ? now() : null,
        ]);
    }

    private function address(User $user, array $codes, string $street): Address
    {
        return Address::create([
            'user_id' => $user->id,
            'label' => 'Test',
            'recipient_name' => $user->name,
            'contact_number' => $user->contact_number,
            ...$codes,
            'street_address' => $street,
        ]);
    }

    private function center(User $owner, string $name, array $codes): LogisticsCenter
    {
        $address = $this->address($owner, $codes, $name.' Address');

        return LogisticsCenter::create([
            'owner_user_id' => $owner->id,
            'address_id' => $address->id,
            'code' => strtoupper(str_replace(' ', '-', $name)).'-'.uniqid(),
            'business_name' => $name,
            'status' => 'ACTIVE',
            'approved_by_user_id' => $owner->id,
            'approved_at' => now(),
        ]);
    }

    private function assertRuntimeFailure(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Expected RuntimeException containing: '.$message);
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
