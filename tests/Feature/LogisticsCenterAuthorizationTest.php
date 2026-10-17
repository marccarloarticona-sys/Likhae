<?php

namespace Tests\Feature;

use App\Models\Buyer\Order;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\PickupRequest;
use App\Models\Logistics\Shipment;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogisticsCenterAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_center_cannot_view_or_review_another_centers_shipment(): void
    {
        [$user, $center] = $this->center('AUTH-CENTER-A');
        [, $otherCenter] = $this->center('AUTH-CENTER-B');
        $pickup = $this->pickupForCenter($otherCenter);

        $this->actingAs($user)
            ->get(route('logistics.parcels.tracking', ['tracking' => $pickup->shipment->tracking_number]))
            ->assertForbidden();

        $this->post(route('logistics.pickups.approve', $pickup))
            ->assertForbidden();

        $this->assertDatabaseHas('pickup_requests', ['id' => $pickup->id, 'status' => 'PENDING']);
    }

    public function test_logistics_report_download_is_pdf_and_scoped_to_the_authenticated_center(): void
    {
        [$user, $center] = $this->approvedCenter('PDF-CENTER-A');
        [, $otherCenter] = $this->approvedCenter('PDF-CENTER-B');
        $ownPickup = $this->pickupForCenter($center);
        $foreignPickup = $this->pickupForCenter($otherCenter);

        $response = $this->actingAs($user)->get(route('logistics.reports.export'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload();
        $pdf = $response->getContent();

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString($ownPickup->shipment->tracking_number, $pdf);
        $this->assertStringNotContainsString($foreignPickup->shipment->tracking_number, $pdf);
    }

    private function center(string $code): array
    {
        $user = User::factory()->create([
            'account_type' => User::TYPE_LOGISTICS,
            'status' => User::STATUS_ACTIVE,
        ]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $user->id,
            'code' => $code,
            'business_name' => $code.' Sorting Center',
            'status' => 'ACTIVE',
        ]);

        return [$user, $center];
    }

    private function approvedCenter(string $code): array
    {
        $user = User::factory()->create([
            'account_type' => User::TYPE_LOGISTICS,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $admin = User::factory()->create([
            'account_type' => User::TYPE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $user->id,
            'code' => $code,
            'business_name' => $code.' Sorting Center',
            'status' => 'ACTIVE',
            'approved_by_user_id' => $admin->id,
            'approved_at' => now(),
        ]);

        return [$user, $center];
    }

    private function pickupForCenter(LogisticsCenter $center): PickupRequest
    {
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $category = Category::create(['name' => 'Center test', 'slug' => 'center-test-'.uniqid(), 'is_active' => true]);
        $seller = SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_name' => 'Center test seller',
            'status' => 'ACTIVE',
        ]);
        $order = Order::create([
            'order_number' => 'CENTER-ORDER-'.uniqid(),
            'buyer_user_id' => $buyer->id,
            'status' => 'PLACED',
        ]);
        $sellerOrder = SellerOrder::create([
            'seller_order_number' => 'CENTER-SELLER-ORDER-'.uniqid(),
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'status' => 'READY_FOR_PICKUP',
        ]);
        $shipment = Shipment::create([
            'seller_order_id' => $sellerOrder->id,
            'tracking_number' => 'CENTER-TRACK-'.uniqid(),
            'logistics_center_id' => $center->id,
            'destination_province_code' => 'P1',
            'destination_province_name' => 'Province',
            'destination_municipality_code' => 'M1',
            'destination_municipality_name' => 'Municipality',
            'destination_barangay_code' => 'B1',
            'destination_barangay_name' => 'Barangay',
            'current_status' => 'READY_FOR_PICKUP',
        ]);

        return PickupRequest::create([
            'shipment_id' => $shipment->id,
            'requested_by_user_id' => $sellerUser->id,
            'status' => 'PENDING',
        ]);
    }
}
