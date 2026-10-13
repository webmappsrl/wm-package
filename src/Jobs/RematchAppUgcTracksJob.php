<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

/**
 * Accoda il calcolo per le UgcTrack di un'App non aggiornate (oc:8773): mai calcolate, fallite o
 * calcolate con parametri diversi da quelli attuali. Unico per App: più cambi di parametri di
 * fila producono un solo giro, e ogni job per traccia legge i parametri quando gira.
 */
class RematchAppUgcTracksJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 600;

    public function __construct(public int $appId)
    {
        $this->onQueue(config('wm-package.ugc_track_matching.queue', 'ugc-track-matching'));
    }

    public function uniqueId(): string
    {
        return (string) $this->appId;
    }

    public function handle(UgcTrackMatchingService $service): void
    {
        $service->unmatchedUgcTrackIdsQuery($this->appId)->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                MatchUgcTrackToEcTracksJob::dispatch((int) $row->id);
            }
        }, 'g.id', 'id');
    }
}
