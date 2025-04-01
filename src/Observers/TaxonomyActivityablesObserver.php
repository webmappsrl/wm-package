<?php


namespace Wm\WmPackage\Observers;

use App\Nova\TaxonomyActivity;
use Wm\WmPackage\Models\Layer;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\TaxonomyActivityable;
use Wm\WmPackage\Services\Models\LayerService;

class TaxonomyActivityablesObserver
{

    public function __construct(protected LayerService $layerService) {}
    public function created(TaxonomyActivityable $taxonomyActivityable)
    {
        $this->handleRelatedFeaturesUpdate($taxonomyActivityable);
    }
    public function deleted(TaxonomyActivityable $taxonomyActivityable)
    {
        $this->handleRelatedFeaturesUpdate($taxonomyActivityable);
    }

    private function handleRelatedFeaturesUpdate(TaxonomyActivityable $taxonomyActivityable)
    {
        $relatedTypeClass = $taxonomyActivityable->taxonomy_activityable_type;
        if (
            str_contains($relatedTypeClass, '\Layer')

        ) {
            $layer = Layer::find($taxonomyActivityable->taxonomy_activityable_id);
            if ($layer !== null)
                $this->layerService->updateLayersPropertyOnAllLayeredFeaturesWithJobs($layer);
        } elseif (
            str_contains($relatedTypeClass, '\EcTrack')
            || str_contains($relatedTypeClass, '\EcPoi')
        ) {
            //TODO: here probably is better to find a way to get layers from ec feature
            Layer::all()->each(function ($layer) {
                $this->layerService->updateLayersPropertyOnAllLayeredFeaturesWithJobs($layer);
            });
        }
    }
}
