<?php

namespace Wm\WmPackage\Imports\Processors;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Imports\AbstractExcelSpreadsheetImporter;
use Wm\WmPackage\Imports\Concerns\NormalizesSpreadsheetInput;

/**
 * Applica una "riga dati" EcTrack al modello (stessa logica per import Excel e GeoJSON).
 *
 * La riga dati ha chiavi in snake_case allineate a {@see config('wm-excel-ec-import.ecTracks.validHeaders')}.
 */
final class EcTrackRowProcessor
{
    use NormalizesSpreadsheetInput;

    /**
     * @return string[] intestazioni valide normalizzate (lower snake_case)
     */
    public static function validHeaders(): array
    {
        return AbstractExcelSpreadsheetImporter::normalizedValidHeadersFromConfig(
            'wm-excel-ec-import.ecTracks.validHeaders'
        );
    }

    /**
     * Trascrive i valori della riga nelle properties del modello (no persist).
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(Model $model, array $data): void
    {
        $validHeaders = self::validHeaders();

        $properties = $model->getAttribute('properties');
        $properties = is_array($properties) ? $properties : (array) $properties;

        foreach ($data as $key => $value) {
            if (! in_array($key, $validHeaders, true) || $key === 'id') {
                continue;
            }

            $value = self::normalizeCellValue($value);
            if ($value === null || $value === '') {
                continue;
            }

            if ($key === 'distance') {
                $value = is_string($value) ? str_replace(',', '.', $value) : $value;
                if (is_string($value) && str_contains($value, 'km')) {
                    $value = str_replace('km', '', $value);
                }
                $value = is_string($value) ? trim($value) : $value;
            }

            $properties[$key] = $value;
        }

        // Flag sempre settato in fase di import (comportamento storico Geohub) per segnalare al geomixer di saltare i ricalcoli tech.
        $properties['skip_geomixer_tech'] = true;

        $model->setAttribute('properties', $properties);
    }
}
