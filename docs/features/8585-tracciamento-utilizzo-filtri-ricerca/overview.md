> Ticket: oc:8585

# Aggregazione e visualizzazione dell'uso dei filtri avanzati (route) — Analytics camminiditalia

## Cosa cambia

Nella card Nova "Analytics — Tutti i cammini" (vista globale, `LayerAnalyticsCard.vue`), subito
dopo il grafico "Cammini più frequentati", viene aggiunto un nuovo grafico a barre orizzontali
impilate per piattaforma (Android/iOS/Webapp) che mostra, per ciascuna delle 7 caratteristiche del
pannello "filtro avanzato" della search bar camminiditalia (Lunghezza, Tappe, Tipologia, Portata,
Regioni, Stagioni, Temi), quante volte è stata usata nel range selezionato — conteggio grezzo
degli eventi PostHog `filterUsed` con `filter_type: 'route'`, già emessi dal frontend (nessuna
modifica lato app/wm-core).

Le 7 righe sono sempre visibili, anche a zero, ordinate per totale decrescente, coerente con
l'esistente grafico "Cammini più frequentati".

## Perché

Richiesta emersa durante la call di collaudo release 13.1.17: il cliente vuole sapere quali filtri
di ricerca sono realmente usati, per poter eventualmente rimuovere quelli poco utilizzati
dall'interfaccia. L'evento di tracking esiste già — introdotto sotto oc:8414 insieme al pannello
filtri, verificato presente su `develop` sia in `wm-core` (`search-bar.component.camminiditalia.ts`)
che, sul lato backend, il filtro shard che lo riguarda in `wm-package`. Manca solo l'aggregazione e
la visualizzazione lato Nova.

## Requisiti

- [ ] Nuovo metodo `AnalyticsService::getRouteFilterUsage(string $range)`. Usa
      `runQuery(..., strict: true)`: un fallimento HTTP lancia `AnalyticsQueryException`,
      propagata da `AnalyticsController::global()` come per le altre metriche (risposta 502)
      invece di restituire zeri silenziosi indistinguibili da "nessun uso".
- [ ] La query recupera righe grezze (`filter_id`, `properties.$session_id`, `properties.$lib`,
      `timestamp`) filtrate su `event = 'filterUsed' AND properties.filter_type = 'route'`, stesso
      filtro shard (`whereClause()`) delle altre query — nessun `GROUP BY` in HogQL.
- [ ] Deduplica a 5 secondi fatta **lato PHP**, non con una window function HogQL: righe ordinate
      per `(session_id, filter_id, timestamp)`, eventi consecutivi della stessa sessione/filtro
      entro 5s vengono collassati in un solo conteggio. Scelta deliberata per non affidarsi a
      `lag()`/`lagInFrame()` non verificate in questo ambiente HogQL — precedente noto:
      `leadInFrame()` non è affidabile qui (vedi commento su `queryTopSearchQueries()`).
- [ ] Le 7 righe (`distance`, `stageCount`, `shape`, `walkingNetwork`, `regions`, `themes`,
      `seasons`) sono sempre presenti nel risultato finale, anche con `total: 0` e
      `breakdown: []`, indipendentemente da cosa restituisce PostHog per il range.
- [ ] Se dal fetch risulta un `filter_id` **non** fra i 7 attesi, va loggato con `Log::warning`
      (stesso pattern tripwire del cap 1000 righe in `queryAllLayersRanking()`) invece di essere
      scartato silenziosamente — segnale di drift fra `wm-core` e questo mapping.
- [ ] Mapping statico `filter_id` → etichetta italiana: `distance`→Lunghezza, `stageCount`→Tappe,
      `shape`→Tipologia, `walkingNetwork`→Portata, `regions`→Regioni, `themes`→Temi,
      `seasons`→Stagioni.
- [ ] Timeout dedicato per questa query (nuova costante, pattern
      `USER_PRESENCE_TIMEOUT_SECONDS`), più aggressivo del default 10s — un rallentamento non deve
      trascinare l'intera `global()`.
- [ ] Cache con lo stesso pattern `rememberWithLock()` + `TTL_MAP` in base al `$range`, chiave
      `posthog:filterUsed:route:ranking:{$range}`.
- [ ] `AnalyticsController::global()` chiama il nuovo metodo e aggiunge `ranking_route_filters`
      alla risposta JSON — stesso `$range` risolto da `resolveRange()`, nessun nuovo parametro;
      `AnalyticsQueryException` gestita nel `try/catch` già esistente.
- [ ] `LayerAnalyticsCard.vue`: nuova sezione **"Uso dei filtri sui cammini"**, ultimo blocco
      dentro la sezione collassabile "Mostra altre statistiche" (`showRestOfAnalytics`) — non
      visibile di default insieme a "Cammini più aperti"/"Cammini più frequentati" (deciso a
      posteriori, vedi [notes.md](notes.md#task-3-layeranalyticscardvue)). Stesso stile a barre
      orizzontali impilate per piattaforma con la legenda `PLATFORMS` già esistente — nessun
      troncamento/"Mostra tutti" (sempre 7 righe fisse).
- [ ] Test unitari su `AnalyticsService::getRouteFilterUsage()`: righe presenti anche a zero,
      dedup a 5s (eventi ravvicinati collassati, eventi distanti >5s contati separatamente),
      `Log::warning` su `filter_id` sconosciuto, propagazione di `AnalyticsQueryException` su
      fallimento query, filtro shard applicato, cache.
- [ ] Test feature su `AnalyticsController::global()`: `ranking_route_filters` presente nella
      risposta, risposta 502 su fallimento della query.
- [ ] Nuovo config opt-in `wm-package.route_filter_analytics_enabled` (default `false` nel
      pacchetto): `AnalyticsController::global()` chiama `getRouteFilterUsage()` e valorizza
      `ranking_route_filters` solo se il flag è attivo, altrimenti la chiave resta `null` —
      nessuna query PostHog inutile, nessuna sezione vuota per i consumer di `wm-package` che
      non hanno il pannello "filtro avanzato" (oggi solo camminiditalia lo emette). Il consumer
      camminiditalia lo abilita con un override **versionato** in `camminiditalia/config/wm-package.php`
      (`mergeConfigFrom()`, stesso pattern già usato per `internal_attribute_keys`, oc:8463) —
      non un `.env`, per lo stesso motivo già documentato lì: un `.env` di produzione non è
      tracciato da nessun test.

## Rischi

- **Il conteggio (anche dopo dedup a 5s) resta "quante volte", non "quanti utenti"**: pesa
  diversamente i filtri multi-select (Regioni, Temi, Stagioni) rispetto a quelli a scelta singola
  (Tipologia), e non traccia un `resetFilters()` immediato dopo la selezione. Il dedup a 5s
  attenua il rumore più grossolano ma non elimina il limite — accettato come coerente con la
  decisione esplicita di usare un conteggio grezzo (non un conteggio per utente unico).
- **Zero indistinguibile da errore di query**: mitigato usando `runQuery(..., strict: true)` — un
  fallimento propaga `AnalyticsQueryException` invece di restituire zeri silenziosi.
- **Drift silenzioso fra `wm-core` e il mapping statico Nova**: nessun tipo condiviso lega i 7
  `filter_id` lato frontend al mapping lato backend. Mitigato con un `Log::warning` su ogni
  `filter_id` sconosciuto, ma resta un accoppiamento a distanza fra due repository senza
  contratto formale — un ottavo filtro futuro va comunque aggiunto manualmente qui.
- **Terzo pattern di query quasi duplicato in `AnalyticsService`**: affianca `fetchUsage()` e
  `queryAllLayersRanking()` come pattern simile ma non riusato — debito tecnico accettato, la
  classe supera già 1300 righe.
- **Asimmetria di rollback**: se il cliente rimuove un filtro dall'interfaccia sulla base di
  questi numeri, quella è una modifica di prodotto separata (frontend, altro repo, altro ciclo di
  rilascio) — molto più costosa da annullare del revert di questo ticket.
- **Piattaforme non pesate per base utenti** e **volume storico ancora basso** (evento
  introdotto di recente sotto oc:8414): limiti ereditati dal grafico "Cammini più frequentati"
  già esistente, non introdotti da questo ticket.
- **Cache stantia dopo un eventuale fix**: fino allo scadere del TTL (fino a 21600s/6h) la card
  continuerebbe a mostrare dati basati su una versione precedente del codice, senza invalidazione
  manuale prevista — stesso limite già presente per le altre metriche cache di questa classe.

## Out of scope

- Nessuna modifica al frontend (`webmapp-app`, `wm-core`): l'evento `filterUsed`/`route` esiste
  già ed è fuori da questo ticket.
- Nessun dettaglio per singolo valore selezionato all'interno di un filtro (es. quali regioni
  specifiche) — solo conteggio per filtro, una riga per `filter_id`.
- Nessuna vista per-layer di questo dato: il pannello filtri agisce sulla lista Home, non su un
  singolo layer, quindi la sezione vive solo nella vista "Tutti i layer" (`card.mode === 'global'`).

## Moduli toccati

- `wm-package/src/Services/PostHog/AnalyticsService.php` — nuovo metodo `getRouteFilterUsage()` +
  query privata `queryRouteFilterUsage()`
- `wm-package/src/Http/Controllers/Nova/AnalyticsController.php` — `global()`
- `wm-package/src/Nova/Cards/LayerAnalytics/resources/js/components/LayerAnalyticsCard.vue` —
  nuova sezione UI
- `wm-package/tests/Unit/AnalyticsServiceTest.php`,
  `wm-package/tests/Feature/AnalyticsControllerGlobalTest.php` — nuovi test
- `wm-package/config/wm-package.php` — nuova chiave `route_filter_analytics_enabled` (default
  `false`)
- `camminiditalia/config/wm-package.php` — override a `true` per il consumer camminiditalia
  (fuori da `wm-package`, repo `camminiditalia` root)
