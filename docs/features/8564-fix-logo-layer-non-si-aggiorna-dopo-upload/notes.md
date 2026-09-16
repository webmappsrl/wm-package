> Ticket: oc:8564

# Notes — Fix logo layer non si aggiorna nel campo MAP->layers[] dopo upload

## Deviazioni dal piano

### Task 5: generalizzazione a tutte le collection del Layer

L'overview e il piano iniziali (Task 1-4) coprivano solo la collection `'logo'`, con la
decisione esplicita "il campo Immagine (`'default'`) ha lo stesso bug ma resta out of scope".
Dopo l'implementazione e la review dei Task 1-4 (entrambe approvate senza rilievi bloccanti),
il dev ha chiesto di generalizzare: dato che il campo Immagine ha lo stesso identico bug
strutturale e in più genera thumbnail via conversion, ha senso risolvere entrambi con lo
stesso meccanismo invece di lasciare un gap noto.

**Scoperta rilevante durante la generalizzazione**: la premessa scritta nell'overview iniziale
("la collection 'logo' non ha conversion registrate") era imprecisa. Verificato che
`GeometryModel::registerMediaConversions()` (`wm-package/src/Models/Abstracts/GeometryModel.php:268-279`,
ereditata da `Layer` tramite `Polygon`) registra le conversion per **l'intero modello**, non
per singola collection — quindi anche `'logo'` genera un `PerformConversionsJob` reale (solo
sprecato, perché `logo_image` legge sempre l'originale via `getFirstMediaUrl('logo')`, mai una
conversion). `feature_image` (`'default'`) invece legge esplicitamente la thumbnail via
`MediaService::getThumbnailUrl()`, quindi necessita davvero del delay. La conclusione pratica
("nessun delay per il fix iniziale scope-'logo'") restava corretta nel suo scope originale, ma
il ragionamento dietro era da correggere prima di generalizzare — motivo per cui il Task 5
applica il delay uniformemente a tutte le collection, invece di provare a distinguere caso per
caso quali ne hanno davvero bisogno.

**Rename**: `LayerLogoMediaObserver` → `LayerMediaObserver` (non più logo-specifico),
`LayerLogoMediaObserverTest.php` → `LayerMediaObserverTest.php`.

**Test rimosso**: il caso negativo "altra collection non dispatcha" (Task 4) non è più valido
dopo la generalizzazione — rimosso nella riscrittura del Task 5. Resta l'unico caso negativo
rilevante: "altro modello (non Layer) non dispatcha".

## Bug trovati

Nessuno oltre a quanto già documentato in overview.md (Rischi).

## Decisioni

- Task 1-4 (scope iniziale solo `'logo'`, nessun delay) restano storicamente corretti per
  quello scope — non riscritti nel piano, solo superati dal Task 5. Vedi il rimando `> ⚠️` in
  cima al Task 5 di `plan.md`.
- Generalizzazione decisa dal dev dopo la review dei Task 1-4, non dal challenge iniziale —
  nessun ripensamento sulla review già fatta (Task 1-4 restano "Approved" per lo scope che
  coprivano al momento).

### Riallineamento del branch a `develop` + scoperta oc:8488

Durante il lavoro sul Task 5, un'altra sessione attiva sulla stessa cartella `wm-package` ha
eseguito operazioni git indipendenti (un rebase da `RDO_ass_cammini_italia_2026_2`, poi
abortito, poi un checkout a `develop`), lasciando il repo sul branch `develop` invece del
nostro branch di feature. Il dev ha confermato: il branch andava comunque ricreato da
`develop` aggiornato (dimenticanza sua di allinearlo prima di iniziare).

**Effetto collaterale non innocuo**: l'operazione della sessione concorrente (probabilmente un
`rebase --abort` o `reset`) ha silenziosamente scartato la nostra modifica non committata a un
file TRACKED (`src/Models/Media.php`, la registrazione dell'observer) — i file NUOVI (untracked:
`LayerMediaObserver.php`, il test, `docs/features/`) sono invece sopravvissuti, perché un
checkout non tocca gli untracked. Riapplicata manualmente la modifica a `Media.php` dopo aver
ricreato il branch da `develop` HEAD (verificato prima che `Media.php` fosse identico tra la
vecchia base e `develop`, quindi nessun conflitto reale, solo la nostra modifica non committata
perduta e da riscrivere).

**Lezione**: quando più sessioni Claude Code operano sulla stessa working directory di un
repo, un file tracked modificato ma non committato non è al sicuro da operazioni git di
un'altra sessione (rebase/reset possono scartarlo silenziosamente) — solo i file NUOVI
(untracked) sopravvivono a un cambio di branch. Non c'è modo di prevenirlo dal lato nostro
oltre a committare più spesso, cosa che però il workflow di questo progetto vieta esplicitamente
durante l'esecuzione (commit solo dopo approvazione esplicita del dev) — rischio noto e
accettato di questo pattern, non risolto qui.

**Scoperta rilevante (non richiesta, trovata riallineando a `develop`)**: `develop` include già
oc:8488, che rende `UpdateAppConfigJob` `ShouldBeUnique` con `uniqueFor() = 600`. Il dev aveva
chiesto esplicitamente ("l'update app config viene lanciato più volte, è possibile renderlo
unico per qualche secondo?") esattamente questo comportamento — già risolto a monte da un
altro ticket, il nostro `LayerMediaObserver` lo eredita automaticamente senza bisogno di codice
aggiuntivo. La decisione "nessuna deduplica" della stesura iniziale dell'overview è quindi
superata (non perché sbagliata nel suo scope originale, ma perché il contesto è cambiato sotto
di noi durante l'esecuzione). Vedi `overview.md` → Rischi, aggiornato di conseguenza.

### Task 6: bug bloccante del lock su database di UpdateAppConfigJob (oc:8488 + PostgreSQL)

Il dev ha testato dal vivo il fix salvando un Layer da Nova, ottenendo un errore 500
(`SQLSTATE[25P02]: In failed sql transaction`). Diagnosi completa:

**Meccanismo**: `Wm\WmPackage\Jobs\UpdateAppConfigJob` implementa `ShouldBeUnique` da oc:8488
(già mergiato in `develop`, riallineato al Task 5). Con `CACHE_STORE=database` (default in
`.env`), Laravel usa `Illuminate\Cache\DatabaseLock` per il lock di unicità. Quel codice
(vendor Laravel, non nostro) fa:

```php
try {
    $this->connection->table($this->table)->insert([...]);   // piano A
} catch (QueryException) {
    $updated = $this->connection->table($this->table)->where(...)->update([...]);  // piano B
}
```

Su MySQL, un `INSERT` fallito (chiave duplicata) non compromette il resto della transazione,
quindi il piano B (`UPDATE`) funziona. **Su PostgreSQL, una query fallita dentro una
transazione blocca (`25P02`, "current transaction is aborted") tutte le query successive nella
stessa transazione**, finché non arriva un `ROLLBACK` esplicito — quindi il piano B fallisce
anche lui, con lo stesso errore generico che nasconde la causa reale (stesso pattern già
documentato per oc:8158 in `wm-package/CLAUDE.md`).

Nova avvolge **ogni** salvataggio di risorsa in una transazione
(`Laravel\Nova\Http\Controllers\ResourceUpdateController`). Quindi il crash si presenta ogni
volta che: (1) un salvataggio di Layer dispatcha `UpdateAppConfigJob` (dal nostro
`LayerMediaObserver` **o** dal percorso preesistente `LayerObserver::updateAppConf()` — lo
stack trace dell'errore riportato dal dev mostra proprio quest'ultimo, confermando che il bug
non è specifico al nostro fix), **e** (2) la riga di lock per quell'`app_id` esiste già in
`cache_locks` (es. da un dispatch precedente non ancora scaduto, fino a 10 minuti per
`uniqueFor() = 600`).

**Fix**: aggiunto `UpdateAppConfigJob::uniqueVia(): Repository` che ritorna
`Cache::store('redis')` — Laravel consulta questo metodo se presente sul job, al posto dello
store di default, per acquisire il lock. Redis usa `SET NX` (atomico, non partecipa a
transazioni SQL), quindi non ha il problema. Un solo metodo, un solo file, risolve per tutti e
7 i chiamanti esistenti di `UpdateAppConfigJob`, non solo per il nostro. Verificato
empiricamente in tinker: due dispatch dello stesso job dentro una transazione esplicita non
generano più eccezioni, e `cache_locks` non riceve più righe per quella chiave (il lock passa
interamente da Redis).

**Fuori scope di oc:8564** nella sua origine (il bug viene da oc:8488), ma trattato come
dipendenza bloccante da risolvere qui perché impedisce anche al nostro fix di funzionare. Se
oc:8488 viene backportato su altri branch/consumer del package, andrebbe verificato se anche
lì serve lo stesso fix `uniqueVia()` (non verificato in questo ciclo — riguarda solo questo
branch).

### Review formale (wm-review-ticket): 3 fix applicati, 1 finding ridimensionato dopo verifica

`wm-skills:wm-review-ticket` ha lanciato 5 finder paralleli sul diff completo (Task 1-6). Due
finding bloccanti confermati e corretti in `LayerMediaObserver.php`:

1. **Try/catch mancante** attorno a `$media->model` — l'observer è globale su tutti i Media del
   sistema, non solo quelli di un Layer; un'eccezione nella risoluzione del morph avrebbe potuto
   far fallire il salvataggio di media estranei ai Layer. Fix: try/catch con log, stesso pattern
   già usato da `MediaObserver::setAppIdAndGeometry()`.
2. **Test non isolato da Redis reale**: `Bus::fake()` non impedisce l'acquisizione del lock
   `ShouldBeUnique` (gira in `PendingDispatch::shouldDispatch()`, prima che il Dispatcher fake
   entri in gioco). Fix: `config(['cache.stores.redis.driver' => 'array'])` in `beforeEach()`,
   stesso workaround già presente per lo stesso motivo in
   `tests/Unit/Models/EcPoiAppRelationTest.php:27` (per `BuildAppPoisGeojsonJob`, non
   introdotto qui).

Un terzo finding (`app_id` nullo → `TypeError` nel dispatch) è stato segnalato come bloccante da
un finder e come "non raggiungibile" da un altro — **verificato di persona**: `layers.app_id` è
`$table->integer('app_id')` **senza** `->nullable()` in
`wm-package/database/migrations/create_layers_table.php.stub` (e nella migration reale
applicata) — colonna `NOT NULL` a livello di schema. Un Layer non può essere persistito con
`app_id` nullo, quindi lo scenario di crash segnalato non è raggiungibile con lo schema
attuale. Il guard `if ($model->app_id === null) return;` è stato comunque mantenuto nel codice
come difesa economica e innocua (protegge un consumer futuro con schema diverso), ma senza
l'urgenza inizialmente attribuita dalla review.

### Cleanup applicati dalla review (non bloccanti, richiesti esplicitamente dal dev)

- Rinominato l'helper di test `makeLayerForLogoMediaObserverTest()` → `makeLayerForMediaObserverTest()`
  — residuo di naming dalla versione logo-specifica pre-Task 5.
- Estratta l'asserzione ripetuta 4 volte (`Bus::assertDispatched(...appId === ... && delay !== null...)`)
  in un helper condiviso `assertUpdateAppConfigJobDispatchedWithDelayFor()`.
- Corretto il riferimento di riga impreciso a `GeometryModel::registerMediaConversions()`
  (266-277 → 268-279, verificato nel file reale) in `overview.md` e in questo file.

**Cleanup non applicato, deliberatamente**: la costante `DISPATCH_DELAY_SECONDS = 10` in
`LayerMediaObserver.php` resta duplicata (non condivisa) con il literal `10` già presente in
`LayerObserver::updateAppConf()` — centralizzarla richiederebbe toccare
`wm-package/src/Observers/LayerObserver.php`, esplicitamente escluso dai Global Constraints del
piano ("Non toccare... restano invariati"). Segnalato come cleanup residuo, non risolto per
rispettare quel vincolo.

## Follow-up

- **Verificato**: il pattern `uniqueVia() { return Cache::store('redis'); }` non è
  un'invenzione di questo ciclo — esiste già identico in
  `wm-package/src/Jobs/BuildAppPoisGeojsonJob.php:68` ("Uses Redis for better performance and
  reliability"), stesso fix per lo stesso motivo. Conferma che l'approccio scelto qui è quello
  già idiomatico nel codebase, non una soluzione ad-hoc.
- **Altri job `ShouldBeUnique`/`ShouldBeUniqueUntilProcessing` del package SENZA `uniqueVia()`,
  quindi potenzialmente esposti allo stesso crash se dispatchati dentro una transazione con
  `CACHE_STORE=database`** (grep eseguito, non verificato uno per uno se vengono davvero
  dispatchati dentro una transazione — solo elencati come rischio noto, fuori scope per
  oc:8564):
  - `wm-package/src/Jobs/UpdateLayerGeometryJob.php`
  - `wm-package/src/Jobs/Track/ReindexEcTrackSearchableJob.php`
  - `wm-package/src/Jobs/Layer/SyncAutoLayerAfterPoiTaxonomyChangeJob.php`
  - `wm-package/src/Jobs/Layer/SyncAutoLayerAfterTrackTaxonomyChangeJob.php`
  - `wm-package/src/Jobs/Pbf/DispatcherAppPbfsDebouncedJob.php`
  - `app/Jobs/RecalculateLayerAttributesJob.php` (locale camminiditalia)

  Vale la pena aprire un ticket dedicato per applicare lo stesso fix a questi, se non già
  tracciato altrove.
