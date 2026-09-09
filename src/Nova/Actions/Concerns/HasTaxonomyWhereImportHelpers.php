<?php

namespace Wm\WmPackage\Nova\Actions\Concerns;

use Illuminate\Support\Facades\Schema;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\GeometryComputationService;

trait HasTaxonomyWhereImportHelpers
{
    /**
     * Risolve l'App da usare per l'import: se esiste una sola App non serve
     * selezione esplicita, altrimenti richiede `app_id` dal campo Select.
     *
     * @return App|string L'App risolta, oppure il messaggio di errore da
     *                    passare ad Action::danger() se la risoluzione fallisce.
     */
    protected function resolveApp(ActionFields $fields): App|string
    {
        $apps = App::all();
        if ($apps->count() === 1) {
            return $apps->first();
        }

        $appId = $fields->get('app_id');
        if (! $appId) {
            return "Seleziona un'App.";
        }

        $app = App::find($appId);
        if (! $app) {
            return 'App non trovata.';
        }

        return $app;
    }

    private function assignTaxonomyUserFromApp(TaxonomyWhere $taxonomyWhere, App $app): void
    {
        if (! Schema::hasColumn($taxonomyWhere->getTable(), 'user_id')) {
            return;
        }

        if (empty($app->user_id)) {
            return;
        }

        $taxonomyWhere->forceFill(['user_id' => $app->user_id])->saveQuietly();
    }

    /**
     * Dispatcha il sync locale (via ST_Intersects) delle track esistenti sulle
     * taxonomy_where appena importate/aggiornate, e appende il contatore al
     * messaggio finale — stesso comportamento per tutte e tre le sorgenti.
     */
    protected function finalizeWithTracksSync(string $message): string
    {
        $tracksSynced = GeometryComputationService::make()->syncTracksTaxonomyWhere(
            config('wm-package.ec_track_model', EcTrack::class)
        );

        return $message." Sync taxonomy_where su {$tracksSynced} tracks avviata.";
    }
}
