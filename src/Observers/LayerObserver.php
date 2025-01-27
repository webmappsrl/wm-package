<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\EcMedia;
use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Services\LayerService;
use Wm\WmPackage\Jobs\UpdateLayerTracksJob;
use Wm\WmPackage\Services\GeometryComputationService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LayerObserver extends AbstractObserver
{
    /**
     * Handle the Layer "creating" event.
     *
     * @return void
     */
    public function creating(Model $layer)
    {
        $layer->rank = LayerService::make()->getLayerMaxRank($layer) + 1;
    }


    /**
     * Handle the Layer "saved" event.
     *
     * @return void
     */
    public function saved(Layer $layer)
    {
        dispatch(new UpdateLayerTracksJob($layer))->onQueue('layers');
    }
}
