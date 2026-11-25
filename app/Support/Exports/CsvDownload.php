<?php

namespace App\Support\Exports;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Einheitlicher Transport fuer Datei-Downloads (CSV, Text, JSON).
 *
 * Der Inhalt wird unveraendert weitergereicht: Trennzeichen, BOM und Spalten
 * bestimmt allein der jeweilige Export-Service. Vereinheitlicht werden nur
 * Header und Dateiname.
 */
final class CsvDownload
{
    public const CSV = 'text/csv; charset=UTF-8';

    public const TEXT = 'text/plain; charset=UTF-8';

    public const JSON = 'application/json; charset=UTF-8';

    /**
     * Gestreamter CSV-Download (Livewire-Aktionen, Controller).
     */
    public static function stream(string $csv, string $filename): StreamedResponse
    {
        return self::streamContent($csv, $filename, self::CSV);
    }

    /**
     * Gestreamter Download beliebigen Inhalts mit einheitlichen Headern.
     */
    public static function streamContent(string $content, string $filename, string $contentType): StreamedResponse
    {
        return response()->streamDownload(static function () use ($content): void {
            echo $content;
        }, self::safeFilename($filename), self::headers($contentType));
    }

    /**
     * Nicht gestreamte CSV-Antwort (API-Endpunkte), gleiche Header wie stream().
     */
    public static function response(string $csv, string $filename): Response
    {
        $filename = self::safeFilename($filename);

        return response($csv, 200, self::headers(self::CSV) + [
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, $filename),
        ]);
    }

    /**
     * Baut CSV-Text mit fputcsv; die Parameter entsprechen fputcsv exakt.
     *
     * @param  array<int, mixed>  $header
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function fromRows(array $header, iterable $rows, string $separator = ';', string $enclosure = '"', string $escape = ''): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $header, $separator, $enclosure, $escape);
        foreach ($rows as $row) {
            fputcsv($stream, $row, $separator, $enclosure, $escape);
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * Dateiname nur aus ASCII-Buchstaben, Ziffern, Punkt, Binde- und Unterstrich.
     */
    public static function safeFilename(string $filename): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?? '';
        $filename = trim($filename, '.-');

        return $filename !== '' ? $filename : 'download';
    }

    /**
     * @return array<string, string>
     */
    public static function headers(string $contentType): array
    {
        return [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
