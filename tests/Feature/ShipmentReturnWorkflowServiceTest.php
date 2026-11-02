<?php

namespace Tests\Feature;

use App\Http\Controllers\Rider\RiderShipmentController;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentReturnWorkflow;
use App\Models\Logistics\ShipmentTransferLeg;
use App\Models\Rider\RiderAssignment;
use App\Models\User;
use App\Services\Fulfillment\ShipmentReturnWorkflowService;
use App\Services\Fulfillment\ShipmentWorkflowService;
use App\Services\Fulfillment\ShipmentTransferService;
use App\Services\Fulfillment\LocalRiderTripService;
use App\Services\Finance\ReturnTransportLossService;
use App\Services\Media\ImageOptimizationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShipmentReturnWorkflowServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        Event::fake();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_failed_delivery_return_moves_through_verified_custody_before_shipment_is_returned(): void
    {
        [$centerUser, $originalRider, $returnRider, $seller, $workflow, $shipment] = $this->fixture();
        $returns = app(ShipmentReturnWorkflowService::class);

        $this->assertSame(ShipmentReturnWorkflow::AWAITING_CENTER_HANDOFF, $workflow->status);
        try {
            $returns->handoffToCenterAsOriginalRider($workflow, $returnRider, 'MANUAL', 'LK-RETURN-1');
            $this->fail('A different rider must not hand off the original rider’s parcel.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        try {
            $returns->handoffToCenterAsOriginalRider($workflow, $originalRider, 'MANUAL', 'WRONG-CODE');
            $this->fail('A wrong parcel code must not advance custody.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('does not match', $exception->getMessage());
        }
        $this->assertSame(ShipmentReturnWorkflow::AWAITING_CENTER_HANDOFF, $workflow->fresh()->status);

        $returns->handoffToCenterAsOriginalRider($workflow, $originalRider, 'BARCODE', 'LK-RETURN-1');
        $this->assertSame(ShipmentReturnWorkflow::AWAITING_CENTER_RECEIPT, $workflow->fresh()->status);
        try {
            $returns->handoffToCenterAsOriginalRider($workflow, $originalRider, 'BARCODE', 'LK-RETURN-1');
            $this->fail('A duplicate center handoff must not be recorded.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no longer available', $exception->getMessage());
        }
        DB::table('users')->insert(['id' => 5, 'first_name' => 'Other Center', 'last_name' => 'Operator', 'email' => 'other.center@test.invalid', 'account_type' => 'LOGISTICS', 'status' => 'ACTIVE']);
        DB::table('logistics_centers')->insert(['id' => 2, 'owner_user_id' => 5, 'business_name' => 'Other Center']);
        try {
            $returns->receiveAtCenter($workflow, User::query()->findOrFail(5), 'QR', 'LK-RETURN-1');
            $this->fail('A different logistics center must not receive this parcel.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $returns->receiveAtCenter($workflow, $centerUser, 'QR', 'LK-RETURN-1', 'Parcel sealed at intake');
        $this->assertSame(ShipmentReturnWorkflow::READY_FOR_RETURN_ASSIGNMENT, $workflow->fresh()->status);
        try {
            $returns->receiveAtCenter($workflow, $centerUser, 'QR', 'LK-RETURN-1');
            $this->fail('A duplicate center receipt must not create another scan.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no longer available', $exception->getMessage());
        }
        $this->assertSame(2, DB::table('parcel_scans')->where('shipment_id', $shipment->id)->count());

        $returns->assignReturnRider($workflow->fresh(), $centerUser, (int) $returnRider->riderProfile->id);
        $this->assertSame(ShipmentReturnWorkflow::ASSIGNED_TO_RETURN_RIDER, $workflow->fresh()->status);
        try {
            $returns->assignReturnRider($workflow->fresh(), $centerUser, (int) $returnRider->riderProfile->id);
            $this->fail('A duplicate return rider assignment must not be recorded.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already assigned', $exception->getMessage());
        }
        $returns->assignReturnRider($workflow->fresh(), $centerUser, (int) $originalRider->riderProfile->id);
        $returns->assignReturnRider($workflow->fresh(), $centerUser, (int) $returnRider->riderProfile->id);
        $this->assertSame(1, DB::table('shipment_return_workflows')->where('shipment_id', $shipment->id)->count());
        $this->assertStringContainsString('reassigned the return rider', (string) DB::table('shipment_events')->where('shipment_id', $shipment->id)->orderByDesc('id')->value('notes'));
        $this->assertSame((int) $returnRider->riderProfile->id, (int) $workflow->fresh()->return_rider_profile_id);
        $returns->acceptAsReturnRider($workflow->fresh(), $returnRider);
        $this->assertSame(ShipmentReturnWorkflow::RETURN_RIDER_ACCEPTED, $workflow->fresh()->status);
        $returns->pickupFromCenterAsReturnRider($workflow->fresh(), $returnRider, 'BARCODE', 'LK-RETURN-1');
        $this->assertSame(ShipmentReturnWorkflow::IN_TRANSIT_TO_SELLER, $workflow->fresh()->status);
        $returns->handoffToSellerAsReturnRider($workflow->fresh(), $returnRider, 'QR', 'LK-RETURN-1', 'Delivered to the seller counter');
        $this->assertSame(ShipmentReturnWorkflow::AWAITING_SELLER_CONFIRMATION, $workflow->fresh()->status);
        $this->assertSame('DELIVERY_FAILED', $shipment->fresh()->current_status);
        try {
            $returns->handoffToSellerAsReturnRider($workflow->fresh(), $returnRider, 'QR', 'LK-RETURN-1');
            $this->fail('A duplicate seller handoff must not create another scan.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no longer available', $exception->getMessage());
        }
        $this->assertSame(4, DB::table('parcel_scans')->where('shipment_id', $shipment->id)->count());
        try {
            $returns->confirmSellerReceipt($workflow->fresh(), $returnRider, 'Unauthorized confirmation');
            $this->fail('A rider must not confirm seller receipt.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('DELIVERY_FAILED', $shipment->fresh()->current_status);

        $returns->confirmSellerReceipt($workflow->fresh(), $seller, 'Seller checked the parcel');
        $completedWorkflow = $workflow->fresh();
        $this->assertSame(ShipmentReturnWorkflow::SELLER_RECEIVED, $completedWorkflow->status);
        $this->assertSame($originalRider->id, $completedWorkflow->initiated_by_user_id);
        $this->assertNotNull($completedWorkflow->initiated_at);
        $this->assertSame($originalRider->id, $completedWorkflow->center_handoff_by_user_id);
        $this->assertNotNull($completedWorkflow->center_handoff_at);
        $this->assertSame($centerUser->id, $completedWorkflow->center_received_by_user_id);
        $this->assertNotNull($completedWorkflow->center_received_at);
        $this->assertSame($centerUser->id, $completedWorkflow->return_rider_assigned_by_user_id);
        $this->assertNotNull($completedWorkflow->return_rider_assigned_at);
        $this->assertNotNull($completedWorkflow->return_rider_accepted_at);
        $this->assertSame($returnRider->id, $completedWorkflow->return_rider_picked_up_by_user_id);
        $this->assertNotNull($completedWorkflow->return_rider_picked_up_at);
        $this->assertSame($returnRider->id, $completedWorkflow->seller_handoff_by_user_id);
        $this->assertNotNull($completedWorkflow->seller_handoff_at);
        $this->assertSame($seller->id, $completedWorkflow->seller_received_by_user_id);
        $this->assertNotNull($completedWorkflow->seller_received_at);
        $this->assertSame('RETURNED', $shipment->fresh()->current_status);
        $this->assertSame(5, DB::table('parcel_scans')->where('shipment_id', $shipment->id)->count());
        $this->assertSame('RETURNED', DB::table('shipment_events')->where('shipment_id', $shipment->id)->orderByDesc('id')->value('status'));
        $this->assertSame(0, DB::table('return_custody_events')->count());
        $this->assertFalse($returns->hasOpenWorkflow($shipment->fresh()));

        $returns->confirmSellerReceipt($workflow->fresh(), $seller, 'Duplicate submit');
        $this->assertSame(5, DB::table('parcel_scans')->where('shipment_id', $shipment->id)->count(), 'Repeated confirmation must not duplicate custody scans.');
    }

    public function test_logistics_receipt_scan_recovers_lost_seller_return_without_erasing_loss_history(): void
    {
        [$centerUser, $originalRider, , , $workflow, $shipment] = $this->fixture();
        $shipment->update(['current_status' => 'LOST']);
        app(ShipmentWorkflowService::class)->recordEvent($shipment, 'LOST', $centerUser, 'Original loss exception for review.');
        $returns = app(ShipmentReturnWorkflowService::class);

        $returns->handoffToCenterAsOriginalRider($workflow, $originalRider, 'BARCODE', $shipment->tracking_number);
        try {
            $returns->receiveAtCenter($workflow->fresh(), $centerUser, 'QR', 'WRONG-CODE');
            $this->fail('A mismatched scan must not recover or receive the parcel.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('does not match', $exception->getMessage());
        }
        $this->assertSame('LOST', $shipment->fresh()->current_status);
        $this->assertSame(ShipmentReturnWorkflow::AWAITING_CENTER_RECEIPT, $workflow->fresh()->status);

        $returns->receiveAtCenter($workflow->fresh(), $centerUser, 'QR', $shipment->tracking_number);

        $this->assertSame('DELIVERY_FAILED', $shipment->fresh()->current_status);
        $this->assertSame(ShipmentReturnWorkflow::READY_FOR_RETURN_ASSIGNMENT, $workflow->fresh()->status);
        $this->assertSame(1, DB::table('shipment_events')->where('shipment_id', $shipment->id)->where('status', 'LOST')->count());
        $this->assertSame(1, DB::table('parcel_scans')->where('shipment_id', $shipment->id)->where('scan_type', 'RETURN_TO_CENTER_RECEIVED')->count());
        $this->assertStringContainsString('previously recorded as lost', (string) DB::table('shipment_events')->where('shipment_id', $shipment->id)->orderByDesc('id')->value('notes'));
    }

    public function test_refused_delivery_automatically_opens_a_return_case(): void
    {
        [, $originalRider, , , $workflow, $shipment] = $this->fixture();
        DB::table('shipment_return_workflows')->delete();
        DB::table('shipments')->where('id', $shipment->id)->update(['current_status' => 'OUT_FOR_DELIVERY']);
        DB::table('rider_assignments')->where('id', 1)->update(['status' => 'IN_PROGRESS']);
        $assignment = RiderAssignment::query()->findOrFail(1);

        app(ShipmentWorkflowService::class)->riderTransition($assignment, 'delivery_failed', $originalRider, [
            'failure_reason' => 'Buyer refused parcel',
            'attempt_status' => 'FAILED',
        ]);

        $opened = ShipmentReturnWorkflow::query()->where('shipment_id', $shipment->id)->firstOrFail();
        $this->assertSame(ShipmentReturnWorkflow::AWAITING_CENTER_HANDOFF, $opened->status);
        $this->assertTrue(app(ShipmentReturnWorkflowService::class)->hasOpenWorkflow($shipment->fresh()));
        $this->assertSame('DELIVERY_FAILED', $shipment->fresh()->current_status);
        $this->assertSame('FAILED', DB::table('delivery_attempts')->where('shipment_id', $shipment->id)->value('status'));
        $this->assertSame('Buyer refused parcel', DB::table('delivery_attempts')->where('shipment_id', $shipment->id)->value('failure_reason'));
        $this->assertSame(1, DB::table('shipment_events')->where('shipment_id', $shipment->id)->where('status', 'DELIVERY_FAILED')->count());
    }

    public function test_third_failed_attempt_opens_return_instead_of_scheduling_a_fourth_delivery(): void
    {
        [, $originalRider, , , , $shipment] = $this->fixture();
        DB::table('shipment_return_workflows')->delete();
        DB::table('shipments')->where('id', $shipment->id)->update(['current_status' => 'OUT_FOR_DELIVERY']);
        DB::table('rider_assignments')->where('id', 1)->update(['status' => 'IN_PROGRESS']);
        foreach ([1, 2] as $attempt) {
            DB::table('delivery_attempts')->insert([
                'shipment_id' => $shipment->id,
                'rider_assignment_id' => 1,
                'attempt_number' => $attempt,
                'status' => 'FAILED',
                'failure_reason' => 'Recipient unavailable',
                'attempted_at' => now()->subDays(4 - $attempt),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(ShipmentWorkflowService::class)->riderTransition(RiderAssignment::query()->findOrFail(1), 'delivery_failed', $originalRider, [
            'failure_reason' => 'Recipient unavailable again',
            'attempt_status' => 'RESCHEDULED',
            'next_attempt_at' => now()->addDay(),
        ]);

        $this->assertSame('FAILED', DB::table('delivery_attempts')->where('shipment_id', $shipment->id)->orderByDesc('attempt_number')->value('status'));
        $this->assertSame(ShipmentReturnWorkflow::AWAITING_CENTER_HANDOFF, ShipmentReturnWorkflow::query()->where('shipment_id', $shipment->id)->value('status'));
        $this->assertSame('DELIVERY_FAILED', $shipment->fresh()->current_status);
    }

    public function test_valid_reschedule_stays_retryable_and_invalid_reschedule_date_is_rejected_by_controller(): void
    {
        [, $originalRider, , , , $shipment] = $this->fixture();
        DB::table('shipment_return_workflows')->delete();
        DB::table('shipments')->where('id', $shipment->id)->update(['current_status' => 'OUT_FOR_DELIVERY']);
        DB::table('rider_assignments')->where('id', 1)->update(['status' => 'IN_PROGRESS']);
        $assignment = RiderAssignment::query()->findOrFail(1);
        $validRequest = Request::create('/rider/assignments/1', 'PATCH', [
            'action' => 'delivery_failed',
            'failure_reason' => 'Recipient requested a new delivery time',
            'attempt_status' => 'RESCHEDULED',
            'next_attempt_at' => now()->addDay()->toDateTimeString(),
        ]);
        $validRequest->setUserResolver(fn () => $originalRider);
        app(RiderShipmentController::class)->transition(
            $validRequest,
            $assignment,
            app(ShipmentWorkflowService::class),
            app(ImageOptimizationService::class),
            app(LocalRiderTripService::class),
        );

        $attempt = DB::table('delivery_attempts')->where('shipment_id', $shipment->id)->first();
        $this->assertSame('RESCHEDULED', $attempt->status);
        $this->assertSame('ACCEPTED', $assignment->fresh()->status);
        $this->assertSame('DELIVERY_FAILED', $shipment->fresh()->current_status);
        $workflow = ShipmentReturnWorkflow::query()->where('shipment_id', $shipment->id)->firstOrFail();
        $this->assertSame(ShipmentReturnWorkflow::DISPOSITION_REDELIVERY, $workflow->return_disposition);
        $this->assertTrue(app(ShipmentReturnWorkflowService::class)->hasOpenWorkflow($shipment->fresh()));

        try {
            app(ShipmentWorkflowService::class)->riderTransition($assignment->fresh(), 'start', $originalRider);
            $this->fail('A scheduled retry must wait until Logistics confirms route-return custody.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Return the undelivered parcel', $exception->getMessage());
        }

        $returns = app(ShipmentReturnWorkflowService::class);
        $returns->handoffToCenterAsOriginalRider($workflow, $originalRider, 'MANUAL', $shipment->tracking_number);
        $returns->receiveAtCenter($workflow->fresh(), User::query()->findOrFail(1), 'QR', $shipment->tracking_number);
        $this->assertSame(ShipmentReturnWorkflow::CENTER_RECEIVED_FOR_REDELIVERY, $workflow->fresh()->status);
        $this->assertSame('DELIVERY_FAILED', $shipment->fresh()->current_status);
        $this->assertFalse($returns->hasOpenWorkflow($shipment->fresh()));
        $this->assertSame(1, DB::table('rider_assignments')->where('shipment_id', $shipment->id)->count(), 'Returning the parcel must not create a separate pickup assignment.');
        app(ShipmentWorkflowService::class)->riderTransition($assignment->fresh(), 'start', $originalRider);
        $this->assertSame('OUT_FOR_DELIVERY', $shipment->fresh()->current_status, 'The parcel remains eligible for its rescheduled delivery after center receipt.');

        DB::table('shipments')->where('id', $shipment->id)->update(['current_status' => 'OUT_FOR_DELIVERY']);
        DB::table('rider_assignments')->where('id', 1)->update(['status' => 'IN_PROGRESS']);
        $invalidRequest = Request::create('/rider/assignments/1', 'PATCH', [
            'action' => 'delivery_failed',
            'failure_reason' => 'Recipient requested another time',
            'attempt_status' => 'RESCHEDULED',
            'next_attempt_at' => now()->subDay()->toDateTimeString(),
        ]);
        $invalidRequest->setUserResolver(fn () => $originalRider);

        try {
            app(RiderShipmentController::class)->transition(
                $invalidRequest,
                $assignment->fresh(),
                app(ShipmentWorkflowService::class),
                app(ImageOptimizationService::class),
                app(LocalRiderTripService::class),
            );
            $this->fail('A reschedule date in the past must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('next_attempt_at', $exception->errors());
        }

        $this->assertSame('OUT_FOR_DELIVERY', $shipment->fresh()->current_status);
        $this->assertSame(1, DB::table('delivery_attempts')->where('shipment_id', $shipment->id)->count());
    }

    public function test_batch_return_receiving_records_partial_results_per_parcel(): void
    {
        [, $originalRider, , , , $firstShipment] = $this->fixture();
        $secondShipment = Shipment::query()->create([
            'seller_order_id' => 1,
            'tracking_number' => 'LK-RETURN-2',
            'logistics_center_id' => 1,
            'origin_logistics_center_id' => 1,
            'current_status' => 'DELIVERY_FAILED',
        ]);
        DB::table('rider_assignments')->insert([
            'id' => 2,
            'shipment_id' => $secondShipment->id,
            'rider_profile_id' => 1,
            'assignment_type' => 'DELIVERY',
            'status' => 'COMPLETED',
        ]);
        $secondWorkflow = app(ShipmentReturnWorkflowService::class)->initiateFromDeliveryFailure(
            $secondShipment,
            RiderAssignment::query()->findOrFail(2),
            $originalRider,
            'Recipient unavailable',
            ShipmentReturnWorkflow::DISPOSITION_REDELIVERY,
        );
        $returns = app(ShipmentReturnWorkflowService::class);
        $firstWorkflow = ShipmentReturnWorkflow::query()->where('shipment_id', $firstShipment->id)->firstOrFail();
        $firstWorkflow->update(['return_disposition' => ShipmentReturnWorkflow::DISPOSITION_REDELIVERY]);
        $returns->handoffToCenterAsOriginalRider($firstWorkflow, $originalRider, 'MANUAL', $firstShipment->tracking_number);
        $returns->handoffToCenterAsOriginalRider($secondWorkflow, $originalRider, 'BARCODE', $secondShipment->tracking_number);

        $results = $returns->receiveBatchAtCenter(User::query()->findOrFail(1), [
            $firstShipment->tracking_number,
            $secondShipment->tracking_number,
            'LK-UNKNOWN',
            strtolower($firstShipment->tracking_number),
            $firstShipment->tracking_number,
        ], 'QR');

        $this->assertCount(5, $results);
        $this->assertTrue($results[0]['success']);
        $this->assertTrue($results[1]['success']);
        $this->assertFalse($results[2]['success']);
        $this->assertStringContainsString('No parcel', $results[2]['message']);
        $this->assertFalse($results[3]['success']);
        $this->assertStringContainsString('Duplicate', $results[3]['message']);
        $this->assertFalse($results[4]['success']);
        $this->assertSame(ShipmentReturnWorkflow::CENTER_RECEIVED_FOR_REDELIVERY, $firstWorkflow->fresh()->status);
        $this->assertSame(ShipmentReturnWorkflow::CENTER_RECEIVED_FOR_REDELIVERY, $secondWorkflow->fresh()->status);
        $this->assertSame(2, DB::table('parcel_scans')->where('scan_type', 'RETURN_TO_CENTER_RECEIVED')->count());
    }

    public function test_return_rider_handoff_requires_both_verified_rider_scans_and_can_be_stopped_safely(): void
    {
        [$centerUser, $originalRider, $returnRider, , $workflow, $shipment] = $this->fixture();
        $returns = app(ShipmentReturnWorkflowService::class);
        $workflow->update(['return_disposition' => ShipmentReturnWorkflow::DISPOSITION_SELLER_RETURN]);
        $returns->handoffToCenterAsOriginalRider($workflow, $originalRider, 'MANUAL', $shipment->tracking_number);
        $returns->receiveAtCenter($workflow->fresh(), $centerUser, 'MANUAL', $shipment->tracking_number);
        $returns->assignReturnRider($workflow->fresh(), $centerUser, (int) $returnRider->riderProfile->id);
        $returns->acceptAsReturnRider($workflow->fresh(), $returnRider);
        $returns->pickupFromCenterAsReturnRider($workflow->fresh(), $returnRider, 'MANUAL', $shipment->tracking_number);
        $returns->requestReturnRiderHandoff($workflow->fresh(), $centerUser, 1, 'Vehicle issue during seller route');

        $pending = $workflow->fresh();
        $this->assertSame(ShipmentReturnWorkflow::RETURN_RIDER_HANDOFF_PENDING, $pending->status);
        $this->assertSame((int) $returnRider->riderProfile->id, (int) $pending->return_rider_profile_id, 'The existing rider remains custodian until both scans finish.');
        $this->assertSame(1, (int) $pending->pending_return_rider_profile_id);
        try {
            $returns->confirmReturnRiderHandoffTo($pending, $originalRider, 'QR', $shipment->tracking_number);
            $this->fail('The receiving rider cannot accept before the current rider scans release.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('current custodian must scan', $exception->getMessage());
        }

        $returns->confirmReturnRiderHandoffFrom($workflow->fresh(), $returnRider, 'BARCODE', $shipment->tracking_number);
        $this->assertSame((int) $returnRider->riderProfile->id, (int) $workflow->fresh()->return_rider_profile_id);
        $stopped = $returns->cancelReturnRiderHandoff($workflow->fresh(), $centerUser, 'Receiving rider unavailable');
        $this->assertSame(ShipmentReturnWorkflow::RETURN_EXCEPTION, $stopped->status);
        $this->assertSame((int) $returnRider->riderProfile->id, (int) $stopped->return_rider_profile_id);
        $this->assertNull($stopped->pending_return_rider_profile_id);
        $this->assertSame('DELIVERY_FAILED', $shipment->fresh()->current_status);

        $resumed = $returns->resolveReturnException($stopped, $centerUser, 'Confirmed the parcel remains with the assigned rider.');
        $this->assertSame(ShipmentReturnWorkflow::IN_TRANSIT_TO_SELLER, $resumed->status);
        $this->assertSame((int) $returnRider->riderProfile->id, (int) $resumed->return_rider_profile_id);
        try {
            $returns->handoffToSellerAsReturnRider($resumed, $originalRider, 'MANUAL', $shipment->tracking_number);
            $this->fail('The previous non-custodian must not act as the current return rider.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $returns->requestReturnRiderHandoff($resumed, $centerUser, 1, 'Vehicle issue during seller route');
        $returns->confirmReturnRiderHandoffFrom($workflow->fresh(), $returnRider, 'MANUAL', $shipment->tracking_number);
        $completed = $returns->confirmReturnRiderHandoffTo($workflow->fresh(), $originalRider, 'QR', $shipment->tracking_number);
        $this->assertSame(ShipmentReturnWorkflow::IN_TRANSIT_TO_SELLER, $completed->status);
        $this->assertSame(1, (int) $completed->return_rider_profile_id);
        $this->assertNotNull($completed->handoff_completed_at);
    }

    public function test_cross_center_return_transfer_recovers_lost_parcel_and_preserves_loss_history(): void
    {
        [$centerAUser, $originalRider, , , $workflow, $shipment] = $this->fixture();
        $shipment->update(['current_status' => 'LOST']);
        app(ShipmentWorkflowService::class)->recordEvent($shipment, 'LOST', $centerAUser, 'Original loss exception for review.');
        DB::table('addresses')->insert([
            ['id' => 1, 'province_code' => '01', 'municipality_code' => '0101'],
            ['id' => 2, 'province_code' => '02', 'municipality_code' => '0202'],
        ]);
        DB::table('logistics_centers')->where('id', 1)->update(['address_id' => 1]);
        DB::table('users')->insert([
            ['id' => 5, 'first_name' => 'Center', 'last_name' => 'B', 'email' => 'center.b@test.invalid', 'account_type' => 'LOGISTICS', 'status' => 'ACTIVE'],
            ['id' => 6, 'first_name' => 'Transfer', 'last_name' => 'Driver', 'email' => 'transfer.driver@test.invalid', 'account_type' => 'RIDER', 'status' => 'ACTIVE'],
        ]);
        DB::table('logistics_centers')->insert(['id' => 2, 'owner_user_id' => 5, 'business_name' => 'Seller Center', 'address_id' => 2]);
        DB::table('rider_profiles')->insert(['id' => 3, 'user_id' => 6, 'logistics_center_id' => 1, 'status' => 'ACTIVE', 'vehicle_type' => 'van']);
        DB::table('shipments')->where('id', $shipment->id)->update([
            'origin_logistics_center_id' => 2,
            'origin_province_code' => '02',
            'origin_municipality_code' => '0202',
            'destination_logistics_center_id' => 1,
            'destination_province_code' => '01',
            'destination_municipality_code' => '0101',
        ]);

        $returns = app(ShipmentReturnWorkflowService::class);
        $returns->handoffToCenterAsOriginalRider($workflow, $originalRider, 'MANUAL', $shipment->tracking_number);
        $returns->receiveAtCenter($workflow->fresh(), $centerAUser, 'QR', $shipment->tracking_number);
        $this->assertSame(ShipmentReturnWorkflow::AWAITING_CROSS_CENTER_TRANSFER, $workflow->fresh()->status);
        $leg = ShipmentTransferLeg::query()->where('shipment_id', $shipment->id)->firstOrFail();
        $this->assertSame(ShipmentTransferLeg::READY, $leg->status);

        $transfers = app(ShipmentTransferService::class);
        $transfers->release($leg, $centerAUser, $shipment->tracking_number, 'BARCODE');
        $transfers->depart($leg->fresh(), User::query()->findOrFail(6), $shipment->tracking_number, 'BARCODE');
        $transfers->receive($leg->fresh(), User::query()->findOrFail(5), $shipment->tracking_number, 'QR');

        $this->assertSame(ShipmentTransferLeg::RECEIVED, $leg->fresh()->status);
        $this->assertSame(ShipmentReturnWorkflow::READY_FOR_RETURN_ASSIGNMENT, $workflow->fresh()->status);
        $this->assertSame(2, (int) $shipment->fresh()->logistics_center_id);
        $this->assertSame('DELIVERY_FAILED', $shipment->fresh()->current_status);
        $this->assertSame(1, DB::table('shipment_events')->where('shipment_id', $shipment->id)->where('status', 'LOST')->count());
        $this->assertSame(3, DB::table('parcel_scans')->where('transfer_leg_id', $leg->id)->count(), 'Origin release, transfer departure, and destination receipt are separately recorded custody scans.');
        $this->assertStringContainsString('Seller-side center confirmed receipt', (string) DB::table('shipment_events')->where('shipment_id', $shipment->id)->orderByDesc('id')->value('notes'));
    }

    public function test_return_routes_are_registered_and_return_templates_compile(): void
    {
        [$centerUser] = $this->fixture();
        $this->withoutMiddleware([
            \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            \App\Http\Middleware\EnsureApprovedProvider::class,
        ]);
        $this->actingAs($centerUser)
            ->get(route('logistics.returns'))
            ->assertOk();

        foreach ([
            'logistics.returns', 'logistics.returns.receive', 'logistics.returns.assign',
            'logistics.returns.receive-batch', 'logistics.returns.prepare-transfer', 'logistics.returns.handoff',
            'logistics.returns.handoff.cancel', 'logistics.returns.exceptions.resolve',
            'rider.returns', 'rider.returns.center-handoff', 'rider.returns.accept', 'rider.returns.pickup', 'rider.returns.seller-handoff',
            'rider.returns.handoff.from', 'rider.returns.handoff.to',
            'seller.logistics.returns.confirm',
            'admin.finance.return-transport.classify',
        ] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, $name.' should be registered');
            $this->assertContains('auth', $route->middleware(), $name.' must require authentication');
        }

        foreach ([
            'resources/views/Logistics/returns/index.blade.php',
            'resources/views/rider/returns/index.blade.php',
            'resources/views/seller/logistics.blade.php',
            'resources/views/buyer/orders.blade.php',
            'resources/views/Admin/finance/index.blade.php',
            'resources/views/seller/settlements/index.blade.php',
        ] as $relativePath) {
            $compiled = app('blade.compiler')->compileString(file_get_contents(base_path($relativePath)));
            $this->assertNotSame('', $compiled, $relativePath.' should compile.');
        }
    }

    public function test_admin_posts_buyer_fault_as_platform_loss_once_using_two_checkout_shipping_fees(): void
    {
        [, , , , $workflow] = $this->fixture();
        DB::table('users')->insert(['id' => 5, 'first_name' => 'Finance', 'last_name' => 'Admin', 'email' => 'finance.admin@test.invalid', 'account_type' => 'ADMIN', 'status' => 'ACTIVE']);
        try {
            app(ReturnTransportLossService::class)->classify($workflow, User::query()->findOrFail(5), 'BUYER', 'Buyer refused the parcel after the rider arrived.');
            $this->fail('Return losses must not post before Seller receipt is confirmed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('after the Seller confirms receipt', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('financial_ledger_entries')->count());

        $workflow->update(['status' => ShipmentReturnWorkflow::SELLER_RECEIVED]);
        DB::table('seller_settlements')->insert(['seller_profile_id' => 1, 'seller_payable' => '100.00', 'outstanding_balance' => '100.00', 'payout_status' => 'AVAILABLE']);
        $returnAccounting = app(ReturnTransportLossService::class);
        $pending = $returnAccounting->pendingReviews();
        $this->assertCount(1, $pending);
        $this->assertSame(10000, $pending->first()->return_transport_quote['total_cents']);

        $this->withoutMiddleware([
            \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            \App\Http\Middleware\EnsureWorkspaceRole::class,
        ]);
        $this->actingAs(User::query()->findOrFail(5))
            ->from('/admin/finance')
            ->post(route('admin.finance.return-transport.classify', $workflow), [
                'fault_party' => 'BUYER',
                'decision_notes' => 'Buyer refused the parcel after the rider arrived.',
            ])
            ->assertRedirect('/admin/finance')
            ->assertSessionHas('status');
        $entry = \App\Models\Finance\FinancialLedgerEntry::query()->where('idempotency_key', 'return-transport-loss-workflow-'.$workflow->id)->firstOrFail();
        $retry = $returnAccounting->classify($workflow->fresh(), User::query()->findOrFail(5), 'BUYER', 'Retry after a network interruption.');

        $this->assertSame($entry->id, $retry->id);
        $this->assertSame('PLATFORM_OPERATING_EXPENSE', $entry->account_type);
        $this->assertSame('RETURN_TRANSPORT_LOSS', $entry->entry_type);
        $this->assertSame('100.00', $entry->amount);
        $this->assertSame(2, data_get($entry->metadata, 'trip_count'));
        $this->assertSame('50.00', data_get($entry->metadata, 'checkout_shipping_fee'));
        $this->assertSame(100.0, (float) DB::table('seller_settlements')->value('seller_payable'));
        $this->assertSame(1, DB::table('financial_ledger_entries')->where('idempotency_key', 'return-transport-loss-workflow-'.$workflow->id)->count());
        $this->assertCount(0, $returnAccounting->pendingReviews());
    }

    public function test_admin_charges_seller_fault_to_unpaid_seller_payable_and_carries_any_unpaid_remainder(): void
    {
        [, , , , $workflow] = $this->fixture();
        $workflow->update(['status' => ShipmentReturnWorkflow::SELLER_RECEIVED]);
        DB::table('users')->insert(['id' => 5, 'first_name' => 'Finance', 'last_name' => 'Admin', 'email' => 'finance.admin@test.invalid', 'account_type' => 'ADMIN', 'status' => 'ACTIVE']);
        DB::table('seller_settlements')->insert(['seller_profile_id' => 1, 'seller_payable' => '30.00', 'outstanding_balance' => '30.00', 'payout_status' => 'AVAILABLE']);

        $entry = app(ReturnTransportLossService::class)->classify($workflow, User::query()->findOrFail(5), 'SELLER', 'Seller packed the wrong item for this order.');

        $this->assertSame('SELLER', $entry->account_type);
        $this->assertSame('RETURN_TRANSPORT_CHARGE', $entry->entry_type);
        $this->assertSame('100.00', $entry->amount);
        $this->assertSame(0.0, (float) DB::table('seller_settlements')->value('seller_payable'));
        $this->assertSame(0.0, (float) DB::table('seller_settlements')->value('outstanding_balance'));
        $this->assertSame(7000, app(\App\Services\Finance\SellerSettlementService::class)->unappliedSellerReturnTransportDebtCents(1));
        $this->assertSame(1, DB::table('finance_audit_events')->where('event_type', 'SELLER_RETURN_TRANSPORT_ADJUSTED')->count());
    }

    public function test_admin_finance_page_renders_return_transport_review_without_a_server_error(): void
    {
        $this->fixture();
        DB::table('users')->insert(['id' => 5, 'first_name' => 'Finance', 'last_name' => 'Admin', 'email' => 'finance.admin@test.invalid', 'account_type' => 'ADMIN', 'status' => 'ACTIVE']);
        $this->withoutMiddleware(\Illuminate\Auth\Middleware\EnsureEmailIsVerified::class);

        $this->actingAs(User::query()->findOrFail(5))
            ->get(route('admin.finance'))
            ->assertOk()
            ->assertSee('Return transport losses')
            ->assertSee('No seller returns are waiting for a transport loss decision.');
    }

    /** @return array{User, User, User, User, ShipmentReturnWorkflow, Shipment} */
    private function fixture(): array
    {
        foreach ([
            1 => ['Center Operator', 'logistics'],
            2 => ['Delivery Rider', 'rider'],
            3 => ['Return Rider', 'rider'],
            4 => ['Seller User', 'seller'],
        ] as $id => [$name, $accountType]) {
            [$firstName, $lastName] = array_pad(explode(' ', $name, 2), 2, 'User');
            DB::table('users')->insert([
                'id' => $id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => strtolower(str_replace(' ', '.', $name)).'@test.invalid',
                'account_type' => strtoupper($accountType),
                'status' => 'ACTIVE',
            ]);
        }

        DB::table('logistics_centers')->insert(['id' => 1, 'owner_user_id' => 1, 'business_name' => 'Test Center']);
        DB::table('seller_profiles')->insert(['id' => 1, 'user_id' => 4, 'business_name' => 'Test Seller']);
        DB::table('seller_orders')->insert(['id' => 1, 'seller_profile_id' => 1, 'seller_order_number' => 'SO-1', 'shipping_fee' => '50.00']);
        $orderItemId = DB::table('order_items')->insertGetId(['seller_order_id' => 1, 'line_total' => '100.00', 'quantity' => 1]);
        DB::table('rider_profiles')->insert([
            ['id' => 1, 'user_id' => 2, 'logistics_center_id' => 1, 'status' => 'ACTIVE', 'vehicle_type' => 'motorcycle'],
            ['id' => 2, 'user_id' => 3, 'logistics_center_id' => 1, 'status' => 'ACTIVE', 'vehicle_type' => 'motorcycle'],
        ]);

        $shipment = Shipment::query()->create([
            'seller_order_id' => 1,
            'order_item_id' => $orderItemId,
            'tracking_number' => 'LK-RETURN-1',
            'logistics_center_id' => 1,
            'origin_logistics_center_id' => 1,
            'current_status' => 'DELIVERY_FAILED',
        ]);
        DB::table('rider_assignments')->insert([
            'id' => 1,
            'shipment_id' => $shipment->id,
            'rider_profile_id' => 1,
            'assignment_type' => 'DELIVERY',
            'status' => 'COMPLETED',
        ]);

        $centerUser = User::query()->findOrFail(1);
        $originalRider = User::query()->findOrFail(2);
        $returnRider = User::query()->findOrFail(3);
        $seller = User::query()->findOrFail(4);
        $workflow = app(ShipmentReturnWorkflowService::class)->initiateFromDeliveryFailure(
            $shipment,
            RiderAssignment::query()->findOrFail(1),
            $originalRider,
            'Recipient refused delivery',
        );

        return [$centerUser, $originalRider, $returnRider, $seller, $workflow, $shipment];
    }

    private function createSchema(): void
    {
        $schema = Schema::connection('sqlite');
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('middle_initial')->nullable();
            $table->string('last_name')->nullable();
            $table->string('name_extension')->nullable();
            $table->string('email')->unique();
            $table->string('account_type')->nullable();
            $table->string('status')->nullable();
        });
        $schema->create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type');
            $table->string('title');
            $table->text('message');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('action_url')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        $schema->create('logistics_centers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_user_id');
            $table->string('business_name')->nullable();
            $table->unsignedBigInteger('address_id')->nullable();
        });
        $schema->create('service_areas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('logistics_center_id');
        });
        $schema->create('addresses', function (Blueprint $table): void {
            $table->id();
            $table->string('province_code')->nullable();
            $table->string('municipality_code')->nullable();
        });
        $schema->create('seller_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('business_name')->nullable();
        });
        $schema->create('seller_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_profile_id');
            $table->string('seller_order_number');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->decimal('shipping_fee', 12, 2)->default(0);
            $table->decimal('voucher_discount', 12, 2)->default(0);
            $table->decimal('reward_points_discount', 12, 2)->default(0);
            $table->decimal('shipping_voucher_discount', 12, 2)->default(0);
            $table->decimal('protection_total', 12, 2)->nullable();
            $table->json('shipping_rate_snapshot')->nullable();
            $table->unsignedSmallInteger('funding_snapshot_version')->nullable();
        });
        $schema->create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_order_id');
            $table->decimal('line_total', 12, 2)->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('original_unit_price', 12, 2)->nullable();
            $table->unsignedSmallInteger('funding_snapshot_version')->nullable();
            $table->decimal('seller_voucher_discount', 12, 2)->default(0);
            $table->decimal('platform_voucher_discount', 12, 2)->default(0);
            $table->decimal('platform_shipping_voucher_discount', 12, 2)->default(0);
            $table->decimal('reward_points_discount', 12, 2)->default(0);
            $table->decimal('protection_fee', 12, 2)->default(0);
        });
        $schema->create('shipment_financial_allocations', fn (Blueprint $table) => $table->id());
        $schema->create('seller_settlements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_profile_id');
            $table->decimal('seller_payable', 12, 2)->nullable();
            $table->decimal('outstanding_balance', 12, 2)->nullable();
            $table->string('payout_status');
            $table->timestamps();
        });
        $schema->create('logistics_earning_pools', fn (Blueprint $table) => $table->id());
        $schema->create('rider_earning_pools', fn (Blueprint $table) => $table->id());
        $schema->create('rider_earning_pool_participants', fn (Blueprint $table) => $table->id());
        $schema->create('financial_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shipment_financial_allocation_id')->nullable();
            $table->unsignedBigInteger('shipment_id')->nullable();
            $table->unsignedBigInteger('seller_order_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('account_type', 32);
            $table->string('entry_type', 64);
            $table->string('direction', 16);
            $table->decimal('amount', 12, 2);
            $table->string('status', 32)->default('PENDING');
            $table->string('idempotency_key', 180)->unique();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        $schema->create('finance_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 64);
            $table->string('auditable_type', 150);
            $table->unsignedBigInteger('auditable_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        $schema->create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });
        $schema->create('commission_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_order_id')->nullable();
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->timestamps();
        });
        $schema->create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_order_id');
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->string('tracking_number');
            $table->unsignedBigInteger('logistics_center_id')->nullable();
            $table->unsignedBigInteger('service_area_id')->nullable();
            $table->unsignedBigInteger('destination_logistics_center_id')->nullable();
            $table->unsignedBigInteger('origin_logistics_center_id')->nullable();
            $table->string('origin_province_code')->nullable();
            $table->string('origin_municipality_code')->nullable();
            $table->string('origin_barangay_code')->nullable();
            $table->string('destination_province_code')->nullable();
            $table->string('destination_municipality_code')->nullable();
            $table->string('current_status');
            $table->timestamps();
        });
        $schema->create('rider_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('logistics_center_id');
            $table->string('status');
            $table->string('vehicle_type')->nullable();
        });
        $schema->create('rider_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shipment_id');
            $table->unsignedBigInteger('rider_profile_id');
            $table->string('assignment_type');
            $table->string('status');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamps();
        });
        $schema->create('rider_assignment_locations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rider_assignment_id');
        });
        $schema->create('delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shipment_id');
            $table->unsignedBigInteger('rider_assignment_id');
            $table->unsignedInteger('attempt_number');
            $table->string('status');
            $table->text('failure_reason');
            $table->string('proof_path')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
        });
        $schema->create('shipment_return_workflows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shipment_id')->unique();
            $table->unsignedBigInteger('logistics_center_id');
            $table->unsignedBigInteger('original_delivery_assignment_id');
            $table->unsignedBigInteger('return_rider_profile_id')->nullable();
            $table->string('status');
            $table->text('reason');
            $table->unsignedBigInteger('initiated_by_user_id')->nullable();
            $table->timestamp('initiated_at');
            $table->unsignedBigInteger('center_handoff_by_user_id')->nullable();
            $table->timestamp('center_handoff_at')->nullable();
            $table->unsignedBigInteger('center_received_by_user_id')->nullable();
            $table->timestamp('center_received_at')->nullable();
            $table->unsignedBigInteger('return_rider_assigned_by_user_id')->nullable();
            $table->timestamp('return_rider_assigned_at')->nullable();
            $table->timestamp('return_rider_accepted_at')->nullable();
            $table->unsignedBigInteger('return_rider_picked_up_by_user_id')->nullable();
            $table->timestamp('return_rider_picked_up_at')->nullable();
            $table->unsignedBigInteger('seller_handoff_by_user_id')->nullable();
            $table->timestamp('seller_handoff_at')->nullable();
            $table->unsignedBigInteger('seller_received_by_user_id')->nullable();
            $table->timestamp('seller_received_at')->nullable();
            $table->text('seller_confirmation_notes')->nullable();
            $table->string('return_disposition')->default('SELLER_RETURN');
            $table->unsignedBigInteger('pending_return_rider_profile_id')->nullable();
            $table->unsignedBigInteger('handoff_authorized_by_user_id')->nullable();
            $table->timestamp('handoff_authorized_at')->nullable();
            $table->text('handoff_reason')->nullable();
            $table->timestamp('handoff_from_confirmed_at')->nullable();
            $table->timestamp('handoff_to_confirmed_at')->nullable();
            $table->timestamp('handoff_completed_at')->nullable();
            $table->text('return_exception_reason')->nullable();
            $table->unsignedBigInteger('return_exception_recorded_by_user_id')->nullable();
            $table->timestamp('return_exception_recorded_at')->nullable();
            $table->timestamps();
        });
        $schema->create('parcel_scans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shipment_id');
            $table->unsignedBigInteger('scanned_by_user_id')->nullable();
            $table->unsignedBigInteger('logistics_center_id')->nullable();
            $table->unsignedBigInteger('rider_assignment_id')->nullable();
            $table->unsignedBigInteger('transfer_leg_id')->nullable();
            $table->string('scan_type');
            $table->string('scan_method');
            $table->string('scanned_code');
            $table->string('result');
            $table->text('notes')->nullable();
            $table->timestamp('scanned_at');
            $table->timestamps();
        });
        $schema->create('shipment_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shipment_id');
            $table->string('status');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('logistics_center_id')->nullable();
            $table->unsignedBigInteger('rider_assignment_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
        $schema->create('shipment_transfer_legs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shipment_id');
            $table->unsignedInteger('sequence_no');
            $table->unsignedBigInteger('origin_logistics_center_id');
            $table->unsignedBigInteger('destination_logistics_center_id');
            $table->string('origin_province_code');
            $table->string('origin_municipality_code');
            $table->string('destination_province_code');
            $table->string('destination_municipality_code');
            $table->string('status');
            $table->unsignedBigInteger('released_by_user_id')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('received_by_user_id')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });
        $schema->create('transfer_manifests', fn (Blueprint $table) => $table->id());
        $schema->create('transfer_manifest_items', fn (Blueprint $table) => $table->id());
        $schema->create('return_custody_events', fn (Blueprint $table) => $table->id());
    }
}
