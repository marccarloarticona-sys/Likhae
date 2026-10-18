<?php

namespace App\Services\Reports;

/** Renders scoped Seller and Logistics exports as dependency-free PDFs. */
class WorkspaceExportPdfService
{
    private const PORTRAIT = [612.0, 792.0];
    private const LANDSCAPE = [792.0, 612.0];
    private const MARGIN = 42.0;
    private const BOTTOM = 48.0;
    private const LINE_HEIGHT = 10.0;

    /** @var list<string> */
    private array $pages = [];

    /** @var list<string> */
    private array $commands = [];

    /** @var array<string, mixed> */
    private array $report = [];

    private float $pageWidth = 612.0;
    private float $pageHeight = 792.0;
    private float $contentWidth = 528.0;
    private float $cursorY = 0.0;

    /** @param array<string, mixed> $report */
    public function render(array $report): string
    {
        $this->pages = [];
        $this->commands = [];
        $this->report = $report;
        [$this->pageWidth, $this->pageHeight] = ($report['orientation'] ?? 'portrait') === 'landscape'
            ? self::LANDSCAPE
            : self::PORTRAIT;
        $this->contentWidth = $this->pageWidth - (self::MARGIN * 2);

        $columns = $report['columns'] ?? [];
        $this->newPage(false);
        if ($columns !== []) {
            $this->drawTableHeader($columns);
        }

        $rowCount = 0;
        foreach (($report['rows'] ?? []) as $row) {
            $this->drawRow($columns, is_array($row) ? $row : []);
            $rowCount++;
        }

        if ($rowCount === 0) {
            $this->text(self::MARGIN + 8, $this->cursorY - 2, (string) ($report['empty_message'] ?? 'No records found.'), 9, '80675A');
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
        $this->fillRect(0, $this->pageHeight - 108, $this->pageWidth, 108, '641F19');
        $this->text(self::MARGIN, $this->pageHeight - 29, 'LIKHAE', 10, 'FFFFFF', 'F2');
        $this->text($this->pageWidth - 152, $this->pageHeight - 29, strtoupper((string) ($this->report['workspace'] ?? 'WORKSPACE')), 7, 'DAB9B0', 'F2');
        $title = (string) ($this->report['title'] ?? 'Workspace export');
        $this->text(self::MARGIN, $this->pageHeight - 56, $this->truncate($continued ? $title.' - continued' : $title, 88), $continued ? 15 : 20, 'FFFFFF', 'F2');

        if (! $continued) {
            $this->text(self::MARGIN, $this->pageHeight - 77, $this->truncate((string) ($this->report['description'] ?? ''), 120), 8, 'F7EDEA');
            $this->text(self::MARGIN, $this->pageHeight - 94, $this->truncate($this->metadataLine(), 132), 7, 'DAB9B0');
        }

        $this->cursorY = $this->pageHeight - ($continued ? 130 : 120);
    }

    private function metadataLine(): string
    {
        $parts = ['Generated '.(string) ($this->report['generated_at'] ?? '')];
        $filters = [];
        foreach (($this->report['filters'] ?? []) as $label => $value) {
            if (filled($value)) {
                $filters[] = (is_int($label) ? '' : (string) $label.': ').(string) $value;
            }
        }
        if ($filters !== []) {
            $parts[] = 'Filters: '.implode(' | ', $filters);
        }

        return implode(' | ', $parts);
    }

    /** @param list<array{label:string,width?:float}> $columns */
    private function drawTableHeader(array $columns): void
    {
        if ($columns === []) {
            return;
        }

        $this->fillRect(self::MARGIN, $this->cursorY - 19, $this->contentWidth, 21, 'F3E4DE');
        $x = self::MARGIN;
        foreach ($this->columnWidths($columns) as $index => $width) {
            $label = (string) ($columns[$index]['label'] ?? '');
            $this->text($x + 5, $this->cursorY - 5, $this->truncate($label, $this->characterWidth($width)), 6.5, '641F19', 'F2');
            $x += $width;
        }
        $this->cursorY -= 24;
    }

    /** @param list<array{label:string,width?:float}> $columns
     *  @param list<mixed> $row
     */
    private function drawRow(array $columns, array $row): void
    {
        if ($columns === []) {
            return;
        }

        $widths = $this->columnWidths($columns);
        $cellLines = [];
        $lineCount = 1;
        foreach ($columns as $index => $column) {
            $text = $this->value($row[$index] ?? null);
            $lines = $this->wrap($text, $this->characterWidth($widths[$index]));
            $cellLines[] = $lines;
            $lineCount = max($lineCount, count($lines));
        }

        $offset = 0;
        while ($offset < $lineCount) {
            if ($this->cursorY - self::LINE_HEIGHT < self::BOTTOM) {
                $this->newPage(true);
                $this->drawTableHeader($columns);
            }

            $availableLines = max(1, (int) floor(($this->cursorY - self::BOTTOM - 6) / self::LINE_HEIGHT));
            $segmentLines = min($lineCount - $offset, $availableLines);
            $rowHeight = ($segmentLines * self::LINE_HEIGHT) + 7;
            $this->fillRect(self::MARGIN, $this->cursorY - $rowHeight + 2, $this->contentWidth, $rowHeight, ($offset % 2 === 0) ? 'FFF9F2' : 'FFFDF9');

            $x = self::MARGIN;
            foreach ($widths as $columnIndex => $width) {
                for ($line = 0; $line < $segmentLines; $line++) {
                    $value = $cellLines[$columnIndex][$offset + $line] ?? '';
                    if ($value !== '') {
                        $baseline = $this->cursorY - 8 - ($line * self::LINE_HEIGHT);
                        $this->text($x + 5, $baseline, $value, 7, '4D3329');
                    }
                }
                $x += $width;
            }

            $this->line(self::MARGIN, $this->cursorY - $rowHeight + 2, self::MARGIN + $this->contentWidth, $this->cursorY - $rowHeight + 2, 'E8D8C8');
            $this->cursorY -= $rowHeight;
            $offset += $segmentLines;
        }
    }

    /** @param list<array{label:string,width?:float}> $columns
     *  @return list<float>
     */
    private function columnWidths(array $columns): array
    {
        $weights = array_map(fn (array $column): float => max(0.04, (float) ($column['width'] ?? 1)), $columns);
        $total = array_sum($weights) ?: 1;

        return array_map(fn (float $weight): float => $this->contentWidth * ($weight / $total), $weights);
    }

    private function characterWidth(float $width): int
    {
        return max(4, (int) floor(($width - 10) / 3.6));
    }

    /** @return list<string> */
    private function wrap(string $value, int $width): array
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $ascii = preg_replace('/[\r\n\t]+/', ' ', $ascii) ?? $ascii;
        $ascii = preg_replace('/\s{2,}/', ' ', trim($ascii)) ?? trim($ascii);
        if ($ascii === '') {
            return ['-'];
        }

        return explode("\n", wordwrap($ascii, max(4, $width), "\n", true));
    }

    private function value(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    private function truncate(string $value, int $length): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return mb_strimwidth($ascii, 0, max(8, $length), '...');
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
        $pageCount = count($this->pages);
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => ''];
        $pageIds = [];
        $fontRegular = 3 + ($pageCount * 2);
        $fontBold = $fontRegular + 1;

        foreach ($this->pages as $index => $stream) {
            $pageNumber = $index + 1;
            $stream .= "\n".$this->textCommand(self::MARGIN, 27, 'LIKHAE '.(string) ($this->report['workspace'] ?? 'Workspace').' | Page '.$pageNumber.' of '.$pageCount, 6.5, '80675A', 'F1');
            $stream .= "\n".$this->textCommand($this->pageWidth - 170, 27, 'Generated '.(string) ($this->report['generated_at'] ?? ''), 6.5, '80675A', 'F1');

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

    private function textCommand(float $x, float $y, string $value, float $size, string $hex, string $font = 'F1'): string
    {
        return sprintf('%s rg BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET', $this->color($hex), $font, $size, $x, $y, $this->pdfString($value));
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
