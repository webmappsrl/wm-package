> Ticket: oc:8242

# Endpoint export Apps per sync distribuita (Orchestrator multi-shard)

## Cosa cambia

wm-package espone un nuovo endpoint API machine-to-machine, **versionato**, che permette a Orchestrator (o a qualsiasi consumer autorizzato) di leggere l'anagrafica completa delle app dell'istanza:

- `GET /api/v1/export/apps` — lista **paginata** (paginazione standard Laravel) di tutte le app; supporta `?updated_after=<ISO8601>` per la sync incrementale (Orchestrator inizialmente non lo usa — fa full fetch — ma il contratto lo prevede da subito)
- `GET /api/v1/export/apps/{app}` — singola app (usato dalla sync on-demand all'apertura del detail su Orchestrator)

**Contratto = whitelist esplicita.** Il payload è definito da una `JsonResource` con l'elenco dei campi scritto nero su bianco — mai serializzazione automatica delle colonne del modello (`$guarded = []` renderebbe lo shape dipendente dallo stato delle migration dello shard). Include i dati CRM agganciati all'app (email/nome del proprietario via relazione `author`, con eager loading per evitare N+1). La versione nell'URL (`v1`) rende espliciti gli eventuali breaking change futuri: campo aggiunto = ok in `v1`, campo rinominato/rimosso = `v2`.

**Autenticazione e protezione:**
- Bearer token statico verificato contro una variabile ENV dell'istanza (`WM_EXPORT_TOKEN`); ogni consumer/shard usa un token proprio
- Semantica errori distinta: **401** token mancante o errato nella richiesta; **403** export non configurato sullo shard (ENV assente); **422** `updated_after` malformato (mai degradare silenziosamente a full fetch); 404 solo per app inesistente sul detail
- **Throttle** sulle route (come le altre route sensibili del package)

Essendo parte del package, l'endpoint arriva a **tutti gli shard** (maphub, camminiditalia, osm2cai, …) con il normale aggiornamento di wm-package — nessuno sviluppo per-istanza. Kill switch operativo: rimuovere `WM_EXPORT_TOKEN` dall'ENV disabilita l'endpoint (403) senza release.

## Perché

Orchestrator (ticket oc:8242) sostituisce l'import statico dal solo geohub con una sync multi-shard. Gli shard basati su wm-package oggi non espongono alcun endpoint "tutte le app" (le route API del package sono per-app: config, icone, geojson): questo endpoint è il pezzo mancante lato shard. Il token evita di replicare l'esposizione pubblica di email e anagrafica clienti presente nell'endpoint legacy del geohub.

## Requisiti

- [ ] Route `GET /api/v1/export/apps` (lista paginata) e `GET /api/v1/export/apps/{app}` registrate nel routing API del package
- [ ] Middleware Bearer token statico da ENV: 401 token errato/mancante, 403 se `WM_EXPORT_TOKEN` non configurato lato server
- [ ] Throttle sulle route export
- [ ] Supporto `?updated_after=` (ISO 8601) sulla lista, filtrando su `apps.updated_at`; input malformato → 422, mai fallback silenzioso al full fetch
- [ ] Payload definito da `JsonResource` con whitelist esplicita dei campi (colonne modello App + email/nome proprietario via `author` con eager loading); struttura documentata — è il contratto verso Orchestrator
- [ ] Nessun effetto collaterale: endpoint read-only, nessun observer/job scatenato
- [ ] Test sul package (workbench): 401/403/422, throttle, filtro `updated_after`, paginazione, shape del payload (contract test sulla whitelist)

## Rischi

- **Contratto condiviso con Orchestrator**: ogni modifica futura allo shape impatta la sync. Mitigazione strutturale: whitelist esplicita nella Resource + versione nell'URL — un campo si aggiunge in `v1`, non si rinomina/rimuove mai (per quello nasce `v2`).
- **Rollout per shard**: l'endpoint esiste solo dopo l'aggiornamento del package su ciascuna istanza; fino ad allora Orchestrator riceve 404 su quello shard (gestito lato Orchestrator con errori isolati per shard).
- **Payload pesante**: le colonne config/traduzioni sono JSON grandi. Mitigazione: paginazione dal giorno uno; a volumi attuali è una pagina, ma il contratto è pronto.
- **Token statico senza rotazione/audit**: un leak espone l'anagrafica app+proprietari dello shard finché il token non viene cambiato in ENV (restart istanza). Accettato per la v1 (token distinto per shard limita il raggio); rotazione programmata e audit log fuori scope.

## Out of scope

- Export di Layer, POI, track, UGC o media
- Autenticazione diversa dal token statico (OAuth, firma richieste, rotazione automatica)
- Endpoint di scrittura
- Audit log degli accessi

## Moduli toccati

- `routes/api.php` — nuove route export versionate
- `src/Http/Controllers/Api/` — nuovo controller export apps
- `src/Http/Middleware/` — nuovo middleware Bearer token da ENV (non esiste nulla di riusabile nel package)
- `src/Http/Resources/` — JsonResource con whitelist campi (contratto)
- `tests/` — test endpoint (auth, throttle, updated_after, paginazione, contract test payload)
