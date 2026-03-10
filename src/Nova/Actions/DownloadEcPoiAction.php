<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Support\Collection;
use Wm\WmPackage\Exporters\EcPoiExcelExporter;

/**
 * Azione unica di download per EcPoi: l'utente sceglie il formato (XLSX o GeoJSON).
 */
class DownloadEcPoiAction extends AbstractDownloadMultiFormatAction
{
    public function name()
    {
        return __('Download EcPois');
    }

    protected function filePrefix(): string
    {
        return 'ec_pois';
    }

    protected function eagerLoads(): array
    {
        // Necessario per exporter e per includere tassonomie nel geojson.
        return ['taxonomyPoiTypes'];
    }

    protected function excelExporterFor(Collection $models): object
    {
        return new EcPoiExcelExporter($models);
    }
}
