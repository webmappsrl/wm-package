<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Jobs\Pbf\GenerateAppPBFJob;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\Models\EcTrackService;
use Wm\WmPackage\Services\Models\UserService;

class AbstractEcObserver extends AbstractObserver
{

    public function morphToManyAttached($relation, $parent, $ids, $attributes)
    {
        $this->morphToManyEvent($relation, $parent, $ids, true);
    }

    public function morphToManyDetached($relation, $parent, $ids)
    {
        $this->morphToManyEvent($relation, $parent, $ids, false);
    }

    // custom method, it's not a laravel event
    // Used to update layers property when a Layer is manually attached to the ec model
    private function morphToManyEvent($relation, $parent, $ids, $add)
    {
        $relatedModel = $parent->$relation()->getRelated();


        // "manual" attach of layer
        if (
            str_contains($relatedModel::class, '\Layer')
        ) {
            $properties = $parent->properties;
            if ($add)
                $properties['layers'] = array_merge($properties['layers'], $ids);
            else
                $properties['layers'] = array_diff($properties['layers'], $ids);

            $parent->properties = $properties;
            $parent->saveQuietly();
        }
    }
}
