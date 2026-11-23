<?php

namespace Wm\WmPackage\Jobs\Track;

use Illuminate\Queue\Middleware\WithoutOverlapping;
use Wm\WmPackage\Enums\WmfeTrackVersion;
use Wm\WmPackage\Http\Resources\EcTrackPropertiesBuilder;
use Wm\WmPackage\Http\Resources\EcTrackResource;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\StorageService;

class UpdateEcTrackAwsJob extends BaseEcTrackJob
{
    /**
     * Quanto puo' durare la scrittura delle versioni del file prima che il worker tronchi il job.
     *
     * Dichiarato qui e non lasciato al supervisor, che ogni shard configura a modo suo: da questo
     * valore dipende la scadenza del lock, e le due cose devono restare legate.
     */
    public int $timeout = 120;

    /**
     * Quante volte il job puo' essere ripreso.
     *
     * Un numero alto non e' tolleranza verso gli errori: ogni volta che la traccia e' gia' in
     * scrittura il middleware rimette il job in coda, e quel rientro consuma un tentativo. Con il
     * valore di prima (3, dal supervisor) bastavano tre rientri in pochi millisecondi per far
     * fallire una scrittura che non aveva ancora provato niente, e con lei i job che la seguono
     * nella catena. Gli errori veri li ferma `maxExceptions`, non questo numero.
     *
     * `tries` conta i tentativi, non il tempo: un job fermo in una coda lunga — un import massivo
     * su un altro shard — non ne consuma nessuno. Per questo qui non c'e' `retryUntil()`, che il
     * worker valuta prima di eseguire e che farebbe fallire chi ha solo aspettato il suo turno.
     */
    public int $tries = 25;

    /**
     * Gli errori veri: una `put` che non riesce, lo storage irraggiungibile.
     *
     * Tre bastano a distinguere un disservizio passeggero da uno vero, e tengono il freno dove
     * serve mentre `tries` lascia spazio all'attesa del lock. Conta anche i timeout.
     */
    public int $maxExceptions = 3;

    /**
     * L'attesa fra due tentativi dopo un errore vero: senza, i tre di `maxExceptions` si
     * brucerebbero nello stesso istante, mentre lo storage e' ancora giu'.
     */
    public int $backoff = 30;

    public function __construct(protected EcTrack $ecTrack)
    {
        parent::__construct($ecTrack);
        $this->onQueue('aws');
    }

    /**
     * Due scritture sulla stessa traccia non devono girare insieme, o si sovrascriverebbero il
     * file a vicenda: il lock e' per traccia.
     *
     * `releaseAfter` e' la correzione del difetto: senza, `WithoutOverlapping` nasce con zero e
     * rimette il job in coda all'istante, dove ritrova il lock ancora occupato — chi sta
     * scrivendo impiega decine di millisecondi — e lo consuma un tentativo per volta. Dieci
     * secondi bastano perche' il primo finisca, e il secondo scriva davvero invece di morire.
     *
     * `expireAfter` chiude un guasto che restava silenzioso: un worker ucciso mentre tiene il
     * lock lo lasciava per sempre su Redis, e quella traccia non si aggiornava piu'. Deve stare
     * sopra il `timeout` del job, altrimenti il lock scade mentre il primo sta ancora scrivendo,
     * un secondo parte in parallelo e il piu' vecchio puo' sovrascrivere il piu' nuovo.
     *
     * @return array<int, object>
     */
    public function middleware()
    {
        return [
            (new WithoutOverlapping($this->getLockKey()))
                ->releaseAfter(10)
                ->expireAfter($this->timeout + 60),
        ];
    }

    /**
     * Genera una chiave unica per il lock di WithoutOverlapping.
     *
     * @return string
     */
    protected function getLockKey()
    {
        // Utilizza un identificatore unico per ogni tile
        return 'upload-ectrack-to-aws-'.$this->ecTrack->id;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(StorageService $cloudStorageService)
    {
        // Ottieni il modello dalla configurazione per essere consistente
        $ecTrackModelClass = config('wm-package.ec_track_model');

        // Ricarica il modello usando la classe configurata per assicurarsi che sia della classe corretta
        $ecTrack = $ecTrackModelClass::find($this->ecTrack->id);

        if (! $ecTrack) {
            throw new \Exception("EcTrack with ID {$this->ecTrack->id} not found");
        }

        // Un builder solo per traccia: i dati si calcolano una volta e valgono per tutte le
        // versioni. La serializzazione passa dalla Resource, perché il filtro di JsonResource fa
        // parte della forma del file (oc:8997).
        $builder = new EcTrackPropertiesBuilder($ecTrack);

        foreach (WmfeTrackVersion::active() as $version) {
            $resource = new EcTrackResource($ecTrack, $version, $builder);

            if ($cloudStorageService->storeTrack($ecTrack->id, $resource->toJson(), $version) === false) {
                // Senza questo, una `put` fallita lascerebbe il job verde e i file divergenti:
                // uno aggiornato e uno fermo, senza nessun segnale. Horizon ritenta, e riscrivere
                // tutte le versioni è idempotente.
                throw new \RuntimeException(sprintf(
                    'Scrittura del JSON della traccia %d fallita per la versione "%s".',
                    $ecTrack->id,
                    $version->value
                ));
            }
        }
    }
}
