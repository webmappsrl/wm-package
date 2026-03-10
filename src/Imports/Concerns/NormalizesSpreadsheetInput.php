<?php

namespace Wm\WmPackage\Imports\Concerns;

use Illuminate\Support\Str;

/**
 * Helper di normalizzazione usati sia dagli importer Maatwebsite (riga Excel/CSV)
 * sia dai processor di trasformazione chiamati da altri flussi (es. GeoJSON).
 */
trait NormalizesSpreadsheetInput
{
    /**
     * @param  array<string|int, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeKeys(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            $key = is_string($key) ? trim($key) : (string) $key;
            $key = Str::of($key)->lower()->replace(' ', '_')->toString();
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    public static function normalizeCellValue(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
        }

        if (is_string($value) && in_array(strtoupper($value), ['NULL', 'N/A', 'NA'], true)) {
            return null;
        }

        return $value;
    }
}
