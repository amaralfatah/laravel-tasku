<?php

namespace App\Concerns;

use Illuminate\Support\Facades\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands a built workbook back as a download.
 *
 * Streamed rather than written to a temporary file: the sheets are already in
 * memory, and nothing here is worth keeping on disk.
 */
trait StreamsWorkbook
{
    protected function streamWorkbook(Spreadsheet $spreadsheet, string $subject): StreamedResponse
    {
        $name = trim(preg_replace('/[^\p{L}\p{N} \-_]+/u', '', $subject) ?? '');
        $filename = sprintf(
            'Project Management - %s - %s.xlsx',
            $name === '' ? 'Export' : $name,
            Date::now()->format('Y-m-d'),
        );

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }
}
