<?php

namespace Wm\WmPackage\Imports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;
use Wm\WmPackage\Models\EcTrack as PackageEcTrack;

class EcTrackFromCSV implements OnEachRow, SkipsEmptyRows, WithChunkReading, WithCustomCsvSettings, WithHeadingRow
{
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
        $data = $row->toArray();
        if (! is_array($data) || $data === []) {
            return;
        }

        $data = $this->normalizeKeys($data);

        $this->validateHeaders(array_keys($data));

        $modelClass = config('wm-package.ec_track_model', PackageEcTrack::class);
        if (! is_string($modelClass) || ! class_exists($modelClass)) {
            $modelClass = PackageEcTrack::class;
        }

        // Geohub behaviour: update existing track by ID, don't silently create empty tracks.
        $id = $data['id'] ?? null;
        $id = $this->normalizeCellValue($id);
        if ($id === null || $id === '') {
            throw new \InvalidArgumentException('Invalid track ID found. Please check the file and try again.');
        }

        $id = is_numeric($id) ? (int) $id : (string) $id;

        /** @var Model&PackageEcTrack|null $model */
        $model = $modelClass::query()->whereKey($id)->first();
        if (! $model) {
            throw new \InvalidArgumentException("Track with ID {$id} not found. Import updates existing tracks only.");
        }

        $this->applyGeohubRowToModel($model, $data);

        if ($this->saveQuietly && method_exists($model, 'saveQuietly')) {
            $model->saveQuietly();
        } else {
            $model->save();
        }
    }

    /**
     * Validate that all headers are within the allowed EcTrack set.
     *
     * @param  string[]  $headers
     */
    private function validateHeaders(array $headers): void
    {
        $valid = config('wm-geohub-import.ecTracks.validHeaders', []);
        $valid = array_map(
            static fn (string $h) => Str::of($h)->lower()->replace(' ', '_')->toString(),
            $valid
        );

        $unknown = [];
        foreach ($headers as $header) {
            // Keep Geohub quirk: ignore numeric headers.
            if (is_numeric($header)) {
                continue;
            }

            $normalized = Str::of((string) $header)->lower()->replace(' ', '_')->toString();
            if (! in_array($normalized, $valid, true)) {
                $unknown[] = (string) $header;
            }
        }

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Invalid headers found: '.implode(', ', $unknown).'. Please check the file and try again.'
            );
        }
    }

    private function applyGeohubRowToModel(Model $model, array $data): void
    {
        $validHeaders = config('wm-geohub-import.ecTracks.validHeaders', []);
        $validHeaders = array_map(
            static fn (string $h) => Str::of($h)->lower()->replace(' ', '_')->toString(),
            $validHeaders
        );

        $properties = $model->getAttribute('properties');
        $properties = is_array($properties) ? $properties : (array) $properties;

        foreach ($data as $key => $value) {
            if (! in_array($key, $validHeaders, true) || $key === 'id') {
                continue;
            }

            $value = $this->normalizeCellValue($value);
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

        // Always set this flag on import (Geohub behaviour)
        $properties['skip_geomixer_tech'] = true;

        $model->setAttribute('properties', $properties);
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

        // Convert common "NULL" markers
        if (is_string($value) && in_array(strtoupper($value), ['NULL', 'N/A', 'NA'], true)) {
            return null;
        }

        return $value;
    }

}

