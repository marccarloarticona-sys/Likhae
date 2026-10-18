<?php

namespace Tests\Feature;

use App\Models\Logistics\LogisticsCenter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogisticsBarcodeScannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_logistics_workflow_pages_render_scanner_with_tracking_query(): void
    {
        $this->withoutVite();

        $user = User::factory()->create([
            'account_type' => User::TYPE_LOGISTICS,
            'status' => User::STATUS_ACTIVE,
        ]);

        LogisticsCenter::create([
            'owner_user_id' => $user->id,
            'code' => 'SCAN-CENTER',
            'business_name' => 'Scanner Test Center',
            'status' => 'ACTIVE',
            'approved_by_user_id' => $user->id,
            'approved_at' => now(),
        ]);

        $tracking = 'LK-WAYBILL-TEST-001';
        $this->actingAs($user)
            ->get(route('logistics.pickups').'?tracking='.$tracking)
            ->assertOk()
            ->assertDontSee('data-parcel-scanner', false)
            ->assertDontSee('Start Camera')
            ->assertSee('value="'.$tracking.'"', false);

        $pages = [
            route('logistics.parcels.receive'),
            route('logistics.sorting'),
            route('logistics.dispatch'),
            route('logistics.parcels.tracking'),
        ];

        foreach ($pages as $page) {
            $this->actingAs($user)
                ->get($page.'?tracking='.$tracking)
                ->assertOk()
                ->assertSee('data-parcel-scanner', false)
                ->assertSee('data-scanner-start', false)
                ->assertSee('name="tracking"', false)
                ->assertSee('value="'.$tracking.'"', false);
        }
    }
}
