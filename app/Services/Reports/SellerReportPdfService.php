<?php

namespace App\Services\Reports;

/** Creates a compact, dependency-free PDF report using the PDF core fonts. */
class SellerReportPdfService
{
    private const PAGE_WIDTH = 612.0;
    private const PAGE_HEIGHT = 792.0;
    private array $pages = [];
    private array $commands = [];
    private float $cursorY = 0.0;

    public function render(array $report): string
    {
        $this->pages = [];
        $this->commands = [];
        $this->newPage($report, false);
        $this->text(42, 684, 'BUSINESS INTELLIGENCE', 8, 'FDF5F1', 'F2');
        $this->text(42, 658, $report['title'], 21, 'FFFFFF', 'F2');
        $this->text(42, 639, 'Seller: '.$this->truncate($report['seller_name'] ?? 'Seller', 70), 9, 'F7EDEA');
        $this->text(42, 622, $this->truncate($report['scope'], 72), 9, 'F7EDEA');
        $this->text(42, 606, $report['range_label'], 9, 'F7EDEA');
        $this->text(42, 590, 'Generated '.$report['generated_at'].(empty($report['timezone']) ? '' : ' ('.$report['timezone'].')'), 8, '80675A');

        $this->sectionHeading('Performance at a glance', $report);
        $summary = $report['summary'];
        $this->metricCard(42, $this->cursorY - 48, 'Gross sales', $this->money($summary['gross']), 100);
        $this->metricCard(148, $this->cursorY - 48, 'Commission', $this->money($summary['commission'] ?? 0), 100);
        $this->metricCard(254, $this->cursorY - 48, 'Net sales', $this->money($summary['net']), 100);
        $this->metricCard(360, $this->cursorY - 48, 'Recognized orders', (string) ($summary['financial_orders'] ?? $summary['orders']), 100);
        $this->metricCard(466, $this->cursorY - 48, 'Completion', $summary['completion_rate'].'%', 100);
        $this->cursorY -= 78;

        $this->sectionHeading('Settlement ledger', $report);
        if ($summary['finance_schema_ready'] ?? false) {
            $this->text(42, $this->cursorY, 'Order-level settlement records for recognized orders in this report scope.', 8, '80675A');
            $this->cursorY -= 16;
            $this->text(42, $this->cursorY, 'Pending / review: '.$this->money($summary['pending_balance']).'   Authorized: '.$this->money($summary['authorized_balance']), 8, '4D3329');
            $this->cursorY -= 14;
            $this->text(42, $this->cursorY, 'Paid: '.$this->money($summary['paid_balance']).'   Outstanding: '.$this->money($summary['outstanding_balance']), 8, '4D3329');
            $this->cursorY -= 14;
            $this->text(42, $this->cursorY, 'Seller payable: '.$this->money($summary['seller_payable']).'   Discounts: '.$this->money($summary['discounts']), 8, '4D3329');
            $this->cursorY -= 14;
            $this->text(42, $this->cursorY, 'Posted seller refund adjustments: '.$this->money($summary['seller_refund_adjustment']).'   Commission reversals: '.$this->money($summary['commission_refund_reversal']), 8, '4D3329');
            $this->cursorY -= 22;
        } else {
            $this->text(42, $this->cursorY, 'Settlement figures are unavailable until the finance schema is installed.', 8, '80675A');
            $this->cursorY -= 24;
        }

        $this->sectionHeading('Catalog performance mix', $report);
        $mix = $report['performance_counts'];
        $this->text(42, $this->cursorY, 'High performers', 9, '4D1712', 'F2');
        $this->text(154, $this->cursorY, (string) $mix['high'], 15, '641F19', 'F2');
        $this->text(222, $this->cursorY, 'Steady sellers', 9, '4D1712', 'F2');
        $this->text(325, $this->cursorY, (string) $mix['mid'], 15, '641F19', 'F2');
        $this->text(390, $this->cursorY, 'Low / no sales', 9, '4D1712', 'F2');
        $this->text(506, $this->cursorY, (string) ($mix['low'] + $mix['no_sales']), 15, '641F19', 'F2');
        $this->cursorY -= 24;
        $this->line(42, $this->cursorY, 570, $this->cursorY, 'E8D8C8');
        $this->cursorY -= 20;

        $this->sectionHeading('What to act on', $report);
        foreach ($report['insights'] as $insight) {
            $this->ensureSpace(20, $report);
            $this->fillRect(42, $this->cursorY - 3, 4, 4, 'B86A5B');
            $this->text(55, $this->cursorY - 6, $insight, 9, '4D3329');
            $this->cursorY -= 19;
        }
        $this->cursorY -= 8;

        $this->sectionHeading('Product detail', $report);
        $this->tableHeader();
        foreach ($report['products'] as $product) {
            $this->ensureSpace(25, $report);
            $this->productRow($product);
        }
        $this->cursorY -= 4;
        $this->ensureSpace(30, $report);
        $this->text(42, $this->cursorY, 'Method: gross sales and commission use recognized ledger records and historic order item prices. Net sales = Gross Sales - Commission.', 7, '80675A');
        $this->text(42, $this->cursorY - 12, 'Shipping and Additional Care are excluded. Order activity uses order dates; refund adjustments use posted dates.', 7, '80675A');

        return $this->compile();
    }

    private function newPage(array $report, bool $continued): void
    {
        if ($this->commands !== []) {
            $this->pages[] = implode("\n", $this->commands);
        }
        $this->commands = [];
        $this->fillRect(0, 0, self::PAGE_WIDTH, self::PAGE_HEIGHT, 'FFFDF9');
        $this->fillRect(0, 594, self::PAGE_WIDTH, 198, '641F19');
        $this->text(42, 754, 'LIKHAE', 10, 'FFFFFF', 'F2');
        $this->text(486, 754, 'CONFIDENTIAL', 7, 'DAB9B0', 'F2');
        $this->cursorY = $continued ? 548 : 574;
        if ($continued) {
            $this->text(42, 558, $report['title'].' - Product detail (continued)', 13, 'FFFFFF', 'F2');
            $this->tableHeader();
        }
    }

    private function sectionHeading(string $value, array $report): void
    {
        $this->ensureSpace(30, $report);
        $this->text(42, $this->cursorY, strtoupper($value), 8, '9A6558', 'F2');
        $this->cursorY -= 17;
    }

    private function metricCard(float $x, float $y, string $label, string $value, float $width = 124): void
    {
        $this->fillRect($x, $y, $width, 55, 'FAF3EC');
        $this->text($x + 10, $y + 39, strtoupper($label), 6.5, '9A6558', 'F2');
        $this->text($x + 10, $y + 19, $value, 9, '4D1712', 'F2');
    }

    private function tableHeader(): void
    {
        $this->fillRect(42, $this->cursorY - 3, 528, 19, 'F3E4DE');
        $this->text(51, $this->cursorY + 4, 'PRODUCT', 6.5, '641F19', 'F2');
        $this->text(237, $this->cursorY + 4, 'TIER', 6.5, '641F19', 'F2');
        $this->text(295, $this->cursorY + 4, 'UNITS', 6.5, '641F19', 'F2');
        $this->text(345, $this->cursorY + 4, 'ORDERS', 6.5, '641F19', 'F2');
        $this->text(390, $this->cursorY + 4, 'GROSS', 6.5, '641F19', 'F2');
        $this->text(445, $this->cursorY + 4, 'COMMISSION', 6.5, '641F19', 'F2');
        $this->text(505, $this->cursorY + 4, 'NET', 6.5, '641F19', 'F2');
        $this->cursorY -= 24;
    }

    private function productRow(array $product): void
    {
        $this->line(42, $this->cursorY - 3, 570, $this->cursorY - 3, 'E8D8C8');
        $this->text(51, $this->cursorY + 4, $this->truncate($product['name'], 28), 8, '4D3329', 'F2');
        $this->text(237, $this->cursorY + 4, strtoupper(str_replace('_', ' ', $product['tier'])), 6.5, $this->tierColor($product['tier']), 'F2');
        $this->text(301, $this->cursorY + 4, (string) $product['units'], 8, '4D3329');
        $this->text(352, $this->cursorY + 4, (string) $product['orders'], 8, '4D3329');
        $this->text(390, $this->cursorY + 4, $this->money($product['gross'] ?? $product['revenue'] ?? 0), 7.5, '4D3329', 'F2');
        $this->text(445, $this->cursorY + 4, $this->money($product['commission'] ?? 0), 7, '4D3329', 'F2');
        $this->text(505, $this->cursorY + 4, $this->money($product['net'] ?? (($product['gross'] ?? $product['revenue'] ?? 0) - ($product['commission'] ?? 0))), 7.5, '4D3329', 'F2');
        $this->cursorY -= 24;
    }

    private function ensureSpace(float $height, array $report): void
    {
        if ($this->cursorY - $height >= 58) {
            return;
        }
        $this->newPage($report, true);
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
        $this->pages[] = implode("\n", $this->commands);
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
            $objects[$contentId] = '<< /Length '.strlen($stream).' >>' . "\nstream\n" . $stream . "\nendstream";
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
        $pdf .= 'xref' . "\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_keys($objects) as $id) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$id])."\n";
        }
        return $pdf.'trailer << /Size '.(count($objects) + 1).' /Root 1 0 R >>' . "\nstartxref\n".$xref."\n%%EOF";
    }

    private function money(float|int $value): string { return 'PHP '.number_format((float) $value, 2); }
    private function tierColor(string $tier): string { return match ($tier) { 'high' => '236B45', 'mid' => '7C5C12', 'low' => 'A34A34', default => '80675A' }; }
    private function truncate(string $value, int $length): string { return mb_strimwidth($value, 0, $length, '...'); }
    private function pdfString(string $value): string { $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value; return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $ascii); }
    private function color(string $hex): string { return implode(' ', array_map(fn (string $channel): string => number_format(hexdec($channel) / 255, 3, '.', ''), str_split($hex, 2))); }
}
