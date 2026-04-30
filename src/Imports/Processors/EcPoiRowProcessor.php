<?php

namespace Wm\WmPackage\Imports\Processors;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Imports\AbstractExcelSpreadsheetImporter;
use Wm\WmPackage\Imports\Concerns\NormalizesSpreadsheetInput;
use Wm\WmPackage\Models\TaxonomyPoiType;

/**
 * Applica una "riga dati" EcPoi al modello (stessa logica per import Excel e GeoJSON).
 *
 * Le chiavi della riga seguono le intestazioni di
 * {@see config('wm-excel-ec-import.ecPois.validHeaders')} normalizzate a lower snake_case.
 */
final class EcPoiRowProcessor
{
    use NormalizesSpreadsheetInput;

    /**
     * @return string[] intestazioni valide normalizzate (lower snake_case)
     */
    public static function validHeaders(): array
    {
        return AbstractExcelSpreadsheetImporter::ecPoiImportNormalizedValidHeaders();
    }

    /**
     * Valida i campi minimi di una riga POI; restituisce stringa errore o null.
     *
     * @param  array<string, mixed>  $data
     */
    public function validate(array $data): ?string
    {
        $nameIt = self::normalizeCellValue($data['name_it'] ?? null);
        $poiType = self::normalizeCellValue($data['poi_type'] ?? null);
        $lat = self::normalizeCellValue($data['lat'] ?? null);
        $lng = self::normalizeCellValue($data['lng'] ?? null);

        if ($nameIt === null || $nameIt === '') {
            return 'Validation error: name_it is required.';
        }
        if ($poiType === null || $poiType === '') {
            return 'Validation error: poi_type is required.';
        }
        if ($lat === null || $lat === '' || $lng === null || $lng === '') {
            return 'Validation error: lat and lng are required.';
        }
        $latN = is_string($lat) ? str_replace(',', '.', $lat) : $lat;
        $lngN = is_string($lng) ? str_replace(',', '.', $lng) : $lng;
        if (! is_numeric($latN) || ! is_numeric($lngN)) {
            return 'Validation error: lat and lng must be numeric.';
        }

        $related = self::normalizeCellValue($data['related_url'] ?? null);
        if (is_string($related) && $related !== '') {
            $t = trim($related);
            if (str_starts_with($t, '{') || str_starts_with($t, '[')) {
                try {
                    json_decode($t, true, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    return 'Validation error: related_url JSON is invalid.';
                }
            } else {
                foreach ($this->splitCsvIdentifiers($related) as $part) {
                    if ($part !== '' && ! str_starts_with($part, 'http://') && ! str_starts_with($part, 'https://')) {
                        return 'Validation error: related_url must start with http:// or https://';
                    }
                }
            }
        } elseif (is_array($related) && $related !== []) {
            foreach ($related as $v) {
                if (! is_string($v) || ($v !== '' && ! str_starts_with($v, 'http://') && ! str_starts_with($v, 'https://'))) {
                    return 'Validation error: related_url must start with http:// or https://';
                }
            }
        }

        $poiTypeIdentifiers = $this->splitCsvIdentifiers((string) $poiType);
        if ($poiTypeIdentifiers === []) {
            return 'Validation error: poi_type must contain at least one identifier.';
        }
        $foundPoiTypes = TaxonomyPoiType::query()->whereIn('identifier', $poiTypeIdentifiers)->pluck('identifier')->all();
        $missingPoiTypes = array_values(array_diff($poiTypeIdentifiers, $foundPoiTypes));
        if ($missingPoiTypes !== []) {
            return 'Validation error: invalid poi_type identifiers: '.implode(', ', $missingPoiTypes);
        }

        return null;
    }

    /**
     * Trascrive i valori nella entity (no persist). Se lat/lng presenti setta la geometry POINT Z.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(Model $model, array $data): void
    {
        $validHeaders = self::validHeaders();

        $properties = $model->getAttribute('properties');
        $properties = is_array($properties) ? $properties : (array) $properties;

        $lat = null;
        $lng = null;

        foreach ($data as $key => $value) {
            if (! in_array($key, $validHeaders, true) || $key === 'id') {
                continue;
            }

            // Colonna di feedback import; non scrivere nelle properties del modello.
            if ($key === 'errors') {
                continue;
            }

            $value = self::normalizeCellValue($value);
            if ($value === null || $value === '') {
                continue;
            }

            if ($key === 'lat') {
                $lat = is_string($value) ? str_replace(',', '.', $value) : $value;

                continue;
            }
            if ($key === 'lng') {
                $lng = is_string($value) ? str_replace(',', '.', $value) : $value;

                continue;
            }

            if ($key === 'name_it') {
                if (method_exists($model, 'setTranslation')) {
                    $model->setTranslation('name', 'it', (string) $value);
                } else {
                    $properties['name_it'] = $value;
                }

                continue;
            }
            if ($key === 'name_en') {
                if (method_exists($model, 'setTranslation')) {
                    $model->setTranslation('name', 'en', (string) $value);
                } else {
                    $properties['name_en'] = $value;
                }

                continue;
            }

            if ($key === 'description_it') {
                $properties['description'] = $this->setLocaleValue($properties['description'] ?? [], 'it', $value);

                continue;
            }
            if ($key === 'description_en') {
                $properties['description'] = $this->setLocaleValue($properties['description'] ?? [], 'en', $value);

                continue;
            }
            if ($key === 'excerpt_it') {
                $properties['excerpt'] = $this->setLocaleValue($properties['excerpt'] ?? [], 'it', $value);

                continue;
            }
            if ($key === 'excerpt_en') {
                $properties['excerpt'] = $this->setLocaleValue($properties['excerpt'] ?? [], 'en', $value);

                continue;
            }

            // addr_complete, capacity, contact_*, related_url: solo in properties (schema ec_pois senza colonne dedicate).
            if (in_array($key, ['addr_complete', 'capacity', 'contact_phone', 'contact_email'], true)) {
                $properties[$key] = $value;

                continue;
            }

            if ($key === 'related_url') {
                $existingRel = $properties['related_url'] ?? null;
                $properties['related_url'] = $this->mergeRelatedUrl($value, $existingRel);

                continue;
            }

            // Tutto il resto (poi_type, gallery, feature_image, ecc.) finisce in properties.
            $properties[$key] = $value;
        }

        if ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng)) {
            $latF = (float) $lat;
            $lngF = (float) $lng;
            $model->setAttribute('geometry', "POINT Z ({$lngF} {$latF} 0)");
        }

        $model->setAttribute('properties', $properties);
    }

    /**
     * Sincronizza la pivot taxonomyPoiTypes dai poi_type della riga.
     *
     * @param  array<string, mixed>  $data
     */
    public function syncTaxonomyPoiTypes(Model $model, array $data): void
    {
        if (! method_exists($model, 'taxonomyPoiTypes')) {
            return;
        }

        $poiType = self::normalizeCellValue($data['poi_type'] ?? null);
        if ($poiType === null || $poiType === '') {
            return;
        }

        $identifiers = $this->splitCsvIdentifiers((string) $poiType);
        if ($identifiers === []) {
            return;
        }

        $ids = TaxonomyPoiType::query()
            ->whereIn('identifier', $identifiers)
            ->pluck('id')
            ->all();

        $model->taxonomyPoiTypes()->sync($ids);
    }

    /**
     * @return string[]
     */
    private function splitCsvIdentifiers(string $value): array
    {
        $parts = array_map('trim', explode(',', $value));
        $parts = array_filter($parts, static fn ($p) => $p !== '');

        return array_values(array_unique($parts));
    }

    private function setLocaleValue(mixed $current, string $locale, mixed $value): array
    {
        $arr = is_array($current) ? $current : [];
        $arr[$locale] = $value;

        return $arr;
    }

    private function tryJsonDecode(string $value): mixed
    {
        $value = trim($value);
        if ($value === '' || (! str_starts_with($value, '{') && ! str_starts_with($value, '['))) {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                return $decoded;
            }
        } catch (\Throwable $e) {
        }

        return null;
    }

    /**
     * Merge di related_url (Nova KeyValue): accetta string, array associativo o lista di URL.
     *
     * @return array<string, string>
     */
    private function mergeRelatedUrl(mixed $incoming, mixed $existing): array
    {
        if (is_array($incoming)) {
            return $this->normalizeRelatedUrlToAssoc($incoming);
        }

        $incoming = is_scalar($incoming) ? (string) $incoming : '';
        $incoming = trim($incoming);
        if ($incoming === '') {
            return $this->normalizeRelatedUrlToAssoc($existing);
        }

        $decoded = $this->tryJsonDecode($incoming);
        if (is_array($decoded) && $decoded !== []) {
            return $this->normalizeRelatedUrlToAssoc($decoded);
        }

        $newUrls = $this->splitCsvIdentifiers($incoming);
        if ($newUrls === []) {
            return $this->normalizeRelatedUrlToAssoc($existing);
        }

        $existingAssoc = $this->normalizeRelatedUrlToAssoc($existing);
        $keys = array_keys($existingAssoc);

        if ($keys === []) {
            $out = [];
            foreach ($newUrls as $u) {
                $out[$u] = $u;
            }

            return $out;
        }

        if (count($newUrls) === 1) {
            $u = $newUrls[0];
            $out = [];
            foreach ($keys as $k) {
                $out[$k] = $u;
            }

            return $out;
        }

        $out = [];
        $nKeys = count($keys);
        $nNew = count($newUrls);
        for ($i = 0; $i < $nKeys; $i++) {
            $out[$keys[$i]] = $newUrls[$i] ?? $newUrls[$nNew - 1];
        }
        for ($j = $nKeys; $j < $nNew; $j++) {
            $u = $newUrls[$j];
            $out[$u] = $u;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeRelatedUrlToAssoc(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (! (is_string($v) || is_numeric($v))) {
                    continue;
                }
                if (is_string($k)) {
                    $out[$k] = (string) $v;
                } else {
                    $s = (string) $v;
                    if ($s !== '') {
                        $out[$s] = $s;
                    }
                }
            }

            return $out;
        }
        if (! is_string($value)) {
            return [];
        }
        $t = trim($value);
        if ($t === '') {
            return [];
        }
        if (str_starts_with($t, '{')) {
            $d = json_decode($t, true);
            if (is_array($d)) {
                return $this->normalizeRelatedUrlToAssoc($d);
            }
        }
        if (str_starts_with($t, 'http://') || str_starts_with($t, 'https://')) {
            return [$t => $t];
        }

        return [];
    }
}
