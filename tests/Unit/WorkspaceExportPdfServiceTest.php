<?php

namespace Tests\Unit;

use App\Services\Reports\WorkspaceExportPdfService;
use Tests\TestCase;

class WorkspaceExportPdfServiceTest extends TestCase
{
    public function test_workspace_export_pdf_supports_wrapped_rows_and_multiple_pages(): void
    {
        $longText = str_repeat('Long review text with parentheses (and escaped content). ', 90);
        $rows = [[$longText, 'READY_FOR_PICKUP']];
        foreach (range(1, 70) as $number) {
            $rows[] = ['Shipment '.$number, 'DELIVERED'];
        }

        $pdf = app(WorkspaceExportPdfService::class)->render([
            'workspace' => 'Logistics Portal',
            'title' => 'Shipment report',
            'description' => 'Exported shipment details.',
            'generated_at' => '2026-10-17 12:00:00 +08:00',
            'orientation' => 'landscape',
            'columns' => [
                ['label' => 'Tracking / comment', 'width' => 0.75],
                ['label' => 'Status', 'width' => 0.25],
            ],
            'rows' => $rows,
        ]);

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith('%%EOF', $pdf);
        $this->assertStringContainsString('Logistics Portal', $pdf);
        $this->assertStringContainsString('Shipment 70', $pdf);
        $this->assertGreaterThan(1, preg_match_all('/\/Type \/Page \/Parent/', $pdf));
    }
}
