<?php

namespace Tests\Feature;

use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Logistics\Shipment;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Fulfillment\ShipmentWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class LegacyShipmentCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_ambiguous_legacy_shipment_is_preserved_and_seller_order_page_renders(): void
    {
        $this->withoutVite();
        [$seller, $sellerOrder, $shipment] = $this->legacyOrderFixture();

        try {
            app(ShipmentWorkflowService::class)->ensureItemShipments($sellerOrder, $seller);
            $this->fail('An ambiguous legacy shipment must not be assigned to an order item.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no safe item mapping', $exception->getMessage());
        }

        $this->assertSame(1, Shipment::query()->where('seller_order_id', $sellerOrder->id)->count());
        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'order_item_id' => null,
            'tracking_number' => 'LEGACY-TRACKING-001',
            'current_status' => 'PLACED',
        ]);

        $this->actingAs($seller)
            ->get(route('seller.orders', ['mode' => 'show', 'order' => $sellerOrder->seller_order_number]))
            ->assertOk()
            ->assertSee('Legacy shipment preserved')
            ->assertSee('LEGACY-TRACKING-001')
            ->assertSee('item mapping is unresolved')
            ->assertDontSee('Print this parcel waybill');

        $this->actingAs($seller)
            ->get(route('seller.orders.waybill', $sellerOrder))
            ->assertOk()
            ->assertSee('LEGACY-TRACKING-001')
            ->assertSee('Legacy parcel contents: Legacy Item A, Legacy Item B. This tracking number covers the legacy parcel.');
    }

    private function legacyOrderFixture(): array
    {
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $seller = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $category = Category::create([
            'name' => 'Legacy Parcel Test',
            'slug' => 'legacy-parcel-test-'.uniqid(),
            'is_active' => true,
        ]);
        $sellerProfile = SellerProfile::create([
            'user_id' => $seller->id,
            'primary_category_id' => $category->id,
            'business_name' => 'Legacy Parcel Seller',
            'status' => 'ACTIVE',
        ]);
        $order = Order::create([
            'order_number' => 'LEGACY-ORDER-'.uniqid(),
            'buyer_user_id' => $buyer->id,
            'status' => 'PLACED',
            'grand_total' => 30,
        ]);
        $sellerOrder = SellerOrder::create([
            'seller_order_number' => 'LEGACY-SELLER-'.uniqid(),
            'order_id' => $order->id,
            'seller_profile_id' => $sellerProfile->id,
            'status' => 'PLACED',
            'grand_total' => 30,
        ]);
        foreach ([['Legacy Item A', 'LEGACY-A'], ['Legacy Item B', 'LEGACY-B']] as [$name, $sku]) {
            OrderItem::create([
                'seller_order_id' => $sellerOrder->id,
                'product_name' => $name,
                'sku' => $sku,
                'variant_description' => 'Standard',
                'unit_price' => 10,
                'quantity' => 1,
                'discount_amount' => 0,
                'line_total' => 10,
            ]);
        }
        $shipment = Shipment::create([
            'seller_order_id' => $sellerOrder->id,
            'tracking_number' => 'LEGACY-TRACKING-001',
            'destination_province_code' => 'P-TEST',
            'destination_province_name' => 'Test Province',
            'destination_municipality_code' => 'M-TEST',
            'destination_municipality_name' => 'Test Municipality',
            'destination_barangay_code' => 'B-TEST',
            'destination_barangay_name' => 'Test Barangay',
            'current_status' => 'PLACED',
        ]);

        return [$seller, $sellerOrder, $shipment];
    }
}
