<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\LayerService;
use Wm\WmPackage\Services\PBFGeneratorService;

class LayerObserver extends AbstractObserver
{
    public function __construct(
        protected LayerService $layerService,
        protected PBFGeneratorService $pbfGeneratorService
    ) {}

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

        // Se il layer ha tassonomie di attività associate, assegna automaticamente le track
        if ($this->hasTaxonomyActivitiesChanged($layer)) {
            $this->layerService->assignTracksByTaxonomy($layer);
        }

        // Update App conf when layer properties change
        if ($layer->wasChanged('properties')) {
            $this->updateAppConf($layer);
            $this->layerService->updateLayerGeometryWithJob($layer);
        }

        // Aggiorna sempre la geometria del layer quando viene salvato
        $this->layerService->updateLayerGeometryWithJob($layer);
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

    /**
     * Check if taxonomy activities have changed
     */
    private function hasTaxonomyActivitiesChanged(Layer $layer): bool
    {
        // Se è un nuovo record e ha tassonomie di attività
        if ($layer->wasRecentlyCreated) {
            return $layer->taxonomyActivities()->count() > 0;
        }

        // Se le tassonomie di attività sono cambiate
        return $layer->wasChanged() && $layer->taxonomyActivities()->count() > 0;
    }

    private function updateAppConf(Layer $layer): void
    {
        // Dispatches il job con ritardo di 10 secondi per permettere ai media di essere processati
        UpdateAppConfigJob::dispatch($layer->app_id)
            ->delay(now()->addSeconds(10))
            ->onQueue('default');
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
        // Rigenera i PBF del layer dopo la cancellazione
        $this->pbfGeneratorService->regeneratePbfsForLayer($layer);

        $this->updateAppConf($layer);
    }
}
