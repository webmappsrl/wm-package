<?php

namespace Wm\WmPackage\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\Layerable;

trait EcFeatureTrait
{
    public function associatedLayers(): MorphToMany
    {
        return $this->morphToMany(Layer::class, 'layerable')->using(Layerable::class);
    }

    public function layers(): MorphToMany
    {
        return $this->associatedLayers();
    }

    /**
     * Scope a query to only a specific layer.
     * Get all features "visible" by a specific layer
     * These arent the features associated to the layer!
     * These are all possible features that can be associated to the layer ;)
     */
    public function scopeOnLayer(Builder $query, Layer $layer): void
    {

        $query
            ->whereIn('app_id', [
                $layer->app_id,
                ...$layer->associatedApps->pluck('id')->toArray(),
            ])
            ->whereNotNull('geometry');  // Controlla che la geometria non sia null-> todo: remove

        // ## TAXONOMY WHERE - strings inside properties
        // check the local scope here wm-package/src/Traits/TaxonomyWhereAbleModel.php
        $query->byWhereProperty($layer->properties);

        // ## TAXONOMY ACTIVITY - relation
        $ids = $layer->taxonomyActivities->pluck('id')->toArray() ?? [];
        if (count($ids) > 0) {
            $query
                ->whereHas('taxonomyActivities', function ($query) use ($ids) {
                    $query->whereIn('taxonomy_activities.id', $ids); // whereIn = LOGIC OPERATOR OR
                });
        }

        // ## TAXONOMY TERM - relation (oc:8910)
        // Una condizione per tassonomia, non una sola su tutti i termini: dentro la stessa
        // tassonomia i termini sono in OR, fra tassonomie diverse in AND — «(sentiero o cammino)
        // e (difficoltà BC)» — la stessa regola di `LayerService::assignTracksByTaxonomy()`.
        // Senza questo blocco un layer del modello unico, che le attività non le ha più, vede
        // tutte le tracce dell'app: è questa query a dire quali può contenere.
        if (method_exists($this, 'taxonomyTerms')) {
            foreach ($layer->taxonomyTerms->groupBy('parent_id') as $parentId => $termsOfTaxonomy) {
                if ($parentId === null || $parentId === '') {
                    continue;
                }

                $termIds = $termsOfTaxonomy->pluck('id')->toArray();
                $query->whereHas('taxonomyTerms', function ($query) use ($termIds) {
                    $query->whereIn('taxonomy_terms.id', $termIds);
                });
            }
        }
    }
}
