<?php

namespace Wm\WmPackage\Services\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Jobs\UpdateLayeredFeaturesJob;
use Wm\WmPackage\Jobs\UpdateLayerGeometryJob;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\BaseService;
use Wm\WmPackage\Services\GeometryComputationService;

class LayerService extends BaseService
{
    public function getLayerMaxRank(?int $appId = null): int
    {
        $query = DB::table('layers')->selectRaw('max(rank) as max_rank');
        if (! is_null($appId)) {
            $query->where('app_id', $appId);
        }

        return (int) ($query->value('max_rank') ?? 0);
    }

    public function hasRelatedManualModels(Layer $layer, string $model): bool
    {
        // Log::info('RELATION', [
        //     'model' => $model,
        //     'relation' => (new $model)->getLayerRelationName()
        // ]);
        return $layer->{(new $model)->getLayerRelationName()}->first() !== null;
    }

    public function getRelatedModels(Layer $layer, string $model, $count = false): Collection|int
    {
        $relationName = (new $model)->getLayerRelationName();

        if ($this->hasRelatedManualModels($layer, $model)) {
            return $count ? $layer->$relationName->count() : $layer->$relationName;
        }

        return $this->getAllVisibleModels($model, $layer, $count);
    }

    private function getRelatedModelsQuery(string $geometryModelClass, Layer $layer): MorphToMany|Builder
    {

        $relationName = (new $geometryModelClass)->getLayerRelationName();

        if ($this->hasRelatedManualModels($layer, $geometryModelClass)) {
            return $layer->$relationName();
        }

        return $this->getAllVisibleModelsQuery($geometryModelClass, $layer);
    }

    private function getAllVisibleModelsQuery(string $geometryModelClass, Layer $layer): Builder
    {
        return (new $geometryModelClass)
            ->onLayer($layer) // Local scope in EcFeatureTrait
            ->orderBy('id')
            ->orderBy('name');
    }

    /**
     * Get all visible models for a given layer.
     * A visible model has:
     *  - the same taxonomies of the layer
     *  - a geometry
     *  - an app_id column that matches the layer's app_id or one of the associated apps
     *
     * @param  string  $geometryModelClass  - The class name of the geometry model
     * @param  bool  $count  - If true, returns the count of the models. Otherwise, returns the models themselves.
     */
    public function getAllVisibleModels(
        string $geometryModelClass,
        Layer $layer,
        $count = false
    ): Collection|int {
        $features = $this->getAllVisibleModelsQuery($geometryModelClass, $layer);

        if ($count) {
            return $features->count();
        }

        $features = $features->get();

        return $features;
    }

    public function getPbfTracks(Layer $layer)
    {
        // Chiamata a getTracks per ottenere la collection delle tracce filtrate
        $allEcTracks = $layer->ecTracks;

        // Verifica che ci siano tracce disponibili
        if ($allEcTracks->isEmpty()) {
            // Log::channel('layer')->info('Nessuna traccia trovata da getTracks.');

            return collect(); // Restituisci una collezione vuota
        }

        // Logga il numero di tracce filtrate dalla geometria e dalle tassonomie
        // Log::channel('layer')->info('Numero di tracce finali filtrate da getTracks: ' . $allEcTracks->count());

        // Restituisci tracce uniche in base all'ID
        return $allEcTracks->unique('id');
    }

    public function getLayerUserID(Layer $layer)
    {
        return DB::table('apps')->where('id', $layer->app_id)->select(['user_id'])->first()->user_id;
    }

    /**
     * Returns a list of taxonomy IDs associated with the layer.
     *
     * @return array
     */
    public function getLayerTaxonomyIDs(Layer $layer)
    {
        $ids = [];

        if ($layer->taxonomyThemes->count() > 0) {
            $ids['themes'] = $layer->taxonomyThemes->pluck('id')->toArray();
        }
        if ($layer->taxonomyWheres->count() > 0) {
            $ids['wheres'] = $layer->taxonomyWheres->pluck('id')->toArray();
        }
        if ($layer->taxonomyActivities->count() > 0) {
            $ids['activities'] = $layer->taxonomyActivities->pluck('id')->toArray();
        }

        return $ids;
    }

    public function updateLayerGeometry(Layer $layer, $save = true): bool
    {
        $relatedFeaturesQuery = $this->getRelatedModelsQuery(EcTrack::class, $layer);
        $geometry = GeometryComputationService::make()->geometryModelToBbox($relatedFeaturesQuery);

        $saved = false;
        if ($geometry !== $layer->geometry) {
            $layer->geometry = $geometry;
            if ($save) {
                // Use saveQuietly to avoid triggering observers and prevent infinite loops
                // This method is called from observers and jobs, so we need to prevent observer loops
                $saved = $layer->saveQuietly();
            }
        }

        return $saved;
    }

    // public function chainLayersFeaturedPropertiesUpdate($layers)
    // {
    //     $jobs = [];
    //     foreach ($layers as $layer) {
    //         foreach ($this->getModelsWithLayersInProperties() as $modelClass) {
    //             $jobs[] = new UpdateLayeredFeaturesJob($layer, $modelClass);
    //         }
    //     }
    //     Bus::chain($jobs)->delay($this->getUniqueJobDelay())->dispatch();
    // }

    public function updateLayerIdsPropertyOnLayeredFeature(GeometryModel $geometryModel, array $layerIds, bool $add)
    {
        $properties = $geometryModel->properties;

        if (! isset($properties['layers'])) {
            $properties['layers'] = [];
        }

        if ($add) {
            $properties['layers'] = array_merge($properties['layers'], $layerIds);
        } else {
            $properties['layers'] = array_diff($properties['layers'], $layerIds);
        }

        $properties['layers'] = array_values($properties['layers']);

        $geometryModel->properties = $properties;
        // Use saveQuietly to avoid triggering observers and prevent infinite loops
        $geometryModel->saveQuietly();
    }

    public function updateLayersPropertyOnAllLayeredFeaturesWithJobs(Layer $layer, bool $delay = true)
    {
        // update all ecpoi and ectrack related to the layer
        foreach ($this->getModelsWithLayersInProperties() as $modelClass) {
            $this->updateLayersPropertyOnLayeredFeatureWithJob($layer, $modelClass, $delay);
        }
        // Bus::batch([$jobs])->name("Layer {$layer->id} features properties update")->dispatch(); //to avoid transactions errors
    }

    public function updateLayersPropertyOnLayeredFeatureWithJob(Layer $layer, string $ecModelClass, bool $delay = true)
    {
        UpdateLayeredFeaturesJob::dispatch($layer, $ecModelClass)
            ->delay($delay ? $this->getUniqueJobDelay() : null);
    }

    public function updateLayerGeometryWithJob(Layer $layer)
    {
        UpdateLayerGeometryJob::dispatch($layer);
    }

    /**
     * Update the layers property on the features related to a specific layer
     *
     * @param  string  $ecModelClass  - the class string related to the layer
     * @return array - ids of feature saved
     *
     * @throws Exception
     */
    public function updateLayersPropertyOnLayeredFeature(Layer $layer, string $ecModelClass): array
    {
        // https://neon.tech/postgresql/postgresql-json-functions/postgresql-jsonb-operators

        // Features where add the layer
        $newLayerFeatures = $this->getRelatedModelsQuery($ecModelClass, $layer)
            ->whereRaw(
                "(
                    NOT \"properties\"->'layers' @> '[{$layer->id}]'::jsonb
                    OR \"properties\"->'layers' is null
                )" // where the feature doesn't have the layer
            );

        // Log::info($newLayerFeatures->toSql());
        $newLayerFeatures = $newLayerFeatures->get();

        // Features where remove the layer
        $layerFeaturesIds = $this->getRelatedModels($layer, $ecModelClass)->pluck('id')->toArray();
        $oldLayerFeatures = $ecModelClass::whereNotIn('id', $layerFeaturesIds)
            ->whereRaw(
                "\"properties\"->'layers' @> '[{$layer->id}]'::jsonb" // where the feature has the layer
            );

        // Log::info($oldLayerFeatures->toSql());
        $oldLayerFeatures = $oldLayerFeatures->get();

        $added = [];
        $deleted = [];

        DB::beginTransaction();
        try {
            // useful if in the past the feature was associated to the layer and now it's not
            foreach ($oldLayerFeatures as $feature) {
                $properties = $feature->properties;
                // remove the layer from the properties
                $properties['layers'] = array_diff($properties['layers'], [$layer->id]);
                $properties['layers'] = array_values($properties['layers']);
                $feature->properties = $properties;
                // Use saveQuietly to avoid triggering observers and prevent infinite loops
                $feature->saveQuietly();
                $deleted[] = $feature->id;
            }
            $oldLayerFeatures = null;

            // Add the layer to the features that don't have it
            foreach ($newLayerFeatures as $feature) {
                // Save only features that don't have the layer
                $properties = $feature->properties;
                $properties['layers'][] = $layer->id;
                $feature->properties = $properties;
                // Use saveQuietly to avoid triggering observers and prevent infinite loops
                $feature->saveQuietly();
                $added[] = $feature->id;
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return [
            'added' => $added,
            'deleted' => $deleted,
        ];
    }

    private function getUniqueJobDelay(): Carbon
    {
        return now()->addSeconds(
            app()->isLocal() ? 5 : 60 * 5
        );
    }

    public function getModelsWithLayersInProperties(): array
    {
        return [
            EcPoi::class,
            EcTrack::class,
        ];
    }

    /**
     * Assegna automaticamente le track che hanno le stesse tassonomie del layer.
     * Supporta filtro per TaxonomyActivity (JOIN), TaxonomyWhere (ST_Intersects), o entrambi (AND).
     */
    public function assignTracksByTaxonomy(Layer $layer): void
    {
        if (! $layer->isAutoTrackMode()) {
            return;
        }

        $layerTaxonomyIds = $layer->taxonomyActivities->pluck('id')->toArray();
        $layerWhereIds = $layer->taxonomyWheres->pluck('id')->filter()->toArray();

        $layerAppIds = [
            $layer->app_id,
            ...$layer->associatedApps->pluck('id')->toArray(),
        ];

        if (empty($layerAppIds) || (empty($layerTaxonomyIds) && empty($layerWhereIds))) {
            $layer->ecTracks()->sync([]);

            return;
        }

        $ecTrackModelClass = config('wm-package.ec_track_model', 'App\Models\EcTrack');
        $trackTable = (new $ecTrackModelClass)->getTable();
        $trackMorphTypes = array_values(array_unique([
            $ecTrackModelClass,
            EcTrack::class,
            'App\\Models\\EcTrack',
        ]));

        // Base query: app filter + geometry not null
        $query = DB::table($trackTable)
            ->whereIn("{$trackTable}.app_id", $layerAppIds)
            ->whereNotNull("{$trackTable}.geometry");

        // Filter by TaxonomyActivity via JOIN (se presenti)
        if (! empty($layerTaxonomyIds)) {
            $query->join(
                'taxonomy_activityables',
                'taxonomy_activityables.taxonomy_activityable_id',
                '=',
                "{$trackTable}.id"
            )
                ->whereIn('taxonomy_activityables.taxonomy_activityable_type', $trackMorphTypes)
                ->whereIn('taxonomy_activityables.taxonomy_activity_id', $layerTaxonomyIds);
        }

        // Filter by TaxonomyWhere via ST_Intersects (se presenti)
        if (! empty($layerWhereIds)) {
            $query->whereExists(function ($sub) use ($layerWhereIds, $trackTable) {
                $sub->select(DB::raw(1))
                    ->from('taxonomy_wheres')
                    ->whereIn('taxonomy_wheres.id', $layerWhereIds)
                    ->whereNotNull('taxonomy_wheres.geometry')
                    ->whereRaw("ST_Intersects({$trackTable}.geometry::geometry, taxonomy_wheres.geometry::geometry)");
            });
        }

        $trackIds = $query->distinct()->pluck("{$trackTable}.id")->toArray();

        $now = now();
        $syncPayload = [];
        foreach ($trackIds as $trackId) {
            $syncPayload[$trackId] = [
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $layer->ecTracks()->sync($syncPayload);

        Log::info('Track sincronizzate automaticamente al layer', [
            'layer_id' => $layer->id,
            'track_count' => count($trackIds),
            'taxonomy_ids' => $layerTaxonomyIds,
            'where_ids' => $layerWhereIds,
        ]);
    }
}
