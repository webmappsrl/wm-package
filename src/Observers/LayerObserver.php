<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\LayerService;
use Wm\WmPackage\Services\PBFGeneratorService;

class LayerObserver extends AbstractObserver
{
    public function __construct(protected LayerService $layerService) {}

    /**
     * Handle the Layer "creating" event.
     *
     * @return void
     */
    public function creating(Model $layer)
    {
        $layer->rank = $this->layerService->getLayerMaxRank() + 1;
    }

    /**
     * Handle the Layer "saved" event.
     *
     * @return void
     */
    public function saved(Layer $layer)
    {
        // update layers properties on ec models ONLY if taxonomy_where properties have changed
        if ($this->hasTaxonomyWhereChanged($layer)) {
            $this->layerService->updateLayersPropertyOnAllLayeredFeaturesWithJobs($layer);
        }
    }

    /**
     * Check if taxonomy_where properties have changed
     */
    private function hasTaxonomyWhereChanged(Layer $layer): bool
    {
        // If this is a new record, always update
        if (! $layer->wasRecentlyCreated && $layer->wasChanged('properties')) {
            $original = $layer->getOriginal('properties') ?? [];
            $current = $layer->properties ?? [];

            $originalTaxonomyWhere = $original['taxonomy_where'] ?? [];
            $currentTaxonomyWhere = $current['taxonomy_where'] ?? [];

            // Check if taxonomy_where has actually changed
            return $originalTaxonomyWhere !== $currentTaxonomyWhere;
        }

        // For new records, only update if there are taxonomy_where properties
        if ($layer->wasRecentlyCreated) {
            return isset($layer->properties['taxonomy_where']) && count($layer->properties['taxonomy_where']) > 0;
        }

        return false;
    }

    public function saving($layer)
    {
        parent::saving($layer);
        if (is_null($layer->properties)) {
            $layer->properties = [];
        }
    }

    public function deleted(Layer $layer)
    {
        PBFGeneratorService::make()->generateWholeAppPbfs($layer->app);
    }
}
