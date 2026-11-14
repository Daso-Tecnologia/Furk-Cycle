<?php

declare(strict_types=1);

namespace FurkiCycle\Webhook;

use RuntimeException;

final class WebhookExportService
{
    public const EXCEL_MAX_ROWS = 1048576;

    public const COLUMNS = [
        'Nota',
        'ID Furk',
        'Furk status',
        'Status webhook',
        'Processamento',
        'HTTP',
        'Últ. callback',
        'Últ. verificação',
        'Próx. verificação',
    ];

    public function assertExportable(int $total): void
    {
        if ($total > self::EXCEL_MAX_ROWS) {
            throw new RuntimeException(
                "A consulta retorna {$total} registros, o que ultrapassa a capacidade de uma planilha "
                . '(máximo ' . number_format(self::EXCEL_MAX_ROWS, 0, ',', '.') . '). '
                . 'Use o filtro de busca antes de exportar.',
            );
        }
    }

    /** @param array<int, array<string, mixed>> $rows
     *  @return array{filename: string, bytes: string} */
    public function export(array $rows): array
    {
        return ['filename' => 'webhook_' . gmdate('Ymd_His') . '.xlsx', 'bytes' => $this->buildXlsx($rows)];
    }

    private function buildXlsx(array $rows): string
    {
        $sheetRows = [];
        $headerCells = [];
        $rowNumber = 1;
        foreach (self::COLUMNS as $index => $column) {
            $escaped = $this->escapeXml($column);
            $headerCells[] = '<c r="' . $this->columnLetter($index) . $rowNumber . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
        }
        $sheetRows[] = '<row r="' . $rowNumber . '">' . implode('', $headerCells) . '</row>';
        foreach ($rows as $row) {
            $rowNumber++;
            $cells = [];
            foreach (self::COLUMNS as $index => $column) {
                $cells[] = $this->cellFor($index, $rowNumber, $row);
            }
            $sheetRows[] = '<row r="' . $rowNumber . '">' . implode('', $cells) . '</row>';
        }

        $dimension = 'A1:' . $this->columnLetter(count(self::COLUMNS) - 1) . $rowNumber;

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="' . $dimension . '"/>'
            . '<sheetData>' . implode('', $sheetRows) . '</sheetData></worksheet>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Webhook" sheetId="1" r:id="rId1"/></sheets></workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';

        $tempDir = getenv('TEMP') ?? '/tmp';
        $tempPath = $tempDir . '/furk_webhook_export_' . time() . '_' . rand(1000, 9999) . '.xlsx';
        $zip = new \ZipArchive();
        if ($zip->open($tempPath, 1) !== true) {
            throw new RuntimeException('Não foi possível criar o arquivo XLSX temporal.');
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        try {
            $bytes = file_get_contents($tempPath);
        } finally {
            try {
                unlink($tempPath);
            } catch (Throwable) {
            }
        }

        return $bytes;
    }

    private function cellFor(int $index, int $rowNumber, array $row): string
    {
        $reference = $this->columnLetter($index) . $rowNumber;
        $value = match ($index) {
            0 => $row['nota'] ?? null,
            1 => $row['furk_rebooking_id'] ?? null,
            2 => $row['furk_status'] ?? null,
            3 => $row['last_webhook_status'] ?? null,
            4 => $row['processing_status'] ?? null,
            5 => $row['http_status'] ?? null,
            6 => $row['last_webhook_datetime'] ?? null,
            7 => $row['last_checked_at'] ?? null,
            8 => $row['next_check_at'] ?? null,
            default => null,
        };

        if ($value === null || $value === '') {
            return '<c r="' . $reference . '" t="inlineStr"><is><t></t></is></c>';
        }
        if (is_int($value) || is_float($value)) {
            return '<c r="' . $reference . '"><v>' . $value . '</v></c>';
        }
        $text = $this->escapeXml((string) $value);

        return '<c r="' . $reference . '" t="inlineStr"><is><t>' . $text . '</t></is></c>';
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $letter = chr(65 + (($index - 1) % 26)) . $letter;
            $index = intdiv($index - 1, 26);
        }

        return $letter;
    }

    private function escapeXml(string $text): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
        $escaped = '';
        foreach (str_split($clean) as $character) {
            $escaped .= match ($character) {
                '&' => '&amp;',
                '<' => '&lt;',
                '>' => '&gt;',
                default => $character,
            };
        }

        return $escaped;
    }
}
