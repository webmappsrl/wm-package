<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyWhereable;
use Wm\WmPackage\Services\Models\LayerService;

class TaxonomyWhereablesObserver
{
    public function __construct(protected LayerService $layerService) {}

    public function created(TaxonomyWhereable $taxonomyWhereable): void
    {
        $this->syncLayerIfNeeded($taxonomyWhereable, true);
    }

    public function deleted(TaxonomyWhereable $taxonomyWhereable): void
    {
        $this->syncLayerIfNeeded($taxonomyWhereable, false);
    }

    private function syncLayerIfNeeded(TaxonomyWhereable $taxonomyWhereable, bool $add): void
    {
        $relatedTypeClass = $taxonomyWhereable->taxonomy_whereable_type;

        if (! str_contains($relatedTypeClass, '\Layer')) {
            return;
        }

        $layer = Layer::find($taxonomyWhereable->taxonomy_whereable_id);
        if ($layer === null) {
            return;
        }

        $this->layerService->assignTracksByTaxonomy($layer);
        $this->layerService->updateLayersPropertyOnAllLayeredFeaturesWithJobs($layer);
        $layer->dispatchFeatureCollectionRegeneration();

        Log::info('TaxonomyWhereablesObserver: auto-sync layer after where change', [
            'layer_id' => $layer->id,
            'taxonomy_where_id' => $taxonomyWhereable->taxonomy_where_id,
            'add' => $add,
        ]);
    }
}
