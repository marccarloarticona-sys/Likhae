<?php

namespace Database\Seeders;

use App\Models\Buyer\Address;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Rider\RiderAreaAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductImage;
use App\Models\Seller\ProductOption;
use App\Models\Seller\ProductOptionValue;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\ProductVariantOptionValue;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = $this->user('ADMIN', 'Admin', 'User', 'admin@likhae.com');
        $buyer = $this->user('BUYER', 'Buyer', 'User', 'buyer@likhae.com');
        $sellerUser = $this->user('SELLER', 'Seller', 'User', 'seller@likhae.com');
        $logisticsUser = $this->user(
            'LOGISTICS',
            'JNT',
            'Logistics',
            'jnt@likhae.online',
            '09170000001',
            '1990-01-01',
            true,
        );
        $riderUser = $this->user(
            'RIDER',
            'Carlo',
            'Martinez',
            'carlomartinez@likhae.online',
            '09170000002',
            '2001-01-01',
            true,
        );

        $this->address($admin, 'Home');
        $buyerAddress = $this->address($buyer, 'Home');
        $sellerAddress = $this->address($sellerUser, 'Business');
        $centerAddress = $this->address($logisticsUser, 'Center', [
            'province_code' => 'LAG',
            'province_name' => 'Laguna',
            'municipality_code' => 'PILA',
            'municipality_name' => 'Pila',
            'barangay_code' => 'BAGONG-POOK',
            'barangay_name' => 'Bagong Pook',
            'postal_code' => '4010',
            'house_number' => '803',
            'street_address' => 'Purok 3',
            'landmark' => 'Region IV-A (CALABARZON)',
        ]);
        $this->address($riderUser, 'Home', [
            'province_code' => 'LAG',
            'province_name' => 'Laguna',
            'municipality_code' => 'PILA',
            'municipality_name' => 'Pila',
            'barangay_code' => 'SAN-MIGUEL',
            'barangay_name' => 'San Miguel',
            'postal_code' => '4010',
            'house_number' => null,
            'street_address' => 'Barangay San Miguel',
            'landmark' => 'Region IV-A (CALABARZON)',
        ]);

        $this->call(CatalogCategorySeeder::class);

        $category = Category::firstOrCreate(
            ['slug' => 'handmade-goods'],
            ['name' => 'Handmade Goods', 'description' => 'Local products', 'is_active' => true],
        );

        $seller = SellerProfile::updateOrCreate(
            ['user_id' => $sellerUser->id],
            [
                'primary_category_id' => $category->id,
                'business_address_id' => $sellerAddress->id,
                'business_name' => 'Likhae Sample Shop',
                'business_registration_number' => 'SELLER-'.date('Ymd'),
                'status' => 'ACTIVE',
                'approved_by_user_id' => $admin->id,
                'approved_at' => now(),
            ],
        );

        $this->call(BackstreetSellerSeeder::class);
        $this->call(KizaniaDemoSellerSeeder::class);
        $this->call(TestSellerCatalogSeeder::class);

        $center = LogisticsCenter::updateOrCreate(
            ['owner_user_id' => $logisticsUser->id],
            [
                'address_id' => $centerAddress->id,
                'code' => 'JNT-LAGUNA-001',
                'business_name' => 'JNT',
                'business_registration_number' => 'JNT-LAGUNA-SEED',
                'dti_registration_number' => 'JNT-DTI-LAGUNA-SEED',
                'status' => 'ACTIVE',
                'approved_by_user_id' => $admin->id,
                'approved_at' => now(),
            ],
        );

        $rider = RiderProfile::updateOrCreate(
            ['user_id' => $riderUser->id],
            [
                'logistics_center_id' => $center->id,
                'vehicle_type' => 'Motorcycle',
                'plate_number' => 'JNT-CARLO-01',
                'drivers_license_number' => 'DL-CARLO-MARTINEZ-01',
                'status' => 'ACTIVE',
                'approved_by_user_id' => $logisticsUser->id,
                'approved_at' => now(),
            ],
        );

        $area = ServiceArea::updateOrCreate(
            ['logistics_center_id' => $center->id, 'code' => 'PILA-SAN-MIGUEL'],
            ['name' => 'Barangay San Miguel - Pila Area', 'is_active' => true],
        );

        ServiceAreaLocation::updateOrCreate(
            ['service_area_id' => $area->id, 'barangay_code' => 'SAN-MIGUEL'],
            [
                'province_code' => 'LAG',
                'province_name' => 'Laguna',
                'municipality_code' => 'PILA',
                'municipality_name' => 'Pila',
                'barangay_name' => 'San Miguel',
            ],
        );

        RiderAreaAssignment::updateOrCreate(
            ['rider_profile_id' => $rider->id, 'service_area_id' => $area->id, 'is_active' => true],
            ['assigned_by_user_id' => $logisticsUser->id, 'assigned_at' => now()],
        );

        $this->sampleProduct($seller, $category);
    }

    private function user(
        string $type,
        string $first,
        string $last,
        string $email,
        ?string $contactNumber = null,
        ?string $birthday = null,
        bool $resetPassword = false,
    ): User
    {
        $user = User::firstOrNew(['email' => $email]);
        $user->fill([
            'account_type' => $type,
            'first_name' => $first,
            'middle_initial' => null,
            'last_name' => $last,
            'sex' => $user->sex ?: 'PREFER_NOT_TO_SAY',
            'contact_number' => $contactNumber ?: ($user->contact_number ?: '09'.random_int(100000000, 999999999)),
            'birthday' => $birthday ?: ($user->birthday ?: '2000-01-01'),
            'email_verified_at' => $user->email_verified_at ?: now(),
            'status' => 'ACTIVE',
        ]);

        if ($resetPassword || ! $user->exists || blank($user->password)) {
            $user->password = Hash::make('Password1');
        }

        $user->save();

        return $user;
    }

    private function address(User $user, string $label, array $overrides = []): Address
    {
        return Address::updateOrCreate(
            ['user_id' => $user->id, 'label' => $label],
            array_merge([
                'recipient_name' => $user->name,
                'contact_number' => $user->contact_number,
                'province_code' => 'LAG',
                'province_name' => 'Laguna',
                'municipality_code' => 'PILA',
                'municipality_name' => 'Pila',
                'barangay_code' => 'MASICO',
                'barangay_name' => 'Masico',
                'postal_code' => '4010',
                'house_number' => '1',
                'street_address' => 'Sample Street',
                'landmark' => 'Near Barangay Hall',
                'is_default' => true,
            ], $overrides),
        );
    }

    private function sampleProduct(SellerProfile $seller, Category $category): void
    {
        $product = Product::updateOrCreate(
            ['slug' => 'sample-handmade-tote'],
            [
                'seller_profile_id' => $seller->id,
                'category_id' => $category->id,
                'name' => 'Sample Handmade Tote',
                'description' => 'A sample product with dynamic options.',
                'status' => 'ACTIVE',
                'published_at' => now(),
            ],
        );

        ProductImage::firstOrCreate(['product_id' => $product->id, 'file_path' => 'products/sample-tote.jpg'], ['is_primary' => true, 'sort_order' => 1]);

        $color = ProductOption::firstOrCreate(['product_id' => $product->id, 'name' => 'Color'], ['sort_order' => 1]);
        $size = ProductOption::firstOrCreate(['product_id' => $product->id, 'name' => 'Size'], ['sort_order' => 2]);
        $black = ProductOptionValue::firstOrCreate(['product_option_id' => $color->id, 'value' => 'Black'], ['sort_order' => 1]);
        $large = ProductOptionValue::firstOrCreate(['product_option_id' => $size->id, 'value' => 'Large'], ['sort_order' => 1]);

        $variant = ProductVariant::updateOrCreate(
            ['product_id' => $product->id, 'sku' => 'TOTE-BLK-L'],
            ['price' => 350.00, 'stock' => 20, 'is_default' => true, 'is_active' => true],
        );

        ProductVariantOptionValue::firstOrCreate(['product_variant_id' => $variant->id, 'product_option_value_id' => $black->id]);
        ProductVariantOptionValue::firstOrCreate(['product_variant_id' => $variant->id, 'product_option_value_id' => $large->id]);
    }
}
