<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Support\Collection;
use Wm\WmPackage\Exporters\EcTrackExcelExporter;
use Wm\WmPackage\Exporters\EcTrackImportTemplateExporter;

/**
 * Azione unica di download per EcTrack: l'utente sceglie il formato (XLSX o GeoJSON).
 *
 * - Senza risorse selezionate:
 *   - xlsx  → template con sole intestazioni allineate all'import;
 *   - geojson → FeatureCollection vuota.
 * - Con selezione: file pieno di righe/feature.
 */
class DownloadEcTrackAction extends AbstractDownloadMultiFormatAction
{
    public function name()
    {
        return __('Download EcTracks');
    }

    protected function filePrefix(): string
    {
        return 'ec_tracks';
    }

    protected function excelExporterFor(Collection $models): object
    {
        if ($models->isEmpty()) {
            // Template vuoto (solo intestazioni) quando l'azione è lanciata senza selezione.
            return new EcTrackImportTemplateExporter;
        }

        return new EcTrackExcelExporter($models);
    }
}
