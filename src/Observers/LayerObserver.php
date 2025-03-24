<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Jobs\UpdateLayerGeometryJob;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\LayerService;

class LayerObserver extends AbstractObserver
{
    /**
     * Handle the Layer "creating" event.
     *
     * @return void
     */
    public function creating(Model $layer)
    {
        $layer->rank = LayerService::make()->getLayerMaxRank() + 1;
    }

    /**
     * Handle the Layer "saved" event.
     *
     * @return void
     */
    public function saved(Layer $layer) {}

    public function morphToManyAttached($relation, $parent, $ids, $attributes)
    {
        UpdateLayerGeometryJob::dispatch($parent);
    }

    public function morphToManyDetached($relation, $parent, $ids)
    {
        UpdateLayerGeometryJob::dispatch($parent);
    }
}
