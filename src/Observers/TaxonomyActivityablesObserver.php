<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Jobs\Layer\SyncAutoLayerAfterTrackTaxonomyChangeJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
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
        $isTrackType = $relatedTypeClass === $ecTrackModelClass || str_contains($relatedTypeClass, '\EcTrack');
        $iconName = $taxonomyActivityable->activity->icon;
        $iconExists = $iconName !== null && $appIconsService->existIcon($iconName);
        $shouldUpdate = $add ? ! $iconExists : $iconExists;

        Log::info('TaxonomyActivityablesObserver triggered', [
            'add' => $add,
            'type' => $relatedTypeClass,
            'model_class_config' => $ecTrackModelClass,
            'is_track_type' => $isTrackType,
            'taxonomy_activity_id' => $taxonomyActivityable->taxonomy_activity_id,
            'model_id' => $taxonomyActivityable->taxonomy_activityable_id,
        ]);

        if (str_contains($relatedTypeClass, '\Layer')) {
            $layer = Layer::find($taxonomyActivityable->taxonomy_activityable_id);
            if ($layer !== null) {
                // Ricalcola le track associate al layer in base alle tassonomie
                $this->layerService->assignTracksByTaxonomy($layer);

                // Aggiorna le features correlate
                $this->layerService->updateLayersPropertyOnAllLayeredFeaturesWithJobs($layer);

                // Aggiorna le icone dell'app
                if ($shouldUpdate && isset($layer->app_id)) {
                    $appIconsService->writeIconsOnAws($layer->app_id);
                }
            }
        } elseif (
            $isTrackType
            || str_contains($relatedTypeClass, '\EcPoi')
        ) {
            $layers = Layer::whereHas('taxonomyActivities', function ($query) use ($taxonomyActivityable) {
                $query->where('taxonomy_activities.id', $taxonomyActivityable->taxonomy_activity_id);
            })->get();

            // Aggiorna le features correlate
            $this->layerService->updateLayerIdsPropertyOnLayeredFeature($taxonomyActivityable->model, $layers->pluck('id')->toArray(), $add);

            // Se la tassonomia viene aggiunta/rimossa su una traccia, riallinea i layer in auto
            // e rigenera PBF per riflettere subito la nuova composizione del layer.
            $relatedModel = $taxonomyActivityable->model;
            if ($relatedModel instanceof EcTrack) {
                $debounceAt = now()->addSeconds($this->getDebounceDelaySeconds());
                $candidateLayers = Layer::query()
                    ->whereHas('taxonomyActivities', fn ($query) => $query->where('taxonomy_activities.id', $taxonomyActivityable->taxonomy_activity_id))
                    ->where(function ($query) use ($relatedModel) {
                        $query->where('app_id', $relatedModel->app_id)
                            ->orWhereHas('associatedApps', fn ($q) => $q->where('apps.id', $relatedModel->app_id));
                    })
                    ->get()
                    ->filter(fn (Layer $layer) => $layer->isAutoTrackMode());

                foreach ($candidateLayers as $layer) {
                    SyncAutoLayerAfterTrackTaxonomyChangeJob::dispatch($layer->id, $relatedModel->id)
                        ->delay($debounceAt);
                }

                Log::info('Auto layers resynced for track taxonomy change', [
                    'track_id' => $relatedModel->id,
                    'candidate_layers_count' => $candidateLayers->count(),
                    'candidate_layer_ids' => $candidateLayers->pluck('id')->toArray(),
                ]);

                // Reindex eseguito nel job di sync layer, dopo aggiornamento pivot.
            }

            // Aggiorna le icone dell'app
            if ($shouldUpdate && $relatedModel && isset($relatedModel->user_id)) {
                $user = User::find($relatedModel->user_id);
                $apps = App::where('user_id', $user->id)->get();
                foreach ($apps as $app) {
                    $appIconsService->writeIconsOnAws($app->id);
                }
            }
        }
    }

    private function getDebounceDelaySeconds(): int
    {
        return app()->isLocal() ? 5 : 300;
    }
}
