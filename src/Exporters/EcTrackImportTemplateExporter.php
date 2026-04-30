<?php

namespace Wm\WmPackage\Exporters;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Wm\WmPackage\Imports\AbstractExcelSpreadsheetImporter;

/**
 * Foglio Excel vuoto (solo riga intestazioni) per l'import traccia, allineato a {@see config('wm-excel-ec-import.ecTracks.validHeaders')}.
 */
class EcTrackImportTemplateExporter implements FromCollection, WithHeadings
{
    public function collection(): Collection
    {
        return collect();
    }

    /**
     * @return string[]
     */
    public function headings(): array
    {
        return AbstractExcelSpreadsheetImporter::ecTrackImportValidHeaderNames();
    }
}
