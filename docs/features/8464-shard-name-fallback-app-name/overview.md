> Ticket: oc:8464

# Config app scritta sullo shard sbagliato: SHARD_NAME fa fallback silenzioso su APP_NAME

## Cosa cambia

`AnalyticsService::shardNameClause()` (il filtro shard usato da tutte le query PostHog della dashboard analytics Nova, introdotto da oc:8354) smette di leggere direttamente `config('wm-package.shard_name')` e legge invece, con priorità, una nuova chiave dedicata `config('wm-package.analytics_shard_name')`, con fallback a runtime su `shard_name` quando non impostata.

Viene aggiunta la nuova chiave `analytics_shard_name` in `config/wm-package.php`, letta da `env('ANALYTICS_SHARD_NAME')` (nessun default pre-calcolato nel file di config — il fallback su `shard_name` avviene a runtime dentro `shardNameClause()`, non nel file di config, per restare coerente con com'è già scritto il test esistente che override `wm-package.shard_name` via `config()` a runtime).

Nessun'altra chiave o consumer di `shard_name` viene toccato: `StorageService::getShardName()/getShardBasePath()`, `Nova\Layer::$shard`, `EcTrackApiLinksCard` restano ancorati a `shard_name` esattamente come oggi.

## Perché

Rilevato in call del 03/09/2026 (vedi `customer_request` del ticket): le query PostHog per la dashboard analytics su Nova filtrano per `shard_name` (fix oc:8354, cross-tenant leak). Su ambienti diversi da produzione `shard_name` risolve a un valore diverso da quello di produzione (es. `camminiditaliadev`, valore reale osservato negli eventi PostHog — vedi `docs/features/8159-.../notes.md` e `docs/features/8354-.../notes.md`), ma per interrogare i dati reali di produzione da PostHog serve poter puntare esplicitamente allo shard `camminiditalia` senza cambiare lo shard usato per storage/link.

La soluzione originariamente proposta nella `description` del ticket (rendere `SHARD_NAME` esplicito per ogni ambiente, rimuovere il fallback silenzioso su `APP_NAME`) è stata scartata su decisione esplicita del dev in fase di reverse-interaction: nessuna modifica a `SHARD_NAME` o al suo fallback, che restano come sono oggi. L'unico intervento è la nuova variabile dedicata alle query PostHog.

## Requisiti

- [ ] Nuova chiave `analytics_shard_name` in `wm-package/config/wm-package.php`, letta da `env('ANALYTICS_SHARD_NAME')` (default `null`)
- [ ] `AnalyticsService::shardNameClause()` risolve il valore a runtime con priorità: `config('wm-package.analytics_shard_name')` se non vuoto/trimmed, altrimenti `config('wm-package.shard_name')` — comportamento pre-esistente invariato per chiunque non imposti `ANALYTICS_SHARD_NAME`
- [ ] Nessuna modifica a `shard_name`, `StorageService`, `Nova\Layer::$shard` (riga 299), `EcTrackApiLinksCard` (riga 13) — restano tutti ancorati a `shard_name`
- [ ] Nuovo test in `tests/Unit/AnalyticsServiceTest.php`: verifica che la clausola generata usi `analytics_shard_name` quando impostata (diverso da `shard_name`), e che faccia fallback a `shard_name` quando `analytics_shard_name` è assente/vuota (i test esistenti, che impostano solo `wm-package.shard_name`, devono continuare a passare invariati)
- [ ] [UX] N/A — nessun componente UI coinvolto, solo config PHP e query server-side

## Rischi

Emersi da challenge adversariale (due subagenti isolati, uno per repo). Mitigazione scelta per tutti: **solo documentazione** (commenti `.env-example`, docblock nel codice, questa sezione) — nessuna guardia/logica applicativa aggiuntiva, per decisione esplicita del dev, che preferisce mantenere lo scope minimo già approvato.

- **[CRITICO] Leak cross-tenant nella direzione opposta a oc:8354.** Se `ANALYTICS_SHARD_NAME` venisse impostata per errore in produzione con il valore dello shard di un *altro* cliente Webmapp (stesso progetto PostHog condiviso), la dashboard Nova di camminiditalia mostrerebbe le metriche di quel cliente a un Administrator. Mitigazione: nessuna guardia di codice (es. blocco su `app()->environment('production')`) — il rischio è accettato e mitigato solo con un avviso esplicito, sia nel commento `.env-example` sia nel docblock di `shardNameClause()`, che la variabile è pensata per uso locale/debug temporaneo e **non va mai impostata in produzione**.
- **Nessuna osservabilità quando l'override è attivo.** `Log::warning` (pre-esistente) scatta solo se sia `analytics_shard_name` sia `shard_name` sono vuote — non c'è alcun log quando `analytics_shard_name` sta effettivamente sovrascrivendo `shard_name`. Chi debugga una dashboard con numeri sospetti non ha un segnale diretto su quale shard sia stato usato. Mitigazione: nessun `Log::info` aggiuntivo (decisione esplicita del dev) — il limite viene solo documentato nel docblock del metodo.
- **Typo/case-mismatch indistinguibile da "nessun dato nel periodo".** Il confronto è un semplice `trim()` + escape apici/backslash, nessuna normalizzazione case-insensitive. Un valore sbagliato produce silenziosamente zero risultati invece di un errore. Mitigazione: documentato nel docblock come limite noto, nessuna validazione aggiuntiva.
- **`config:cache` in produzione.** Se la variabile venisse mai impostata in un ambiente con configurazione cachata, un `.env` aggiornato non avrebbe effetto senza un `php artisan config:cache` esplicito. Non rilevante nello scope attuale (solo uso locale/dev), ma documentato per evitare confusione futura.
- **Scope più stretto del titolo del ticket.** Il titolo originale ("Config app scritta sullo shard sbagliato") riguarda la *scrittura* su storage (`StorageService`), che resta interamente intoccata da questo fix — qui si corregge solo la *lettura* per le query PostHog. Va reso esplicito in fase di chiusura ticket (Fase: update-context / note dev) per evitare che venga interpretato come "il problema di scrittura è risolto".
- **Nessun meccanismo di scadenza per un uso da debug puntuale.** Se `ANALYTICS_SHARD_NAME` viene impostata per un'indagine mirata (come quella del 03/09/2026) e poi dimenticata, resta debito ambientale silenzioso in quel `.env` locale/non versionato. Accettato: nessun meccanismo di scadenza/TTL applicativo, responsabilità del dev che la imposta.
- **Proliferazione di chiavi quasi omonime.** `shard_name` (storage/link) e `analytics_shard_name` (query PostHog) si somigliano nel nome ma servono scopi diversi — un futuro dev che scrive una nuova query PostHog potrebbe leggere per errore `shard_name` direttamente. Mitigazione: docblock di `shardNameClause()` aggiornato per spiegare esplicitamente la distinzione e linkare a questa nota.

## Out of scope

- Rendere `SHARD_NAME` esplicito per ambiente (dev/staging/prod) — deciso esplicitamente di non toccarlo in questo ciclo
- Rimuovere il fallback silenzioso di `shard_name` su `APP_NAME` (fail-loud o default `'webmapp'`) — punto 2 della `description` originale del ticket, rimandato a un ticket successivo se necessario
- Verifica di eventuali config di produzione già corrotte da scritture partite da ambienti dev — punto 3 della `description` originale, rimandato
- Backport su `develop`/`main` di wm-package — fix mantenuto solo su `RDO_ass_cammini_italia_2026_2`, coerente con la decisione già presa per oc:8354

## Moduli toccati

- `wm-package/config/wm-package.php` — nuova chiave `analytics_shard_name`
- `wm-package/src/Services/PostHog/AnalyticsService.php` — `shardNameClause()` usa la nuova chiave con fallback a runtime su `shard_name`
- `wm-package/tests/Unit/AnalyticsServiceTest.php` — nuovo test di override/fallback
