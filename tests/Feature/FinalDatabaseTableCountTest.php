<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinalDatabaseTableCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_final_database_contains_the_expected_tables(): void
    {
        foreach ([
            'users', 'orders', 'seller_orders', 'order_items', 'products', 'product_variants',
            'vouchers', 'shipments', 'shipment_events', 'notifications',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected table [$table] to exist.");
        }
    }
}
