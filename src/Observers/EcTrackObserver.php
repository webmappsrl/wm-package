<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Jobs\Layer\SyncAutoLayerAfterTrackTaxonomyChangeJob;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\Layerable;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\Models\EcTrackService;
use Wm\WmPackage\Services\StorageService;

class EcTrackObserver extends AbstractEcObserver
{
    /**
     * Handle events after all transactions are committed.
     *
     * @var bool
     */
    public $afterCommit = true;

    public function __construct(
        protected GeometryComputationService $geometryComputationService,
        protected EcTrackService $ecTrackService
    ) {}

    /**
     * Handle the EcTrack "created" event.
     *
     * @return void
     */
    public function created(EcTrack $ecTrack)
    {
        $this->ecTrackService->createDataChain($ecTrack);
    }

    /**
     * Handle the EcTrack "saved" event.
     *
     * @return void
     */
    public function updated($ecTrack)
    {
        $this->ecTrackService->updateDataChain($ecTrack);
        $this->syncAutoLayersAfterNovaTrackEdit($ecTrack);

        if ($user = auth()->user()) {
            //  UserService::make()->assigUserAppIdIfNeeded($user, null, $ecTrack->app_id); TODO: attualmente non c'e' una migrazione valutare se inserirla in caso come array di app_id
        }
    }

    /**
     * Handle the EcTrack "saving" event.
     *
     * @return void
     */
    public function saving($ecTrack)
    {
        parent::saving($ecTrack);

        $properties = $ecTrack->properties;
        $properties['searchable'] = $ecTrack->getSearchableString();
        $ecTrack->setAttribute('properties', $properties);

        if (isset($ecTrack->properties['excerpt'])) {
            $properties = $ecTrack->properties;

            if (is_array($properties['excerpt'])) {
                foreach ($properties['excerpt'] as $locale => $text) {
                    if (is_string($text)) {
                        $properties['excerpt'][$locale] = substr($text, 0, 255);
                    }
                }
            } elseif (is_string($properties['excerpt'])) {
                $properties['excerpt'] = substr($properties['excerpt'], 0, 255);
            } elseif ($properties['excerpt'] === null) {
                $properties['excerpt'] = [];
            }

            $ecTrack->setAttribute('properties', $properties);
        }
    }

    /**
     * Handle the EcTrack "deleting" event.
     * Cancella i Layerable associati prima che l'EcTrack venga cancellato.
     * Questo triggera automaticamente il LayerableObserver::deleted() per ogni Layerable.
     *
     * @return void
     */
    public function deleting(EcTrack $ecTrack)
    {
        $ecTrackModelClass = config('wm-package.ec_track_model', 'App\Models\EcTrack');

        // Cancella tutti i Layerable associati a questa track
        // Questo triggera automaticamente LayerableObserver::deleted() per ogni record
        Layerable::where('layerable_id', $ecTrack->id)
            ->where('layerable_type', $ecTrackModelClass)
            ->delete();
    }

    /**
     * Handle the EcTrack "deleted" event.
     *
     * @return void
     */
    public function deleted(EcTrack $ecTrack)
    {
        $storageService = new StorageService;
        $storageService->deleteTrack($ecTrack->id);
    }

    private function syncAutoLayersAfterNovaTrackEdit(EcTrack $ecTrack): void
    {
        // Fallback robusto: alcuni aggiornamenti taxonomy da Nova non emettono eventi pivot.
        // In quel caso forziamo qui il riallineamento dei layer auto.
        $request = request();
        $isNovaTrackRequest = $request && $request->is('nova-api/ec-tracks*');
        if (! $isNovaTrackRequest) {
            return;
        }

        $taxonomyIds = $ecTrack->taxonomyActivities()->pluck('taxonomy_activities.id')->toArray();
        if (empty($taxonomyIds)) {
            return;
        }

        $candidateLayers = Layer::query()
            ->where(function ($query) use ($ecTrack) {
                $query->where('app_id', $ecTrack->app_id)
                    ->orWhereHas('associatedApps', fn ($q) => $q->where('apps.id', $ecTrack->app_id));
            })
            ->whereHas('taxonomyActivities', fn ($query) => $query->whereIn('taxonomy_activities.id', $taxonomyIds))
            ->get()
            ->filter(fn (Layer $layer) => $layer->isAutoTrackMode());
        $debounceAt = now()->addSeconds($this->getDebounceDelaySeconds());

        foreach ($candidateLayers as $layer) {
            SyncAutoLayerAfterTrackTaxonomyChangeJob::dispatch($layer->id, $ecTrack->id)
                ->delay($debounceAt);
        }
    }

    private function getDebounceDelaySeconds(): int
    {
        return app()->isLocal() ? 5 : 300;
    }
}
