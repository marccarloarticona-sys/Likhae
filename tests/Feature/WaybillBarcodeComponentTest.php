<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class WaybillBarcodeComponentTest extends TestCase
{
    public function test_waybill_barcode_component_renders_a_machine_readable_code_128_barcode(): void
    {
        $html = Blade::render('<x-shared.waybill-barcode tracking="LKH-TEST-123" />');

        $this->assertStringContainsString('Waybill barcode for LKH-TEST-123', $html);
        $this->assertStringContainsString('LKH-TEST-123', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('<rect', $html);
        $this->assertStringContainsString('viewBox=', $html);
        $this->assertStringContainsString('width:100%', $html);
        $this->assertStringNotContainsString('Barcode renderer unavailable', $html);
    }
}
