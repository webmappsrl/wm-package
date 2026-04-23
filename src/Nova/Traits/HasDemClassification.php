<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Traits;

trait HasDemClassification
{
    /**
     * Classifica il valore corrente di un campo in base alle sorgenti DEM/OSM/MANUAL.
     *
     * Priorità:
     * 1. MANUAL — se manual_data[field] non è null e non è stringa vuota
     * 2. OSM    — se osmid !== null e osm_data[field] non è null
     * 3. DEM    — se dem_data[field] non è null
     * 4. EMPTY  — nessuna sorgente disponibile
     *
     * @return array{indicator: string, demValue: mixed, osmValue: mixed, manualValue: mixed, currentValue: mixed}
     */
    public function classifyField(object $model, string $field): array
    {
        $demData = $this->safeArray($model->properties['dem_data'] ?? null);
        $osmData = $this->safeArray($model->properties['osm_data'] ?? null);
        $manualData = $this->safeArray($model->properties['manual_data'] ?? null);

        $demValue = $demData[$field] ?? null;
        $osmValue = $osmData[$field] ?? null;
        $manualValue = $manualData[$field] ?? null;
        $osmid = $model->osmid ?? null;

        $manualIsBlank = $manualValue === null || $manualValue === '';

        if (! $manualIsBlank) {
            return [
                'indicator' => 'MANUAL',
                'demValue' => $demValue,
                'osmValue' => $osmValue,
                'manualValue' => $manualValue,
                'currentValue' => $manualValue,
            ];
        }

        if ($osmid !== null && $osmValue !== null) {
            return [
                'indicator' => 'OSM',
                'demValue' => $demValue,
                'osmValue' => $osmValue,
                'manualValue' => $manualValue,
                'currentValue' => $osmValue,
            ];
        }

        if ($demValue !== null) {
            return [
                'indicator' => 'DEM',
                'demValue' => $demValue,
                'osmValue' => $osmValue,
                'manualValue' => $manualValue,
                'currentValue' => $demValue,
            ];
        }

        return [
            'indicator' => 'EMPTY',
            'demValue' => $demValue,
            'osmValue' => $osmValue,
            'manualValue' => $manualValue,
            'currentValue' => null,
        ];
    }

    /**
     * Genera la tabella HTML per il detail view di Nova.
     * La colonna OSM appare solo se $model->osmid non è null.
     */
    public function generateFieldTable(object $model, string $field): string
    {
        $data = $this->classifyField($model, $field);
        $indicator = $data['indicator'];
        $currentValue = $data['currentValue'];
        $showOsm = ($model->osmid ?? null) !== null;

        $th = 'style="border:1px solid #ddd;padding:4px;text-align:center;white-space:nowrap;"';
        $td = 'style="border:1px solid #ddd;padding:4px;text-align:center;white-space:nowrap;"';

        $html = '<table style="border-collapse:collapse;width:auto;min-width:400px;">';
        $html .= '<tr>';
        $html .= "<th {$th}>DEM</th>";
        if ($showOsm) {
            $html .= "<th {$th}>OSM</th>";
        }
        $html .= "<th {$th}>MANUAL</th>";
        $html .= "<th {$th}>CURRENT VALUE ({$indicator})</th>";
        $html .= '</tr><tr>';
        $html .= "<td {$td}>".(string) ($data['demValue'] ?? '').'</td>';
        if ($showOsm) {
            $html .= "<td {$td}>".(string) ($data['osmValue'] ?? '').'</td>';
        }
        $html .= "<td {$td}>".(string) ($data['manualValue'] ?? '').'</td>';
        $html .= "<td {$td}>".(string) ($currentValue ?? '').'</td>';
        $html .= '</tr></table>';

        return $html;
    }

    /**
     * Converte in modo sicuro un valore in array.
     * Se è una stringa JSON la decodifica. Se è già un array lo ritorna.
     * Se è null o malformato ritorna [].
     *
     * @return array<string, mixed>
     */
    private function safeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
