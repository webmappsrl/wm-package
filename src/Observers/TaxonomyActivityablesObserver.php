<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyActivityable;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\AppIconsService;
use Wm\WmPackage\Services\Models\LayerService;

class TaxonomyActivityablesObserver
{
    public function __construct(protected LayerService $layerService) {}

    public function created(TaxonomyActivityable $taxonomyActivityable)
    {
        $this->handleTaxonomyAssignment($taxonomyActivityable, true);
    }

    public function deleted(TaxonomyActivityable $taxonomyActivityable)
    {
        $this->handleTaxonomyAssignment($taxonomyActivityable, false);
    }

    private function handleTaxonomyAssignment(TaxonomyActivityable $taxonomyActivityable, $add)
    {
        $appIconsService = AppIconsService::make();
        $relatedTypeClass = $taxonomyActivityable->taxonomy_activityable_type;
        $ecTrackModelClass = config('wm-package.ec_track_model', 'App\Models\EcTrack');
        $iconName = $taxonomyActivityable->activity->icon;
        $iconExists = $appIconsService->existIcon($iconName);
        $shouldUpdate = $add ? ! $iconExists : $iconExists;

        if (str_contains($relatedTypeClass, '\Layer')) {
            $layer = Layer::find($taxonomyActivityable->taxonomy_activityable_id);
            if ($layer !== null) {
                // Aggiorna le features correlate
                $this->layerService->updateLayersPropertyOnAllLayeredFeaturesWithJobs($layer);
                $this->layerService->updateLayerGeometryWithJob($layer);

                // Aggiorna le icone dell'app
                if ($shouldUpdate && isset($layer->app_id)) {
                    $appIconsService->writeIconsOnAws($layer->app_id);
                }
            }
        } elseif (
            $relatedTypeClass === $ecTrackModelClass
            || str_contains($relatedTypeClass, '\EcPoi')
        ) {
            $layers = Layer::whereHas('taxonomyActivities', function ($query) use ($taxonomyActivityable) {
                $query->where('taxonomy_activities.id', $taxonomyActivityable->taxonomy_activity_id);
            })->get();

            // Aggiorna le features correlate
            $this->layerService->updateLayerIdsPropertyOnLayeredFeature($taxonomyActivityable->model, $layers->pluck('id')->toArray(), $add);
            foreach ($layers as $layer) {
                $this->layerService->updateLayerGeometryWithJob($layer);
            }

            // Aggiorna le icone dell'app
            $relatedModel = $taxonomyActivityable->model;
            if ($shouldUpdate && $relatedModel && isset($relatedModel->user_id)) {
                $user = User::find($relatedModel->user_id);
                $apps = App::where('user_id', $user->id)->get();
                foreach ($apps as $app) {
                    $appIconsService->writeIconsOnAws($app->id);
                }
            }
        }
    }
}
