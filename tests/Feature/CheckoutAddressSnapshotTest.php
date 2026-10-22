<?php

namespace Tests\Feature;

use App\Models\Buyer\Address;
use App\Models\Buyer\Cart;
use App\Models\Buyer\CartItem;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Marketplace\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CheckoutAddressSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_keeps_the_selected_address_and_coordinates_after_profile_edit(): void
    {
        config(['services.psgc.local_first' => true]);
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $centerUser = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $admin = User::factory()->create(['account_type' => User::TYPE_ADMIN]);

        $centerAddress = Address::create([
            'user_id' => $centerUser->id,
            'label' => 'Center',
            'recipient_name' => 'Snapshot Center',
            'contact_number' => $centerUser->contact_number,
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403424000',
            'municipality_name' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay_name' => 'Bagong Bayan II-A',
            'street_address' => 'Center Street',
            'latitude' => '14.0695000',
            'longitude' => '121.3255000',
        ]);

        $center = LogisticsCenter::create([
            'owner_user_id' => $centerUser->id,
            'address_id' => $centerAddress->id,
            'code' => 'SNAPSHOT-CENTER',
            'business_name' => 'Snapshot Center',
            'status' => 'ACTIVE',
            'approved_by_user_id' => $admin->id,
            'approved_at' => now(),
        ]);
        $area = ServiceArea::create([
            'logistics_center_id' => $center->id,
            'code' => 'SNAPSHOT-AREA',
            'name' => 'Snapshot Area',
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

        $category = Category::create([
            'name' => 'Snapshot Category',
            'slug' => 'snapshot-category',
            'is_active' => true,
        ]);
        $sellerAddress = Address::create([
            'user_id' => $sellerUser->id,
            'label' => 'Business',
            'recipient_name' => 'Snapshot Seller',
            'contact_number' => $sellerUser->contact_number,
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403424000',
            'municipality_name' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay_name' => 'Bagong Bayan II-A',
            'street_address' => 'Seller Pickup Street',
            'latitude' => '14.0696000',
            'longitude' => '121.3256000',
        ]);
        $seller = SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_address_id' => $sellerAddress->id,
            'business_name' => 'Snapshot Seller',
            'status' => 'ACTIVE',
        ]);
        $product = Product::create([
            'seller_profile_id' => $seller->id,
            'category_id' => $category->id,
            'name' => 'Snapshot Product',
            'slug' => 'snapshot-product',
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SNAPSHOT-SKU',
            'price' => 100,
            'stock' => 5,
            'is_default' => true,
            'is_active' => true,
        ]);
        $cart = Cart::create(['buyer_user_id' => $buyer->id, 'status' => Cart::STATUS_ACTIVE]);
        $item = CartItem::create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);
        $address = Address::create([
            'user_id' => $buyer->id,
            'label' => 'Home',
            'recipient_name' => $buyer->name,
            'contact_number' => $buyer->contact_number,
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403424000',
            'municipality_name' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay_name' => 'Bagong Bayan II-A',
            'street_address' => 'Original Street',
            'latitude' => '14.5995000',
            'longitude' => '120.9842000',
            'is_default' => true,
        ]);

        $checkout = app(CheckoutService::class);
        try {
            $checkout->placeOrder($buyer, $address, collect([$item]), 'ONLINE');
            $this->fail('Online orders must remain unavailable until payment processing is implemented.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_method', $exception->errors());
        }
        $this->assertDatabaseCount('orders', 0);

        $order = $checkout->placeOrder(
            $buyer,
            $address,
            collect([$item]),
            'COD',
            [],
            null,
        );

        $this->assertSame('PENDING', $order->fresh()->payment_status);
        $this->assertSame('COD', $order->payments()->sole()->method);
        $this->assertSame('PENDING', $order->payments()->sole()->status);
        $this->assertSame('50.00', (string) $order->sellerOrders()->sole()->shipping_fee);
        $this->assertSame(4, (int) $variant->fresh()->stock);

        $address->update([
            'street_address' => 'Updated Street',
            'latitude' => '14.7000000',
            'longitude' => '121.1000000',
        ]);

        $this->assertDatabaseHas('order_addresses', [
            'order_id' => $order->id,
            'street_address' => 'Original Street',
            'latitude' => '14.5995000',
            'longitude' => '120.9842000',
        ]);
        $this->assertDatabaseMissing('order_addresses', [
            'order_id' => $order->id,
            'street_address' => 'Updated Street',
        ]);
    }
}
