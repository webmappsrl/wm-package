<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Jobs\Pbf\GenerateOptimizedPBFChainJob;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\LayerService;
use Wm\WmPackage\Services\PBFGeneratorService;

class LayerObserver extends AbstractObserver
{
    public function __construct(protected LayerService $layerService) {}

    /**
     * Handle the Layer "creating" event.
     *
     * @return void
     */
    public function creating(Model $layer)
    {
        $layer->rank = $this->layerService->getLayerMaxRank() + 1;
    }

    /**
     * Handle the Layer "saved" event.
     *
     * @return void
     */
    public function saved(Layer $layer)
    {
        // update layers properties on ec models ONLY if taxonomy_where properties have changed
        if ($this->hasTaxonomyWhereChanged($layer)) {
            $this->layerService->updateLayersPropertyOnAllLayeredFeaturesWithJobs($layer);
        }

        // Se il layer ha tassonomie di attività associate, assegna automaticamente le track
        if ($this->hasTaxonomyActivitiesChanged($layer)) {
            $this->assignTracksByTaxonomy($layer);
        }

        // Update App conf when layer properties change
        if ($layer->wasChanged('properties')) {
            $this->updateAppConf($layer);
        }

        // Aggiorna sempre la geometria del layer quando viene salvato
        $this->layerService->updateLayerGeometryWithJob($layer);

        $this->updatePbfsForLayer($layer);
    }

    /**
     * Check if taxonomy_where properties have changed
     */
    private function hasTaxonomyWhereChanged(Layer $layer): bool
    {
        // If this is a new record, always update
        if (! $layer->wasRecentlyCreated && $layer->wasChanged('properties')) {
            $original = $layer->getOriginal('properties') ?? [];
            $current = $layer->properties ?? [];

            $originalTaxonomyWhere = $original['taxonomy_where'] ?? [];
            $currentTaxonomyWhere = $current['taxonomy_where'] ?? [];

            // Check if taxonomy_where has actually changed
            return $originalTaxonomyWhere !== $currentTaxonomyWhere;
        }

        // For new records, only update if there are taxonomy_where properties
        if ($layer->wasRecentlyCreated) {
            return isset($layer->properties['taxonomy_where']) && count($layer->properties['taxonomy_where']) > 0;
        }

        return false;
    }

    /**
     * Check if taxonomy activities have changed
     */
    private function hasTaxonomyActivitiesChanged(Layer $layer): bool
    {
        // Se è un nuovo record e ha tassonomie di attività
        if ($layer->wasRecentlyCreated) {
            return $layer->taxonomyActivities()->count() > 0;
        }

        // Se le tassonomie di attività sono cambiate
        return $layer->wasChanged() && $layer->taxonomyActivities()->count() > 0;
    }

    /**
     * Assegna automaticamente le track che hanno le stesse tassonomie del layer
     */
    private function assignTracksByTaxonomy(Layer $layer): void
    {
        // Ottieni le tassonomie di attività del layer
        $layerTaxonomyIds = $layer->taxonomyActivities->pluck('id')->toArray();
        $layerAppIds = [
            $layer->app_id,
            ...$layer->associatedApps->pluck('id')->toArray(),
        ];

        if (empty($layerTaxonomyIds) || empty($layerAppIds)) {
            return;
        }

        // Ottieni tutte le track dell'app che hanno le stesse tassonomie
        $ecTrackModelClass = config('wm-package.ec_track_model', 'App\Models\EcTrack');
        $trackTable = (new $ecTrackModelClass)->getTable();

        // Usa una query con join diretto
        $trackIds = \DB::table('taxonomy_activityables')
            ->join($trackTable, 'taxonomy_activityables.taxonomy_activityable_id', '=', $trackTable.'.id')
            ->whereIn($trackTable.'.app_id', $layerAppIds)
            ->where('taxonomy_activityables.taxonomy_activityable_type', 'App\\Models\\EcTrack')
            ->whereIn('taxonomy_activityables.taxonomy_activity_id', $layerTaxonomyIds)
            ->whereNotNull($trackTable.'.geometry')
            ->pluck($trackTable.'.id')
            ->toArray();

        $tracksWithSameTaxonomy = $ecTrackModelClass::whereIn('id', $trackIds)->get();

        // Assegna le track al layer se non sono già assegnate
        foreach ($tracksWithSameTaxonomy as $track) {
            $alreadyAssigned = $layer->ecTracks()->where('layerable_id', $track->id)->exists();

            if (! $alreadyAssigned) {
                $layer->ecTracks()->attach($track->id, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                Log::info('Track assegnata automaticamente al layer per tassonomia', [
                    'layer_id' => $layer->id,
                    'track_id' => $track->id,
                    'taxonomy_ids' => $layerTaxonomyIds,
                ]);
            }
        }
    }

    private function updateAppConf(Layer $layer): void
    {
        // Dispatches il job con ritardo di 10 secondi per permettere ai media di essere processati
        UpdateAppConfigJob::dispatch($layer->app_id)
            ->delay(now()->addSeconds(10))
            ->onQueue('default');
    }

    public function saving($layer)
    {
        parent::saving($layer);
        if (is_null($layer->properties)) {
            $layer->properties = [];
        }
    }

    public function deleted(Layer $layer)
    {
        $this->updatePbfsForLayer($layer);

        $this->updateAppConf($layer);
    }

    public function updatePbfsForLayer(Layer $layer)
    {
        return;
        try {
            $trackIds = $layer->layerables()
                ->where('layerable_type', config('wm-package.ec_track_model', 'App\Models\EcTrack'))
                ->pluck('layerable_id')
                ->toArray();

            if (! empty($trackIds)) {
                GenerateOptimizedPBFChainJob::dispatch(
                    $layer->app->id,
                    5,  // minZoom
                    13, // minZoom
                    false,
                    $trackIds // Passa le track IDs già recuperate
                )->onConnection('redis')->onQueue('pbf');

                Log::info('PBF rigenerati per tracce multiple del layer', [
                    'layer_id' => $layer->id,
                    'track_count' => count($trackIds),
                    'app_id' => $layer->app->id,
                ]);
            } else {
                // Se non ci sono tracce, usa il metodo originale
                PBFGeneratorService::make()->generateWholeAppPbfsOptimized($layer->app);
            }
        } catch (\Exception $e) {
            Log::warning('Fallback a generateWholeAppPbfs per errore nella rigenerazione multipla', [
                'layer_id' => $layer->id,
                'error' => $e->getMessage(),
            ]);
            // Fallback al metodo originale
            PBFGeneratorService::make()->generateWholeAppPbfs($layer->app);
        }
    }
}
