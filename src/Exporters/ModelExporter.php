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
 * @implements FromCollection
 * @implements WithHeadings
 * @implements WithStyles
 * @implements WithMapping
 * @implements ShouldAutoSize
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
class ModelExporter implements FromCollection, WithHeadings, WithStyles, WithMapping, ShouldAutoSize
{
    const DEFAULT_STYLE = [
        1 => [
            'font' => [
                'bold' => true,
                'size' => 14,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E2E8F0']
            ]
        ]
    ];

    protected Collection $models;
    protected array $columns;
    protected array $relations;
    protected array $styles;

    public function __construct(Collection $models, array $columns = [], array $relations = [], array $styles = self::DEFAULT_STYLE)
    {
        $this->models = $models;
        $this->columns = $columns;
        $this->relations = $relations;
        $this->styles = $styles;
    }

    public function collection(): Collection
    {
        if (empty($this->columns)) {
            return $this->models->filter()->values();
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

            return $result;
        });
    }

    /**
     * Maps row values before export.
     *
     * Converts boolean values to localized "Yes"/"No".
     *
     * @param mixed $row Row to map
     * @return array
     */
    public function map($row): array
    {
        return collect($row)->map(function ($value) {
            if (is_bool($value)) {
                return $value ? __('Yes') : __('No');
            }
            return $value;
        })->toArray();
    }

    /**
     * Applies styles to the Excel sheet.
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @return array Array of styles for the sheet
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
     *
     * @return array
     */
    public function headings(): array
    {
        if ($this->columns === []) {
            $table = $this->models->first()->getTable();
            return Schema::getColumnListing($table);
        }

        return collect($this->columns)->values()->map(function ($value) {
            return __($value);
        })->toArray();
    }
}
