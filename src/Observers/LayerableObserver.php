<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Models\Layerable;
use Wm\WmPackage\Services\Models\LayerService;
use Wm\WmPackage\Services\PBFGeneratorService;

class LayerableObserver
{
    protected LayerService $layerService;

    protected PBFGeneratorService $pbfGeneratorService;

    public function __construct(
        LayerService $layerService,
        PBFGeneratorService $pbfGeneratorService
    ) {
        $this->layerService = $layerService;
        $this->pbfGeneratorService = $pbfGeneratorService;
    }

    public function created(Layerable $layerable)
    {
        $this->handleRelatedFeaturesUpdate($layerable, true);
    }

    public function deleting(Layerable $layerable)
    {
        logger()->info('LayerableObserver: deleting', [
            'layerable_id' => $layerable->id,
            'layerable_type' => $layerable->layerable_type,
            'layer_id' => $layerable->layer_id,
        ]);
    }

    public function deleted(Layerable $layerable)
    {
        logger()->info('LayerableObserver: deleted', [
            'layerable_id' => $layerable->id,
            'layerable_type' => $layerable->layerable_type,
            'layer_id' => $layerable->layer_id,
        ]);
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

            // Rigenera i PBF ottimizzati per tutte le tracks del layer (solo per EcTrack)
            if ($relatedTypeClass === $ecTrackModelClass) {
                $this->pbfGeneratorService->regeneratePbfsForLayer($layerable->layer);
            }
        }
    }
}
