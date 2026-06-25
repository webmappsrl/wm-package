<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcPoiEcTrack;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Models\EcTrackService;

class EcPoiEcTrackObserver
{
    public function created(EcPoiEcTrack $pivot): void
    {
        $this->updateEcTrackDataChain($pivot);
        $this->syncPoiToLayers($pivot, 'attach');
    }

    public function updated(EcPoiEcTrack $pivot): void
    {
        $this->updateEcTrackDataChain($pivot);
    }

    public function deleted(EcPoiEcTrack $pivot): void
    {
        $this->updateEcTrackDataChain($pivot);
        $this->syncPoiToLayers($pivot, 'detach');
    }

    /**
     * Sync the EcPoi with the layers of its EcTrack.
     * On attach: associate the POI with all layers of the track.
     * On detach: dissociate the POI from a layer only if no other track
     *            in that layer still has the POI as a related poi.
     *
     * NOTE: This observer fires in wm-package but relies on LayerableObserver
     * (registered in the consumer project) to handle ownership transfer on
     * the created layerable. This cross-layer dependency is intentional —
     * same pattern as oc:8080. If LayerableObserver is absent, ownership
     * transfer will not occur, but the association itself will.
     */
    private function syncPoiToLayers(EcPoiEcTrack $pivot, string $action): void
    {
        $fkName = EcPoiEcTrack::getTrackForeignKeyName();
        $ecTrackId = $pivot->getAttribute($fkName);
        $ecPoiId = $pivot->getAttribute('ec_poi_id');

        if (! $ecTrackId || ! $ecPoiId) {
            return;
        }

        $ecTrackModelClass = config('wm-package.ec_track_model', EcTrack::class);
        $ecTrack = $ecTrackModelClass::find($ecTrackId);

        if (! $ecTrack || ! method_exists($ecTrack, 'associatedLayers')) {
            return;
        }

        $layers = $ecTrack->associatedLayers;

        if ($layers->isEmpty()) {
            return;
        }

        foreach ($layers as $layer) {
            if ($action === 'attach') {
                $layer->ecPois()->syncWithoutDetaching([$ecPoiId]);
            } else {
                if (! $this->layerStillHasPoiViaOtherTrack($layer->id, $ecPoiId, $ecTrackId, $fkName)) {
                    $layer->ecPois()->detach($ecPoiId);
                }
            }
        }
    }

    private function layerStillHasPoiViaOtherTrack(int $layerId, int $ecPoiId, int $excludeTrackId, string $fkName): bool
    {
        $pivotTable = config('wm-package.ec_poi_track_pivot_table', 'ec_poi_ec_track');
        $ecTrackModelClass = config('wm-package.ec_track_model', EcTrack::class);
        $ecTrackMorphType = array_search($ecTrackModelClass, Relation::morphMap()) ?: $ecTrackModelClass;

        return DB::table($pivotTable)
            ->join('layerables', function ($join) use ($layerId, $fkName, $ecTrackMorphType, $pivotTable) {
                $join->on('layerables.layerable_id', '=', "{$pivotTable}.{$fkName}")
                    ->where('layerables.layerable_type', $ecTrackMorphType)
                    ->where('layerables.layer_id', $layerId);
            })
            ->where("{$pivotTable}.ec_poi_id", $ecPoiId)
            ->where("{$pivotTable}.{$fkName}", '!=', $excludeTrackId)
            ->exists();
    }

    private function updateEcTrackDataChain(EcPoiEcTrack $pivot): void
    {
        $ecTrackModelClass = config('wm-package.ec_track_model', EcTrack::class);
        $fkName = EcPoiEcTrack::getTrackForeignKeyName();

        $ecTrackId = $pivot->getAttribute($fkName);
        if ($ecTrackId) {
            $ecTrack = $ecTrackModelClass::find($ecTrackId);
            if ($ecTrack) {
                $ecTrackService = app(EcTrackService::class);
                $ecTrackService->updateDataChain($ecTrack);
            }
        }
    }
}
