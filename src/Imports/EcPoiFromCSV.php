<?php

namespace Wm\WmPackage\Imports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Models\TaxonomyTheme;
use Wm\WmPackage\Models\EcPoi as PackageEcPoi;

class EcPoiFromCSV implements OnEachRow, SkipsEmptyRows, WithChunkReading, WithCustomCsvSettings, WithHeadingRow
{
    /**
     * @var array<int, array{row: int|string, message: string}>
     */
    public array $errors = [];

    /**
     * @var array<int, array{row: int|string, id: int|string}>
     */
    public array $poiIds = [];

    public function __construct(
        private readonly bool $saveQuietly = true,
        private readonly string $delimiter = ',',
        private readonly string $enclosure = '"',
        private readonly string $escape = '\\',
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

    public function onRow(Row $row): void
    {
        $rowIndex = method_exists($row, 'getIndex') ? $row->getIndex() : null;
        $rowIndex = is_int($rowIndex) ? $rowIndex : 0;

        $data = $row->toArray();
        if (! is_array($data) || $data === []) {
            return;
        }

        $data = $this->normalizeKeys($data);
        $this->validateHeaders(array_keys($data));

        $modelClass = config('wm-package.ec_poi_model', PackageEcPoi::class);
        if (! is_string($modelClass) || ! class_exists($modelClass)) {
            $modelClass = PackageEcPoi::class;
        }

        $id = $this->normalizeCellValue($data['id'] ?? null);
        $isCreate = ($id === null || $id === '');

        /** @var Model&PackageEcPoi|null $model */
        $model = null;
        if (! $isCreate) {
            $id = is_numeric($id) ? (int) $id : (string) $id;
            $model = $modelClass::query()->whereKey($id)->first();
            if (! $model) {
                $this->errors[] = [
                    'row' => $rowIndex,
                    'message' => "Poi with ID {$id} not found.",
                ];

                return;
            }
        } else {
            $model = new $modelClass;
            $userId = Auth::id();
            if ($userId && $model->getAttribute('user_id') === null) {
                $model->setAttribute('user_id', $userId);
            }
        }

        $validationError = $this->validateRowData($data);
        if ($validationError !== null) {
            $this->errors[] = [
                'row' => $rowIndex,
                'message' => $validationError,
            ];

            return;
        }

        $this->applyGeohubRowToModel($model, $data);

        if ($this->saveQuietly && method_exists($model, 'saveQuietly')) {
            $model->saveQuietly();
        } else {
            $model->save();
        }

        if ($isCreate) {
            $this->poiIds[] = [
                'row' => $rowIndex,
                'id' => (string) $model->getKey(),
            ];
        }
    }

    private function validateRowData(array $data): ?string
    {
        $nameIt = $this->normalizeCellValue($data['name_it'] ?? null);
        $poiType = $this->normalizeCellValue($data['poi_type'] ?? null);
        $theme = $this->normalizeCellValue($data['theme'] ?? null);
        $lat = $this->normalizeCellValue($data['lat'] ?? null);
        $lng = $this->normalizeCellValue($data['lng'] ?? null);

        if ($nameIt === null || $nameIt === '') {
            return 'Validation error: name_it is required.';
        }
        if ($poiType === null || $poiType === '') {
            return 'Validation error: poi_type is required.';
        }
        if ($theme === null || $theme === '') {
            return 'Validation error: theme is required.';
        }
        if ($lat === null || $lat === '' || $lng === null || $lng === '') {
            return 'Validation error: lat and lng are required.';
        }
        $latN = is_string($lat) ? str_replace(',', '.', $lat) : $lat;
        $lngN = is_string($lng) ? str_replace(',', '.', $lng) : $lng;
        if (! is_numeric($latN) || ! is_numeric($lngN)) {
            return 'Validation error: lat and lng must be numeric.';
        }

        $related = $this->normalizeCellValue($data['related_url'] ?? null);
        if (is_string($related) && $related !== '' && ! str_starts_with($related, 'http://') && ! str_starts_with($related, 'https://')) {
            return 'Validation error: related_url must start with http:// or https://';
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

        $themeIdentifiers = $this->splitCsvIdentifiers((string) $theme);
        if ($themeIdentifiers === []) {
            return 'Validation error: theme must contain at least one identifier.';
        }
        // `taxonomy_themes` non ha la colonna `identifier` (usa `slug` come identificatore).
        $foundThemes = TaxonomyTheme::query()->whereIn('slug', $themeIdentifiers)->pluck('slug')->all();
        $missingThemes = array_values(array_diff($themeIdentifiers, $foundThemes));
        if ($missingThemes !== []) {
            return 'Validation error: invalid theme identifiers: '.implode(', ', $missingThemes);
        }

        return null;
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

    /**
     * @param  string[]  $headers
     */
    private function validateHeaders(array $headers): void
    {
        foreach ($headers as $header) {
            if (is_numeric($header)) {
                continue;
            }

            Str::of((string) $header)->lower()->replace(' ', '_')->toString();
        }
    }

    private function applyGeohubRowToModel(Model $model, array $data): void
    {
        $validHeaders = config('wm-geohub-import.ecPois.validHeaders', []);
        $validHeaders = array_map(
            static fn (string $h) => Str::of($h)->lower()->replace(' ', '_')->toString(),
            $validHeaders
        );

        $properties = $model->getAttribute('properties');
        $properties = is_array($properties) ? $properties : (array) $properties;

        $lat = null;
        $lng = null;

        foreach ($data as $key => $value) {
            if (! in_array($key, $validHeaders, true) || $key === 'id') {
                continue;
            }

            $value = $this->normalizeCellValue($value);
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

            // Translations (Geohub-like headers)
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

            // Prefer explicit columns when they exist on the model
            if (in_array($key, ['addr_complete', 'capacity', 'contact_phone', 'contact_email'], true)) {
                $model->setAttribute($key, $value);
                $properties[$key] = $value;
                continue;
            }

            if ($key === 'related_url') {
                $decoded = $this->tryJsonDecode((string) $value);
                if ($decoded !== null) {
                    $model->setAttribute('related_url', $decoded);
                    $properties['related_url'] = $decoded;
                } else {
                    $model->setAttribute('related_url', (string) $value);
                    $properties['related_url'] = (string) $value;
                }
                continue;
            }

            // Everything else goes into properties (poi_type, theme, gallery, feature_image, errors...)
            $properties[$key] = $value;
        }

        if ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng)) {
            $latF = (float) $lat;
            $lngF = (float) $lng;
            $model->setAttribute('geometry', "POINT Z ({$lngF} {$latF} 0)");
        }

        $model->setAttribute('properties', $properties);
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

    private function normalizeKeys(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            $key = is_string($key) ? trim($key) : (string) $key;
            $key = Str::of($key)->lower()->replace(' ', '_')->toString();
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function normalizeCellValue(mixed $value): mixed
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

