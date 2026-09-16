> Ticket: oc:8564

# Fix - logo layer non si aggiorna nel campo MAP->layers[] dopo upload

## Cosa cambia

Quando un media viene aggiunto o rimosso da **qualsiasi** collection Spatie di un `Layer` (non
solo `'logo'` — anche `'default'`/Immagine, e qualunque altra collection futura), il sistema
rigenera automaticamente la config pubblica dell'app (`config.json`, dispatch di
`UpdateAppConfigJob`), così che sia `logo_image` sia `feature_image` in `MAP->layers[]`
riflettano sempre lo stato reale dei rispettivi media. Oggi, un upload o una rimozione isolati
di un qualsiasi media non fanno scattare nessuna rigenerazione: nessuno dei trigger esistenti
(`wasChanged('properties')` nel package, `calculatedValuesAreUnchanged()` in camminiditalia)
reagisce a un cambio che coinvolge solo la relazione media — bug strutturale identico per
entrambe le collection, verificato empiricamente in fase di reverse-interaction.

Il dispatch avviene con lo stesso delay di 10s già usato altrove nel package
(`LayerObserver::updateAppConf()`), applicato uniformemente a tutte le collection: sia
`'default'` (thumbnail via conversion, che richiede il delay) sia `'logo'` (che userebbe
comunque l'originale, ma il delay non causa danno) lo condividono, per semplicità e per
proteggere automaticamente eventuali collection future con conversion.

## Perché

Il cliente ha segnalato che, caricando il logo del cammino "Santa Barbara", il logo non compare
sul frontend nonostante l'upload vada a buon fine (i job Spatie `PerformConversionsJob` e
`RecalculateLayerAttributesJob` completano correttamente). La causa verificata: nessun
meccanismo esistente rigenera la config quando cambia solo il media `'logo'` di un Layer.

## Requisiti

- [ ] Quando viene aggiunto un media a **qualsiasi** collection di un `Layer`, dispatchare
      `UpdateAppConfigJob` per l'`app_id` del layer, con delay di 10s (stesso pattern di
      `LayerObserver::updateAppConf()` nel package — "permettere ai media di essere
      processati").
- [ ] Quando viene rimosso un media da qualsiasi collection di un `Layer` (senza sostituirlo
      con un nuovo upload), stesso dispatch con delay.
- [ ] **Correzione rispetto alla stesura iniziale**: verificato che `GeometryModel`
      (`wm-package/src/Models/Abstracts/GeometryModel.php:268-279`, ereditata da `Layer` via
      `Polygon`) registra conversion (`registerMediaConversions()`) **per l'intero modello**,
      non per singola collection — quindi anche `'logo'` genera un `PerformConversionsJob`
      reale (sprecato, dato che `logo_image` legge sempre l'originale via
      `getFirstMediaUrl('logo')`), mentre `feature_image` (`'default'`) legge esplicitamente
      la thumbnail via `MediaService::getThumbnailUrl()`. Il delay uniforme copre
      correttamente entrambi i casi.
- [ ] Il fix è generico nel `wm-package`, non specifico di camminiditalia: deve beneficiare
      qualsiasi consumer del package che usa media collection sul Layer, non solo questo
      progetto.
- [ ] Test automatico (Pest, `Bus::fake()`) che verifica il dispatch di `UpdateAppConfigJob`
      con delay su aggiunta/rimozione di media su collection diverse (`'logo'` e `'default'`
      almeno), più il caso negativo "altro modello".
- [ ] Verifica manuale end-to-end su Nova (upload/rimozione reali su Logo e Immagine →
      `config.json` aggiornato su S3) prima del merge, a complemento del test automatico.
- [ ] **Aggiunto durante il test dal vivo**: `UpdateAppConfigJob::uniqueVia()` deve puntare a
      un lock Redis, non al lock su database (default `CACHE_STORE=database`) — altrimenti
      qualunque dispatch del job (dal nostro observer o dai 6 chiamanti preesistenti) rischia
      di far fallire con un 500 il salvataggio del Layer su Nova. Vedi Rischi per la diagnosi
      completa.

## Rischi

- **Bug bloccante scoperto testando dal vivo su Nova, corretto (non introdotto da questo
  ticket)**: dopo il riallineamento del branch a `develop` (vedi `notes.md`), è emerso che
  `UpdateAppConfigJob` implementa `ShouldBeUnique` (da oc:8488) con lock su
  `CACHE_STORE=database`. `DatabaseLock::acquire()` (Laravel) prova un `INSERT` e, se fallisce
  per chiave duplicata, ripiega su un `UPDATE` nello stesso `try/catch` — su PostgreSQL una
  query fallita dentro una transazione blocca (`25P02`) tutte le query successive nella stessa
  transazione, quindi anche l'`UPDATE` di fallback fallisce. Nova avvolge ogni salvataggio di
  risorsa in una transazione, quindi **qualunque save di un Layer con la riga di lock già
  presente** andava in 500 — non solo dal nostro observer, anche dal percorso preesistente
  `LayerObserver::updateAppConf()`. **Fix**: `UpdateAppConfigJob::uniqueVia()` ora punta
  esplicitamente allo store `'redis'` (già configurato, già usato per le code) invece del
  default — Redis non ha questo problema (operazione atomica, non partecipa a transazioni
  SQL). Un solo metodo, un solo file, risolve per tutti e 7 i chiamanti del job. Vedi Task 6 di
  `plan.md` e `notes.md` per la diagnosi completa.
- **Doppio dispatch ridondante preesistente**: `Layer::observe()` è registrato sia dal package
  (`wm-package/src/Models/Layer.php:34`) sia dal consumer locale
  (`app/Providers/AppServiceProvider.php:74`, che al suo interno chiama anche
  `parent::saved()`), quindi la logica del package su `saved()` gira già due volte per ogni
  save. Il nuovo fix si aggancia a un evento Media (non a `Layer::saved()`), quindi non
  aggrava questa ridondanza preesistente, ma è un'area del codice già duplicata da tenere a
  mente.
- **Nessun evento Spatie dedicato alla rimozione**: la libreria espone
  `MediaHasBeenAddedEvent` ma non un evento equivalente per la rimozione — la rimozione va
  intercettata via evento Eloquent `deleted` sul modello `Media`. **Decisione**: non estendere
  `Wm\WmPackage\Observers\MediaObserver` (già globale su ogni media del sistema — foto
  EcTrack/EcPoi, avatar, icon/splash App, story_frame — con un pattern try/catch che inghiotte
  le eccezioni, pensato per "non far mai fallire un upload media", non per un dispatch
  affidabile). Il fix vive in un **observer/listener dedicato**, filtrato esplicitamente su
  `model_type` risolvibile a `Layer` (**qualsiasi** collection, non più solo `'logo'` —
  generalizzato su richiesta esplicita del dev dopo la scoperta che `'default'`/Immagine ha lo
  stesso bug e necessita anche del delay per le conversion), isolato e testabile senza toccare
  la logica generica esistente.
- **`singleFile()` sostituisce il vecchio media alla creazione del nuovo**: un nuovo upload di
  logo fa eliminare il media precedente da Spatie prima/dopo la creazione del nuovo, quindi può
  generare un doppio dispatch di `UpdateAppConfigJob` per la stessa operazione (add + remove
  quasi in contemporanea). **Decisione superata**: la prima stesura accettava questo rischio
  senza deduplica. Durante l'implementazione, il branch è stato riallineato a `develop` (rimasto
  indietro per una dimenticanza del dev) e si è scoperto che `develop` include già oc:8488:
  `UpdateAppConfigJob` implementa ora `ShouldBeUnique` con `uniqueFor() = 600` (secondi),
  introdotto per lo stesso identico problema ma su altri chiamanti (`TileObserver`,
  `LayerObserver`, `FeatureCollection*`). Il nostro `LayerMediaObserver` eredita questa
  deduplica automaticamente — nessun codice aggiuntivo necessario da parte nostra, la richiesta
  esplicita del dev ("rendere unico per qualche secondo") è già soddisfatta a monte, con una
  finestra più ampia (10 minuti) di quella minima richiesta.

## Out of scope

- Nessun backfill per i layer già in questo stato (incluso "Santa Barbara") — decisione
  esplicita del dev: non ci sono altri casi noti oltre a quello segnalato, si risolve al primo
  re-save del layer o del logo dopo il deploy del fix.
- Creazione di un Layer con logo/immagine caricati direttamente in fase di creazione (mai
  osservato finora dal dev — i layer sono tutti preesistenti) — da verificare in un ciclo
  futuro se il caso si presenta davvero.
- **Aggiornamento (deviazione dalla stesura iniziale)**: il gap sulla collection `'default'`
  (Immagine, `feature_image`) non è più out of scope — generalizzato in scope su richiesta
  esplicita del dev, dato che ha lo stesso bug strutturale e in più richiede il delay per le
  conversion (thumbnail). Vedi Cosa cambia/Requisiti.

## Moduli toccati

- `wm-package/src/Observers/LayerLogoMediaObserver.php` → **rinominato** in
  `LayerMediaObserver.php` (non più logo-specifico) — dispatcha `UpdateAppConfigJob` (con
  delay 10s) su creazione/rimozione di un media di **qualsiasi** collection su un `Layer`.
- `wm-package/src/Models/Media.php` — aggiornamento del riferimento alla classe rinominata
  nella registrazione dell'observer.
- `wm-package/tests/Feature/LayerLogoMediaObserverTest.php` → **rinominato** in
  `LayerMediaObserverTest.php` — test aggiornati per il delay (`Bus::assertDispatched` non
  basta più da solo, serve verificare anche il delay) e per coprire sia `'logo'` sia
  `'default'`; il caso negativo "altra collection" viene rimosso (non ha più senso: ora
  qualsiasi collection deve dispatchare); resta il caso negativo "altro modello".
- `wm-package/src/Jobs/UpdateAppConfigJob.php` — aggiunto `uniqueVia()` (lock su Redis invece
  del default database) per correggere il bug bloccante scoperto testando dal vivo su Nova
  (vedi Rischi).
- `camminiditalia` (repo principale) — nessun file applicativo, solo bump del puntatore
  submodule `wm-package` a fine ciclo.
