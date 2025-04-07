<?php

namespace Wm\WmPackage\Exporters;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Class for exporting Eloquent models to various spreadsheet formats.
 *
 * This class enables easy export of Eloquent model data to Excel, CSV, and other
 * spreadsheet formats, with support for custom columns, relationships, and styling.
 *
 * @example
 * ```php
 * // Using key => value pairs for custom headers
 * $export = new ModelExporter(
 *     User::query(),
 *     ['name' => 'User Name', 'email' => 'Email Address', 'profile.phone' => 'Phone Number'],
 *     ['profile' => 'phone'],
 *     [
 *         1 => ['font' => ['bold' => true]]
 *     ]
 * );
 *
 * // Using array of strings for direct column names as headers
 * $export = new ModelExporter(
 *     User::query(),
 *     ['name', 'email'],
 *     ['profile' => 'phone']
 * );
 * Excel::download($export, 'users.xlsx');
 * ```
 *
 * @link https://docs.laravel-excel.com/ Laravel Excel Documentation
 * @link https://phpspreadsheet.readthedocs.io/en/latest/topics/styling/ PhpSpreadsheet Styling Documentation
 */
class ModelExporter implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    const DEFAULT_STYLE = [
        1 => [
            'font' => [
                'bold' => true,
                'size' => 14,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E2E8F0'],
            ],
        ],
    ];

    protected Collection $models;

    protected array $columns;

    protected array $relations;

    protected array $styles;

    protected array $expandJsonColumns = [];

    protected array $expandedColumnHeaders = [];

    public function __construct(Collection $models, array $columns = [], array $relations = [], array $styles = self::DEFAULT_STYLE)
    {
        $this->models = $models;
        $this->columns = $columns;
        $this->relations = $relations;
        $this->styles = $styles;
    }

    /**
     * Set columns that should be expanded from JSON to individual columns
     *
     * @param array $columns Array of column names that contain JSON data to be expanded
     * @return $this
     */
    public function expandJsonColumns(array $columns): self
    {
        $this->expandJsonColumns = $columns;

        // Cache expanded column headers
        if (!empty($this->expandJsonColumns) && $this->models->isNotEmpty()) {
            $this->cacheExpandedColumnHeaders();
        }

        return $this;
    }

    /**
     * Cache all possible keys from the JSON columns to ensure consistent columns across rows
     */
    protected function cacheExpandedColumnHeaders(): void
    {
        $this->expandedColumnHeaders = [];

        foreach ($this->expandJsonColumns as $jsonColumn) {
            $allKeys = [];

            // Collect all possible keys from all rows
            $this->models->each(function ($model) use ($jsonColumn, &$allKeys) {
                $jsonData = data_get($model, $jsonColumn, []);
                if (is_array($jsonData) || is_object($jsonData)) {
                    // Extract all keys recursively
                    $this->extractJsonKeysRecursively($jsonData, $allKeys);
                }
            });

            // Store the keys for this column
            $this->expandedColumnHeaders[$jsonColumn] = $allKeys;
        }
    }

    /**
     * Extract all keys from a nested JSON structure recursively
     *
     * @param array|object $data The JSON data to extract keys from
     * @param array $keys Array to collect the flattened keys
     * @param string $prefix Current key prefix for nested structures
     */
    protected function extractJsonKeysRecursively($data, array &$keys, string $prefix = ''): void
    {
        foreach ($data as $key => $value) {
            $currentKey = $prefix ? "{$prefix}.{$key}" : $key;

            // Add the current key
            if (!in_array($currentKey, $keys)) {
                $keys[] = $currentKey;
            }

            // If value is an array or object, process it recursively
            if (is_array($value) || is_object($value)) {
                $this->extractJsonKeysRecursively($value, $keys, $currentKey);
            }
        }
    }

    public function collection(): Collection
    {
        if (empty($this->columns)) {
            // When no columns specified, get all model data and expand JSON columns
            return $this->models->filter()->values()->map(function ($item) {
                $result = is_array($item) ? $item : $item->toArray();

                // Expand JSON columns
                foreach ($this->expandJsonColumns as $jsonColumn) {
                    $jsonData = data_get($item, $jsonColumn, []);
                    if (is_array($jsonData) || is_object($jsonData)) {
                        // Remove the original JSON column if we're expanding it
                        if (isset($result[$jsonColumn])) {
                            unset($result[$jsonColumn]);
                        }

                        // Add all nested JSON properties as separate columns
                        $flattenedData = [];
                        $this->flattenJsonData($jsonData, $flattenedData);

                        foreach ($this->expandedColumnHeaders[$jsonColumn] as $nestedKey) {
                            $result["{$jsonColumn}.{$nestedKey}"] = $flattenedData[$nestedKey] ?? null;
                        }
                    }
                }

                return $result;
            });
        }

        return $this->models->filter()->values()->map(function ($item) {
            $result = [];

            foreach ($this->columns as $key => $value) {
                $column = is_numeric($key) ? $value : $key;
                $result[$column] = data_get($item, $column);
            }

            foreach ($this->relations as $relation => $attribute) {
                $relatedValue = data_get($item, "$relation.$attribute", null);
                $result["$relation.$attribute"] = $relatedValue;
            }

            // Expand JSON columns
            foreach ($this->expandJsonColumns as $jsonColumn) {
                $jsonData = data_get($item, $jsonColumn, []);
                if (is_array($jsonData) || is_object($jsonData)) {
                    // Remove the original JSON column if it's included and we're expanding it
                    if (isset($result[$jsonColumn])) {
                        unset($result[$jsonColumn]);
                    }

                    // Add all nested JSON properties as separate columns
                    $flattenedData = [];
                    $this->flattenJsonData($jsonData, $flattenedData);

                    foreach ($this->expandedColumnHeaders[$jsonColumn] as $nestedKey) {
                        $result["{$jsonColumn}.{$nestedKey}"] = $flattenedData[$nestedKey] ?? null;
                    }
                }
            }

            return $result;
        });
    }

    /**
     * Flatten a nested JSON structure into dot notation
     *
     * @param array|object $data The JSON data to flatten
     * @param array $result Array to store the flattened data
     * @param string $prefix Current key prefix for nested structures
     */
    protected function flattenJsonData($data, array &$result, string $prefix = ''): void
    {
        foreach ($data as $key => $value) {
            $currentKey = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value) || is_object($value)) {
                // If this is a nested structure, flatten it recursively
                $this->flattenJsonData($value, $result, $currentKey);
            } else {
                // Add leaf value with full dot notation path
                $result[$currentKey] = $value;
            }
        }
    }

    /**
     * Maps row values before export.
     *
     * Converts boolean values to localized "Yes"/"No".
     *
     * @param  mixed  $row  Row to map
     */
    public function map($row): array
    {
        return collect($row)->map(function ($value) {
            if (is_bool($value)) {
                return $value ? __('Yes') : __('No');
            }

            // Convert arrays/objects to JSON strings
            if (is_array($value) || is_object($value)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE);
            }

            return $value;
        })->toArray();
    }

    /**
     * Applies styles to the Excel sheet.
     *
     * @param  \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet  $sheet
     * @return array Array of styles for the sheet
     *
     * @link https://phpspreadsheet.readthedocs.io/en/latest/topics/styling/
     */
    public function styles($sheet): array
    {
        return $this->styles;
    }

    /**
     * Gets the column headers.
     *
     * If no columns are specified, uses the table column names.
     * Otherwise, uses the labels specified in the columns array.
     */
    public function headings(): array
    {
        $headers = [];

        if ($this->columns === []) {
            if ($this->models->isEmpty()) {
                return [];
            }

            $table = $this->models->first()->getTable();
            $headers = Schema::getColumnListing($table);

            // Remove JSON columns that will be expanded
            foreach ($this->expandJsonColumns as $jsonColumn) {
                $columnIndex = array_search($jsonColumn, $headers);
                if ($columnIndex !== false) {
                    unset($headers[$columnIndex]);
                }
            }
        } else {
            $headers = collect($this->columns)->values()->map(function ($value) {
                return __($value);
            })->toArray();

            // Remove JSON columns that will be expanded
            foreach ($this->expandJsonColumns as $jsonColumn) {
                $columnIndex = array_search($jsonColumn, $headers);
                if ($columnIndex !== false) {
                    unset($headers[$columnIndex]);
                }
            }
        }

        // Add expanded JSON column headers
        foreach ($this->expandJsonColumns as $jsonColumn) {
            foreach ($this->expandedColumnHeaders[$jsonColumn] as $key) {
                $headers[] = "{$jsonColumn}.{$key}";
            }
        }

        return array_values($headers);
    }
}
