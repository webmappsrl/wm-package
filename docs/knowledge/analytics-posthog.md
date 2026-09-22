# Analytics PostHog

Come il package interroga PostHog per le statistiche mostrate in Nova, e perché le query sono
scritte così.

## Stato attuale

**Un solo punto di ingresso**: `AnalyticsService` (`src/Services/PostHog/AnalyticsService.php`),
esposto in Nova dalla card `LayerAnalytics` e dai due endpoint di
`AnalyticsController`: `layer()` (per-layer) e `global()` (aggregato, Administrator-only).

**Filtro shard.** Ogni query eredita `shardNameClause()` da `whereClause()`: non va duplicato
nelle query private. Legge `config('wm-package.analytics_shard_name')` (env
`ANALYTICS_SHARD_NAME`) e ripiega **a runtime, dentro il metodo** su
`config('wm-package.shard_name')` se vuota. Il fallback non può essere pre-calcolato nel file di
config — `env()` è risolto una volta all'avvio e non seguirebbe gli override `config([...])` che
i test fanno a runtime (oc:8464).

La property `shard_name` su PostHog ha due forme osservate sui dati reali: annidata
`{"_value": "..."}` (eventi mobile recenti) e flat (eventi storici di altri shard). Il filtro fa
OR tra le due. Guard fail-open con `trim()` + `Log::warning`: a config vuota non si applica
alcun filtro, invece di una clausola che non fa mai match e azzererebbe tutte le metriche
(oc:8354).

**Rischio accettato**: impostare `ANALYTICS_SHARD_NAME` con lo shard di un altro cliente sullo
stesso progetto PostHog condiviso produce dati cross-tenant. Nessun blocco di codice, nessun log
quando l'override è attivo — documentato nel docblock di `shardNameClause()` (oc:8464).

**Fallback `layer_id` → `layer_label`.** La maggior parte degli eventi `layerOpened` reali non
porta più `layer_id`, solo `layer_label` nel formato `"{id} - {titolo}"`.
`effectiveLayerIdExpression()` fa il coalesce, ed è usato da `idFilterClause()`,
`idInFilterClause()` **e** `queryAllLayersRanking()` — tutti e tre: `idInFilterClause()` serve
anche `validLayerIdsClause()` per i `layer_id`, non solo `queryTrackDownloads()` per i
`track_id` (oc:8354).

**Coerenza fra KPI e classifica.** Quando l'id è `null` il filtro diventa
`IS NOT NULL AND != ''`, non viene omesso: altrimenti eventi con id malformato finirebbero nel
totale ma in nessuna riga della classifica. Stesso principio per le ricerche:
`getTotalSearches()` applica la **stessa** subquery di deduplica e lo stesso filtro
`results_count > 0 AND length(query) >= 4` di `getTopSearchQueries()` (oc:8182).

**Ranking e cancellati.** Nessun `LIMIT` secco a `RANKING_LIMIT` lato SQL: si prende un margine
più ampio, si filtra "esiste ancora nel DB locale" e si tronca in PHP — altrimenti una traccia
cancellata occuperebbe un posto sottraendolo a una attiva. `getGlobalUsage()` esclude i layer
cancellati passando `validLayerIdsClause()` come `$extraFilter` opzionale a
`getUsage()`/`fetchUsage()` (oc:8182).

**Errori, non silenzio.** `runQuery()` ha un `$strict` opt-in (default `false`): a `true` un
fallimento HTTP lancia `AnalyticsQueryException` invece di ritornare `[]`, e il controller
risponde 502 — così il frontend distingue "0 aperture reali" da "query fallita". Il controller
cattura anche `LockTimeoutException`, perché `rememberWithLock()` mette un `Cache::lock`
anti-stampede su tutti i metodi che fanno `Cache::remember` su range costosi (oc:8182).

**Limiti noti di HogQL su questo ambiente**: `toYYYYMM`/`toUInt32` non supportati (il WHERE per
mesi usa il confronto fra date, oc:7648); `leadInFrame()` ritorna sempre il default anche con
righe successive nella partition, quindi i prefissi di ricerca non si collassano — il filtro
`length(query) >= 4` è il ripiego pragmatico (oc:8182).

**Non fidarsi della UI degli insight.** L'editor PostHog etichetta l'evento di condivisione
tracce come "Counting 'MOBILE Track Shared' Pageview", ma l'evento raw è `contentShared`
diretto: verificato via Activity Explorer (oc:8182).

**Confinamento di branch.** I fix oc:8354 e oc:8464 vivono solo su
`RDO_ass_cammini_italia_2026_2`. I due metodi già presenti su `develop`/`main`
(`getLayerUsage`, `getLayerTrackDownloads`) hanno lo stesso leak di shard e non sono corretti lì.

**Aggregazione fatta in PHP, non in HogQL — unico caso nella classe (oc:8585).**
`getRouteFilterUsage()` conta gli usi dei 7 filtri del pannello "avanzato" (route) della search
bar camminiditalia (`filterUsed` con `filter_type: 'route'`). A differenza di ogni altro metodo
di questa classe, la query non fa `GROUP BY`: recupera righe grezze (`filter_id`, `$session_id`,
`$lib`, `timestamp`) e la deduplica — eventi consecutivi della stessa `(session_id, filter_id)`
con gap ≤ 5s contano come un solo utilizzo — è un loop PHP stateful, non una window function
HogQL. Motivo: `leadInFrame()`, la window function più vicina a questo bisogno, non è affidabile
in questo ambiente (vedi sopra, ricerche senza risultati) — non ci si è fidati di `lag()`/
`lagInFrame()` senza una verifica specifica.

Le 7 righe (Lunghezza/`distance`, Tappe/`stageCount`, Tipologia/`shape`, Portata/
`walkingNetwork`, Regioni/`regions`, Temi/`themes`, Stagioni/`seasons`) sono sempre presenti nel
risultato, anche a zero: a differenza delle classifiche aperte di questa classe (dove un elemento
senza eventi semplicemente non compare), qui l'insieme è chiuso e noto — un filtro mai usato è il
dato più interessante per l'obiettivo del ticket (capire quali filtri rimuovere dall'interfaccia),
non un dato da nascondere.

`session_id` non è garantito da PostHog (come `layer_id`/`user_id` altrove in questa classe): un
evento senza sessione non entra nella chiave di dedup e conta sempre come nuovo utilizzo — usare
una stringa vuota come chiave avrebbe collassato silenziosamente utenti diversi privi di sessione
fra loro (bug trovato in review, non nella prima implementazione). Il parsing del timestamp è in
try/catch: una riga con formato non riconosciuto viene scartata con `Log::warning`, non propaga
un'eccezione che abbatterebbe l'intera `global()` — lo stesso principio difensivo di
`getUserMovedStats()`/`getRecentUserPositions()`, applicato qui per lo stesso motivo (una query su
dati grezzi non aggregati è quella più esposta ad anomalie del formato).

**Opt-in per consumer.** L'evento `filterUsed`/`route` esiste solo per il pannello filtri
camminiditalia (`wm-core`, `fileReplacements` di quello shard) — nessun altro consumer di
`wm-package` lo emette. `AnalyticsController::global()` chiama `getRouteFilterUsage()` solo se
`config('wm-package.route_filter_analytics_enabled')` è `true` (default `false` nel pacchetto);
altrimenti `ranking_route_filters` resta `null` e nessuna query PostHog viene eseguita. Il
consumer camminiditalia abilita il flag con un override versionato in
`camminiditalia/config/wm-package.php` (`mergeConfigFrom()`, stesso pattern di
`internal_attribute_keys`, oc:8463) — mai un `.env`, per lo stesso motivo già documentato lì: un
`.env` di produzione non è tracciato da nessun test.

## Come ci siamo arrivati

- Una classifica "ricerche senza risultati" (`getTopSearchQueriesWithoutResults`) esisteva per
  individuare gap di contenuto, rimossa su richiesta del dev perché mostrava query non pulite.
  Se reintrodotta, va riallineata a `getTotalSearches()` (oc:8182).
- "Cammini più aperti" è una lista con barre CSS: un tentativo con `indexAxis:'y'` di Chart.js è
  stato scartato per resa estetica. Legenda e tooltip replicano manualmente i default di
  Chart.js per coerenza col grafico adiacente (oc:8182).
- `AnalyticsController::layer()` non aveva alcuna autorizzazione oltre al middleware `nova`:
  qualunque utente Nova poteva leggere l'analytics di un layer altrui indovinandone l'ID. Chiuso
  con `abort_unless(Administrator || owner)` (oc:8182).
- Riconciliazione con `feature/oc-8159-...`: quel branch aveva un filtro shard indipendente e
  incompleto in `fetchUserMovedPointsRows`. Per due dei tre metodi "user presence" l'equality
  nuda in AND rendeva l'OR logicamente inefficace (`A ∧ (A ∨ C) = A`). Debito accettato:
  `shardNameClause()` è ora invocato due volte per quelle query — SQL più verboso, stessi
  risultati (oc:8354).

## Utenti che percorrono davvero un cammino (oc:8159)

Alla `LayerAnalyticsCard` si affianca alle «Aperture» una metrica «Utenti sul cammino», sugli stessi
range a 30, 90 e 365 giorni e sempre visibile come testo, non solo al passaggio del mouse.

Il conteggio non è un'aggregazione temporale o spaziale dei punti: `AnalyticsService` interroga
PostHog via HogQL per i punti GPS dell'evento `userMoved`, filtrati per shard, **pre-filtrati per il
bounding box delle EcTrack del layer** calcolato in Postgres con un margine, e poi li porta in bulk
in Postgres per un match `ST_DWithin` contro le singole tracce. Il risultato è un
`COUNT(DISTINCT person_id)`.

È la stessa logica geografica che `UgcService::resolveLayerByProximity()` usa per attribuire una
segnalazione a un layer, ma qui in un'unica query su un intero insieme di punti invece che punto per
punto.
