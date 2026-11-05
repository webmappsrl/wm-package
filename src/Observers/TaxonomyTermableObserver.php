<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\ModelObserver;
use Laravel\Scout\Searchable;
use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\TaxonomyTermable;

/**
 * Una riga di pivot non tocca il contenuto, quindi Scout non si accorge della relazione:
 * qui il contenuto collegato viene reindicizzato a mano (oc:8910).
 */
class TaxonomyTermableObserver
{
    public function saved(TaxonomyTermable $termable): void
    {
        $this->reindex($termable);
    }

    public function deleted(TaxonomyTermable $termable): void
    {
        $this->reindex($termable);
    }

    private function reindex(TaxonomyTermable $termable): void
    {
        // termable_type contiene l'alias del morphMap, non il nome della classe.
        $class = Relation::getMorphedModel($termable->termable_type) ?? $termable->termable_type;

        if (! class_exists($class)) {
            return;
        }

        // `EcPoi` non è Searchable, e il suo «reindice» è un altro: l'app non interroga le API
        // per i POI, legge `pois.geojson`, dove i filtri si risolvono su
        // `taxonomyIdentifiers`. Senza rigenerare quel file un termine cambiato in Nova non
        // arriva mai a frontend (oc:8043, oc:8910).
        if (is_a($class, EcPoi::class, true)) {
            $this->rebuildPoisGeojson($class, (int) $termable->termable_id);

            return;
        }

        if (! in_array(Searchable::class, class_uses_recursive($class), true)) {
            return;
        }

        // withoutSyncingToSearch() deve valere anche qui: e' la leva con cui un import silenzia il
        // reindex mentre scrive molte relazioni.
        if (ModelObserver::syncingDisabledFor($class)) {
            return;
        }

        $model = $class::query()->find($termable->termable_id);

        try {
            $model?->searchable();
        } catch (\Throwable $e) {
            // Con scout.queue a false l'indicizzazione e' sincrona e gira dentro attach():
            // un Elasticsearch irraggiungibile non deve lasciare le relazioni scritte a meta'.
            // Un indice non aggiornato si rimedia con un reindex.
            Log::error('Reindex fallito dopo la modifica di una relazione di tassonomia', [
                'termable_type' => $termable->termable_type,
                'termable_id' => $termable->termable_id,
                'taxonomy_term_id' => $termable->taxonomy_term_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Riscrive `pois.geojson` dell'app a cui il POI appartiene.
     *
     * Il job è unico per app e in coda, quindi un import che scrive molte relazioni accoda una
     * rigenerazione sola e la richiesta non la aspetta. `withoutSyncingToSearch()` non lo
     * silenzia, perché non passa da Scout: un import che non la vuole la evita scrivendo le
     * relazioni senza eventi, come fa già per le attività.
     *
     * @param  class-string  $class
     */
    private function rebuildPoisGeojson(string $class, int $poiId): void
    {
        $appId = $class::query()->whereKey($poiId)->value('app_id');

        if ($appId !== null) {
            BuildAppPoisGeojsonJob::dispatch((int) $appId);
        }
    }
}
