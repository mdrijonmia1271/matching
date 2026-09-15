<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** Streams large CSV downloads row by row, so exports never load everything into memory. */
final class CsvExport
{
    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');

            // A byte-order mark makes Excel read UTF-8 (Bangla text, ৳) correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::safe(...), $row), ',', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Text starting with = + - @ would run as a spreadsheet formula; prefix it with an apostrophe. */
    protected static function safe(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
