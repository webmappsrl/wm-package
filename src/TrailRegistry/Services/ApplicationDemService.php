<?php

namespace Wm\WmPackage\TrailRegistry\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Wm\WmPackage\Services\Models\EcTrackService;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

/**
 * Il calcolo DEM di un'istanza del Catasto Sentieri, condiviso fra il job in
 * coda e la chiamata sincrona: chiama il servizio DEM e scrive
 * `properties['dem_data']` e la geometria tridimensionale.
 *
 * Scrive in SQL solo le proprie chiavi, senza risalvare il modello: la
 * geometria nel package non passa dall'ORM, e un salvataggio dell'intero
 * `properties` potrebbe coprire un valore manuale appena scritto
 * dall'operatore (oc:8571).
 */
class ApplicationDemService
{
    public function __construct(protected EcTrackService $ecTrackService) {}

    /**
     * Calcola e salva i dati tecnici dell'istanza.
     *
     * Non solleva se il servizio non risponde o risponde male: restituisce
     * false e logga la causa (body compreso), perche' chi chiama deve poter
     * accettare la domanda lo stesso e non deve mai vedere il testo del
     * servizio.
     *
     * @param  int|null  $timeout  secondi di attesa, senza retry; null =
     *                             `clients.dem.timeout_seconds` (limite della
     *                             chiamata sincrona). Il job in coda passa un
     *                             valore piu' largo.
     * @return bool true solo se una riga e' stata davvero scritta, false altrimenti
     */
    public function computeAndStore(TrailApplication $application, ?int $timeout = null): bool
    {
        // Savepoint: se il service gira dentro una transazione (la creazione
        // della domanda), un errore SQL inghiottito dal catch lascerebbe
        // Postgres in stato abortito e fallirebbe ogni query successiva, commit
        // compreso. Deve coprire TUTTO il calcolo, lettura della geometria
        // compresa: un errore SQL che nasce fuori dal savepoint abortisce la
        // transazione esterna e il savepoint non lo vede. Fuori da una
        // transazione (il job in coda) non serve, e non si tiene aperta una
        // transazione per tutta l'attesa del servizio.
        $compute = function () use ($application, $timeout): int {
            $response = $this->ecTrackService->fetchDemTechData(
                $application->getGeojson(),
                $timeout ?? (int) config('wm-package.clients.dem.timeout_seconds', 5)
            );

            $demData = $this->ecTrackService->normalizeDemData($response['properties'] ?? []);
            $dem = json_encode($demData, JSON_THROW_ON_ERROR);
            $geometry = json_encode($response['geometry'], JSON_THROW_ON_ERROR);

            // Non si tocca updated_at: e' un arricchimento automatico, non una
            // modifica dell'operatore. Se lo aggiornassimo, il trafficCop di Nova
            // rifiuterebbe con 409 il salvataggio di un operatore che ha aperto
            // il form prima dell'arrivo del DEM (oc:8571).
            //
            // Il cast di 'properties' => [] (default della factory) produce un jsonb
            // '[]', non '{}': jsonb_set su un array si aspetta un indice numerico nel
            // path e fallisce con "dem_data" non intero. COALESCE copre solo il NULL,
            // non questo caso, quindi lo normalizziamo esplicitamente a oggetto vuoto.
            return DB::update(
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
                    'dem' => $dem,
                    'geometry' => $geometry,
                    'id' => $application->getKey(),
                ],
            );
        };

        try {
            $written = DB::transactionLevel() > 0 ? DB::transaction($compute) : $compute();
        } catch (Throwable $e) {
            Log::warning('trail_registry: calcolo DEM non riuscito', [
                'application_id' => $application->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        // Zero righe: l'istanza e' sparita fra la lettura e la scrittura.
        return $written > 0;

    }
}
