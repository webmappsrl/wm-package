<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\LayerService;
use Wm\WmPackage\Services\PBFGeneratorService;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Jobs\Pbf\GenerateOptimizedPBFChainJob;

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
        $this->updatePbfsForLayer($layer);
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
        $this->updatePbfsForLayer($layer);
    }

    public function updatePbfsForLayer(Layer $layer)
    {
        return;
        try {
            $trackIds = $layer->layerables()
                ->where('layerable_type', config('wm-package.ec_track_model', 'App\Models\EcTrack'))
                ->pluck('layerable_id')
                ->toArray();
            
            if (!empty($trackIds)) {
                GenerateOptimizedPBFChainJob::dispatch(
                    $layer->app->id,
                    5,  // minZoom
                    13, // minZoom
                    false,
                    null,
                    $trackIds // Passa le track IDs già recuperate
                );

                Log::info('PBF rigenerati per tracce multiple del layer', [
                    'layer_id' => $layer->id,
                    'track_count' => count($trackIds),
                    'app_id' => $layer->app->id
                ]);
            } else {
                // Se non ci sono tracce, usa il metodo originale
                PBFGeneratorService::make()->generateWholeAppPbfsOptimized($layer->app);
            }
        } catch (\Exception $e) {
            Log::warning('Fallback a generateWholeAppPbfs per errore nella rigenerazione multipla', [
                'layer_id' => $layer->id,
                'error' => $e->getMessage()
            ]);
            // Fallback al metodo originale
            PBFGeneratorService::make()->generateWholeAppPbfs($layer->app);
        }
    }
}
