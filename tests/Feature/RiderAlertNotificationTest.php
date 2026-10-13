<?php

namespace Tests\Feature;

use App\Mail\AccountAlert;
use App\Models\Admin\Notification;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Services\AccountNotificationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RiderAlertNotificationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_moderation_alert_is_saved_and_emailed_even_after_account_suspension(): void
    {
        Mail::fake();

        $centerOwner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerOwner->id,
            'code' => 'ALERT-CENTER-'.uniqid(),
            'business_name' => 'Alert Test Center',
            'status' => 'ACTIVE',
        ]);
        $riderUser = User::factory()->create([
            'account_type' => User::TYPE_RIDER,
            'status' => User::STATUS_SUSPENDED,
        ]);
        $rider = RiderProfile::create([
            'user_id' => $riderUser->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'ALERT-PLATE-'.uniqid(),
            'drivers_license_number' => 'ALERT-LICENSE-'.uniqid(),
            'status' => 'SUSPENDED',
        ]);

        app(AccountNotificationService::class)->riderModerationChanged($rider, 'SUSPENDED', 'Repeated missed pickups.');

        $notification = Notification::query()->where('user_id', $riderUser->id)->firstOrFail();
        $this->assertSame('RIDER_MODERATION_SUSPENDED', $notification->type);
        $this->assertStringContainsString('Repeated missed pickups.', $notification->message);
        Mail::assertSent(AccountAlert::class, fn (AccountAlert $mail): bool => $mail->hasTo($riderUser->email));
    }

    public function test_new_rider_assignment_creates_an_individual_notification_and_email(): void
    {
        Mail::fake();

        $centerOwner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerOwner->id,
            'code' => 'ASSIGNMENT-ALERT-'.uniqid(),
            'business_name' => 'Assignment Alert Center',
            'status' => 'ACTIVE',
        ]);
        $riderUser = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        $rider = RiderProfile::create([
            'user_id' => $riderUser->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'ASSIGN-PLATE-'.uniqid(),
            'drivers_license_number' => 'ASSIGN-LICENSE-'.uniqid(),
            'status' => 'ACTIVE',
        ]);

        $assignment = new RiderAssignment(['assignment_type' => 'PICKUP']);
        $assignment->setAttribute('id', 9876);
        $assignment->setRelation('riderProfile', $rider->setRelation('user', $riderUser));
        $assignment->setRelation('shipment', new Shipment(['tracking_number' => 'TRACK-ALERT-9876']));

        app(AccountNotificationService::class)->riderAssignmentCreated($assignment);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $riderUser->id,
            'type' => 'RIDER_ASSIGNMENT',
            'reference_id' => 9876,
        ]);
        Mail::assertSent(AccountAlert::class, fn (AccountAlert $mail): bool => $mail->hasTo($riderUser->email));
    }

    public function test_unverified_rider_keeps_in_app_alert_but_does_not_receive_email(): void
    {
        Mail::fake();

        $centerOwner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerOwner->id,
            'code' => 'UNVERIFIED-ALERT-'.uniqid(),
            'business_name' => 'Unverified Alert Center',
            'status' => 'ACTIVE',
        ]);
        $riderUser = User::factory()->create([
            'account_type' => User::TYPE_RIDER,
            'email_verified_at' => null,
        ]);
        $rider = RiderProfile::create([
            'user_id' => $riderUser->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'UNVERIFIED-PLATE-'.uniqid(),
            'drivers_license_number' => 'UNVERIFIED-LICENSE-'.uniqid(),
            'status' => 'ACTIVE',
        ]);

        app(AccountNotificationService::class)->riderModerationChanged($rider, 'WARNED', 'Please follow pickup procedures.');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $riderUser->id,
            'type' => 'RIDER_MODERATION_WARNED',
        ]);
        Mail::assertNothingSent();
    }
}
