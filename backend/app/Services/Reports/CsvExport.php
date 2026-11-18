<?php

namespace App\Services\Reports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** Streams report rows as an Excel-friendly CSV: UTF-8 BOM, header row, formula-injection protection, row cap. */
final class CsvExport
{
    public const MAX_ROWS = 10000;

    /** A cell a spreadsheet could run as a formula gets a leading apostrophe. Numbers are left alone. */
    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (! is_string($value)) {
            return (string) $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    public static function response(string $filename, array $headers, array $rows, bool $truncated): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map([self::class, 'cell'], $headers));
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'cell'], $row));
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Export-Row-Limit' => (string) self::MAX_ROWS,
            'X-Export-Truncated' => $truncated ? 'true' : 'false',
            'Access-Control-Expose-Headers' => 'X-Export-Row-Limit, X-Export-Truncated, Content-Disposition',
        ]);
    }
}
