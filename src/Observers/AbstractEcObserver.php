<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\LayerService;

class AbstractEcObserver extends AbstractObserver
{
    public function saved($model)
    {
        // update layers properties on ec models if there are some taxonomy_where properties on layer

        // ## TAXONOMY WHERE - strings inside properties
        // check the local scope here wm-package/src/Traits/TaxonomyWhereAbleModel.php
        if (isset($model->properties['taxonomy_where']) && count($model->properties['taxonomy_where']) > 0) {
            //filter by properties only if there are some wheres
            $layers = Layer::byWhereProperty($model->properties)->get();
            if ($layers->count() > 0) {
                LayerService::make()->updateLayerIdsPropertyOnLayeredFeature($model, $layers->pluck('id')->toArray(), true);
            }
        }
    }

    // public function morphToManyAttached($relation, $parent, $ids, $attributes)
    // {
    //     $this->morphToManyEvent($relation, $parent, $ids, true);
    // }

    // public function morphToManyDetached($relation, $parent, $ids)
    // {
    //     $this->morphToManyEvent($relation, $parent, $ids, false);
    // }

    // // custom method, it's not a laravel event
    // // Used to update layers property when a Layer is manually attached to the ec model
    // private function morphToManyEvent($relation, $parent, $ids, $add)
    // {
    //     $relatedModel = $parent->$relation()->getRelated();

    //     // "manual" attach of layer
    //     if (
    //         str_contains($relatedModel::class, '\Layer')
    //     ) {
    //         LayerService::make()->updateLayerIdsPropertyOnLayeredFeature($parent, $ids, $add);
    //     }
    // }
}
