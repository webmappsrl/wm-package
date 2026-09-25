> Ticket: oc:8637

# Nome utente sul marker live della mappa attivabile per singolo shard

## Cosa cambia
Il flag che decide se il marker live della mappa Nova (posizione in tempo reale degli utenti
vicini alle tracce del layer, dati PostHog `userMoved`) mostra nome e cognome e il link alla
scheda Nova dell'utente smette di essere una variabile locale fissa a `false` dentro
`Layer::getFeatureCollectionMap()` e diventa una chiave di configurazione del package,
`wm-package.analytics_show_live_user_identity`, letta dalla variabile d'ambiente
`ANALYTICS_SHOW_LIVE_USER_IDENTITY` con default `false`.

Ogni shard può quindi riattivare l'identità sul marker dal proprio `.env`, senza toccare il
codice. Chi non imposta la variabile mantiene il comportamento attuale (marker anonimo).

## Perché
oc:8586 ha reso anonimo il marker per tutti i consumer del package, con un valore fisso nel
codice. Allo scrum del 23/09/2026 è stato deciso di renderlo opzionale «perché dipende chi la
vuole», ma senza un interruttore in piattaforma (Nova) perché «è semplice e al momento non ci
serve, non abbiamo altri casi d'uso»: basta una variabile per shard. Camminiditalia resta
anonimo.

## Requisiti
- [ ] Nuova chiave `analytics_show_live_user_identity` in `config/wm-package.php`, letta da
      `env('ANALYTICS_SHOW_LIVE_USER_IDENTITY', false)`, accanto a `layer_user_presence_distance_meters`
- [ ] `Layer::getFeatureCollectionMap()` legge il flag da `config('wm-package.analytics_show_live_user_identity')`
      al posto della variabile fissa; un solo flag controlla insieme nome e link
- [ ] Flag spento (default): comportamento identico a oggi — tooltip «Posizione utente (ultimi 30 minuti)»,
      nessuna property `link`, nessuna query sugli utenti
- [ ] Flag acceso, utente trovato con nome/cognome: tooltip con nome e cognome, `link` a
      `nova/resources/users/{id}`
- [ ] Flag acceso, utente trovato ma con nome e cognome vuoti: tooltip generico, `link` presente
      (se l'utente esiste il link c'è — comportamento precedente a oc:8586)
- [ ] Flag acceso, `user_id` assente o senza utente corrispondente: tooltip generico, nessun `link`
- [ ] Nessuna distinzione per ruolo: con il flag acceso l'identità è visibile a ogni utente
      che supera il gate Nova del consumer e apre la mappa
- [ ] Commento di oc:8586 in `Layer.php` e docblock di `AnalyticsService::getRecentUserPositions()`
      riscritti: il valore non è più fisso, spiegando che la scelta di oc:8586 è stata superata da oc:8637,
      e dichiarando il limite noto: l'identità mostrata è quella dichiarata dall'app nell'evento
      PostHog (`properties.user_id`), non verificata dal backend
- [ ] Test in `tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php` per entrambi gli
      stati del flag (config impostata nel test)
- [ ] Test del default reale: senza impostare la config, con utente trovato e nome valorizzato,
      il marker resta anonimo e senza link (copre il collegamento file di config → `Layer.php`)

## Rischi
- Riattivazione per errore da `.env`: era il motivo per cui oc:8586 aveva scelto un valore fisso.
  Mitigazione: default `false`, nessun consumer lo accende in questo ciclo, documentazione nel
  consumer solo come riga commentata.
- Con il flag acceso l'identità è visibile a ogni utente che supera il gate Nova del consumer, e su
  qualunque layer: l'endpoint `/nova-vendor/feature-collection-map/{model}/{id}` richiede solo la
  sessione Nova e non ha autorizzazione per singolo layer (rischio già noto da oc:8586). Accettato:
  il filtro per ruolo è fuori scope; va documentato nel consumer che attiva il flag.
- `user_id` arriva dall'evento PostHog `userMoved` inviato dal client e non è verificato: un evento
  forgiato può far comparire il nome di un utente reale in un punto falso. Comportamento già
  presente prima di oc:8586, accettato in questo ciclo e dichiarato nel commento del codice.
- Con la configurazione messa in cache (`config:cache`) il cambio di `.env` non ha effetto finché
  la cache non viene rigenerata — comportamento standard Laravel, da ricordare a chi attiva il flag.

## Out of scope
- Impostazione per App modificabile da Nova (scartata allo scrum del 23/09/2026)
- Visibilità dell'identità limitata a certi ruoli
- Flag separati per nome e link
- Attivazione del flag in qualunque shard
- Autorizzazione per singolo layer sull'endpoint della mappa
- Verifica lato server dell'identità negli eventi PostHog

## Moduli toccati
- `wm-package/config/wm-package.php` — nuova chiave
- `wm-package/src/Models/Layer.php` — lettura del flag da config, commento aggiornato
- `wm-package/src/Services/PostHog/AnalyticsService.php` — solo docblock
- `wm-package/tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php` — test per i due stati
- Parte nel consumer camminiditalia: vedi `docs/features/8637-.../overview.md` del repo principale
