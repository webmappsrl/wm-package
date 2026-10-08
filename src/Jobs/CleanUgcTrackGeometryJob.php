<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\Models\UgcTrackCleanupService;
use Wm\WmPackage\Services\Models\UgcTrackStatsService;

/**
 * Applica la pulizia GPS (oc:8719) a una traccia già salvata. Scrive con un UPDATE SQL: non
 * riattiva gli observer e non fa transitare la geometria dall'ORM. Rilanciarlo non cambia la
 * geometria; riscrive stats (computed_at nuovo) e richiede il DEM solo dove manca (oc:8742).
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
            // Nessuna geometria utilizzabile: le stats si calcolano comunque (o si tolgono) dai locations.
            $this->syncStats($track, false);

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

        $this->syncStats($track->fresh() ?? $track, $updated > 0);
    }

    /**
     * oc:8742: stats per ogni traccia con punti, anche se la geometria non è cambiata (le tracce
     * senza punti scartati sono la maggioranza). I valori DEM si conservano solo se la geometria
     * è la stessa; altrimenti si richiedono al servizio.
     */
    private function syncStats(UgcTrack $track, bool $geometryChanged): void
    {
        $locations = UgcTrackCleanupService::make()->locationsOf($track);
        $stats = $locations === null ? null : UgcTrackStatsService::make()->localStats($locations);

        if ($stats === null) {
            DB::update("UPDATE {$track->getTable()} SET properties = properties - 'stats' WHERE id = ?", [$track->id]);

            return;
        }

        $saved = $track->properties['stats'] ?? [];
        $demComplete = true;
        foreach (UgcTrackStatsService::DEM_KEYS as $key) {
            if (($saved[$key] ?? null) === null) {
                $demComplete = false;
            }
        }
        if ($demComplete && ! $geometryChanged) {
            foreach (UgcTrackStatsService::DEM_KEYS as $key) {
                $stats[$key] = $saved[$key];
            }
        }

        DB::update(
            "UPDATE {$track->getTable()} SET properties = jsonb_set(properties, '{stats}', ?::jsonb) WHERE id = ?",
            [json_encode($stats), $track->id]
        );

        if (! $demComplete || $geometryChanged) {
            UpdateUgcTrackDemStatsJob::dispatch($track->id, $stats['computed_at']);
        }
    }
}
