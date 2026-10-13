<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

/**
 * Ricalcola la corrispondenza fra una EcTrack e le UgcTrack vicine (oc:8773). Unico per EcTrack finché
 * non parte. Nessun failed(): lo stato è per UgcTrack, un fallimento resta nei failed job di Horizon.
 */
class MatchEcTrackToUgcTracksJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Le release di WithoutOverlapping contano come tentativi: si limitano le sole eccezioni
    // vere (3) e il job vive al massimo 15 minuti, così un lock occupato non esaurisce i tentativi.
    public int $maxExceptions = 3;

    // Sotto il retry_after di default della connessione redis (90 s): con un timeout maggiore il
    // job verrebbe rimesso in coda mentre gira ancora (e failed() potrebbe segnarlo fallito
    // durante il calcolo). Non si tocca la config di coda dei consumer.
    public int $timeout = 85;

    // Senza scadenza il lock di unicità resterebbe per sempre se un job in coda andasse perso.
    public int $uniqueFor = 3600;

    public function __construct(public int $ecTrackId)
    {
        $this->onQueue(config('wm-package.ugc_track_matching.queue', 'ugc-track-matching'));
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(15);
    }

    public function uniqueId(): string
    {
        return (string) $this->ecTrackId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("ugc-track-matching-ec:{$this->ecTrackId}"))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(UgcTrackMatchingService $service): void
    {
        $service->matchEcTrack($this->ecTrackId);
    }
}
