<?php

namespace Wm\WmPackage\TrailRegistry\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Wm\WmPackage\Models\Abstracts\MultiLineString;
use Wm\WmPackage\TrailRegistry\Services\ApplicationDemService;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

/**
 * Il calcolo DEM di un'istanza del Catasto Sentieri: scrive
 * `properties['dem_data']` e mette nella geometria la quota del nostro DEM.
 *
 * La chiamata al DEM e la scrittura in SQL stanno in
 * ApplicationDemService, condiviso con la chiamata sincrona. Il file caricato
 * resta intatto nella collection `original_geometry` (oc:8571).
 *
 * Riceve l'id e non il modello: un'istanza sparita nel frattempo e' un
 * job che non ha niente da fare, non un errore.
 */
class UpdateTrailApplicationDemJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Il lock di ShouldBeUnique scade da solo: un job perso non blocca per
     * sempre il ricalcolo di quell'istanza. Stessa durata del lock del
     * ricalcolo alla visualizzazione (oc:8660).
     */
    public int $uniqueFor = MultiLineString::DEM_LOCK_SECONDS;

    public function __construct(public int $applicationId)
    {
        $this->onQueue('dem');
    }

    public function uniqueId(): string
    {
        return (string) $this->applicationId;
    }

    /**
     * Questo job e' accodato alla creazione dell'istanza, dentro la
     * transazione di salvataggio di Nova: il lock di default di
     * ShouldBeUnique su CACHE_STORE=database fa fallire Postgres con 25P02
     * quando la riga di lock esiste gia', perche' DatabaseLock::acquire()
     * prova un INSERT e ripiega su UPDATE nello stesso try/catch, e Postgres
     * blocca tutte le query successive nella stessa transazione dopo la
     * prima fallita. Stesso pattern di BuildAppPoisGeojsonJob (oc:8564).
     */
    public function uniqueVia(): Repository
    {
        return Cache::store('redis');
    }

    /**
     * In coda aspettare va bene: il limite e' quello largo
     * (`clients.dem.async_timeout_seconds`), non quello della chiamata
     * sincrona. Se il servizio non risponde il job fallisce, come prima.
     * Se i dati tecnici ci sono gia' non chiama il servizio.
     */
    public function handle(ApplicationDemService $demService): void
    {
        $application = TrailRegistryClasses::application()::find($this->applicationId);

        if ($application === null) {
            return;
        }

        // Il calcolo puo' essere gia' stato fatto in modo sincrono, alla
        // creazione: rifarlo sovrascriverebbe numeri gia' consegnati a chi ha
        // presentato la domanda, e il servizio puo' rispondere valori appena
        // diversi. needsDem() guarda dove il calcolo scrive, e l'istanza e'
        // appena stata ricaricata dal database.
        if (! $application->needsDem()) {
            return;
        }

        $done = $demService->computeAndStore(
            $application,
            (int) config('wm-package.clients.dem.async_timeout_seconds', 30)
        );

        if (! $done) {
            throw new RuntimeException("Calcolo DEM non riuscito per l'istanza {$this->applicationId}");
        }
    }
}
