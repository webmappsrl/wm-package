<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\Models\EcTrackService;
use Wm\WmPackage\Services\Models\UgcTrackStatsService;

/**
 * Dislivelli e quote di una UgcTrack dal servizio DEM (oc:8742), sulla geometria pulita.
 * Scrive in SQL solo le chiavi DEM di properties.stats: non riattiva gli observer e non tocca
 * i valori locali. Scrive solo se stats.computed_at è ancora quello del momento in cui è stato
 * accodato: se nel frattempo i punti sono cambiati, il dislivello di questa geometria è vecchio
 * e un altro job è già in coda.
 */
class UpdateUgcTrackDemStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $ugcTrackId, public string $computedAt)
    {
        // Queueable dichiara già $queue: ridichiararlo con un altro valore iniziale è un fatal error.
        $this->onQueue('dem');
    }

    public function handle(EcTrackService $ecTrackService): void
    {
        $track = UgcTrack::find($this->ugcTrackId);
        if (! $track || ($track->properties['stats']['computed_at'] ?? null) !== $this->computedAt) {
            return;
        }

        $row = DB::selectOne(
            "SELECT ST_AsGeoJSON(ST_Force2D(geometry::geometry)) AS g FROM {$track->getTable()} WHERE id = ?",
            [$track->id]
        );
        if ($row?->g === null) {
            return;
        }

        $response = $ecTrackService->fetchDemTechData([
            'type' => 'Feature',
            'properties' => ['id' => $track->id],
            'geometry' => json_decode($row->g, true),
        ]);

        $dem = [];
        foreach (UgcTrackStatsService::DEM_KEYS as $key) {
            $value = $response['properties'][$key] ?? null;
            $dem[$key] = is_numeric($value) ? $value + 0 : null;
        }

        // Il WHERE su computed_at ripete il controllo in SQL: copre i punti cambiati mentre il
        // servizio DEM rispondeva.
        DB::update(
            "UPDATE {$track->getTable()}
             SET properties = jsonb_set(properties, '{stats}', (properties->'stats') || ?::jsonb)
             WHERE id = ? AND properties->'stats'->>'computed_at' = ?",
            [json_encode($dem), $track->id, $this->computedAt]
        );
    }
}
