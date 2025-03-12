<?php

namespace Wm\WmPackage\Services\Import;

use Carbon\Carbon;
use Illuminate\Support\Str;

class DataTransformer
{
    /**
     * Convert a JSON string to an array.
     */
    public function jsonToArray(?string $json): ?array
    {
        if (! $json) {
            return null;
        }

        return json_decode($json, true);
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
}
