<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Csv_export {
    public function download(string $filename, array $columns, array $rows): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $stream = fopen('php://output', 'w');
        fputcsv($stream, $columns, ',', '"', '');
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $value = (string) ($row[$column] ?? '');
                // Catalog names are untrusted spreadsheet text, never formulas.
                if (preg_match('/^[\s]*[=+\-@]|^[\t\r\n]/u', $value)) $value = "'" . $value;
                $cells[] = $value;
            }
            fputcsv($stream, $cells, ',', '"', '');
        }
        fclose($stream);
        exit;
    }
}
