<?php

namespace Wm\WmPackage\Jobs\Track;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Wm\WmPackage\Models\EcTrack;

class ReindexEcTrackSearchableJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public int $trackId)
    {
        $this->onQueue('default');
    }

    /**
     * Il lock di unicità si prende su Redis, non sul database (oc:8910).
     *
     * Questo job si accoda dentro la transazione del salvataggio Nova di un contenuto. Con il
     * lock su `cache_locks`, `DatabaseLock::acquire()` tenta un INSERT e, se la riga esiste
     * già, PostgreSQL aborta la transazione: da lì in poi ogni query del salvataggio muore con
     * «current transaction is aborted» e il form non si salva. Stesso rimedio di
     * `BuildAppPoisGeojsonJob`.
     *
     * @return Store
     */
    public function uniqueVia()
    {
        return Cache::store('redis');
    }

    public function uniqueId(): string
    {
        return 'reindex-ectrack-searchable-'.$this->trackId;
    }

    public function handle(): void
    {
        $trackModelClass = config('wm-package.ec_track_model', EcTrack::class);
        $track = $trackModelClass::find($this->trackId);
        if (! $track) {
            return;
        }

        $track->searchable();
    }
}
