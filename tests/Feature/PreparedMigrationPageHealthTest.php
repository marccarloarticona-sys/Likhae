<?php

namespace Tests\Feature;

use App\Models\Buyer\Address;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\Category;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreparedMigrationPageHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_buyer_seller_and_admin_pages_render_on_the_prepared_schema(): void
    {
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $this->actingAs($buyer);
        foreach ([
            'buyer.rewards',
            'buyer.cart',
            'buyer.checkout',
            'buyer.orders',
        ] as $routeName) {
            $response = $this->get(route($routeName));
            $this->assertContains($response->getStatusCode(), [200, 302], $routeName.' must render or redirect to the buyer flow.');
        }

        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $category = Category::query()->create([
            'name' => 'Page Health',
            'slug' => 'page-health-'.str()->uuid(),
            'is_active' => true,
        ]);
        SellerProfile::query()->create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_name' => 'Page Health Seller',
            'status' => 'ACTIVE',
        ]);
        $this->actingAs($sellerUser);
        foreach (['seller.orders', 'seller.logistics', 'seller.marketing'] as $routeName) {
            $this->get(route($routeName))->assertSuccessful();
        }

        $admin = User::factory()->create(['account_type' => User::TYPE_ADMIN]);
        $this->actingAs($admin);
        foreach ([
            route('admin.marketing'),
            route('admin.marketing', ['section' => 'announcements']),
            route('admin.messages'),
        ] as $url) {
            $this->get($url)->assertSuccessful();
        }
    }

    public function test_logistics_and_rider_operations_pages_render_on_the_prepared_schema(): void
    {
        $admin = User::factory()->create(['account_type' => User::TYPE_ADMIN]);
        $centerOwner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $centerAddress = $this->address($centerOwner, 'Logistics Center');
        $center = LogisticsCenter::query()->create([
            'owner_user_id' => $centerOwner->id,
            'address_id' => $centerAddress->id,
            'code' => 'PAGE-HEALTH-'.str()->upper(str()->random(12)),
            'business_name' => 'Page Health Center',
            'status' => 'ACTIVE',
            'approved_by_user_id' => $admin->id,
            'approved_at' => now(),
        ]);

        $this->actingAs($centerOwner);
        foreach ([
            'logistics.pickups',
            'logistics.parcels',
            'logistics.parcels.receive',
            'logistics.parcels.tracking',
            'logistics.sorting',
            'logistics.assignments',
            'logistics.transfers',
            'logistics.local-trips',
        ] as $routeName) {
            $this->get(route($routeName))->assertSuccessful();
        }

        $localRider = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        RiderProfile::query()->create([
            'user_id' => $localRider->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'LOCAL-'.str()->upper(str()->random(12)),
            'drivers_license_number' => 'LOCAL-LICENSE-'.str()->upper(str()->random(12)),
            'status' => 'ACTIVE',
        ]);
        $this->actingAs($localRider);
        foreach (['rider.pickups', 'rider.deliveries', 'rider.parcels.index', 'rider.trips', 'rider.history'] as $routeName) {
            $this->get(route($routeName))->assertSuccessful();
        }

        $hubRider = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        RiderProfile::query()->create([
            'user_id' => $hubRider->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'van',
            'plate_number' => 'HUB-'.str()->upper(str()->random(12)),
            'drivers_license_number' => 'HUB-LICENSE-'.str()->upper(str()->random(12)),
            'status' => 'ACTIVE',
        ]);
        $this->actingAs($hubRider)->get(route('rider.transfers'))->assertSuccessful();
    }

    private function address(User $user, string $label): Address
    {
        return Address::query()->create([
            'user_id' => $user->id,
            'label' => $label,
            'recipient_name' => $user->name,
            'contact_number' => $user->contact_number,
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403422000',
            'municipality_name' => 'Pila',
            'barangay_code' => '0403422015',
            'barangay_name' => 'San Miguel',
            'street_address' => $label.' Street',
        ]);
    }
}
