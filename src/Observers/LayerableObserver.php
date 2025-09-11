<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Models\Layerable;
use Wm\WmPackage\Services\Models\LayerService;

class LayerableObserver
{
    public function __construct(protected LayerService $layerService) {}

    public function created(Layerable $layerable)
    {
        $this->handleRelatedFeaturesUpdate($layerable, true);
    }

    public function deleted(Layerable $layerable)
    {
        $this->handleRelatedFeaturesUpdate($layerable, false);
    }

    private function handleRelatedFeaturesUpdate(Layerable $layerable, $add)
    {
        $relatedTypeClass = $layerable->layerable_type;
        $ecTrackModelClass = config('wm-package.ec_track_model', 'App\Models\EcTrack');
        if (
            $relatedTypeClass === $ecTrackModelClass
            || str_contains($relatedTypeClass, '\EcPoi')
        ) {
            $this->layerService->updateLayerIdsPropertyOnLayeredFeature($layerable->model, [$layerable->layer->id], $add);
            $this->layerService->updateLayerGeometryWithJob($layerable->layer);
        }
    }
}
