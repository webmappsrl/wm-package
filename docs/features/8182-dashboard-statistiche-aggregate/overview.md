> Ticket: oc:8182

# Dashboard statistiche aggregate per Cammini d'Italia

## Cosa cambia
`AnalyticsService`, `AnalyticsController` e `LayerAnalyticsCard` (Vue + PHP) — oggi disponibili solo per singolo layer — vengono estesi con una modalità "globale" che aggrega i dati su tutti i layer/tracce:

- `AnalyticsService::getUsage()`/`fetchUsage()` e le query private (`queryDailyBreakdown`, `queryBreakdown`, `queryUniqueUsers`) diventano tolleranti a `$id = null`: quando `null`, la clausola `AND properties.{$idProperty} = '{$id}'` viene omessa dalla query SQL, aggregando su tutti i layer. Nessun metodo duplicato: stesso core, stesso shape di ritorno (`total`, `daily_breakdown`, `breakdown`, `unique_users`) — copre "aperture totali" + "media giornaliera" (derivata client-side come oggi)
- `AnalyticsService::getAllLayersUsage(string $range)` — nuovo metodo sottile: ranking top 20 layer per aperture (`GROUP BY properties.layer_id`, `ORDER BY total DESC LIMIT 20`), stesso pattern di `getLayerTrackDownloads()` (query + risoluzione nomi dal DB locale, cascade traduzioni `['it','en',locale]`) — risponde a "quale cammino è più aperto"
- `AnalyticsService::queryTrackDownloads(?array $trackIds, string $range)` diventa tollerante a `$trackIds = null`: quando `null`, la clausola `WHERE properties.track_id IN (...)` viene omessa, aggregando su tutte le tracce di tutti i layer. `getAllTracksDownloads(string $range)` — nuovo metodo sottile che chiama `queryTrackDownloads(null, $range)` — risponde a "quale tappa è più scaricata"
- Nuova route dedicata `GET /nova-vendor/layer-analytics/global` → `AnalyticsController::global()`, che chiama `getUsage(..., id: null, ...)` per i KPI/grafico aggregati + `getAllLayersUsage()` e `getAllTracksDownloads()` per le due classifiche
- **Fix di sicurezza sull'endpoint esistente**: `AnalyticsController::layer()` non ha oggi alcun controllo di autorizzazione oltre al middleware `nova` (autenticazione) — qualunque utente Nova autenticato può vedere l'analytics di un layer che non gestisce, indovinando/enumerando l'ID. Aggiunto controllo esplicito: solo Administrator o il gestore proprietario di quel layer specifico (`$layer->user_id === $user->id`)
- `LayerAnalyticsCard::global()` — static factory che produce un'istanza in modalità aggregata (endpoint diverso, non `onlyOnDetail`)
- `LayerAnalyticsCard.vue` — KPI e bar chart giornaliero invariati. Blocco classifiche affiancate (2 colonne: layer + tracce, top 10 visibili con espansione a 20, `overflow-x-auto`) — stesso design già validato con `ui-ux-pro-max` prima del disallineamento di scope, ora confermato necessario da entrambe le classifiche

## Perché
La `customer_request` originale del ticket resta il riferimento primario: "quale cammino è più aperto, quale tappa è più scaricata" — **entrambe** le classifiche sono richieste, non solo quella layer. La call di refinement con il team (2026-07-16) non riduce questo scope: aggiunge solo una tecnica di implementazione DRY (estendere le query esistenti con parametri nullable invece di duplicarle) e conferma che le metriche aggregate semplici (aperture totali, media giornaliera) sono incluse allo stesso modo. Nessuna riduzione reale di scope rispetto al ticket iniziale — la lettura precedente di questo overview (che escludeva il ranking tracce) è stata corretta dal dev.

## Requisiti
- [ ] `getUsage(string $event, string $idProperty, ?int $id, string $range)` — `$id` nullable; `fetchUsage()` e le 3 query private omettono la clausola `AND properties.{$idProperty} = '{$id}'` quando `$id === null`
- [ ] Helper privato centralizzato (es. `idFilterClause(string $idProperty, ?int $id): string`) che costruisce la clausola filtro-ID, riusato da `queryDailyBreakdown`, `queryBreakdown`, `queryUniqueUsers` e `queryTrackDownloads` — invece di ripetere la stessa condizione `if ($id !== null)` in 4 punti diversi, riducendo il rischio che una futura modifica ne aggiorni solo alcuni
- [ ] Quando `$id === null` (modalità aggregata), aggiungere `AND properties.{$idProperty} IS NOT NULL AND properties.{$idProperty} != ''` — esclude eventi con `layer_id`/`track_id` assente o malformato, così il KPI totale aggregato e la somma delle righe della classifica (`getAllLayersUsage`/`getAllTracksDownloads`, che per costruzione escludono già questi eventi via `GROUP BY`) restano sempre coerenti tra loro
- [ ] Cache key aggiornata per gestire `$id = null` senza collisioni con le chiavi per-layer esistenti (es. `posthog:{event}:all:usage:{range}` quando `$id` è null, invariata altrimenti)
- [ ] `getAllLayersUsage(string $range)`: nuovo metodo, `GROUP BY properties.layer_id`, `ORDER BY total DESC LIMIT 50` (margine per filtrare orfani, vedi sotto), risoluzione nomi layer dal DB locale (stesso pattern di `getLayerTrackDownloads`)
- [ ] `queryTrackDownloads(?array $trackIds, string $range)` — `$trackIds` nullable; omette `WHERE properties.track_id IN (...)` quando `null`, aggregando su tutte le tracce
- [ ] `getAllTracksDownloads(string $range)`: nuovo metodo sottile, chiama `queryTrackDownloads(null, $range)`, `GROUP BY track_id ORDER BY downloads DESC LIMIT 50` (stesso margine), stessa risoluzione nomi già usata da `getLayerTrackDownloads`
- [ ] Righe con ID che non risolvono più a un layer/traccia esistente nel DB locale (cancellati, ma con eventi storici ancora in PostHog fino a 365gg) vengono scartate **dopo** la query e **prima** di troncare a 20 — evita che entità cancellate occupino un posto in classifica sottraendolo a layer/tracce realmente attivi
- [ ] Cache key dedicata anche per il ranking tracce globale (es. `posthog:trackDownloaded:all:downloads:{range}`), distinta da quella per-layer (`posthog:trackDownloaded:layer:{id}:downloads:{range}`)
- [ ] Route dedicata `GET /nova-vendor/layer-analytics/global` → `AnalyticsController::global()` (nuovo metodo)
- [ ] `AnalyticsController::global()` riusa `resolveRange()` esistente per coerenza con i range del pannello per-layer (30/90/365gg + mese specifico)
- [ ] **`AnalyticsController::global()` verifica esplicitamente il ruolo Administrator** (es. `abort_unless($request->user()?->hasRole('Administrator'), 403)`) — la route ha solo middleware `nova`, nessun controllo di ruolo; `canSee()` sulla Nova Card nasconde solo la UI, non protegge l'endpoint
- [ ] **`AnalyticsController::layer()` (esistente) aggiunge un controllo di autorizzazione**: `abort_unless($request->user()?->hasRole('Administrator') || $layer->user_id === $request->user()?->id, 403)` — cambio di comportamento intenzionale rispetto a oggi (dove qualunque utente Nova autenticato può vedere l'analytics di un layer altrui); non si modifica `LayerPolicy::view()` (usata più in generale per la risorsa Layer, restarne fuori evita un blast radius più ampio non richiesto da questo ticket) — il check vive solo nel controller analytics
- [ ] Test di regressione: un Validator proprietario del layer continua a vedere l'analytics del proprio layer; un Validator non proprietario riceve 403
- [ ] `LayerAnalyticsCard::global()`: static factory, nessun `layerId`, endpoint `/nova-vendor/layer-analytics/global`, card non `onlyOnDetail`
- [ ] Vue component: KPI (aperture totali, utenti unici, media/giorno) e bar chart giornaliero invariati — nessuna modifica al loro markup/logica
- [ ] [UX] Le due classifiche (layer + tracce) affiancate in 2 colonne (grid), stesso stile tabella già usato per "download per traccia" — nessun nuovo pattern visivo, solo replicato
- [ ] [UX] Render iniziale limitato a top 10 righe per classifica con toggle "mostra tutti (20)" — riduce il peso visivo della card senza perdere dati
- [ ] [UX] Wrapper `overflow-x-auto` sul contenitore delle due tabelle affiancate — evita rottura di layout su viewport stretti/sidebar Nova ridotta
- [ ] [UX] Titolo esplicito diverso tra le due modalità ("Analytics — Tutti i cammini" vs "Analytics Layer — {layer}") + badge/chip accanto al titolo in modalità globale
- [ ] Unit test in `tests/Unit/AnalyticsServiceTest.php`: nuovi casi per `getUsage(..., id: null, ...)`, `getAllLayersUsage()`, `queryTrackDownloads(null, ...)` e `getAllTracksDownloads()`; verifica di non-regressione sui test esistenti con `$id`/`$trackIds` valorizzati
- [ ] Feature test end-to-end su `AnalyticsController::global()` (route → controller → service), inclusa l'asserzione 403 per utenti non-Administrator
- [ ] `runQuery()`/`fetchUsage()` propagano il fallimento (query malformata, timeout PostHog) invece di ritornare silenziosamente un array vuoto — il JSON di risposta include un flag/eccezione che distingue "0 aperture reali" da "query fallita"; `LayerAnalyticsCard.vue` mostra il messaggio di errore già esistente per i fallimenti HTTP anche in questo caso, invece di renderizzare KPI a zero

## Rischi
- Refactor di due metodi privati condivisi (`getUsage`/`fetchUsage` e `queryTrackDownloads`) usati anche dai path per-layer esistenti — rischio di regressione se i parametri nullable introducono un bug nella costruzione delle clausole WHERE; mitigato dai test di non-regressione sui casi già coperti da `AnalyticsServiceTest.php`
- Query aggregate su tutti i layer/tracce (118 layer in produzione) sono potenzialmente più pesanti della query per singolo layer — mitigato dallo stesso pattern di cache (`TTL_MAP`/`LOCK_RANGES`) già usato per le query per-layer
- Endpoint globale raggiungibile da qualsiasi utente Nova autenticato senza il check esplicito di ruolo (vedi Requisiti) — mitigato aggiungendo l'authorization check lato controller, non solo lato Nova Card
- Il fix di autorizzazione su `layer()` è un cambio di comportamento per utenti già in produzione (un Validator che oggi riesce a vedere l'analytics di un layer non suo, dopo il fix riceverà 403) — non dovrebbe esserci impatto reale se nessun Validator sta sfruttando questo gap, ma non è verificabile a priori senza controllare i log di accesso; da monitorare dopo il deploy
- [UX] Due tabelle affiancate full-width in una Nova Card rischiano di affollare la vista o rompere il layout su schermi stretti — mitigato da: cap iniziale a 10 righe con espansione, e wrapper `overflow-x-auto` (raccomandazioni da `ui-ux-pro-max`, product type "Analytics Dashboard" → stile Data-Dense, dashboard pattern Drill-Down Analytics + Comparative)

## Out of scope
- Filtri aggiuntivi sulla vista aggregata (per app, per owner, per periodo custom oltre ai range esistenti)
- Export CSV/PDF della classifica
- Paginazione oltre il top 20
- Gestione multi-app (assunta singola App "Cammini di Italia" — verificato: 1 sola App nel DB locale)

## Moduli toccati
- `src/Services/PostHog/AnalyticsService.php`
- `src/Http/Controllers/Nova/AnalyticsController.php`
- `src/WmPackageServiceProvider.php` (nuova route)
- `src/Nova/Cards/LayerAnalytics/src/LayerAnalyticsCard.php`
- `src/Nova/Cards/LayerAnalytics/resources/js/components/LayerAnalyticsCard.vue`
- `tests/Unit/AnalyticsServiceTest.php` (estensione)
- nuovo test Feature per `AnalyticsController::global()`
