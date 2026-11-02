<?php

namespace Wm\WmPackage\Services;

use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Jobs\Import\ImportAppJob;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;

/**
 * Rigenera i due file che dipendono dai POI di un'App: pois.geojson (i POI sulla mappa) e
 * config.json (il filtro dei tipi di POI, MAP.filters.poi_types). Stanno in un punto solo, così
 * chi aggiunge un'occasione di rigenerazione non ne dimentica uno dei due e mappa e filtro non
 * divergono (oc:8911).
 */
class AppPoiOutputsService extends BaseService
{
    /**
     * @param  bool  $withConfig  false quando la modifica non può cambiare il filtro dei tipi
     *                            (per esempio il salvataggio di un POI che non ne tocca `global`)
     */
    public function dispatchRegeneration(int $appId, bool $withConfig = true): void
    {
        // afterCommit su entrambi: la coda Redis dei consumer ha after_commit = false, e un
        // worker veloce leggerebbe i dati prima del commit della transazione Nova.
        BuildAppPoisGeojsonJob::dispatch($appId)->afterCommit();

        if (! $withConfig) {
            return;
        }

        // Durante l'import Geohub dei layer dell'App la config la scrive solo l'import, a layer
        // batch integro (oc:8488): l'import collega i tipi ai POI con gli eventi attivi. Il
        // blocco resta anche se il batch layer fallisce, finché scade il segnale in cache.
        if (! ImportAppJob::layerBatchIsPublishReady($appId)) {
            return;
        }

        UpdateAppConfigJob::dispatch($appId)->afterCommit();
    }
}
