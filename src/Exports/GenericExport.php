<?php

namespace Wm\WmPackage\Exports;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class GenericExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    protected $modelClass;

    protected $fields;

    protected $filters;

    public function __construct(string $modelClass, array $fields, array $filters = [])
    {
        $this->modelClass = 'App\Models\\'.$modelClass;
        $this->fields = $fields;
        $this->filters = $filters;
    }

    public function collection(): Collection
    {
        if (! class_exists($this->modelClass)) {
            throw new \Exception("Class {$this->modelClass} not found");
        }

        $class = new $this->modelClass;
        $query = $class::query();

        // Apply filters if defined
        foreach ($this->filters as $filter) {
            if (isset($filter['field'], $filter['value'])) {
                $query->where($filter['field'], $filter['operator'] ?? '=', $filter['value']);
            }
        }

        // Handle geometries: replace 'geometry' with ST_AsText(geometry)
        $selectFields = [];
        foreach ($this->fields as $field) {
            if ($field === 'geometry' || $field === 'geom') {
                // Convert geometry to text using PostGIS function ST_AsText()
                $selectFields[] = DB::raw('ST_AsText('.$field.') AS '.$field);
            } else {
                $selectFields[] = $field;
            }
        }

        return $query->select($selectFields)->get();
    }

    public function headings(): array
    {
        return array_map(function ($field) {
            return ucfirst(str_replace('_', ' ', $field));
        }, $this->fields);
    }

    public function map($row): array
    {
        return Arr::only($row->toArray(), $this->fields);
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle($sheet->calculateWorksheetDimension())
            ->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

        return [];
    }
}
