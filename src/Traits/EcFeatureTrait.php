<?php

namespace Wm\WmPackage\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\TaxonomyWhereService;

trait EcFeatureTrait
{
    public function associatedLayers(): MorphToMany
    {
        return $this->morphToMany(Layer::class, 'layerable');
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
            ->whereNotNull('geometry');  // Controlla che la geometria non sia null

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
    }
}
