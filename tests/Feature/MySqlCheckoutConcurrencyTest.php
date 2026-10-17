<?php

namespace Tests\Feature;

use App\Models\Buyer\Address;
use App\Models\Buyer\Cart;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Buyer\Payment;
use App\Models\Finance\CodCollection;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Logistics\Shipment;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Services\Marketplace\CheckoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MySqlCheckoutConcurrencyTest extends TestCase
{
    private const PILA = [
        'province_code' => '0403400000',
        'province_name' => 'Laguna',
        'municipality_code' => '0403422000',
        'municipality_name' => 'Pila',
        'barangay_code' => '0403422015',
        'barangay_name' => 'San Miguel',
    ];

    public function test_mysql_checkout_locks_stock_and_resolves_concurrent_idempotent_replays(): void
    {
        $this->assertDisposableMySql();

        [$seller, $category, $center] = $this->sellerRoutingFixture('stock-race');
        $scarceVariant = $this->variant($seller, $category, 'stock-race', 1);
        [$buyerA, $addressA, $itemA] = $this->buyerCart($scarceVariant, 'stock-race-a');
        [$buyerB, $addressB, $itemB] = $this->buyerCart($scarceVariant, 'stock-race-b');
        $raceResults = $this->runWorkers([
            ['buyer' => $buyerA, 'address' => $addressA, 'item' => $itemA, 'key' => (string) str()->uuid()],
            ['buyer' => $buyerB, 'address' => $addressB, 'item' => $itemB, 'key' => (string) str()->uuid()],
        ]);

        $this->assertSame(1, collect($raceResults)->where('status', 'success')->count(), json_encode($raceResults));
        $this->assertSame(1, collect($raceResults)->where('status', 'business_failure')->count(), json_encode($raceResults));
        $raceBuyerIds = [$buyerA->id, $buyerB->id];
        $raceOrders = Order::query()->whereIn('buyer_user_id', $raceBuyerIds)->get();
        $this->assertCount(1, $raceOrders);
        $this->assertSame(1, SellerOrder::query()->whereIn('order_id', $raceOrders->pluck('id'))->count());
        $this->assertSame(1, OrderItem::query()->whereHas('sellerOrder', fn ($query) => $query->whereIn('order_id', $raceOrders->pluck('id')))->count());
        $this->assertSame(1, Shipment::query()->whereHas('sellerOrder', fn ($query) => $query->whereIn('order_id', $raceOrders->pluck('id')))->count());
        $this->assertSame(1, Payment::query()->whereIn('order_id', $raceOrders->pluck('id'))->count());
        $this->assertSame(1, CodCollection::query()->whereIn('order_id', $raceOrders->pluck('id'))->count());
        $this->assertSame(0, (int) $scarceVariant->fresh()->stock);
        $this->assertGreaterThanOrEqual(0, (int) $scarceVariant->fresh()->stock);
        $this->assertSame(1, DB::table('checkout_attempts')->whereIn('buyer_user_id', $raceBuyerIds)->count());

        $sameKey = (string) str()->uuid();
        $idempotentVariant = $this->variant($seller, $category, 'idempotency', 1);
        [$sameBuyer, $sameAddress, $sameItem] = $this->buyerCart($idempotentVariant, 'idempotency-owner');
        $sameKeyResults = $this->runWorkers([
            ['buyer' => $sameBuyer, 'address' => $sameAddress, 'item' => $sameItem, 'key' => $sameKey],
            ['buyer' => $sameBuyer, 'address' => $sameAddress, 'item' => $sameItem, 'key' => $sameKey],
        ]);
        $this->assertSame(['success', 'success'], collect($sameKeyResults)->pluck('status')->sort()->values()->all(), json_encode($sameKeyResults));
        $sameKeyOrderIds = collect($sameKeyResults)->pluck('order_id')->unique();
        $this->assertCount(1, $sameKeyOrderIds);
        $sameKeyOrderId = (int) $sameKeyOrderIds->first();
        $this->assertSame(1, Order::query()->whereKey($sameKeyOrderId)->count());
        $this->assertSame(1, SellerOrder::query()->where('order_id', $sameKeyOrderId)->count());
        $this->assertSame(1, OrderItem::query()->whereHas('sellerOrder', fn ($query) => $query->where('order_id', $sameKeyOrderId))->count());
        $this->assertSame(1, Shipment::query()->whereHas('sellerOrder', fn ($query) => $query->where('order_id', $sameKeyOrderId))->count());
        $this->assertSame(1, Payment::query()->where('order_id', $sameKeyOrderId)->count());
        $this->assertSame(1, CodCollection::query()->where('order_id', $sameKeyOrderId)->count());
        $this->assertSame(1, DB::table('checkout_attempts')->where('key_hash', hash('sha256', strtolower($sameKey)))->count());
        $this->assertSame(0, (int) $idempotentVariant->fresh()->stock);

        [$otherBuyer, $otherAddress, $otherItem] = $this->buyerCart($idempotentVariant, 'idempotency-other-buyer');
        try {
            app(CheckoutService::class)->placeOrder($otherBuyer, $otherAddress, [$otherItem->id], 'COD', [], null, [], [], $sameKey);
            $this->fail('A different buyer must not be able to resolve an existing checkout key.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('belongs to another buyer', strtolower((string) $exception->errors()['checkout_attempt_key'][0]));
        }
        $this->assertSame(0, Order::query()->where('buyer_user_id', $otherBuyer->id)->count());
        $this->assertSame(1, DB::table('checkout_attempts')->where('key_hash', hash('sha256', strtolower($sameKey)))->count());
        $this->assertSame(0, (int) $idempotentVariant->fresh()->stock);

        $multiVariantA = $this->variant($seller, $category, 'multi-line-a', 5, '120.00');
        $multiVariantB = $this->variant($seller, $category, 'multi-line-b', 5, '80.00');
        [$multiBuyer, $multiAddress, $multiItemA] = $this->buyerCart($multiVariantA, 'multi-line', 2);
        $multiItemB = CartItem::query()->create([
            'cart_id' => $multiItemA->cart_id,
            'product_variant_id' => $multiVariantB->id,
            'quantity' => 2,
        ]);
        $multiOrder = app(CheckoutService::class)->placeOrder(
            $multiBuyer,
            $multiAddress,
            [$multiItemA->id, $multiItemB->id],
            'COD',
            [],
            null,
            [],
            [],
            (string) str()->uuid(),
        );
        $multiSellerOrders = $multiOrder->sellerOrders()->with(['items.shipment'])->get();
        $this->assertCount(1, $multiSellerOrders);
        $this->assertCount(2, $multiSellerOrders->first()->items);
        $multiShipments = $multiSellerOrders->first()->shipments()->with('orderItem')->get();
        $this->assertCount(2, $multiShipments);
        $this->assertSame(
            $multiSellerOrders->first()->items->pluck('id')->sort()->values()->all(),
            $multiShipments->pluck('order_item_id')->sort()->values()->all(),
        );
        $this->assertCount(2, $multiShipments->pluck('tracking_number')->unique());
        $itemA = $multiSellerOrders->first()->items->firstWhere('sku', $multiVariantA->sku);
        $itemB = $multiSellerOrders->first()->items->firstWhere('sku', $multiVariantB->sku);
        $sellerUser = $seller->user;
        $this->actingAs($sellerUser)
            ->get(route('seller.orders.waybill.item', [$multiSellerOrders->first(), $itemA]))
            ->assertOk()
            ->assertSee('Checkout multi-line-a')
            ->assertDontSee('Checkout multi-line-b');
        $this->get(route('seller.orders.waybill.item', [$multiSellerOrders->first(), $itemB]))
            ->assertOk()
            ->assertSee('Checkout multi-line-b')
            ->assertDontSee('Checkout multi-line-a');
        $this->assertSame(1, $multiOrder->payments()->count());
        $this->assertSame(2, CodCollection::query()->where('order_id', $multiOrder->id)->count());
        $this->assertSame(3, (int) $multiVariantA->fresh()->stock);
        $this->assertSame(3, (int) $multiVariantB->fresh()->stock);

        $this->assertNotNull($center->fresh());
    }

    public function test_mysql_product_lock_prevents_concurrent_checkouts_exceeding_the_buyer_limit(): void
    {
        $this->assertDisposableMySql();

        [$seller, $category] = $this->sellerRoutingFixture('purchase-limit-race');
        $variant = $this->variant($seller, $category, 'purchase-limit-race', 2);
        $product = $variant->product()->firstOrFail();
        $product->update(['purchase_limit_per_buyer' => 1]);
        [$buyer, $address, $firstItem] = $this->buyerCart($variant, 'purchase-limit-race-a');

        // Separate active carts make the two requests independent at the cart
        // lock. Both represent one unit of the same product for this Buyer.
        $secondCart = Cart::query()->create([
            'buyer_user_id' => $buyer->id,
            'status' => Cart::STATUS_ACTIVE,
        ]);
        $secondItem = CartItem::query()->create([
            'cart_id' => $secondCart->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        $results = $this->runWorkers([
            ['buyer' => $buyer, 'address' => $address, 'item' => $firstItem, 'key' => (string) str()->uuid()],
            ['buyer' => $buyer, 'address' => $address, 'item' => $secondItem, 'key' => (string) str()->uuid()],
        ]);

        $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
        $this->assertSame(1, collect($results)->where('status', 'business_failure')->count(), json_encode($results));
        $failure = collect($results)->firstWhere('status', 'business_failure');
        $this->assertArrayHasKey('cart', $failure['errors'] ?? []);
        $this->assertStringContainsString('limit of 1', strtolower((string) ($failure['errors']['cart'][0] ?? '')));
        $this->assertSame(1, OrderItem::query()->where('product_id', $product->id)->sum('quantity'));
        $this->assertSame(1, (int) $variant->fresh()->stock);
    }

    public function test_mysql_voucher_lock_prevents_concurrent_checkout_from_exceeding_last_global_use(): void
    {
        $this->assertDisposableMySql();

        [$seller, $category] = $this->sellerRoutingFixture('voucher-race');
        $voucher = Voucher::query()->create([
            'seller_profile_id' => $seller->id,
            'code' => 'RACE'.str()->upper(str()->random(12)),
            'name' => 'Concurrency voucher cap',
            'discount_type' => 'PERCENT',
            'discount_value' => '10.00',
            'minimum_order_amount' => '0.00',
            'usage_limit' => 1,
            'per_user_limit' => null,
            'is_active' => true,
        ]);
        $variantA = $this->variant($seller, $category, 'voucher-race-a', 2);
        $variantB = $this->variant($seller, $category, 'voucher-race-b', 2);
        [$buyerA, $addressA, $itemA] = $this->buyerCart($variantA, 'voucher-race-a');
        [$buyerB, $addressB, $itemB] = $this->buyerCart($variantB, 'voucher-race-b');

        $results = $this->runWorkers([
            ['buyer' => $buyerA, 'address' => $addressA, 'item' => $itemA, 'key' => (string) str()->uuid(), 'voucher' => $voucher->code],
            ['buyer' => $buyerB, 'address' => $addressB, 'item' => $itemB, 'key' => (string) str()->uuid(), 'voucher' => $voucher->code],
        ]);

        $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
        $this->assertSame(1, collect($results)->where('status', 'business_failure')->count(), json_encode($results));
        $this->assertSame(1, DB::table('seller_orders')->where('voucher_id', $voucher->id)->where('status', '<>', 'CANCELLED')->distinct()->count('order_id'));
        $this->assertSame(1, Order::query()->whereIn('buyer_user_id', [$buyerA->id, $buyerB->id])->count());
    }

    private function assertDisposableMySql(): void
    {
        $expectedDatabase = trim((string) env('LIKHAE_DISPOSABLE_DB'));
        if ($expectedDatabase === '' || DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Set LIKHAE_DISPOSABLE_DB and use MySQL to run this destructive concurrency test.');
        }

        $actualDatabase = DB::connection()->getDatabaseName();
        $this->assertSame($expectedDatabase, $actualDatabase);
        $this->assertStringContainsString('disposable', strtolower($actualDatabase));
        $this->assertNotSame('likhae', strtolower($actualDatabase));
    }

    /** @param list<array{buyer: User, address: Address, item: CartItem, key: string}> $workers
     *  @return list<array<string, mixed>>
     */
    private function runWorkers(array $workers): array
    {
        $barrierDirectory = storage_path('framework/testing/checkout-'.str()->uuid());
        if (! mkdir($barrierDirectory, 0777, true) && ! is_dir($barrierDirectory)) {
            $this->fail('Could not create the local checkout worker barrier directory.');
        }

        $workerCode = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$barrier = getenv('CHECKOUT_BARRIER');
$worker = getenv('CHECKOUT_WORKER');
$voucher = getenv('CHECKOUT_VOUCHER');
file_put_contents($barrier.DIRECTORY_SEPARATOR.$worker.'.ready', 'ready');
$deadline = microtime(true) + 30;
while (! is_file($barrier.DIRECTORY_SEPARATOR.'start') && microtime(true) < $deadline) { usleep(10000); }
if (! is_file($barrier.DIRECTORY_SEPARATOR.'start')) { fwrite(STDERR, 'worker barrier timed out'); exit(2); }
try {
    $buyer = \App\Models\User::query()->findOrFail((int) getenv('CHECKOUT_BUYER'));
    $address = \App\Models\Buyer\Address::query()->findOrFail((int) getenv('CHECKOUT_ADDRESS'));
    $order = app(\App\Services\Marketplace\CheckoutService::class)->placeOrder(
        $buyer,
        $address,
        [(int) getenv('CHECKOUT_ITEM')],
        'COD',
        $voucher !== '' ? $voucher : [],
        null,
        [],
        [],
        getenv('CHECKOUT_KEY'),
    );
    echo json_encode(['status' => 'success', 'order_id' => $order->id], JSON_THROW_ON_ERROR);
} catch (\Illuminate\Validation\ValidationException $exception) {
    echo json_encode(['status' => 'business_failure', 'errors' => $exception->errors()], JSON_THROW_ON_ERROR);
} catch (\Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
PHP;

        $processes = [];
        try {
            foreach ($workers as $index => $worker) {
                $workerName = 'worker-'.$index;
                $process = new Process(
                    [PHP_BINARY, '-r', $workerCode],
                    base_path(),
                    [
                        'APP_ENV' => 'testing',
                        'DB_CONNECTION' => 'mysql',
                        'DB_HOST' => '127.0.0.1',
                        'DB_PORT' => '3306',
                        'DB_DATABASE' => DB::connection()->getDatabaseName(),
                        'DB_URL' => '',
                        'LIKHAE_DISPOSABLE_DB' => DB::connection()->getDatabaseName(),
                        'CHECKOUT_BARRIER' => $barrierDirectory,
                        'CHECKOUT_WORKER' => $workerName,
                        'CHECKOUT_BUYER' => (string) $worker['buyer']->id,
                        'CHECKOUT_ADDRESS' => (string) $worker['address']->id,
                        'CHECKOUT_ITEM' => (string) $worker['item']->id,
                        'CHECKOUT_KEY' => $worker['key'],
                        'CHECKOUT_VOUCHER' => $worker['voucher'] ?? '',
                    ],
                );
                $process->setTimeout(90);
                $process->start();
                $processes[$workerName] = $process;
            }

            $readyDeadline = microtime(true) + 45;
            while (microtime(true) < $readyDeadline) {
                $allReady = collect(array_keys($processes))->every(fn (string $workerName): bool => is_file($barrierDirectory.DIRECTORY_SEPARATOR.$workerName.'.ready'));
                if ($allReady) {
                    break;
                }
                foreach ($processes as $workerName => $process) {
                    if (! $process->isRunning() && ! is_file($barrierDirectory.DIRECTORY_SEPARATOR.$workerName.'.ready')) {
                        $this->fail('Checkout worker failed before the start barrier: '.$process->getErrorOutput().$process->getOutput());
                    }
                }
                usleep(20000);
            }

            $allReady = collect(array_keys($processes))->every(fn (string $workerName): bool => is_file($barrierDirectory.DIRECTORY_SEPARATOR.$workerName.'.ready'));
            $this->assertTrue($allReady, 'Checkout workers did not reach the shared start barrier.');
            file_put_contents($barrierDirectory.DIRECTORY_SEPARATOR.'start', 'go');

            $results = [];
            foreach ($processes as $workerName => $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $workerName.' failed: '.$process->getErrorOutput().$process->getOutput());
                $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            \Illuminate\Support\Facades\File::deleteDirectory($barrierDirectory);
        }
    }

    private function sellerRoutingFixture(string $suffix): array
    {
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER, 'status' => User::STATUS_ACTIVE]);
        $category = Category::query()->create([
            'name' => 'Concurrency '.$suffix,
            'slug' => 'concurrency-'.str()->slug($suffix).'-'.str()->uuid(),
            'is_active' => true,
        ]);
        $sellerAddress = $this->address($sellerUser, 'Seller '.$suffix);
        $seller = SellerProfile::query()->create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_address_id' => $sellerAddress->id,
            'business_name' => 'Concurrency Seller '.$suffix,
            'status' => 'ACTIVE',
        ]);

        $center = LogisticsCenter::query()
            ->approvedActive()
            ->whereHas('address', fn ($query) => $query
                ->where('province_code', self::PILA['province_code'])
                ->where('municipality_code', self::PILA['municipality_code']))
            ->orderBy('id')
            ->first();
        if ($center) {
            $areaExists = ServiceAreaLocation::query()
                ->where('province_code', self::PILA['province_code'])
                ->where('municipality_code', self::PILA['municipality_code'])
                ->where('barangay_code', self::PILA['barangay_code'])
                ->whereHas('serviceArea', fn ($query) => $query
                    ->where('logistics_center_id', $center->id)
                    ->where('is_active', true))
                ->exists();
            if (! $areaExists) {
                $area = ServiceArea::query()->create([
                    'logistics_center_id' => $center->id,
                    'code' => 'AREA-'.str()->upper(str()->random(14)),
                    'name' => 'Concurrency Pila coverage '.$suffix,
                    'is_active' => true,
                ]);
                ServiceAreaLocation::query()->create(['service_area_id' => $area->id, ...self::PILA]);
            }

            return [$seller, $category, $center];
        }

        $owner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS, 'status' => User::STATUS_ACTIVE]);
        $centerAddress = $this->address($owner, 'Center '.$suffix);
        $center = LogisticsCenter::query()->create([
            'owner_user_id' => $owner->id,
            'address_id' => $centerAddress->id,
            'code' => 'CONC-'.str()->upper(str()->random(14)),
            'business_name' => 'Concurrency Center '.$suffix,
            'status' => 'ACTIVE',
            'approved_by_user_id' => $owner->id,
            'approved_at' => now(),
        ]);
        $area = ServiceArea::query()->create([
            'logistics_center_id' => $center->id,
            'code' => 'AREA-'.str()->upper(str()->random(14)),
            'name' => 'Concurrency Pila coverage '.$suffix,
            'is_active' => true,
        ]);
        ServiceAreaLocation::query()->create(['service_area_id' => $area->id, ...self::PILA]);

        return [$seller, $category, $center];
    }

    private function variant(SellerProfile $seller, Category $category, string $suffix, int $stock, string $price = '100.00'): ProductVariant
    {
        $slugSuffix = str()->slug($suffix).'-'.str()->uuid();
        $product = Product::query()->create([
            'seller_profile_id' => $seller->id,
            'category_id' => $category->id,
            'name' => 'Checkout '.$suffix,
            'slug' => 'checkout-'.$slugSuffix,
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);

        return ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'SKU-'.str()->upper(str()->random(18)),
            'price' => $price,
            'stock' => $stock,
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    private function buyerCart(ProductVariant $variant, string $suffix, int $quantity = 1): array
    {
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER, 'status' => User::STATUS_ACTIVE]);
        $address = $this->address($buyer, 'Buyer '.$suffix);
        $cart = Cart::query()->create(['buyer_user_id' => $buyer->id, 'status' => Cart::STATUS_ACTIVE]);
        $item = CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
        ]);

        return [$buyer, $address, $item];
    }

    private function address(User $user, string $label): Address
    {
        return Address::query()->create([
            'user_id' => $user->id,
            'label' => $label,
            'recipient_name' => $user->name,
            'contact_number' => $user->contact_number,
            ...self::PILA,
            'street_address' => $label.' Street',
            'latitude' => '14.2340000',
            'longitude' => '121.3640000',
        ]);
    }
}
