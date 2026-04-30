<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Support\Collection;
use Laravel\Nova\Fields\ActionFields;

/**
 * Download GeoJSON di UgcTrack (tracce utente): non esiste un export Excel, quindi
 * l'azione è dedicata al formato GeoJSON, riusando lo stesso supporto di storage/redirect.
 */
class DownloadUgcTrackAction extends AbstractDownloadMultiFormatAction
{
    public function name()
    {
        return __('Download GeoJSON');
    }

    protected function filePrefix(): string
    {
        return 'ugc_tracks';
    }

    protected function supportsXlsx(): bool
    {
        // UgcTrack: solo GeoJSON.
        return false;
    }

    protected function excelExporterFor(Collection $models): ?object
    {
        // Non usato perché supportsXlsx=false.
        return null;
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        // Evita di filtrare la selection: la coerenza del modello è garantita dal resource.
        $appIds = $models->pluck('app_id')->filter()->unique();
        if ($appIds->count() > 1) {
            return self::danger(__('All selected tracks must belong to the same app.'));
        }

        return parent::handle($fields, $models);
    }
}
