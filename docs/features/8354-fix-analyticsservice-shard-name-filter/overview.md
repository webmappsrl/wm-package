> Ticket: oc:8354

# Fix cross-tenant data leak: AnalyticsService non filtra per shard_name

## Cosa cambia

`whereClause($range)` — già l'unico punto condiviso da tutte le 8 query private di `AnalyticsService` per la clausola temporale — viene estesa per includere anche il filtro sulla property `shard_name` dell'evento PostHog, confrontata con `config('wm-package.shard_name')`. Centralizzare qui (invece che duplicare in ognuna delle 8 query) fa sì che ogni query che già chiama `whereClause()` erediti automaticamente il filtro, e che l'escape del valore viva in un solo punto.

La clausola gestisce entrambe le forme empiricamente osservate su dati reali di produzione:

- annidata: `properties.shard_name._value = '<shard>'` (formato attuale, eventi mobile recenti camminiditalia)
- flat: `properties.shard_name = '<shard>'` (formato osservato su almeno un evento storico di un altro shard, "geohub")

tramite un OR tra le due forme. Le 8 query beneficiarie (nessuna modifica alla loro logica propria, solo al contenuto di `$whereClause` a monte): `queryDailyBreakdown`, `queryBreakdown`, `queryUniqueUsers`, `queryTrackDownloads`, `queryTrackShares`, `queryTotalSearches`, `queryTopSearchQueries`, `queryAllLayersRanking`.

**Guard su config vuoto**: se `config('wm-package.shard_name')` risolve a stringa vuota/null, `whereClause()` non applica alcun filtro shard_name (comportamento identico a oggi, nessuna regressione) e loggia un `Log::warning` — fail-open con log, non fail-closed silenzioso. Senza questo guard, un ambiente con `SHARD_NAME`/`APP_NAME` non settati produrrebbe `shard_name = ''`, che non matcha mai nessun evento reale: tutte le metriche scenderebbero a zero senza errore visibile (rischio identificato in Fase: challenge).

**Escape**: il valore di `config('wm-package.shard_name')` viene interpolato in SQL raw come il resto del file — l'apice viene escapato (`str_replace("'", "\\'", ...)`) nello stesso punto centralizzato, prima dell'interpolazione.

**Secondo fix (stesso ticket, scope esteso in corso d'opera)**: `getLayerUsage`, `getGlobalUsage` e `getAllLayersUsage` oggi filtrano/raggruppano solo su `properties.layer_id`, ma la maggior parte degli eventi `layerOpened` reali non porta più questa property — porta solo `properties.layer_label` (formato `"{id} - {titolo}"`). Viene introdotta un'espressione centralizzata che usa `layer_id` se presente, altrimenti estrae l'ID numerico da `layer_label` — vedi Requisiti per il dettaglio tecnico.

## Perché

Il progetto PostHog (project_id=1, posthog.webmapp.it) è condiviso tra più consumer wm-package (confermato durante l'analisi di oc:8159, e confermato empiricamente in questo ciclo interrogando PostHog reale: eventi con `shard_name` diverso da "camminiditalia" sono presenti nello stesso progetto). Nessuno degli 8 metodi elencati filtra per shard/app — solo per `properties.$lib` e per ID numerico (layer_id/track_id). Se un altro consumer ha un Layer o una EcTrack con lo stesso ID numerico di uno di camminiditalia, i suoi eventi PostHog si mescolerebbero silenziosamente nelle metriche mostrate in Nova (card `LayerAnalyticsCard`, dashboard aggregata oc:8182).

`feature/oc-8159-...` introduce lo stesso filtro solo per il nuovo metodo `fetchUserMovedPointsRows` (query "user presence" GPS) — senza questo fix, quello resterebbe l'unico metodo corretto, mentre tutti gli altri KPI già in produzione (o in procinto di andarci, via oc:8182) resterebbero silenziosamente sbagliati in presenza di collisioni di ID tra consumer.

**Estensione di scope (2026-08-18, durante l'implementazione)**: verificando le statistiche reali con l'utente, è emerso un secondo problema sugli stessi metodi — molto più impattante in termini di numeri, anche se distinto dal leak cross-tenant. Verificato empiricamente su PostHog di produzione: negli ultimi 30 giorni solo il 12% degli eventi `layerOpened` Android, il 2% iOS e il 23% web portano la property `layer_id` (queste percentuali sono aggregate su tutti gli shard del progetto PostHog condiviso; per il solo shard camminiditalia la percentuale è 0% su tutte e tre le piattaforme — verificato con query diretta, vedi notes.md) — il resto (fino al 98%) porta solo `layer_label`, una stringa nel formato `"{id} - {titolo}"` (es. `"56 - Cammino Minerario di Santa Barbara"`), verificata **100% consistente** su 90 giorni di dati reali e **mai in contraddizione** con `layer_id` quando entrambe le property sono presenti sullo stesso evento (25.622/25.622 casi concordanti). Il filtro `properties.layer_id = '{id}'` usato da `getLayerUsage`, `getGlobalUsage`, `getAllLayersUsage` scarta silenziosamente quasi tutti gli eventi reali, sottostimando pesantemente le metriche mostrate in Nova — indipendentemente dal fix `shard_name`. Non riguarda `trackDownloaded`/`contentShared` (verificato: `track_id`/`content_id` sempre presenti al 100%).

## Requisiti

- [x] Centralizzare la clausola shard_name (OR tra forma flat e annidata, vedi "Cosa cambia") dentro `whereClause($range)`, non duplicata nelle 8 query private
- [x] Il valore di confronto è `config('wm-package.shard_name')`, coerente con l'uso già esistente altrove nel package (`Nova/Layer.php`, `EcTrackApiLinksCard.php`, `StorageService.php`, `fetchUserMovedPointsRows` di oc:8159)
- [x] Guard su config vuoto: se `config('wm-package.shard_name')` è vuoto/null, nessuna clausola shard_name viene applicata (fail-open, comportamento identico a oggi) + `Log::warning` esplicito
- [x] Escape dell'apice sul valore di `shard_name` prima dell'interpolazione SQL, nello stesso punto centralizzato
- [x] Nessuna modifica ai metodi già introdotti da oc:8159 (`fetchUserMovedPointsRows` e i 3 metodi pubblici che lo usano) — filtrano già correttamente
- [x] Test unitari in `tests/Unit/AnalyticsServiceTest.php`:
  - per almeno uno dei metodi pubblici, con `config(['wm-package.shard_name' => 'camminiditalia'])` impostato a valore letterale fisso nel test (non letto dinamicamente nell'assert, per evitare asserzioni tautologiche), verificare che l'SQL generato contenga esattamente `'camminiditalia'` in entrambe le forme (OR)
  - test dedicato per il guard su config vuoto: `config(['wm-package.shard_name' => ''])` → nessuna clausola shard_name nell'SQL + `Log::warning` chiamato
- [x] Nessuna invalidazione attiva della cache `posthog:*` esistente al deploy — si accetta la finestra di TTL naturale (max 6h su `last_365_days`) durante la quale dati calcolati prima del fix restano in cache
- [x] **Fallback layer_id → layer_label**: introdurre un'espressione SQL centralizzata (`effectiveLayerIdExpression()`) che usa `properties.layer_id` se presente, altrimenti estrae l'ID numerico da `properties.layer_label` (`extract(properties.layer_label, '^([0-9]+)')`) — applicata a `idFilterClause()` (quando `$idProperty === 'layer_id'`, così `getLayerUsage`/`getGlobalUsage` la eredita) e a `queryAllLayersRanking()` (SELECT + GROUP BY)
- [x] **`idInFilterClause()` va aggiornato anch'esso** (non solo `idFilterClause()`/`queryAllLayersRanking()` come inizialmente previsto): è usato anche da `validLayerIdsClause()` per filtrare `layer_id`, non solo da `queryTrackDownloads()` per `track_id` — trovato come finding Critical in review, corretto con lo stesso pattern (`$idProperty === 'layer_id'` → usa `effectiveLayerIdExpression()`)
- [x] Test che verificano il fallback: un evento con solo `layer_label` (senza `layer_id`) deve essere incluso nel filtro per ID esatto e nella classifica aggregata, con lo stesso `layer_id` numerico atteso

## Rischi

- **Formato non uniforme di `shard_name` su PostHog**: verificato empiricamente che eventi diversi (per shard/versione SDK) possono avere `shard_name` sia annidato (`{"_value": "..."}`) sia flat. Mitigato con OR esplicito tra le due forme, centralizzato in `whereClause()`.
- **Config `shard_name` vuoto/non settato produce fallimento silenzioso (dashboard a zero) se non gestito**: mitigato dal guard fail-open + `Log::warning` (vedi Requisiti) e da un test dedicato non tautologico che fissa il valore a livello di test invece di leggerlo dinamicamente dal config nell'assert.
- **Interpolazione SQL raw non parametrizzata**: un valore di `shard_name` contenente un apice romperebbe la sintassi SQL. Mitigato con escape centralizzato in `whereClause()` (un solo punto, non ripetuto 8 volte).
- **Base branch non standard**: il fix va applicato su `RDO_ass_cammini_italia_2026_2` (branch cliente long-lived), non su `develop`/`main` di wm-package — è lì che vivono, già mergiati, tutti gli 8 metodi coinvolti (introdotti da oc:8182, PR #247). I 2 metodi già presenti su `develop` (`getLayerUsage`, `getLayerTrackDownloads`) hanno lo stesso leak e **non vengono corretti in questo ciclo** — altri consumer del package (maphub, osm2cai2, ecc.) restano esposti. Backport tracciato come follow-up in `notes.md`, deciso esplicitamente fuori scope per questo ciclo (scoped su camminiditalia/26Q3).
- **Cache stale post-deploy**: dati già cachati (calcolati senza il filtro) restano visibili fino a naturale scadenza TTL (accettato esplicitamente, vedi Requisiti).
- **Cache non versionata, asimmetria in caso di rollback**: le chiavi di cache (`posthog:{event}:{id}:usage:{range}`) non includono una versione dello schema query. Un eventuale revert del codice non invaliderebbe la cache: per fino a 6h i dati resterebbero quelli calcolati **con** il filtro anche a codice revertito. Accettato come rischio noto, nessuna azione di codice in questo ciclo (versionare le cache key è un cambiamento più ampio, fuori scope).
- **Fallback layer_id→layer_label dipende dal formato "{id} - {titolo}"**: verificato 100% consistente su 90 giorni di dati reali, ma nessuna garanzia contrattuale che l'app mobile/webapp continuino a rispettarlo in futuro — un cambio di formato lato client romperebbe silenziosamente il fallback (nessun evento avrebbe più un ID valido estratto), tornando al comportamento di sotto-conteggio pre-fix senza errori visibili. Non è testabile automaticamente lato backend; da verificare se le metriche calano di nuovo in modo anomalo dopo un rilascio dell'app.
- **Bug cosmetico noto e non toccato**: alcuni eventi hanno `layer_label = "1 - [object Object]"` (bug lato client, un oggetto stringificato invece del titolo) — non impatta l'estrazione dell'ID (funziona correttamente), ma se il layer name venisse mai usato da questo fix per altro scopo andrebbe rivisto. Qui non è usato: il nome del layer per la UI Nova viene già risolto da `Layer::find($id)` sul DB locale, non dalla property PostHog.

## Out of scope

- Il fallback `layer_id`→`layer_label` (vedi sopra, ora in scope) copre solo il caso `layerOpened`. Non copre l'assenza totale di `layer_id`/`layer_label` insieme (verificato: 0 eventi in questa condizione su 90 giorni, ma se dovesse succedere in futuro l'evento resterebbe silenziosamente escluso, come oggi).
- Fix del bug cosmetico `layer_label = "1 - [object Object]"` lato client — non impatta questo fix (serve solo l'ID, mai il titolo), da segnalare a parte al team frontend/mobile se rilevante.
- Metodi "user presence" introdotti da `feature/oc-8159-...` (`fetchUserMovedPointsRows`, `getUserMovedStats`, `getRecentUserPositions`, `getAllLayersUserPresence`) — già filtrati correttamente, non toccati.
- Invalidazione esplicita della cache `posthog:*` esistente al deploy.
- Verifica manuale post-deploy contro PostHog reale (il criterio di successo di questo ciclo sono i soli test unitari).
- Backport del fix su `develop`/`main` di wm-package — deciso esplicitamente fuori scope in Fase: challenge; da registrare come follow-up in `notes.md` e valutare come ticket separato.
- Versionamento delle cache key per gestire in modo pulito lo scenario di rollback.

## Moduli toccati

- `wm-package/src/Services/PostHog/AnalyticsService.php` (branch `RDO_ass_cammini_italia_2026_2`)
- `wm-package/tests/Unit/AnalyticsServiceTest.php` (branch `RDO_ass_cammini_italia_2026_2`)
