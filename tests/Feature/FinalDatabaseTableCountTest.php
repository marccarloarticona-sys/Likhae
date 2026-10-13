<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinalDatabaseTableCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_final_database_contains_the_expected_tables(): void
    {
        $tableCount = DB::getDriverName() === 'mysql'
            ? (int) DB::selectOne(
                'SELECT COUNT(*) AS aggregate FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = ?',
                ['BASE TABLE'],
            )->aggregate
            : count(Schema::getTableListing());

        $this->assertSame(66, $tableCount);
    }
}
