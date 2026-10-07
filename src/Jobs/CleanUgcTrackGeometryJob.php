<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\Models\UgcTrackCleanupService;

/**
 * Applica la pulizia GPS (oc:8719) a una traccia già salvata. Scrive con un UPDATE SQL: non
 * riattiva gli observer e non fa transitare la geometria dall'ORM. Rilanciarlo non cambia nulla.
 *
 * Se la geometria cambia accoda il ricalcolo delle località (`taxonomy_where`), che dipendono da
 * lei. Il cammino (`layer_id`) invece non si ricalcola: è fuori scope.
 */
class CleanUgcTrackGeometryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $ugcTrackId) {}

    public function handle(UgcTrackCleanupService $cleanup): void
    {
        $track = UgcTrack::find($this->ugcTrackId);
        if (! $track) {
            return;
        }

        $ewkt = $cleanup->ewktFor($track);
        if ($ewkt === null) {
            return;
        }

        $updated = DB::update(
            "UPDATE {$track->getTable()} SET geometry = ST_GeomFromEWKT(?), updated_at = NOW()
             WHERE id = ? AND (geometry IS NULL OR ST_HausdorffDistance(geometry::geometry, ST_GeomFromEWKT(?)) > ?)",
            [$ewkt, $track->id, $ewkt, UgcTrackCleanupService::SAME_GEOMETRY_TOLERANCE_DEGREES]
        );

        if ($updated > 0 && ($fresh = $track->fresh()) !== null) {
            UpdateModelWithGeometryTaxonomyWhere::dispatch($fresh);
        }
    }
}
