<?php

namespace Wm\WmPackage\Imports;

use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;
use Wm\WmPackage\Exporters\EcPoiExcelExporter;
use Wm\WmPackage\Imports\Concerns\NormalizesSpreadsheetInput;

/**
 * Comportamento condiviso per import Excel/CSV a righe (Maatwebsite Excel).
 */
abstract class AbstractExcelSpreadsheetImporter implements OnEachRow, SkipsEmptyRows, WithChunkReading, WithCustomCsvSettings, WithHeadingRow
{
    use NormalizesSpreadsheetInput;

    public function __construct(
        protected readonly bool $saveQuietly = true,
        protected readonly string $delimiter = ',',
        protected readonly string $enclosure = '"',
        protected readonly string $escape = '\\',
    ) {}

    public function chunkSize(): int
    {
        return 1000;
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => $this->delimiter,
            'enclosure' => $this->enclosure,
            'escape_character' => $this->escape,
        ];
    }

    abstract public function onRow(Row $row): void;

    /**
     * @param  string[]  $keys
     * @return string[]
     */
    public static function normalizedValidHeadersFromConfig(string $configKey): array
    {
        $headers = config($configKey, []);
        if (! is_array($headers)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $h) => Str::of((string) $h)->lower()->replace(' ', '_')->toString(),
            $headers
        ));
    }

    /**
     * Comma-separated header names as in config (for Nova help text).
     */
    public static function commaSeparatedValidHeadersForDisplay(string $configDotPath): string
    {
        $headers = config($configDotPath, []);
        if (! is_array($headers)) {
            return '';
        }

        return implode(', ', array_map(static fn (mixed $h) => (string) $h, $headers));
    }

    /**
     * Intestazioni import EcTrack come in {@see config('wm-excel-ec-import.ecTracks.validHeaders')},
     * con fallback se la config non è caricata o è vuota (es. cache config obsoleta).
     *
     * @return string[]
     */
    public static function ecTrackImportValidHeaderNames(): array
    {
        $headers = config('wm-excel-ec-import.ecTracks.validHeaders');
        if (is_array($headers) && $headers !== []) {
            return array_values(array_map(static fn (mixed $h) => (string) $h, $headers));
        }

        return [
            'id',
            'from',
            'to',
            'ele_from',
            'ele_to',
            'distance',
            'duration_forward',
            'duration_backward',
            'ascent',
            'descent',
            'ele_min',
            'ele_max',
            'difficulty',
        ];
    }

    /**
     * Elenco separato da virgole per testi d'aiuto Nova (import traccia).
     */
    public static function ecTrackImportValidHeadersCommaSeparated(): string
    {
        return implode(', ', self::ecTrackImportValidHeaderNames());
    }

    /**
     * Intestazioni import EcPoi: {@see config('wm-excel-ec-import.ecPois.validHeaders')} tramite {@see EcPoiExcelExporter::validHeaderNames()}.
     *
     * @return string[]
     */
    public static function ecPoiImportValidHeaderNames(): array
    {
        return EcPoiExcelExporter::validHeaderNames();
    }

    /**
     * Stesse chiavi di {@see self::ecPoiImportValidHeaderNames()} normalizzate (lower snake_case) come in foglio Excel.
     *
     * @return string[]
     */
    public static function ecPoiImportNormalizedValidHeaders(): array
    {
        $headers = self::ecPoiImportValidHeaderNames();

        return array_values(array_map(
            static fn (mixed $h) => Str::of((string) $h)->lower()->replace(' ', '_')->toString(),
            $headers
        ));
    }

    /**
     * Elenco separato da virgole per testi d'aiuto Nova (import POI).
     */
    public static function ecPoiImportValidHeadersCommaSeparated(): string
    {
        return implode(', ', self::ecPoiImportValidHeaderNames());
    }
}
