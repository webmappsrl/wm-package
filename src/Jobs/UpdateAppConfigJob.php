<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppConfigService;

/**
 * ShouldBeUniqueUntilProcessing e non ShouldBeUnique (oc:8959): il lock si libera quando il job
 * parte, non quando finisce. Con ShouldBeUnique un dispatch arrivato mentre il job girava — per
 * esempio la seconda di due tassonomie rinominate a pochi secondi di distanza — veniva scartato, e
 * il config.json restava con il nome vecchio fino al salvataggio successivo. Ora quel dispatch si
 * accoda e rilegge lo stato nuovo; WithoutOverlapping per app impedisce che le due esecuzioni
 * scrivano insieme la stessa chiave S3, che era la ragione dell'unicità (oc:8488).
 *
 * ShouldBeUnique* necessario perché uniqueId() sia effettivo: Laravel lo consulta solo
 * con questa interfaccia. Senza, il dispatch dal finally() del batch layer si sommava a
 * quelli di TileObserver/LayerObserver/FeatureCollection*, N ricalcoli concorrenti che
 * scrivono la stessa chiave S3 senza lock. Vedi oc:8488.
 *
 * uniqueVia() forza il lock su Redis invece dello store di default
 * (CACHE_STORE=database): DatabaseLock::acquire() prova un INSERT e, se fallisce
 * per chiave duplicata, ripiega su un UPDATE nello stesso try/catch — su
 * PostgreSQL una query fallita "avvelena" l'intera transazione, quindi anche
 * l'UPDATE di fallback fallisce con un generico 25P02 (transazione abortita).
 * Ogni dispatch di questo job avviene dentro la transazione che Nova apre per
 * ogni salvataggio di risorsa (ResourceUpdateController), quindi senza questo
 * fix qualunque save di un Layer con la riga di lock già presente in
 * `cache_locks` va in 500. Redis non ha questo problema (SET NX è atomico, non
 * partecipa a transazioni SQL) — vedi oc:8564, scoperto testando dal vivo.
 */
class UpdateAppConfigJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $appId
    ) {}

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return "update-app-config-{$this->appId}";
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store('redis');
    }

    /**
     * Una sola esecuzione per app alla volta: quella che arriva mentre un'altra gira aspetta e
     * riprova, e rilegge lo stato aggiornato. Il lock dura al massimo `uniqueFor()`, così un worker
     * morto non lo tiene per sempre.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("update-app-config-{$this->appId}"))->releaseAfter(10)->expireAfter($this->uniqueFor())];
    }

    public function handle(): void
    {
        try {
            $app = App::find($this->appId);

            if (! $app) {
                Log::warning('App not found for config update', ['app_id' => $this->appId]);

                return;
            }

            (new AppConfigService($app))->writeAppConfigOnAws();
        } catch (\Exception $e) {
            Log::error('Error updating app config in job', [
                'app_id' => $this->appId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
