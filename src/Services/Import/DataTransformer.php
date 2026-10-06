<?php

namespace Wm\WmPackage\Services\Import;

use Carbon\Carbon;
use Illuminate\Support\Str;

class DataTransformer
{
    /**
     * Convert a JSON string to an array.
     */
    public function jsonToArray($json): array
    {
        // Se è già un array, restituiscilo direttamente
        if (is_array($json)) {
            return array_filter($json, fn ($value) => $value !== null && $value !== '');
        }

        // Se è null o vuoto, restituisci array vuoto
        if (! $json) {
            return [];
        }

        // Se è una stringa, decodifica JSON
        if (is_string($json)) {
            $arr = json_decode($json, true);

            return array_filter($arr, fn ($value) => $value !== null && $value !== '');
        }

        // Per altri tipi, restituisci array vuoto
        return [];
    }

    /**
     * Convert a JSON string to an array.
     */
    public function nullableJsonToArray($json): ?array
    {
        // Se è già un array, restituiscilo direttamente
        if (is_array($json)) {
            $filtered = array_filter($json, fn ($value) => $value !== null && $value !== '');

            return empty($filtered) ? null : $filtered;
        }

        // Se è null o vuoto, restituisci null
        if (! $json) {
            return null;
        }

        // Se è una stringa, decodifica JSON
        if (is_string($json)) {
            $arr = json_decode($json, true);
            $filtered = array_filter($arr, fn ($value) => $value !== null && $value !== '');

            return empty($filtered) ? null : $filtered;
        }

        // Per altri tipi, restituisci null
        return null;
    }

    /**
     * Converte related_url di Geohub (testo: oggetto JSON, indirizzo, "[]", "false"...) in
     * etichetta → url; null quando non contiene link: la chiave resta in properties con valore
     * null (oc:8679).
     *
     * Stessa logica di EcPoiRowProcessor::normalizeRelatedUrlToAssoc (import Excel): se cambi
     * una, cambia anche l'altra.
     */
    public function relatedUrlToArray($value): ?array
    {
        $normalized = $this->normalizeRelatedUrl($value);

        return $normalized === [] ? null : $normalized;
    }

    private function normalizeRelatedUrl(mixed $value): array
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
                return $this->normalizeRelatedUrl($d);
            }
        }
        if (str_starts_with($t, 'http://') || str_starts_with($t, 'https://')) {
            return [$t => $t];
        }

        return [];
    }

    /**
     * Convert a string to a boolean.
     */
    public function stringToBoolean(?string $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Convert a string to an integer.
     */
    public function stringToInteger(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * Convert a string to a float.
     */
    public function stringToFloat(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    /**
     * Convert a date string to a Carbon instance.
     */
    public function stringToDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        return Carbon::parse($value);
    }

    /**
     * Convert a Carbon instance to a string.
     */
    public function dateToString(?Carbon $value): ?string
    {
        if (! $value) {
            return null;
        }

        return $value->toIso8601String();
    }

    /**
     * Convert a Carbon instance to a date string.
     */
    public function dateToDateString(?Carbon $value): ?string
    {
        if (! $value) {
            return null;
        }

        return $value->toDateString();
    }

    /**
     * Convert a timestamp to a Carbon instance.
     */
    public function timestampToDate(?int $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        return Carbon::createFromTimestamp($value);
    }

    /**
     * Convert a string to a slug.
     */
    public function stringToSlug(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return Str::slug($value);
    }

    /**
     * Extract a specific key from a JSON object.
     */
    public function extractJsonKey(?string $json, string $key): mixed
    {
        if (! $json) {
            return null;
        }

        $data = json_decode($json, true);

        if (! is_array($data)) {
            return null;
        }

        return $data[$key] ?? null;
    }

    /**
     * Convert a geojson string to a geometry value.
     */
    public function geojsonToGeometry(?string $geojson): ?array
    {
        if (! $geojson) {
            return null;
        }

        $data = json_decode($geojson, true);

        if (! is_array($data)) {
            return null;
        }

        return $data['geometry'] ?? null;
    }

    /**
     * Convert a properties array to a JSON string.
     */
    public function propertiesToJson(?array $properties): ?string
    {
        if (! $properties) {
            return null;
        }

        return json_encode($properties);
    }

    public function svgIconToNameIcon(?string $svg): ?string
    {
        if (! $svg) {
            return null;
        }

        return app(IconMappingService::class)->getSvgIdentifier($svg);
    }
}
