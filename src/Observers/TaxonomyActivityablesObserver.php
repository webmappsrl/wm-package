<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyActivityable;
use Wm\WmPackage\Services\Models\LayerService;

class TaxonomyActivityablesObserver
{
    public function __construct(protected LayerService $layerService) {}

    public function created(TaxonomyActivityable $taxonomyActivityable)
    {
        $this->handleRelatedFeaturesUpdate($taxonomyActivityable, true);
    }

    public function deleted(TaxonomyActivityable $taxonomyActivityable)
    {
        $this->handleRelatedFeaturesUpdate($taxonomyActivityable, false);
    }

    private function handleRelatedFeaturesUpdate(TaxonomyActivityable $taxonomyActivityable, $add)
    {
        $relatedTypeClass = $taxonomyActivityable->taxonomy_activityable_type;
        if (
            str_contains($relatedTypeClass, '\Layer')

        ) {
            $layer = Layer::find($taxonomyActivityable->taxonomy_activityable_id);
            if ($layer !== null) {
                $this->layerService->updateLayersPropertyOnAllLayeredFeaturesWithJobs($layer);
                $this->layerService->updateLayerGeometryWithJob($layer);
            }
        } elseif (
            str_contains($relatedTypeClass, '\EcTrack')
            || str_contains($relatedTypeClass, '\EcPoi')
        ) {

            $layers = Layer::whereHas('taxonomyActivities', function ($query) use ($taxonomyActivityable) {
                $query->where('taxonomy_activities.id', $taxonomyActivityable->taxonomy_activity_id);
            })->get();

            $this->layerService->updateLayerIdsPropertyOnLayeredFeature($taxonomyActivityable->model, $layers->pluck('id')->toArray(), $add);
            foreach ($layers as $layer) {
                $this->layerService->updateLayerGeometryWithJob($layer);
            }
        }
    }
}
