<?php

namespace Wm\WmPackage\Observers;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Jobs\Layer\SyncAutoLayerAfterPoiTaxonomyChangeJob;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\EcPoiService;
use Wm\WmPackage\Services\Models\EcTrackService;
use Wm\WmPackage\Services\StorageService;

class EcPoiObserver extends AbstractEcObserver
{
    /**
     * Handle the EcPoi "deleting" event.
     *
     * @return void
     */
    public function deleting(EcPoi $ecPoi)
    {
        if ($ecPoi->ecTracks()->exists()) {
            throw new HttpException(500, 'Cannot delete this POI because it is linked to one or more tracks.');
        }

        app(StorageService::class)->deleteModelFiles($ecPoi);
    }

    /**
     * Handle the EcPoi "deleted" event.
     *
     * @return void
     */
    public function deleted(EcPoi $ecPoi)
    {
        $app = $ecPoi->app;
        if ($app) {
            BuildAppPoisGeojsonJob::dispatch($app->id);
        }
    }

    /**
     * Handle the EcPoi "saved" event.
     *
     * @return void
     */
    public function saved($ecPoi)
    {
        parent::saved($ecPoi);
        EcPoiService::make()->updateDataChain($ecPoi);

        // Aggiorna anche gli EcTrack collegati a questo EcPoi
        $ecTracks = $ecPoi->ecTracks;
        if ($ecTracks && $ecTracks->isNotEmpty()) {
            $ecTrackService = app(EcTrackService::class);
            foreach ($ecTracks as $ecTrack) {
                $ecTrackService->updateDataChain($ecTrack);
            }
        }

        $this->syncAutoLayersAfterNovaPoiEdit($ecPoi);

        // UserService::make()->assigUserAppIdIfNeeded(null, null, $ecPoi->app_id);
        $app = $ecPoi->app;
        if ($app) {
            BuildAppPoisGeojsonJob::dispatch($app->id);
        }
    }

    /**
     * Fallback robusto: alcuni aggiornamenti taxonomy da Nova non emettono eventi pivot.
     * In quel caso forziamo qui il riallineamento dei layer auto (mirror di
     * EcTrackObserver::syncAutoLayersAfterNovaTrackEdit()).
     */
    private function syncAutoLayersAfterNovaPoiEdit(EcPoi $ecPoi): void
    {
        $request = request();
        $isNovaPoiRequest = $request && $request->is('nova-api/ec-pois*');
        if (! $isNovaPoiRequest) {
            return;
        }

        $taxonomyIds = $ecPoi->taxonomyActivities()->pluck('taxonomy_activities.id')->toArray();
        if (empty($taxonomyIds)) {
            return;
        }

        $candidateLayers = Layer::query()
            ->where(function ($query) use ($ecPoi) {
                $query->where('app_id', $ecPoi->app_id)
                    ->orWhereHas('associatedApps', fn ($q) => $q->where('apps.id', $ecPoi->app_id));
            })
            ->whereHas('taxonomyActivities', fn ($query) => $query->whereIn('taxonomy_activities.id', $taxonomyIds))
            ->get()
            ->filter(fn (Layer $layer) => $layer->isAutoPoiMode());
        $debounceAt = now()->addSeconds($this->getDebounceDelaySeconds());

        foreach ($candidateLayers as $layer) {
            SyncAutoLayerAfterPoiTaxonomyChangeJob::dispatch($layer->id, $ecPoi->id)
                ->delay($debounceAt);
        }
    }

    private function getDebounceDelaySeconds(): int
    {
        return app()->isLocal() ? 5 : 300;
    }
}
