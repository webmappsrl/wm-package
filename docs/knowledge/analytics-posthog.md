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
