<?php
define('PREVENT_DIRECT_ACCESS', TRUE);
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/libraries/Report_export.php';
ini_set('zend.exception_ignore_args', '1');
$checks = 0;
function export_check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('Failed: ' . $name); $checks++; }
$output = getenv('CFABS_EXPORT_TEST_OUTPUT') ?: sys_get_temp_dir() . '/cfabs-export-' . bin2hex(random_bytes(6));
if (!is_dir($output)) mkdir($output, 0700, TRUE);
$files = [];
try {
    $export = new Report_export();
    $columns = ['resource_id','asset_code','asset_name','tracking_mode','category_id','owning_department_id','status','registered_quantity','usable_quantity'];
    $rows = [];
    for ($i = 1; $i <= 80; $i++) $rows[] = ['resource_id' => (string) (9007199254740991 + $i), 'asset_code' => $i === 1 ? '=HYPERLINK("https://example.invalid")' : 'AST-' . str_pad($i, 4, '0', STR_PAD_LEFT),
        'asset_name' => $i === 1 ? '<script>alert(1)</script> Niño’s projector & classroom equipment' : 'Portable presentation equipment for campus events - equipment group ' . $i,
        'tracking_mode' => 'quantity', 'category_id' => '00123', 'owning_department_id' => '00004', 'status' => 'active', 'registered_quantity' => 20, 'usable_quantity' => 18];
    $xlsx = $export->render('xlsx', 'inventory', $columns, $rows, ['status' => 'active']);
    export_check($xlsx['mime'] === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' && str_starts_with($xlsx['bytes'], 'PK'), 'real XLSX ZIP container');
    $file = $output . '/report-export-sample.xlsx'; $files[] = $file; file_put_contents($file, $xlsx['bytes']);
    $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($file); $sheet = $book->getActiveSheet();
    export_check($sheet->getCell('B8')->getDataType() === 's' && $sheet->getCell('B8')->getValue() === $rows[0]['asset_code'], 'formula-like names remain literal strings');
    export_check($sheet->getCell('C8')->getValue() === $rows[0]['asset_name'], 'HTML and Unicode remain literal spreadsheet text');
    export_check($sheet->getCell('A8')->getDataType() === 's' && $sheet->getCell('A8')->getValue() === $rows[0]['resource_id'], 'large IDs preserve exact bytes');
    export_check($sheet->getCell('E8')->getValue() === '00123', 'ID leading zeros preserved');
    export_check($sheet->getCell('H8')->getDataType() === 'n' && $sheet->getCell('H8')->getValue() === 20, 'quantities remain usable numeric cells');
    export_check($sheet->getHighestRow() === 87 && $sheet->getFreezePane() === 'A8', 'all data rows and frozen header');
    export_check($sheet->getAutoFilter()->getRange() === 'A7:I87', 'filtered full worksheet');
    export_check(str_contains($sheet->getCell('A4')->getValue(), 'status=active'), 'applied filters included');
    $book->disconnectWorksheets();
    $pdf = $export->render('pdf', 'inventory', $columns, $rows, ['status' => 'active']);
    export_check($pdf['mime'] === 'application/pdf' && str_starts_with($pdf['bytes'], '%PDF-'), 'real PDF file');
    $file = $output . '/report-export-sample.pdf'; $files[] = $file; file_put_contents($file, $pdf['bytes']);
    foreach (['facility','asset'] as $kind) {
        $report = $export->render('xlsx', $kind, ['resource_id','resource_name','booking_count','reserved_seconds'], [['resource_id' => 1, 'resource_name' => 'Hall A', 'booking_count' => 2, 'reserved_seconds' => 3600]], ['status' => 'APPROVED'], ['2026-11-01 00:00:00','2026-11-02 00:00:00']);
        export_check(str_starts_with($report['bytes'], 'PK'), $kind . ' booking XLSX');
    }
    foreach (['pdf','xlsx'] as $format) {
        $empty = $export->render($format, 'inventory', $columns, []);
        export_check(strlen($empty['bytes']) > 100, $format . ' supports empty results');
        try { $export->render($format, 'inventory', $columns, array_fill(0, $format === 'pdf' ? 501 : 10001, $rows[0])); throw new RuntimeException('Missing row cap'); }
        catch (DomainException $error) { export_check($error->getCode() === 422, $format . ' row cap enforced before generation'); }
    }
    echo "Passed {$checks} export format and safety checks.\n";
} finally {
    if (!getenv('CFABS_EXPORT_TEST_OUTPUT')) { foreach ($files as $file) if (is_file($file)) unlink($file); rmdir($output); }
}
