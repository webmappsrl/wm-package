<?php

namespace Wm\WmPackage\Services\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\BaseService;
use Wm\WmPackage\Services\GeometryComputationService;

class LayerService extends BaseService
{
    public function getLayerMaxRank()
    {
        return DB::table('layers')->selectRaw('max(rank)')->value('max') ?? 0;
    }

    public function hasRelatedManualModels(Layer $layer, string $model): bool
    {
        return $layer->{(new $model)->getLayerRelationName()}->first() !== null;
    }

    public function getRelatedModels(Layer $layer, string $model, $count = false): Collection|int
    {
        $relationName = (new $model)->getLayerRelationName();

        if ($this->hasRelatedManualModels($layer, $model)) {
            return $count ? $layer->$relationName->count() : $layer->$relationName;
        }

        return $this->getAllVisibleModels($model, $layer, false, $count);
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
     * @param  bool  $collection  - If true, returns a collection of models. Otherwise, returns an array of IDs.
     * @param  bool  $count  - If true, returns the count of the models. Otherwise, returns the models themselves.
     */
    public function getAllVisibleModels(
        string $geometryModelClass,
        Layer $layer,
        $collection = false,
        $count = false
    ): array|Collection|int {
        $features = $this->getAllVisibleModelsQuery($geometryModelClass, $layer);

        if ($count) {
            return $features->count();
        }

        $features = $features->get();

        // Se collection è true, ritorna direttamente tutte le tracce raccolte
        if ($collection) {
            return $features;
        }

        // Popola l'array dei track IDs
        $trackIds = $features->pluck('id')->toArray();

        return $trackIds;
    }

    public function getPbfTracks(Layer $layer)
    {
        // Chiamata a getTracks per ottenere la collection delle tracce filtrate
        $allEcTracks = $layer->ecTracks;

        // Verifica che ci siano tracce disponibili
        if ($allEcTracks->isEmpty()) {
            Log::channel('layer')->info('Nessuna traccia trovata da getTracks.');

            return collect(); // Restituisci una collezione vuota
        }

        // Logga il numero di tracce filtrate dalla geometria e dalle tassonomie
        Log::channel('layer')->info('Numero di tracce finali filtrate da getTracks: '.$allEcTracks->count());

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
                $saved = $layer->save();
            }
        }

        return $saved;
    }
}
