<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\EcTrack;
use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Models\Abstracts\Taxonomy;
use Wm\WmPackage\Jobs\UpdateLayerGeometryJob;
use Wm\WmPackage\Services\Models\LayerService;
use Wm\WmPackage\Jobs\UpdateLayeredFeaturesJob;

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
        //update layers properties on ec models if there are some taxonomy_where properties on layer
        if (isset($layer->properties['taxonomy_where']) && count($layer->properties['taxonomy_where']) > 0)
            $this->layerService->updateLayersPropertyOnAllLayeredFeaturesWithJobs($layer);
    }

    public function saving(Layer $layer)
    {
        if (is_null($layer->properties)) {
            $layer->properties = [];
        }
    }

    public function morphToManyAttached($relation, $parent, $ids, $attributes)
    {
        $this->morphToManyEvent($relation, $parent, $ids);
    }

    public function morphToManyDetached($relation, $parent, $ids)
    {
        $this->morphToManyEvent($relation, $parent, $ids);
    }

    //custom method, it's not a laravel event
    private function morphToManyEvent($relation, $parent, $ids)
    {
        $relatedModel = $parent->$relation()->getRelated();
        $modelsWithLayerableProperties = $this->layerService->getModelsWithLayersInProperties();

        //"manual" attach of features
        if (
            in_array($relatedModel::class, $modelsWithLayerableProperties)
        ) {
            $this->layerService->updateLayersPropertyOnLayeredFeatureWithJob($parent, $relatedModel::class);
        }
        //"automatic" attach of features
        // MOVED TO wm-package/src/Observers/TaxonomyActivityablesObserver.php
        // due this issue https://github.com/chelout/laravel-relationship-events/issues/16
        // elseif ($relatedModel instanceof Taxonomy) {
        //     $this->layerService->updateLayersPropertyOnAllLayeredFeaturesWithJobs($parent);
        // }


        $this->layerService->updateLayerGeometryWithJob($parent);
    }
}
