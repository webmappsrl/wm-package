<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Bus\Batch;
use Illuminate\Bus\PendingBatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Wm\WmPackage\Jobs\UpdateAppConfigHomeLayerIdsJob;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Support\ImportedAppProperties;

class ImportAppJob extends BaseImportJob
{
    /**
     * Colonne Geohub che vivono sotto properties.theme in Maphub (oc:8367), non come colonne apps.
     */
    private const THEME_COLUMNS = [
        'primary_color',
        'default_feature_color',
        'font_family_header',
        'font_family_content',
    ];

    /**
     * TTL del sentinel di cache che traccia lo stato del batch layer (vedi
     * layerBatchCacheKey()) — durata generosa rispetto a un import reale (~30 minuti),
     * per non far scadere il sentinel mentre il batch è ancora in corso.
     */
    private const LAYER_BATCH_CACHE_TTL_HOURS = 6;

    /**
     * Dipendenze i cui batch alimentano `config_section_map()` per una via diversa dal
     * layer: `MAP.pois.taxonomies` (getAllPoiTaxonomies(), ec_poi + taxonomy_activity/
     * poi_types) e il `feature_image` per-layer (ec_media, via pivot ec_media_layer). Vedi
     * oc:8488 finding 4.
     *
     * `ec_track` (non `taxonomy_theme`): verificato leggendo `config_section_map()` per
     * intero, non solo `getAllPoiTaxonomies()` — `taxonomy_theme` non alimenta nessuna
     * chiave lì (rimosso: un refresh in più che non serve a nulla), mentre `ec_track`
     * alimenta `MAP.bbox` (fallback quando `apps.map_bbox` è null) e
     * `MAP.filters.activities` (via `collect_taxonomies_from_tracks()`) — la stessa
     * staleness del finding 4, lasciata scoperta su queste due chiavi nel primo giro
     * (review post-oc:8488, punto 6).
     */
    private const CONFIG_DEPENDENT_BATCHES = [
        'ec_media',
        'ec_poi',
        'ec_track',
        'taxonomy_activity',
        'taxonomy_poi_types',
    ];

    protected function getModelKey(): string
    {
        return 'app';
    }

    protected function transformData(array $data): array
    {

        // make a diff between data keys and apps columns in database
        $diff = array_diff(array_keys($data), Schema::getColumnListing('apps'));
        $transformedData = array_diff_key($data, array_flip($diff));

        // Le colonne theme non vanno più scritte come colonne: oc:8367 le legge da properties.
        $transformedData = array_diff_key($transformedData, array_flip(self::THEME_COLUMNS));

        // Geohub restituisce le colonne array/json-cast (track_technical_details, keywords, ...)
        // già come stringa JSON: fetchData() legge via query grezza, non tramite un modello
        // Eloquent con cast. Il copy-through schema-driven sopra le passa così come sono —
        // fill() + il cast locale 'array' le ri-codificano con json_encode() su un valore che
        // è GIÀ una stringa JSON, producendo una doppia codifica
        // ('"{\"show_ascent\":...}"' invece di '{"show_ascent":...}'), che poi fa esplodere
        // qualunque scrittura successiva in stile Nova arrow-notation (track_technical_details->*)
        // con un TypeError su Arr::set(). Bug preesistente in questo copy-through, scoperto
        // scrivendo il primo campo Nova su track_technical_details (oc:8488) — non specifico a
        // quella colonna, quindi corretto qui per ogni colonna array/json-cast del modello, non
        // solo per quella.
        $arrayCastKeys = array_keys(array_filter(
            (new App)->getCasts(),
            static fn (string $cast) => in_array($cast, ['array', 'json', 'collection'], true)
        ));
        foreach ($arrayCastKeys as $castKey) {
            if (isset($transformedData[$castKey]) && is_string($transformedData[$castKey])) {
                $decoded = json_decode($transformedData[$castKey], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $transformedData[$castKey] = $decoded;
                }
            }
        }

        // merge (not replace) the incoming properties with what's already stored locally, so a
        // re-import never wipes out Nova-configured properties keys (theme, analytics, min_app_version, wp_*)
        $existing = $this->findExistingApp($data['id']);
        $transformedData['properties'] = $this->mergeProperties($existing, array_merge(
            $this->buildImportedProperties($data),
            [
                'geohub_id' => $data['id'],
                'geohub_synced_at' => now(),
            ]
        ));

        // we need to check if the user related exists in db. If not, we need to create it.
        $user = $this->geohubImportService->checkUserExistence($transformedData['user_id']);
        $transformedData['user_id'] = $user->id;
        unset($transformedData['id']);

        return $transformedData;
    }

    /**
     * Chiavi Geohub che vivono sotto properties.* in Maphub.
     *
     * Le colonne theme esistono anche su apps ma oc:8367 le ha dichiarate morte: legge
     * solo properties.theme.*, e il filtro schema-driven di transformData le copierebbe
     * nel posto sbagliato. Vengono quindi escluse dal payload colonnare ed emesse qui.
     */
    protected function buildImportedProperties(array $data): array
    {
        $properties = [];

        $theme = [];
        foreach (self::THEME_COLUMNS as $column) {
            if (array_key_exists($column, $data)) {
                $theme[$column] = $data[$column];
            }
        }
        if ($theme !== []) {
            $properties['theme'] = $theme;
        }

        foreach (ImportedAppProperties::geohubColumns() as $geohubColumn => $localKey) {
            if (array_key_exists($geohubColumn, $data)) {
                $properties[$localKey] = $data[$geohubColumn];
            }
        }

        return $properties;
    }

    /**
     * Fonde le properties in arrivo da Geohub con quelle già presenti sull'App locale.
     *
     * Necessario perché importData() fa $model->fill($transformedData) e `properties` è
     * castato `array`: assegnarlo sostituisce l'intera colonna, azzerando tutto ciò che
     * un admin ha configurato da Nova (theme, analytics, min_app_version, wp_*).
     *
     * Precedenza: un valore Geohub non-null vince sempre, anche su un null locale esplicito
     * (properties.theme contiene 4 chiavi a null appena qualcuno apre il tab Theme in Nova).
     * Un valore Geohub assente lascia intatto quello locale.
     *
     * Scrittura monotona crescente: una chiave che Geohub non emette più resta in properties.
     * Scelta consapevole, nessun percorso di rimozione in questo ciclo. Vedi oc:8488.
     */
    protected function mergeProperties(?Model $existing, array $incoming): array
    {
        $current = $existing?->properties ?? [];

        if (! is_array($current)) {
            $current = [];
        }

        foreach ($incoming as $key => $value) {
            if (is_array($value) && is_array($current[$key] ?? null)) {
                $current[$key] = array_merge($current[$key], array_filter(
                    $value,
                    static fn ($v) => ! is_null($v)
                ));

                continue;
            }

            if (! is_null($value)) {
                $current[$key] = $value;
            }
        }

        return $current;
    }

    protected function findExistingApp(int $geohubId): ?Model
    {
        return App::where('properties->geohub_id', $geohubId)->first();
    }

    /**
     * Interpreta la colonna Geohub `apps.tiles`.
     *
     * La forma reale è DOPPIAMENTE CODIFICATA: un array che contiene una stringa JSON.
     * Verificato su Geohub app 28. Coincide col default della migration create_apps_table,
     * che ha la stessa genealogia. Il ramo "array di oggetti" tollera un futuro cambio.
     *
     * @return array<int, array{attribution: string, server_xyz: string}>
     */
    protected function parseGeohubTiles(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $entry = json_decode($entry, true);
            }

            if (! is_array($entry)) {
                continue;
            }

            foreach ($entry as $attribution => $serverXyz) {
                if (! is_string($attribution) || $attribution === '' || ! is_string($serverXyz) || $serverXyz === '') {
                    continue;
                }

                $out[] = ['attribution' => $attribution, 'server_xyz' => $serverXyz];
            }
        }

        return $out;
    }

    /**
     * Popola la pivot app_tile dall'ordine dichiarato da Geohub.
     *
     * App::tiles() ordina per app_tile.sort_order, quindi il primo elemento dell'array
     * Geohub resta il basemap di default in app. sync() invece di attach(): idempotente.
     */
    protected function syncTiles(Model $app, array $parsed): void
    {
        if ($parsed === []) {
            return;
        }

        $pivot = [];

        foreach (array_values($parsed) as $index => $entry) {
            $tile = $this->geohubImportService->resolveTile($entry['attribution'], $entry['server_xyz']);
            $pivot[$tile->id] = ['sort_order' => $index];
        }

        $app->tiles()->sync($pivot);
    }

    protected function processDependencies(array $data, Model $model): void
    {
        $this->syncTiles($model, $this->parseGeohubTiles($data['tiles'] ?? null));

        // Get the list of allowed dependencies from configuration or job data
        $allowedDependencies = $this->getAllowedDependencies();

        // Sentinel PRIMA di dispatchare qualunque batch (review post-oc:8488, punto 5): un
        // batch di CONFIG_DEPENDENT_BATCHES può finire ed eseguire il proprio finally() prima
        // che 'layer' venga anche solo dispatchato (l'ordine qui sotto non è garanzia di
        // ordine di completamento). Senza questo marcatore, quel finally() troverebbe la
        // cache vuota e la interpreterebbe come "nessun layer da aspettare", scrivendo un
        // config che ignora se il batch layer fallirà — esattamente il bug che questo
        // marcatore chiude. Scritto SEMPRE synchronously qui, prima del loop, quindi nessuna
        // corsa è possibile: nessun job in coda può ancora essere partito a questo punto.
        if (in_array('layer', $allowedDependencies, true)) {
            Cache::put(self::layerBatchCacheKey($model->id), 'pending', now()->addHours(self::LAYER_BATCH_CACHE_TTL_HOURS));
        }

        // foreach ($this->getRelations() as $modelKey => $relationData) {
        //     $this->queueEntityImport($modelKey, $userId, $relationData['foreign_key']);
        // }

        // Import only allowed dependencies
        if (in_array('taxonomy_activity', $allowedDependencies)) {
            $this->queueEntityImport('taxonomy_activity', $data['user_id'], 'user_id', $model->id);
        }

        if (in_array('taxonomy_poi_types', $allowedDependencies)) {
            $this->queueEntityImport('taxonomy_poi_types', $data['user_id'], 'user_id', $model->id);
        }

        if (in_array('taxonomy_theme', $allowedDependencies)) {
            $this->queueEntityImport('taxonomy_theme', $data['user_id'], 'user_id', $model->id);
        }

        if (in_array('ec_poi', $allowedDependencies)) {
            $this->queueEntityImport('ec_poi', $data['user_id'], 'user_id', $model->id);
        }

        if (in_array('ec_track', $allowedDependencies)) {
            $this->queueEntityImport('ec_track', $data['user_id'], 'user_id', $model->id);
        }

        if (in_array('layer', $allowedDependencies)) {
            $this->queueEntityImport('layer', $data['user_id'], 'app_id', $model->id);
        }

        if (in_array('ec_media', $allowedDependencies)) {
            $this->queueEntityImport('ec_media', $data['user_id'], 'user_id', $model->id);
        }

        // ugc_poi/ugc_track dispatched before ugc_media: the media job resolves the local
        // model to attach the photo to, so it needs poi/track to exist first (dispatch order
        // is not a hard guarantee under Horizon — see ImportUgcMediaJob's retry).
        if (in_array('ugc_poi', $allowedDependencies)) {
            $this->queueEntityImport('ugc_poi', $data['user_id'], 'app_id', $model->id);
        }

        if (in_array('ugc_track', $allowedDependencies)) {
            $this->queueEntityImport('ugc_track', $data['user_id'], 'app_id', $model->id);
        }

        if (in_array('ugc_media', $allowedDependencies)) {
            $this->queueEntityImport('ugc_media', $data['user_id'], 'app_id', $model->id);
        }

        if (! in_array('layer', $allowedDependencies, true)) {
            // Nessun batch layer dispatchato in questo import: se ci sono già layer sul DB
            // (es. da un import precedente), rimappa e scrivi comunque, sincrono.
            self::finalizeAppImport($model->id, null);
        }
    }

    /**
     * Get the list of allowed dependencies
     */
    protected function getAllowedDependencies(): array
    {
        // All available dependencies (ugc_poi/ugc_track/ugc_media are opt-in only: listed here
        // so they are recognized when passed explicitly, but never in default_dependencies.app)
        $allDependencies = ['taxonomy_activity', 'taxonomy_poi_types', 'taxonomy_theme', 'ec_poi', 'ec_track', 'layer', 'ec_media', 'ugc_poi', 'ugc_track', 'ugc_media'];

        // First check if allowed_dependencies is passed in job data
        if (isset($this->data['allowed_dependencies']) && is_array($this->data['allowed_dependencies'])) {
            return $this->data['allowed_dependencies'];
        }

        // Fallback to configuration
        $configDependencies = config('wm-geohub-import.default_dependencies.app', $allDependencies);

        return is_array($configDependencies) ? $configDependencies : $allDependencies;
    }

    /**
     * Queue imports for entities associated with this app.
     *
     * NOTA: ciascuna dipendenza viene dispatchata come Bus::batch() indipendente (vedi sotto),
     * senza alcun chaining .then() tra batch — nessuna garanzia d'ordine di completamento tra,
     * ad esempio, il batch ec_poi/ec_track e il batch taxonomy. Vedi oc:8094: i job EcTrack/EcPoi
     * sincronizzano le proprie taxonomy pivot autonomamente (child-side sync) proprio per non
     * dipendere da questo ordine.
     */
    protected function queueEntityImport(string $entityModelKey, ?int $userId, string $entityForeignKey, int $appId): void
    {
        $logger = Log::channel('wm-package-failed-jobs');

        try {
            [$whereCondition, $data] = $this->resolveEntityImportQuery($entityModelKey, $userId, $entityForeignKey, $appId);
            $ids = $this->geohubImportService->getGeohubIdsToImport($entityModelKey, $whereCondition, $data);

            if (count($ids) > 0) {
                $this->dispatchEntityImportBatch($entityModelKey, $ids, $data, $appId);
            } elseif ($entityModelKey === 'layer') {
                // layer è tra le allowed_dependencies ma non ci sono id da importare: nessun
                // batch dispatchato, quindi nessun finally() che scatterà. Se ci sono già layer
                // sul DB (import precedente), rimappa e scrivi comunque, sincrono — e libera il
                // sentinel scritto in processDependencies(): non c'è nessun batch da aspettare,
                // il contributo dei layer al config è già stabile da questo momento in poi.
                self::finalizeAppImport($appId, null);
                Cache::forget(self::layerBatchCacheKey($appId));
            }
        } catch (\Exception $e) {
            $logger->error("Error queuing {$entityModelKey} imports for app {$this->entityId}: ".$e->getMessage());
            throw $e;
        }
    }

    /**
     * Costruisce whereCondition/data per getGeohubIdsToImport(), specifici per tipo di entità.
     *
     * @return array{0: array|null, 1: array}
     */
    private function resolveEntityImportQuery(string $entityModelKey, ?int $userId, string $entityForeignKey, int $appId): array
    {
        switch ($entityModelKey) {
            case 'layer':
            case 'ugc_poi':
            case 'ugc_track':
            case 'ugc_media':
                // Filtered by the Geohub app itself (numeric app_id, not the owner's user_id):
                // UGC content is authored by many different end users, not just the app owner.
                return [[$entityForeignKey => $this->entityId], ['app_id' => $appId]];
            case 'ec_media':
                // Per ec_media, importiamo i media associati ai track dell'app, non solo quelli dell'utente
                return [null, ['app_id' => $appId, 'app_user_id' => $userId]]; // Gestiremo i media tramite relazioni
            case strpos($entityModelKey, 'taxonomy') !== false: // import only taxonomies actually used by this app (oc:8094)
                return [['id' => $this->geohubImportService->getUsedTaxonomyGeohubIdsForApp($entityModelKey, $this->entityId, $userId)], []];
            default:
                return [[$entityForeignKey => $userId], ['app_id' => $appId]];
        }
    }

    /**
     * Crea i job per gli id da importare, li accoda in un batch e aggancia il completamento
     * (vedi attachBatchCompletionCallback()).
     *
     * @param  array<int, int>  $ids
     */
    private function dispatchEntityImportBatch(string $entityModelKey, array $ids, array $data, int $appId): void
    {
        $jobs = [];
        foreach ($ids as $id) {
            $jobs[] = $this->geohubImportService->createJob($entityModelKey, $id, $data);
        }

        $batch = Bus::batch($jobs)->name("app-dependencies-{$entityModelKey}-import-batch")->onQueue(config('wm-geohub-import.queue.queue', 'geohub-import'));

        $this->attachBatchCompletionCallback($batch, $entityModelKey, $appId);

        $dispatchedBatch = $batch->dispatch();

        if ($entityModelKey === 'layer') {
            Cache::put(self::layerBatchCacheKey($appId), $dispatchedBatch->id, now()->addHours(self::LAYER_BATCH_CACHE_TTL_HOURS));
        }
    }

    /**
     * Aggancia il `finally()` di completamento batch giusto per questo tipo di entità — solo
     * `layer` e `CONFIG_DEPENDENT_BATCHES` ne hanno uno, gli altri non toccano il config.
     */
    private function attachBatchCompletionCallback(PendingBatch $batch, string $entityModelKey, int $appId): void
    {
        // La HOME e il config dipendono dai layer: aggancia il remap/scrittura al solo
        // completamento di QUESTO batch, non a un dispatch indipendente non sincronizzato.
        // allowFailures(): se un layer fallisce, gli altri completano comunque e finally()
        // scatta con un remap parziale — non un danno, solo incompleto (vedi finalizeAppImport()).
        //
        // Il closure passato a finally() viene serializzato da BatchRepository::store()
        // quando $batch->dispatch() gira: DEVE restare `static` e referenziare il metodo
        // per nome di classe qualificato (non self::/static::, per tenere minimo lo scope
        // catturato), altrimenti cattura implicitamente $this (ImportAppJob), che porta
        // dietro GeohubImportService (Connection PDO + Logger non serializzabili) e fa
        // fallire l'intero batch layer con "Serialization of 'Pdo\Pgsql' is not allowed"
        // (bug reale, vedi review post-oc:8488 — verificato con un test di serializzazione
        // reale in ImportAppJobFinalizeTest.php).
        if ($entityModelKey === 'layer') {
            $batch->allowFailures()->finally(
                static fn (Batch $batch) => ImportAppJob::finalizeAppImport($appId, $batch)
            );

            return;
        }

        if (in_array($entityModelKey, self::CONFIG_DEPENDENT_BATCHES, true)) {
            // config_section_map() legge $this->app->getAllPoiTaxonomies() (ec_poi +
            // taxonomy_activity/poi_types), il feature_image per-layer (ec_media) e
            // MAP.bbox/MAP.filters.activities (ec_track) — batch indipendenti dal batch
            // layer, senza garanzia di completamento relativa tra loro (vedi docblock
            // di questo metodo, oc:8094). Refresh best-effort, IN CODA (non
            // dispatchSync: qui non c'è un motivo per bloccare il worker che chiude
            // questo batch) — dispatch() ridondanti da batch che finiscono quasi in
            // contemporanea collassano su uniqueFor():600 di UpdateAppConfigJob.
            //
            // Gate su layerBatchIsPublishReady() (review post-oc:8488, punto 5): senza
            // di esso, questo refresh scriveva il config INCONDIZIONATAMENTE, ignorando
            // se il batch layer stesso era ancora in corso o fallito — annullando di
            // fatto il gate di finalizeAppImport() ("mai un config con MAP.layers
            // incompleto") in quasi ogni import normale, perché è raro che nessuno di
            // questi altri batch sia tra le dipendenze. Ora si scrive SOLO se il batch
            // layer (quando esiste) è già confermato integro.
            $batch->allowFailures()->finally(
                static function (Batch $batch) use ($appId): void {
                    if (ImportAppJob::layerBatchIsPublishReady($appId)) {
                        UpdateAppConfigJob::dispatch($appId);
                    }
                }
            );
        }
    }

    private static function layerBatchCacheKey(int $appId): string
    {
        return "wm-package:import-layer-batch:{$appId}";
    }

    /**
     * True se è sicuro pubblicare un config scritto da CONFIG_DEPENDENT_BATCHES (review
     * post-oc:8488, punto 5): mai scrivere se il batch layer di questo stesso import è
     * ancora in corso o è fallito, altrimenti quel refresh vanificherebbe il gate di
     * finalizeAppImport() ("mai un config con MAP.layers incompleto").
     *
     * Tre stati possibili nella cache (chiave scritta/aggiornata in processDependencies()/
     * queueEntityImport(), MAI qui):
     * - nessuna voce: 'layer' non è tra le dipendenze di questo import (o il sentinel è
     *   scaduto/mai scritto) — nessun batch layer da aspettare, sempre sicuro pubblicare.
     * - 'pending': il sentinel scritto PRIMA di dispatchare qualunque batch, non ancora
     *   sostituito con un id reale — il batch layer non è stato ancora dispatchato (o non ci
     *   sono id da importare e finalizeAppImport() gira sincrono, che libera la voce). Non
     *   sicuro: si preferisce non scrivere piuttosto che rischiare un config incompleto.
     * - un batch id: guardato su Bus::findBatch() — sicuro SOLO se risulta finished() E non
     *   hasFailures()/cancelled(). Se il batch non si trova più (nessuna pruning schedulata
     *   in questo package, quindi un caso limite), si tratta come non sicuro per lo stesso
     *   motivo: finalizeAppImport() stesso (il finally() del batch layer) scriverà comunque
     *   il config finale non appena il batch layer completa, quindi non scrivere qui non
     *   perde mai il dato, al più lo ritarda.
     */
    public static function layerBatchIsPublishReady(int $appId): bool
    {
        $cached = Cache::get(self::layerBatchCacheKey($appId));

        if ($cached === null) {
            return true;
        }

        if ($cached === 'pending') {
            return false;
        }

        $layerBatch = Bus::findBatch($cached);

        return $layerBatch !== null
            && $layerBatch->finished()
            && ! $layerBatch->hasFailures()
            && ! $layerBatch->cancelled();
    }

    /**
     * Punto di completamento del pezzo di import da cui dipende la HOME e il config.
     *
     * `static`: il closure passato a `finally()` in queueEntityImport() viene serializzato
     * da BatchRepository::store() al dispatch del batch — un metodo d'istanza catturerebbe
     * implicitamente $this (ImportAppJob → GeohubImportService → Connection/Logger non
     * serializzabili). Questo metodo non ha mai avuto bisogno di $this nel corpo.
     *
     * La HOME viene sempre rimappata, batch integro o no: un remap parziale (alcuni layer
     * falliti) non è un danno, solo un HOME potenzialmente incompleta — comunque migliore
     * di una HOME che punta a geohub_id mai rimappati. Il config invece viene scritto SOLO
     * se il batch layer (quando esiste) è integro: `hasFailures()`/`cancelled()` restano
     * true se un job è fallito, e finally() gira comunque per contratto. Scrivere il config
     * in quel caso pubblicherebbe MAP.layers incompleto — un config sbagliato E appena
     * riscritto, peggio dello stale attuale. Il file resta quello precedente e il dev può
     * forzare la riscrittura con GET .../base-config.json.
     *
     * $batch è null sul percorso senza dipendenze (--skip-dependencies, o layer non tra le
     * allowed_dependencies): in quel caso le entità arrivano dal DB, non da un batch appena
     * dispatchato, quindi si procede sempre (remap + config).
     */
    public static function finalizeAppImport(int $appId, ?Batch $batch): void
    {
        UpdateAppConfigHomeLayerIdsJob::dispatchSync($appId);

        if ($batch && ($batch->hasFailures() || $batch->cancelled())) {
            Log::channel('wm-package-failed-jobs')->warning(
                'Import layer incompleto: HOME rimappata, config non riscritto',
                [
                    'app_id' => $appId,
                    'failed_jobs' => $batch->failedJobs,
                    'cancelled' => $batch->cancelled(),
                    'hint' => 'GET /api/app/webmapp/'.$appId.'/base-config.json per forzare la riscrittura',
                ]
            );

            return;
        }

        (new UpdateAppConfigJob($appId))->handle();
    }
}
