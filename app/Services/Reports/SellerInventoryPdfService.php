<?php

namespace App\Services\Reports;

/** Creates a compact, dependency-free, multi-page PDF inventory report. */
class SellerInventoryPdfService
{
    private const PAGE_WIDTH = 612.0;
    private const PAGE_HEIGHT = 792.0;

    /** @var list<string> */
    private array $pages = [];

    /** @var list<string> */
    private array $commands = [];

    private float $cursorY = 0.0;

    /** @param array<string, mixed> $report */
    public function render(array $report): string
    {
        $this->pages = [];
        $this->commands = [];
        $this->startPage($report, false);
        $this->text(42, 686, 'STOCK CONTROL', 8, 'FDF5F1', 'F2');
        $this->text(42, 660, 'Inventory Report', 21, 'FFFFFF', 'F2');
        $this->text(42, 640, 'Seller: '.$report['sellerName'], 9, 'F7EDEA');
        $this->text(42, 622, 'Generated: '.$report['generatedAt'], 8, 'DAB9B0');
        $this->text(42, 606, 'Applied Search: '.($report['appliedSearch'] ?: 'None'), 8, 'F7EDEA');
        $this->text(310, 606, 'Applied Stock Filter: '.$report['appliedStatusLabel'], 8, 'F7EDEA');

        $this->cursorY = 570;
        $this->sectionHeading('Inventory overview');
        $metrics = [
            ['Total SKUs', (string) $report['inventoryStats']['total']],
            ['In Stock', (string) $report['inventoryStats']['in_stock']],
            ['Low Stock', (string) $report['inventoryStats']['low_stock']],
            ['Out of Stock', (string) $report['inventoryStats']['out_of_stock']],
            ['Healthy', (string) $report['inventoryStats']['healthy']],
        ];
        foreach ($metrics as $index => [$label, $value]) {
            $x = 42 + ($index * 106);
            $this->fillRect($x, 492, 100, 48, 'FAF3EC');
            $this->text($x + 8, 523, strtoupper($label), 6.2, '9A6558', 'F2');
            $this->text($x + 8, 501, $value, 15, '4D1712', 'F2');
        }
        $this->cursorY = 477;
        $this->sectionHeading('Product detail');

        if ($report['inventoryGroups']->isEmpty()) {
            $this->text(51, $this->cursorY, 'No variants match the applied search and stock filter.', 9, '80675A');
            $this->cursorY -= 20;
        } else {
            foreach ($report['inventoryGroups'] as $group) {
                if ($this->cursorY < 84) {
                    $this->startPage($report, true);
                }
                $this->productHeading($group);
                $this->tableHeader();

                foreach ($group['variants'] as $variant) {
                    if ($this->cursorY < 70) {
                        $this->startPage($report, true);
                        $this->productHeading($group, true);
                        $this->tableHeader();
                    }
                    $this->variantRow($variant);
                }
                $this->cursorY -= 7;
            }
        }

        return $this->compile();
    }

    /** @param array<string, mixed> $report */
    private function startPage(array $report, bool $continued): void
    {
        if ($this->commands !== []) {
            $this->pages[] = implode("\n", $this->commands);
        }
        $this->commands = [];
        $this->fillRect(0, 0, self::PAGE_WIDTH, self::PAGE_HEIGHT, 'FFFDF9');
        $this->fillRect(0, 594, self::PAGE_WIDTH, 198, '641F19');
        $this->text(42, 754, 'LIKHAE', 10, 'FFFFFF', 'F2');
        $this->text(486, 754, 'CONFIDENTIAL', 7, 'DAB9B0', 'F2');

        if ($continued) {
            $this->text(42, 688, 'Inventory Report - Continued', 16, 'FFFFFF', 'F2');
            $this->text(42, 665, 'Seller: '.$report['sellerName'], 9, 'F7EDEA');
            $this->text(42, 647, 'Generated: '.$report['generatedAt'], 8, 'DAB9B0');
            $this->text(42, 625, 'Search: '.($report['appliedSearch'] ?: 'None'), 8, 'F7EDEA');
            $this->text(310, 625, 'Stock Filter: '.$report['appliedStatusLabel'], 8, 'F7EDEA');
            $this->cursorY = 570;
        }
    }

    private function sectionHeading(string $label): void
    {
        $this->text(42, $this->cursorY, strtoupper($label), 8, '9A6558', 'F2');
        $this->cursorY -= 17;
    }

    /** @param array<string, mixed> $group */
    private function productHeading(array $group, bool $continued = false): void
    {
        $this->fillRect(42, $this->cursorY - 5, 528, 19, 'F3E4DE');
        $name = $group['name'].($continued ? ' (continued)' : '');
        $this->text(50, $this->cursorY + 1, $this->truncate($name, 47), 8, '641F19', 'F2');
        $summary = $group['variant_count'].' variants / '.$group['total_stock'].' units';
        $this->text(430, $this->cursorY + 1, $summary, 7, '80675A');
        $this->cursorY -= 22;
    }

    private function tableHeader(): void
    {
        $this->fillRect(42, $this->cursorY - 4, 528, 17, 'FAF3EC');
        $this->text(50, $this->cursorY + 1, 'VARIANT', 6.5, '9A6558', 'F2');
        $this->text(220, $this->cursorY + 1, 'SKU', 6.5, '9A6558', 'F2');
        $this->text(415, $this->cursorY + 1, 'PRICE', 6.5, '9A6558', 'F2');
        $this->text(475, $this->cursorY + 1, 'STOCK', 6.5, '9A6558', 'F2');
        $this->text(520, $this->cursorY + 1, 'STATUS', 6.5, '9A6558', 'F2');
        $this->cursorY -= 19;
    }

    /** @param array<string, mixed> $variant */
    private function variantRow(array $variant): void
    {
        $this->line(42, $this->cursorY - 4, 570, $this->cursorY - 4, 'E8D8C8');
        $this->text(50, $this->cursorY + 2, $this->truncate($variant['variant'], 27), 7.5, '4D3329');
        $this->text(220, $this->cursorY + 2, $this->truncate((string) ($variant['sku'] ?: 'Not assigned'), 30), 7.2, '4D3329');
        $this->text(415, $this->cursorY + 2, 'PHP '.number_format((float) $variant['price'], 2), 7, '4D3329');
        $this->text(480, $this->cursorY + 2, (string) $variant['stock'], 7.5, '4D3329', 'F2');
        $this->text(520, $this->cursorY + 2, $variant['stock_status'], 6.8, $this->statusColor($variant['stock_level']), 'F2');
        $this->cursorY -= 19;
    }

    private function text(float $x, float $y, string $value, float $size, string $hex, string $font = 'F1'): void
    {
        $this->commands[] = sprintf('%s rg BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET', $this->color($hex), $font, $size, $x, $y, $this->pdfString($value));
    }

    private function fillRect(float $x, float $y, float $width, float $height, string $hex): void
    {
        $this->commands[] = sprintf('%s rg %.2F %.2F %.2F %.2F re f', $this->color($hex), $x, $y, $width, $height);
    }

    private function line(float $x1, float $y1, float $x2, float $y2, string $hex): void
    {
        $this->commands[] = sprintf('%s RG 0.50 w %.2F %.2F m %.2F %.2F l S', $this->color($hex), $x1, $y1, $x2, $y2);
    }

    private function compile(): string
    {
        if ($this->commands !== []) {
            $this->pages[] = implode("\n", $this->commands);
        }
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => ''];
        $pageIds = [];
        $pageCount = count($this->pages);
        $fontRegular = 3 + ($pageCount * 2);
        $fontBold = $fontRegular + 1;
        foreach ($this->pages as $index => $stream) {
            $pageId = 3 + ($index * 2);
            $contentId = $pageId + 1;
            $pageIds[] = $pageId;
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 '.$fontRegular.' 0 R /F2 '.$fontBold.' 0 R >> >> /Contents '.$contentId.' 0 R >>';
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', array_map(fn (int $id): string => $id.' 0 R', $pageIds)).'] /Count '.$pageCount.' >>';
        $objects[$fontRegular] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[$fontBold] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_keys($objects) as $id) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$id])."\n";
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1).' /Root 1 0 R >>' . "\nstartxref\n".$xref."\n%%EOF";
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strimwidth($value, 0, $length, '...');
    }

    private function pdfString(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $ascii);
    }

    private function color(string $hex): string
    {
        return implode(' ', array_map(fn (string $channel): string => number_format(hexdec($channel) / 255, 3, '.', ''), str_split($hex, 2)));
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            'low' => '9A5B11',
            'out' => 'B42318',
            default => '256F4A',
        };
    }
}
