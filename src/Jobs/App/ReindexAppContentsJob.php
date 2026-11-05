<?php

namespace Wm\WmPackage\Jobs\App;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Laravel\Scout\Searchable;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;

/**
 * Reindicizza i contenuti di un'app dopo un cambio che tocca cio' che finisce nell'indice.
 *
 * Nasce dalle tassonomie accese per app (oc:8910): `terms`, nel documento di una traccia, porta
 * solo le tassonomie accese su quell'app. Accenderne o spegnerne una cambia quindi ogni
 * documento, ma nessun contenuto viene salvato e nessuno se ne accorge: l'indice resta indietro
 * finche' una traccia non e' risalvata per altri motivi, e la ricerca continua a filtrare su una
 * classificazione che in Nova non c'e' piu'.
 *
 * Unico per app: piu' modifiche di fila al tab «Tassonomie» non accodano un reindex per clic.
 *
 * Porta con se' la firma delle accensioni di prima del salvataggio e la riconfronta quando gira.
 * Serve perche' il tab ha due campi, uno per tipo di contenuto, che scrivono la stessa tabella
 * uno dopo l'altro: il primo lascia uno stato intermedio che sembra un cambiamento anche quando
 * il risultato finale e' identico. Girando dopo il commit, il job vede lo stato definitivo.
 */
class ReindexAppContentsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Il reindex regge una ripetizione: riscrive gli stessi documenti. */
    public int $tries = 2;

    /**
     * Scadenza del lock, come negli altri job unici del package.
     *
     * Senza, `UniqueLock::acquire()` legge `$job->uniqueFor ?? 0` e con 0 prende un lock Redis
     * senza TTL: un worker che muore a metà — un `horizon:terminate` durante un deploy, un
     * riavvio del container — lo lascerebbe lì per sempre, e da quel momento nessun reindex di
     * quell'app verrebbe più accodato, in silenzio. Mezz'ora come `BuildAppPoisGeojsonJob` e
     * `RegenerateTaxonomyWhereOutputsJob`: questo job reindicizza tutte le tracce dell'app, non
     * è lavoro da dieci minuti.
     */
    public int $uniqueFor = 1800;

    public function __construct(public int $appId, public ?string $signatureBefore = null) {}

    /**
     * Com'erano le tassonomie accese: identifier della tassonomia e contenuti su cui vale.
     */
    public static function taxonomiesSignature(App $app): string
    {
        return $app->taxonomies()->get()
            ->map(fn ($taxonomy) => $taxonomy->id.':'.(string) ($taxonomy->pivot->models ?? ''))
            ->sort()
            ->implode('|');
    }

    public function uniqueId(): string
    {
        return 'reindex_app_contents_'.$this->appId;
    }

    /**
     * Il lock su Redis e non sul database: il cambio arriva dal salvataggio di un'app, che Nova
     * avvolge in una transazione, e un lock su `cache_locks` la farebbe abortire (oc:8910).
     */
    public function uniqueVia()
    {
        return Cache::store('redis');
    }

    public function handle(): void
    {
        $app = App::find($this->appId);
        if (! $app) {
            return;
        }

        // Dopo il commit lo stato e' quello definitivo: se e' lo stesso di prima, il tab e'
        // stato salvato senza cambiare niente e non c'e' niente da reindicizzare.
        if ($this->signatureBefore !== null && self::taxonomiesSignature($app) === $this->signatureBefore) {
            return;
        }

        $trackModelClass = config('wm-package.ec_track_model', EcTrack::class);

        // Uno shard puo' montare un modello di traccia senza Scout: li' non c'e' niente da fare.
        if (! in_array(Searchable::class, class_uses_recursive($trackModelClass), true)) {
            return;
        }

        $trackModelClass::query()->where('app_id', $this->appId)->searchable();
    }
}
