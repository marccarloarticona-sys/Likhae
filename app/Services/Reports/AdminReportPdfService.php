<?php

namespace App\Services\Reports;

/**
 * Renders the Admin exports as small, dependency-free PDF reports.
 *
 * The project already ships a core-font PDF renderer for Seller reports. This
 * renderer keeps the same approach for Admin exports so no Composer package or
 * database change is required.
 */
class AdminReportPdfService
{
    private const PORTRAIT = [612.0, 792.0];
    private const LANDSCAPE = [792.0, 612.0];

    private array $pages = [];
    private array $commands = [];
    private array $report = [];
    private float $pageWidth = 612.0;
    private float $pageHeight = 792.0;
    private float $margin = 36.0;
    private float $contentWidth = 540.0;
    private float $cursorY = 0.0;

    /**
     * @param array<string, mixed> $report
     */
    public function render(array $report): string
    {
        $this->pages = [];
        $this->commands = [];
        $this->report = $report;
        [$this->pageWidth, $this->pageHeight] = ($report['orientation'] ?? 'portrait') === 'landscape'
            ? self::LANDSCAPE
            : self::PORTRAIT;
        $this->contentWidth = $this->pageWidth - ($this->margin * 2);

        $this->newPage(false);
        $this->drawFilters();
        $this->drawSummary();
        $this->drawTable();

        if (filled($report['footnote'] ?? null)) {
            $this->ensureSpace(24);
            $this->text($this->margin, $this->cursorY, (string) $report['footnote'], 7, '80675A');
        }

        $this->pages[] = implode("\n", $this->commands);

        return $this->compile();
    }

    private function newPage(bool $continued): void
    {
        if ($this->commands !== []) {
            $this->pages[] = implode("\n", $this->commands);
        }

        $this->commands = [];
        $this->fillRect(0, 0, $this->pageWidth, $this->pageHeight, 'FFFDF9');
        $this->fillRect(0, $this->pageHeight - 116, $this->pageWidth, 116, '641F19');
        $this->text($this->margin, $this->pageHeight - 30, 'LIKHAE', 11, 'FFFFFF', 'F2');
        $this->text($this->pageWidth - 118, $this->pageHeight - 30, 'ADMIN REPORT', 7, 'DAB9B0', 'F2');

        if ($continued) {
            $this->text($this->margin, $this->pageHeight - 57, $this->truncate((string) $this->report['title'].' - continued', 76), 14, 'FFFFFF', 'F2');
            $this->cursorY = $this->pageHeight - 145;
        } else {
            $this->text($this->margin, $this->pageHeight - 58, $this->truncate((string) ($this->report['title'] ?? 'Admin report'), 76), 20, 'FFFFFF', 'F2');
            $this->text($this->margin, $this->pageHeight - 79, $this->truncate((string) ($this->report['description'] ?? ''), 108), 8.5, 'F7EDEA');
            $generated = 'Generated '.($this->report['generated_at'] ?? '').' | By '.($this->report['generated_by'] ?? 'Authorized Admin');
            $this->text($this->margin, $this->pageHeight - 98, $this->truncate($generated, 124), 7.5, 'DAB9B0');
            $this->cursorY = $this->pageHeight - 142;
        }
    }

    private function drawFilters(): void
    {
        $filters = $this->report['filters'] ?? [];
        $parts = [];

        foreach ($filters as $filter) {
            if (is_array($filter) && filled($filter['value'] ?? null)) {
                $parts[] = ($filter['label'] ?? 'Filter').': '.(string) $filter['value'];
            }
        }

        $label = $parts === [] ? 'Filters: None' : 'Filters: '.implode(' | ', $parts);
        $this->fillRect($this->margin, $this->cursorY - 25, $this->contentWidth, 25, 'F6EFE7');
        $this->text($this->margin + 10, $this->cursorY - 15, $this->truncate($label, (int) ($this->contentWidth / 4.5)), 7.5, '641F19', 'F2');
        $this->cursorY -= 40;
    }

    private function drawSummary(): void
    {
        $summary = $this->report['summary'] ?? [];
        if ($summary === []) {
            return;
        }

        $this->sectionHeading('Summary');
        $gap = 8.0;
        $count = min(4, count($summary));
        $cardWidth = ($this->contentWidth - ($gap * ($count - 1))) / $count;
        $cardHeight = 50.0;
        $startX = $this->margin;

        foreach (array_slice($summary, 0, 4) as $card) {
            $this->fillRect($startX, $this->cursorY - $cardHeight + 8, $cardWidth, $cardHeight, 'FAF3EC');
            $this->text($startX + 9, $this->cursorY - 8, strtoupper((string) ($card['label'] ?? 'Summary')), 6.5, '9A6558', 'F2');
            $this->text($startX + 9, $this->cursorY - 29, $this->truncate((string) ($card['value'] ?? '-'), 22), 12, '4D1712', 'F2');
            if (filled($card['detail'] ?? null)) {
                $this->text($startX + 9, $this->cursorY - 42, $this->truncate((string) $card['detail'], 28), 6.5, '80675A');
            }
            $startX += $cardWidth + $gap;
        }

        $this->cursorY -= $cardHeight + 22;
    }

    private function drawTable(): void
    {
        $columns = $this->report['columns'] ?? [];
        $rows = $this->report['rows'] ?? [];
        if ($columns === []) {
            return;
        }

        $this->sectionHeading((string) ($this->report['table_title'] ?? 'Details'));
        $this->drawTableHeader($columns);

        if ($rows === []) {
            $this->fillRect($this->margin, $this->cursorY - 2, $this->contentWidth, 24, 'FBF7F2');
            $this->text($this->margin + 10, $this->cursorY + 6, (string) ($this->report['empty_message'] ?? 'No records found.'), 8, '80675A');
            $this->cursorY -= 32;

            return;
        }

        foreach ($rows as $index => $row) {
            $this->ensureSpace(23, function () use ($columns): void {
                $this->drawTableHeader($columns);
            });
            if ($index % 2 === 0) {
                $this->fillRect($this->margin, $this->cursorY - 3, $this->contentWidth, 20, 'FFF9F2');
            }

            $x = $this->margin;
            foreach ($columns as $columnIndex => $column) {
                $width = (float) ($column['width'] ?? 80);
                $value = $row[$columnIndex] ?? '-';
                $this->text($x + 6, $this->cursorY + 4, $this->truncate($this->value($value), max(8, (int) ($width / 4.6))), 7.5, '4D3329');
                $x += $width;
            }

            $this->line($this->margin, $this->cursorY - 3, $this->margin + $this->contentWidth, $this->cursorY - 3, 'E8D8C8');
            $this->cursorY -= 22;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     */
    private function drawTableHeader(array $columns): void
    {
        $this->fillRect($this->margin, $this->cursorY - 3, $this->contentWidth, 19, 'F3E4DE');
        $x = $this->margin;

        foreach ($columns as $column) {
            $width = (float) ($column['width'] ?? 80);
            $this->text($x + 6, $this->cursorY + 4, strtoupper($this->truncate((string) ($column['label'] ?? ''), max(8, (int) ($width / 4.6)))), 6.5, '641F19', 'F2');
            $x += $width;
        }

        $this->cursorY -= 23;
    }

    private function sectionHeading(string $value): void
    {
        $this->ensureSpace(28);
        $this->text($this->margin, $this->cursorY, strtoupper($value), 7.5, '9A6558', 'F2');
        $this->cursorY -= 16;
    }

    private function ensureSpace(float $height, ?callable $afterBreak = null): void
    {
        if ($this->cursorY - $height >= 48) {
            return;
        }

        $this->newPage(true);
        if ($afterBreak !== null) {
            $afterBreak();
        }
    }

    private function compile(): string
    {
        $pageCount = count($this->pages);
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => ''];
        $pageIds = [];
        $fontRegular = 3 + ($pageCount * 2);
        $fontBold = $fontRegular + 1;

        foreach ($this->pages as $index => $stream) {
            $pageNumber = $index + 1;
            $stream .= "\n".$this->textCommand($this->margin, 27, 'LIKHAE Admin Center | Page '.$pageNumber.' of '.$pageCount, 6.5, '80675A', 'F1');
            $stream .= "\n".$this->textCommand($this->pageWidth - 180, 27, 'Generated '.($this->report['generated_at'] ?? ''), 6.5, '80675A', 'F1');

            $pageId = 3 + ($index * 2);
            $contentId = $pageId + 1;
            $pageIds[] = $pageId;
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$this->pageWidth.' '.$this->pageHeight.'] /Resources << /Font << /F1 '.$fontRegular.' 0 R /F2 '.$fontBold.' 0 R >> >> /Contents '.$contentId.' 0 R >>';
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

    private function text(float $x, float $y, string $value, float $size, string $hex, string $font = 'F1'): void
    {
        $this->commands[] = $this->textCommand($x, $y, $value, $size, $hex, $font);
    }

    private function textCommand(float $x, float $y, string $value, float $size, string $hex, string $font = 'F1'): string
    {
        return sprintf('%s rg BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET', $this->color($hex), $font, $size, $x, $y, $this->pdfString($value));
    }

    private function fillRect(float $x, float $y, float $width, float $height, string $hex): void
    {
        $this->commands[] = sprintf('%s rg %.2F %.2F %.2F %.2F re f', $this->color($hex), $x, $y, $width, $height);
    }

    private function line(float $x1, float $y1, float $x2, float $y2, string $hex): void
    {
        $this->commands[] = sprintf('%s RG 0.50 w %.2F %.2F m %.2F %.2F l S', $this->color($hex), $x1, $y1, $x2, $y2);
    }

    private function value(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return (string) $value;
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strimwidth($value, 0, max(8, $length), '...');
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
}
