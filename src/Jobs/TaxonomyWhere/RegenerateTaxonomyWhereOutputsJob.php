<?php

namespace Wm\WmPackage\Jobs\TaxonomyWhere;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;

/**
 * Rigenera le uscite pubbliche che dipendono da taxonomy_where per un'App (oc:8588):
 * json statico di ogni EcTrack (contiene anche i related_pois), documento Elasticsearch,
 * pois.geojson dell'App. Non chiama osmfeatures e non modifica il dato salvato.
 *
 * `ShouldBeUniqueUntilProcessing` invece di `ShouldBeUnique`: il lock si libera quando il job
 * inizia l'esecuzione, non quando finisce. Con `ShouldBeUnique` un secondo dispatch arrivato
 * mentre il job è ancora in esecuzione (es. l'operatore cambia di nuovo "Località mostrate"
 * mentre la rigenerazione precedente sta ancora girando) verrebbe scartato in silenzio,
 * perdendo l'aggiornamento più recente.
 */
class RegenerateTaxonomyWhereOutputsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * Il numero di secondi dopo i quali il lock unique del job viene rilasciato.
     * Stesso pattern di BuildAppPoisGeojsonJob, dispatchato in coda da questo stesso job.
     */
    public int $uniqueFor = 1800; // 30 minuti

    public function __construct(public int $appId)
    {
        // Il job va eseguito solo dopo il commit della transazione che lo dispatcha
        // (tipicamente `AppObserver::saved()` dentro il salvataggio Nova dell'App). In
        // camminiditalia `queue.connections.redis.after_commit` è `false` e `scout.queue` è
        // `false`: senza questa chiamata esplicita, un worker abbastanza veloce può eseguirlo
        // prima del commit, rileggere l'opzione "Località mostrate" ancora col valore vecchio e
        // reindicizzare Elasticsearch con quello.
        //
        // Non una property `public bool $afterCommit = true`: il
        // trait `Illuminate\Bus\Queueable`, già usato da questa classe, dichiara la stessa
        // property `$afterCommit` senza tipo — una ridichiarazione tipizzata nella classe è
        // un conflitto fatale in fase di composizione del trait ("definita in modo
        // incompatibile"). Si usa quindi il metodo `afterCommit()` del trait, stesso pattern
        // già in uso in `UpdateLayerGeometryJob`.
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'regenerate-taxonomy-where-outputs-'.$this->appId;
    }

    /**
     * Cache store usato per acquisire il lock unique del job.
     *
     * Il job è dispatchato da AppObserver::saved(), dentro la transazione che salva l'App in
     * Nova: con CACHE_STORE=database il lock fallisce con 25P02 su Postgres se la riga esiste
     * già (trap oc:8564, stesso pattern preesistente in BuildAppPoisGeojsonJob).
     */
    public function uniqueVia()
    {
        return Cache::store('redis');
    }

    public function handle(): void
    {
        $trackModel = config('wm-package.ec_track_model');

        $trackModel::query()
            ->where('app_id', $this->appId)
            ->whereNotNull('geometry')
            ->chunkById(200, function ($tracks) {
                foreach ($tracks as $track) {
                    UpdateEcTrackAwsJob::dispatch($track);
                }
                $tracks->searchable();
            });

        BuildAppPoisGeojsonJob::dispatch($this->appId);
    }
}
