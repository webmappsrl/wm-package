<?php

namespace Wm\WmPackage\TrailRegistry\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Services\Models\EcTrackService;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

/**
 * Il calcolo DEM di un'istanza del Catasto Sentieri: scrive
 * `properties['dem_data']` e mette nella geometria la quota del nostro DEM.
 *
 * Scrive in SQL solo le proprie chiavi, senza risalvare il modello: la
 * geometria nel package non passa dall'ORM, e un salvataggio dell'intero
 * `properties` potrebbe coprire un valore manuale appena scritto
 * dall'operatore. Il file caricato resta intatto nella collection
 * `original_geometry` (oc:8571).
 *
 * Riceve l'id e non il modello: un'istanza sparita nel frattempo e' un
 * job che non ha niente da fare, non un errore.
 */
class UpdateTrailApplicationDemJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Il lock di ShouldBeUnique scade da solo: un job perso non blocca per
     * sempre il ricalcolo di quell'istanza.
     */
    public int $uniqueFor = 600;

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

    public function handle(EcTrackService $ecTrackService): void
    {
        $application = TrailApplication::find($this->applicationId);

        if ($application === null) {
            return;
        }

        $response = $ecTrackService->fetchDemTechData($application->getGeojson());
        $demData = $ecTrackService->normalizeDemData($response['properties'] ?? []);

        // Non si tocca updated_at: e' un arricchimento automatico, non una
        // modifica dell'operatore. Se lo aggiornassimo, il trafficCop di Nova
        // rifiuterebbe con 409 il salvataggio di un operatore che ha aperto
        // il form prima dell'arrivo del DEM (oc:8571).
        //
        // Il cast di 'properties' => [] (default della factory) produce un jsonb
        // '[]', non '{}': jsonb_set su un array si aspetta un indice numerico nel
        // path e fallisce con "dem_data" non intero. COALESCE copre solo il NULL,
        // non questo caso, quindi lo normalizziamo esplicitamente a oggetto vuoto.
        DB::update(
            <<<'SQL'
            UPDATE trail_applications
            SET properties = jsonb_set(
                    CASE
                        WHEN properties IS NULL OR jsonb_typeof(properties) != 'object' THEN '{}'::jsonb
                        ELSE properties
                    END,
                    '{dem_data}', :dem::jsonb
                ),
                geometry = ST_Multi(ST_Force3D(ST_SetSRID(ST_GeomFromGeoJSON(:geometry), 4326)))::geography
            WHERE id = :id
            SQL,
            [
                'dem' => json_encode($demData),
                'geometry' => json_encode($response['geometry']),
                'id' => $this->applicationId,
            ],
        );
    }
}
