<?php

namespace Wm\WmPackage\Services\Models;

use Exception;
use Illuminate\Database\Eloquent\Collection;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\EcTrack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\BaseService;

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


    /**
     * Get all visible models for a given layer.
     * A visible model has:
     *  - the same taxonomies of the layer
     *  - a geometry
     *  - an app_id column that matches the layer's app_id or one of the associated apps
     *
     * @param string $geometryModelClass - The class name of the geometry model
     * @param Layer $layer
     * @param boolean $collection - If true, returns a collection of models. Otherwise, returns an array of IDs.
     * @param boolean $count - If true, returns the count of the models. Otherwise, returns the models themselves.
     * @return array|Collection|int
     */
    public function getAllVisibleModels(
        string $geometryModelClass,
        Layer $layer,
        $collection = false,
        $count = false
    ): array|Collection {
        $allEcTracks = $geometryModelClass::getQuery()->whereIn('app_id', [
            $layer->app_id,
            ...$layer->associatedApps->pluck('id')->toArray(),
        ])
            ->whereNotNull('geometry')  // Controlla che la geometria non sia null
            # https://postgis.net/docs/ST_Dimension.html
            ->where(function ($query) use ($layer) {

                ### TAXONOMY WHERE - strings inside properties
                $layerWhere = $layer->properties['taxonomy_where'] ?? [];
                if (count($layerWhere) > 0) {
                    $layerWhereIdentifiers = collect($layerWhere)->keys();
                    foreach ($layerWhereIdentifiers as $key => $value) {
                        $query->orWhereRaw("properties->'taxonomy_where' ? '$value'");
                    }
                }

                ### TAXONOMY ACTIVITY - relation
                $query->orHas('taxonomyActivity', function ($query) use ($layer) {
                    $query->whereIn('id', $layer->taxonomyActivity->pluck('id')->toArray());
                });
            })
            ->orderBy('id')
            ->orderBy('name');

        if ($count) {
            return $allEcTracks->count();
        }

        $allEcTracks = $allEcTracks->get();

        // Se collection è true, ritorna direttamente tutte le tracce raccolte
        if ($collection) {
            return $allEcTracks;
        }

        // Popola l'array dei track IDs
        $trackIds = $allEcTracks->pluck('id')->toArray();

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
        Log::channel('layer')->info('Numero di tracce finali filtrate da getTracks: ' . $allEcTracks->count());

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
}
