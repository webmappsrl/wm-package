<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\ModelObserver;
use Laravel\Scout\Searchable;
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

        if (! class_exists($class) || ! in_array(Searchable::class, class_uses_recursive($class), true)) {
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
}
