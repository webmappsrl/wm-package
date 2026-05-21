<?php

namespace Wm\WmPackage\Services;

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;

class UgcService extends BaseService
{
    private const LAYER_SEARCH_DISTANCE_METERS = 500;

    /**
     * Risolve il Layer di appartenenza di un UGC (UgcPoi o UgcTrack).
     * Prima controlla properties->layer_id, poi cerca la EcTrack più vicina
     * entro 500m e risale al suo layer.
     */
    public function resolveLayer(GeometryModel $ugc): ?Layer
    {
        $properties = $ugc->properties ?? [];

        if (! empty($properties['layer_id'])) {
            $layer = Layer::find($properties['layer_id']);
            if ($layer) {
                return $layer;
            }
        }

        return $this->resolveLayerByNearestEcTrack($ugc);
    }

    private function resolveLayerByNearestEcTrack(GeometryModel $ugc): ?Layer
    {
        $closestTrack = GeometryComputationService::make()
            ->getClosestWithinDistance($ugc, EcTrack::class, self::LAYER_SEARCH_DISTANCE_METERS);

        if (! $closestTrack) {
            return null;
        }

        $result = DB::selectOne("
            SELECT l.id
            FROM layers l
            INNER JOIN layerables lbl ON lbl.layer_id = l.id
                AND lbl.layerable_type = 'App\Models\EcTrack'
                AND lbl.layerable_id = ?
            ORDER BY ST_Distance(
                ST_Centroid(l.geometry::geometry),
                (SELECT geometry::geography FROM {$ugc->getTable()} WHERE id = ?)
            ) ASC
            LIMIT 1
        ", [$closestTrack->id, $ugc->id]);

        return $result ? Layer::find($result->id) : null;
    }
}
