<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Dompdf\Dompdf;
use Dompdf\Options;

class Report_export {
    private const TITLES = ['facility' => 'Facility booking summary', 'asset' => 'Asset booking summary', 'inventory' => 'Asset inventory'];
    private const HEADERS = ['resource_id' => 'Resource ID', 'resource_name' => 'Resource name', 'booking_count' => 'Bookings',
        'reserved_seconds' => 'Reserved seconds', 'asset_code' => 'Asset code', 'asset_name' => 'Asset name', 'tracking_mode' => 'Tracking',
        'category_id' => 'Category ID', 'owning_department_id' => 'Dept. ID', 'status' => 'Status',
        'registered_quantity' => 'Registered units', 'usable_quantity' => 'Usable units'];

    public function render(string $format, string $kind, array $columns, array $rows, array $filters = [], ?array $period = null): array
    {
        if (!isset(self::TITLES[$kind]) || !in_array($format, ['xlsx','pdf'], TRUE)) throw new InvalidArgumentException('Unsupported report export.');
        if (count($rows) > ($format === 'pdf' ? 500 : 10000)) throw new DomainException('Narrow the filters to export at most ' . ($format === 'pdf' ? '500 resources to PDF.' : '10000 resources to Excel.'), 422);
        if (!$columns || array_diff($columns, array_keys(self::HEADERS))) throw new InvalidArgumentException('Unsupported report columns.');
        $zone = new DateTimeZone('Asia/Manila');
        $date = fn($value) => (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone($zone)->format('M j, Y H:i:s');
        $metadata = [
            'Period: ' . ($period ? $date($period[0]) . ' to ' . $date($period[1]) . ' (Asia/Manila)' : 'Current inventory snapshot'),
            'Generated: ' . (new DateTimeImmutable('now', $zone))->format('M j, Y H:i:s') . ' (Asia/Manila)',
            'Filters: ' . ($filters ? implode('; ', array_map(fn($key, $value) => $key . '=' . $value, array_keys($filters), array_values($filters))) : 'All permitted records'),
            'Resource rows: ' . count($rows),
        ];
        return ['mime' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf',
            'bytes' => $format === 'xlsx' ? $this->excel($kind, $columns, $rows, $metadata) : $this->pdf($kind, $columns, $rows, $metadata)];
    }

    private function excel(string $kind, array $columns, array $rows, array $metadata): string
    {
        $book = new Spreadsheet();
        try {
            $book->getProperties()->setCreator('CFABS')->setTitle(self::TITLES[$kind]);
            $sheet = $book->getActiveSheet()->setTitle('Report');
            $last = Coordinate::stringFromColumnIndex(count($columns));
            foreach (array_merge(['CFABS | ' . self::TITLES[$kind]], $metadata) as $index => $text) {
                $row = $index + 1;
                $sheet->mergeCells("A{$row}:{$last}{$row}")->setCellValueExplicit("A{$row}", $text, DataType::TYPE_STRING);
            }
            $sheet->getStyle('A1')->getFont()->setBold(TRUE)->setSize(17);
            $sheet->getStyle('A2:A5')->getFont()->setSize(10);
            $sheet->getStyle("A1:{$last}5")->getAlignment()->setWrapText(TRUE);
            $sheet->getRowDimension(1)->setRowHeight(28);
            $sheet->getRowDimension(4)->setRowHeight(30);
            foreach ($columns as $index => $column) {
                $letter = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->setCellValueExplicit($letter . '7', self::HEADERS[$column], DataType::TYPE_STRING);
                $sheet->getColumnDimension($letter)->setWidth(in_array($column, ['resource_name','asset_name'], TRUE) ? 44 : ($column === 'asset_code' ? 26 : 19));
            }
            foreach ($rows as $index => $record) foreach ($columns as $offset => $column) {
                $value = (string) ($record[$column] ?? '');
                $numeric = in_array($column, ['booking_count','reserved_seconds','registered_quantity','usable_quantity'], TRUE)
                    && preg_match('/\A\d{1,15}\z/', $value);
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($offset + 1) . ($index + 8), $numeric ? (int) $value : $value, $numeric ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
            $end = max(8, count($rows) + 7);
            $sheet->getStyle("A7:{$last}7")->applyFromArray(['font' => ['bold' => TRUE, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '145D50']]]);
            $sheet->getRowDimension(7)->setRowHeight(28);
            $sheet->getStyle("A7:{$last}{$end}")->getAlignment()->setWrapText(TRUE)->setVertical('top');
            if (!$rows) $sheet->setCellValueExplicit('A8', 'No matching records.', DataType::TYPE_STRING);
            $sheet->freezePane('A8')->setAutoFilter("A7:{$last}" . max(7, count($rows) + 7));
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(FALSE);
            $stream = fopen('php://memory', 'w+b');
            if (!$stream) throw new RuntimeException('Could not allocate export buffer.');
            try { $writer->save($stream); rewind($stream); return stream_get_contents($stream); }
            finally { fclose($stream); }
        } finally { $book->disconnectWorksheets(); }
    }

    private function pdf(string $kind, array $columns, array $rows, array $metadata): string
    {
        $escape = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>
            @page { margin: 30pt 28pt 38pt; } body { font-family: DejaVu Sans, sans-serif; color: #182c29; font-size: 8pt; }
            h1 { font-size: 18pt; margin: 0 0 9pt; color: #145d50; } p { margin: 3pt 0; }
            table { width: 100%; table-layout: fixed; border-collapse: collapse; margin-top: 15pt; }
            thead { display: table-header-group; } th { background: #145d50; color: white; text-align: left; }
            th, td { padding: 6pt 5pt; border-bottom: 1px solid #dce4de; overflow-wrap: break-word; word-wrap: break-word; }
            td { vertical-align: top; } tr { page-break-inside: avoid; } tbody tr:nth-child(even) { background: #f6f8f6; }
            .note { margin-top: 10pt; color: #5e716c; font-size: 7pt; }
            </style></head><body><h1>CFABS | ' . $escape(self::TITLES[$kind]) . '</h1>';
        foreach ($metadata as $line) $html .= '<p>' . $escape($line) . '</p>';
        $html .= '<table><thead><tr>';
        $widths = $kind === 'inventory' ? [12,13,24,8,7,8,8,10,10] : [16,50,14,20];
        foreach ($columns as $index => $column) $html .= '<th style="width:' . ($widths[$index] ?? 10) . '%">' . $escape(self::HEADERS[$column]) . '</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $record) {
            $html .= '<tr>';
            foreach ($columns as $column) $html .= '<td>' . $escape($record[$column] ?? '') . '</td>';
            $html .= '</tr>';
        }
        if (!$rows) $html .= '<tr><td colspan="' . count($columns) . '">No matching records.</td></tr>';
        $html .= '</tbody></table><p class="note">' . ($kind === 'inventory' ? 'Usable units are baseline inventory, not dated booking availability.' : 'Reserved seconds are clipped to the reporting period; this is not an occupancy percentage.') . '</p></body></html>';
        $options = new Options();
        $options->setIsRemoteEnabled(FALSE);
        $options->setIsPhpEnabled(FALSE);
        $options->setIsJavascriptEnabled(FALSE);
        $options->setAllowedProtocols([]);
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'landscape');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $pdf->getCanvas()->page_text(690, 568, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, [0.36, 0.44, 0.42]);
        return $pdf->output();
    }
}
