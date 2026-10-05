> Ticket: oc:8700

# Notes — Catasto visibile solo ad Administrator ed Editor

## Divergenze dal piano, task per task

### Task 1: Policy del Catasto

Il piano prevedeva una sola classe `TrailRegistryPolicy` con `create(User, string $modelClass)` per
distinguere le istanze da codici e anomalie. Il Gate di Laravel però toglie il nome della classe
dagli argomenti prima di chiamare la policy (`vendor/laravel/framework/src/Illuminate/Auth/Access/Gate.php:830-837`):
`create()` riceverebbe solo l'utente, e la creazione di un'istanza verrebbe negata anche all'Editor.
Si applica il ripiego già scritto nel piano: `TrailRegistryPolicy` resta la base, con `allows()` e i
metodi comuni, e tre Policy sottili — `TrailApplicationPolicy`, `TrailRegistryCodePolicy`,
`TrailRegistryAnomalyPolicy` — ridefiniscono solo `create()` e `update()`.

### Task 2: Action, Resource e test HTTP

- L'Action Approva ha uriKey `approva` (slug del nome tradotto), non `approve-trail-application`
  come scritto nel piano: il test lo legge da `(new ApproveTrailApplication)->uriKey()`.
- Il test HTTP registra `NovaCoreServiceProvider` ed esegue le migration Nova di `action_events`:
  nel package da solo le rotte `/nova-api` e quella tabella non esistono.
- `tests/Pest.php` crea per tutta la suite un `class_alias` verso `App\Models\User` se manca:
  `src/Policies/Concerns/AuthorizesViaBypassRoles.php:31` (il `before()` di `EcTrackPolicy` e
  simili) tipizza la classe User dello shard, e il detail di un codice consulta `EcTrackPolicy` per
  il `BelongsTo` verso la traccia. Registrato in un punto solo perché la suite gira in ordine casuale.
- Il test HTTP dell'Action prova `canSee` (Nova risponde 403 perché l'Action non è visibile); il
  `canRun` dei ruoli lo prova un test a parte in `TrailRegistryNovaResourcesTest`.

### Task 3: sezione di menu senza voci visibili

La regola «la sezione si vede solo se almeno una voce è visibile» vale anche nel ramo di
`Nova::mainMenu` usato quando lo shard non dichiara un proprio menu: lì «Tools» e «Catasto» si
costruiscono senza passare da `injectMenuSectionItems()`, e «Catasto» sarebbe rimasta vuota ma
visibile. Il piano parlava solo della sezione ricostruita.

## Bug trovati

- **Prima di questo ticket un Validator approvava un'istanza con un POST all'Action**: verificato
  nel test RED del Task 2 (risposta 200 «Istanze approvate.», istanza approvata davvero).

## Decisioni

- **Gli endpoint `/nova-api/{resource}/search`, `/count` e `/filters` restano senza controllo dei
  ruoli.** Nova non vi applica l'autorizzazione (`ResourceSearchRequest::searchIndex()`): un
  Validator che apre l'URL a mano vede id e titolo dei codici, delle istanze, delle anomalie e delle
  righe del registro, senza detail né scritture. Il dev non ritiene necessario un controllo così
  stretto: nessun utente apre quegli URL a mano per vedere ciò che non gli è mostrato. Emerso dalla
  review finale.

- **Il `canSee` conservato vale anche per la sezione «Tools».** carg, ersaf e osmfeatures dichiarano
  «Tools» (Horizon, log, Telescope) con un `canSee` che la riserva all'account Webmapp
  (`admin@webmapp.it` / `team@webmapp.it`). Da `a06a0362` (novembre 2025) il package, ricostruendo la
  sezione per aggiungere Download DB e Restore DB, perdeva quel `canSee`, e la sezione era visibile a
  chiunque entrasse in Nova. Con oc:8700 torna valida: quando quei tre shard aggiornano il package,
  «Tools» la vede solo l'account indicato. Approvato dal dev.

## Follow-up
