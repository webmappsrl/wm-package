> Ticket: oc:8967

# Notes — Conversione UGC POI in EC POI

## Divergenze dal piano, task per task

### Task 1 — Test della conversione

- **Come si lanciano i test.** Il comando del piano (`docker run ... vendor/bin/pest` sul
  submodule, dal `CLAUDE.md` di camminiditalia, oc:8637) non funziona più così com'è:
  - `wm-package/vendor` non c'era, e `composer install` nel package fallisce per gli advisory di
    sicurezza su `laravel/framework 11.*` (lo stesso errore della CI del package);
  - il database `wm_package` non esisteva nel Postgres di camminiditalia.

  Soluzione usata: il `vendor` di `forestas/wm-package` montato in sola lettura nel container
  `wm-phpfpm:8.4`, un `tmpfs` sulla cartella `storage` di testbench (altrimenti i dischi finti non
  sono scrivibili), il database `wm_package` creato da `template_postgis` e le credenziali DB di
  camminiditalia passate come variabili d'ambiente. Quel `vendor` ha **Laravel 11.51**, mentre
  camminiditalia ha Laravel 12.50: per questo il comportamento delle code è stato verificato anche
  sull'app reale (vedi Task 5).
- I test che dichiarano `Tests\TestCase` del consumer (per esempio
  `tests/Feature/Services/EcPoiServiceUpdateDataChainTest.php`) si lanciano da camminiditalia
  (`docker exec laravel-camminiditalia vendor/bin/pest wm-package/tests/...`); è servito
  `php artisan migrate --env=testing` su `camminiditalia_testing`, che aveva 18 migration in
  sospeso (fra cui `taxonomy_terms`).
- Un caso in più rispetto al piano: `afterCommit` sul primo job della catena (Task 4) è verificato
  nello stesso file.

### Task 5 — Verifica manuale in locale

- **Il test chiesto dalla review dell'overview** («con il rollback del savepoint di un UgcPoi la
  catena dell'EcPoi annullato non deve partire») **non è un test automatico**. Scritto e poi tolto:
  `QueueFake` registra il job subito e ignora `afterCommit`, quindi il test falliva per un limite
  della coda finta, non del codice. Verificato invece sull'app reale (camminiditalia, Laravel 12.50,
  coda Redis, dal vero endpoint Nova): con due UgcPoi selezionati, il primo con una foto mancante,
  l'unico job accodato (evento `JobQueued`) è `SyncModelTaxonomyWhereJob` dell'EcPoi del secondo;
  nessun `failed_jobs`.
- Per la verifica in locale è servito ricollegare alla rete `camminiditalia_default` i container
  `postgres`, `laravel`, `mailpit` e `kibana`, che ne erano staccati (`DB_HOST=db` non si
  risolveva), e riavviare `horizon-camminiditalia` per fargli caricare le classi modificate.

## Bug trovati

- `tests/Unit/Services/EcTrackService` → `update data chain dispatches at least one job` fallisce,
  identico anche senza le modifiche di questo ticket: preesistente, non toccato.
- In locale `BuildAppPoisGeojsonJob` fallisce con `relation "taxonomy_terms" does not exist`: il DB
  di sviluppo di camminiditalia ha migration in sospeso dal 28/09/2026. Non legato a questo ticket.

## Decisioni

- Nessun tag Orchestrator aggiunto al ticket in `wm-plan`: il dev ha tenuto quelli presenti.
- `afterCommit()` sul primo job della catena e non `DB::afterCommit()` attorno a `Bus::chain()`
  (come fa `EcTrackService`): è la forma indicata dalla trappola Nova del package (oc:8543) e dalla
  review dell'overview.
- Dalla review del codice (5 finder) applicati solo i cleanup che una verifica ha mostrato
  necessari:
  - test del caso 1 che confronta nome e geometria (prima, con l'EcPoi creato con un nome
    sbagliato, il test passava);
  - messaggio generico «conversion error, see the log» al posto di «error while copying», che
    compariva anche quando a fallire era la creazione dell'EcPoi;
  - `MediaFileMissingException` aggiunta ai Moduli toccati dell'overview.

  Scartati dopo verifica: `removeAllFiles()` al posto di `deleteDirectory()` e la tabella `media`
  letta dal modello, perché `path_generator` e `media_model` sono imposti dalla configurazione del
  package su ogni consumer (`WmPackageServiceProvider.php:668-671`); l'ordine delle foto nel test
  del file mancante, perché una mutazione che disattiva la pulizia fa fallire il test.

## Follow-up

- `ec_poi_id` resta sull'UgcPoi dopo la cancellazione dell'EcPoi, e l'UgcPoi non si riconverte più
  (provato dagli endpoint Nova): fuori scope, da aprire come ticket se serve.
- La nota di `camminiditalia/CLAUDE.md` sui test del package (`DB wm_package già presente`,
  comando `docker run`) non è più vera: va aggiornata con il dev.
- Dopo il deploy il cliente rilancia «Convert To EcPoi» sull'UgcPoi 143.
- Nel `CLAUDE.md` di wm-package la riga dell'indice «Media, upload e avatar» non cita ancora la
  nuova sottosezione «Copiare un media su un altro modello» di `docs/knowledge/media-e-avatar.md`:
  il `CLAUDE.md` del package non lo modifica direttamente chi ha lavorato a questo ticket. Testo
  proposto per «Cosa copre»: aggiungere «copia di un media su un altro modello (`Media::copy()`)».
