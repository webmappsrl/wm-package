<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Models\Layer;
use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Models\Abstracts\Taxonomy;
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
        // update layers properties on ec models if there are some taxonomy_where properties on layer
        if (isset($layer->properties['taxonomy_where']) && count($layer->properties['taxonomy_where']) > 0) {
            $this->layerService->updateLayersPropertyOnAllLayeredFeaturesWithJobs($layer);
        }
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
