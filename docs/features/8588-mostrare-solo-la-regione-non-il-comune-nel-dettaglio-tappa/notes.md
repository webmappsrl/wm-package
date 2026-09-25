> Ticket: oc:8588

# Notes — Mostrare solo la regione (non il comune) nel dettaglio tappa

## Divergenze dal piano, task per task

### Task 0: ambiente di test

Il piano prevedeva di far girare i test del package in `php-maphub`, come in oc:8487. Avviato il container, è emerso che `php-maphub` monta un'altra copia del package (`maphub/wm-package`, su `develop`) e non vede il branch di questo lavoro, che sta in `camminiditalia/wm-package`. Inoltre l'avvio di maphub ha fermato tutti i container di camminiditalia (conflitto di porte).

Scelta del dev: tornare a camminiditalia e lanciare i test del package dal suo root, con `docker exec laravel-camminiditalia php artisan test wm-package/<file>`. Funziona perché i test del package estendono `Tests\TestCase`, che in camminiditalia esiste, e il runner usa il DB separato `camminiditalia_testing`. Verificato il 2026-09-23: la suite di oc:8487 (`SyncModelTaxonomyWhereJobTest`, `GeometryComputationServiceTaxonomyWhereTest`) dà 16 test passati. Tutti i comandi `Run:` del piano sono stati aggiornati di conseguenza.

### Task 2: scrittori nella forma vecchia

- I due test nuovi di `GeometryComputationServiceTaxonomyWhereTest` si aspettano `{en: 'Corsica', _admin_level: 4, _source: 'geohub'}` e non `{it, en, …}` come scritto nel piano: `createCorsicaTaxonomyWhere()` salva il nome tramite `HasTranslations` di Spatie, che con `APP_LOCALE=en` (in `.env` e `.env.testing` di camminiditalia) persiste solo la traduzione `en`. Verificato che la colonna vale `{"en":"Corsica"}`; il ramo SQL che lo legge è lo stesso di prima, non è una regressione.
- `UgcTaxonomyWhereSourceTest` crea esplicitamente User e App con le factory, invece di affidarsi a `UgcPoi::factory()` (che usa `User::first()`/`App::first()`, nulli in una transazione vuota): stesso schema di `UgcControllerTaxonomyWhereAsyncFallbackTest.php`. Tolto `use Mockery;`, inutile in un file Pest.

### Task 3: filtro nelle uscite pubbliche

- Nel test "ricerca full-text non filtrata" l'App di test riceve `track_searchables` con `taxonomyWheres`: la factory di default (`AppFactory.php:41`, `['name','description','excerpt']`) non include le località nella stringa di ricerca, quindi il test sarebbe fallito indipendentemente dal codice. Solo il test cambia, la factory condivisa no.

### Task 5: opzione App in Nova e rigenerazione

- `RegenerateTaxonomyWhereOutputsJob` ha `uniqueVia()` su `Cache::store('redis')` e `$uniqueFor`, non previsti dal piano: è `ShouldBeUnique` e parte dal salvataggio Nova dell'App, dentro una transazione, caso della trappola oc:8564 (`.claude/rules/job-e-import.md`); stesso schema di `BuildAppPoisGeojsonJob`.
- Nella guardia di `AppObserver` è stato tolto `$app->wasRecentlyCreated ||`: su una stessa istanza PHP `wasRecentlyCreated` resta `true` anche dopo un secondo `save()`, quindi con quella clausola la rigenerazione non partiva mai nel caso "creo l'App e poi cambio l'opzione" (il test del piano falliva). Resta `! wasChanged('properties')`: su un'App appena creata `wasChanged()` vale `false`, quindi alla creazione non parte nessuna rigenerazione, come voluto.
- `use Laravel\Nova\Fields\MultiSelect as NovaMultiSelect;`: il nome semplice collide (PHP non distingue le maiuscole negli import) con `Outl1ne\MultiselectField\Multiselect`, già importato in `src/Nova/App.php`.
- Il salvataggio del `MultiSelect` su `properties->taxonomy_where_display` (array o stringa JSON) non è verificabile senza la UI di Nova: `selectedCategoriesForApp()` accetta entrambi, con un test dedicato. La verifica manuale in Nova resta al dev.

### Task 6: riallineamento conservativo e comando

- Nell'`UPDATE` di `TaxonomyWhereResyncService::resyncRecord()` l'operatore jsonb `?` ("la chiave esiste") è scritto `??`: con i binding posizionali di PDO pgsql un `?` nudo viene preso per un segnaposto e la query va in errore di sintassi; PDO trasforma `??` nel `?` letterale.
- Verificato che `properties` è `jsonb` in tutte e quattro le tabelle (`ec_tracks`, `ec_pois`, `ugc_pois`, `ugc_tracks`): nessun cast da aggiungere.
- `--dry-run` sul DB di sviluppo di camminiditalia (App 1, sola lettura): `ec_tracks` con 1040 record nel formato più vecchio, 240 nella forma vecchia, 4 vuoti — gli stessi numeri misurati in analisi.

### Task 7: regressioni e documentazione

- La suite completa del package non si può lanciare dal runner di camminiditalia (vedi Bug trovati): al suo posto girano insieme tutti i file di test di oc:8588 e di oc:8487 (55/55 verdi) e la suite del consumer camminiditalia (351/351 verdi).
- Nel test di regressione `NovaRequest::create('/')` al posto di `app(NovaRequest::class)`.
- **PHPStan non utilizzabile in `laravel-camminiditalia`**: `wm-package/vendor` dentro quel container è un'installazione separata e non aggiornata (phpstan 2.1.7 contro 2.1.38 del consumer) e produce 1009 errori dovuti all'ambiente su quasi tutti i file (proprietà Eloquent "undefined", classi del consumer non risolte). Sui 16 file toccati da oc:8588 gli errori sono dello stesso tipo, nessuno legato alla logica nuova. Va rilanciato in un ambiente allineato prima del merge (vedi Follow-up).

### Review finale del branch

La review dell'intero branch ha trovato un problema critico e uno importante, corretti nello stesso ciclo:

- **Critico — coda senza worker.** Il batch del comando andava sulla coda `geometric-computations`, che in camminiditalia nessun supervisor Horizon legge (verificato: `default`, `aws`, `pbf`, `dem`, `layers`, `geohub-import`). Il comando avrebbe detto "Record accodati: N" senza che nulla girasse. Ora la coda è l'opzione `--queue` (default `default`), usata anche dal job di rigenerazione accodato dal `finally()` e da `--outputs-only`.
- **Importante — rigenerazione prima del commit.** `RegenerateTaxonomyWhereOutputsJob` parte dal salvataggio Nova dell'App, dentro una transazione; con `queue.connections.redis.after_commit=false` e `scout.queue=false` poteva rileggere l'opzione vecchia e reindicizzare Elasticsearch con quella. Ora chiama `$this->afterCommit()` nel costruttore (una proprietà tipizzata `public bool $afterCommit` va in conflitto con quella già dichiarata dal trait `Queueable`; stesso schema di `UpdateLayerGeometryJob`).
- Inoltre: `ShouldBeUniqueUntilProcessing` al posto di `ShouldBeUnique` (una richiesta di rigenerazione arrivata mentre un'altra è in corso non viene più scartata), whitelist del nome tabella in `resyncRecord()`, correzioni a `docs/resources/TaxonomyWhere.md`.
- Rilievi minori lasciati per un ciclo successivo: vedi Follow-up.

## Bug trovati

- **Nomi GeoHub sovrascritti dal job di dettaglio** (trovato dalla review della modifica sulle traduzioni): `RetryTaxonomyWhereGeometryFetch` accoda `FetchTaxonomyWhereGeometryJob` anche per where con `source` diversa da `osmfeatures` ma con un `osmfeatures_id` rimasto da un import GeoHub (`array_merge` in `executeGeohubImport()`). Con la nuova regola delle 5 lingue il job avrebbe riscritto il nome curato su GeoHub. Corretto: il nome si sincronizza solo per where con `source = osmfeatures` o senza `source` (righe precedenti a oc:8469); la geometria si aggiorna per tutte. Coperto da test.

- **Nome vuoto salvato come `"[]"`** (trovato dalla review della correzione dei nomi): con `'name' => []` Spatie scrive nella colonna `text` il letterale `"[]"`, non `"{}"`, e l'aggregato SQL di `GeometryComputationService` lo trattava come un nome vero (`{"it":"[]","en":"[]"}`). Corretto allargando il controllo a `NULL`, `''`, `'[]'`, `'{}'`, con un test di regressione dall'import fino all'uscita.

- **`Bus::fake()` "rotto" lanciando i test del package dal root di camminiditalia** (2026-09-23): non è un problema di `Bus`. I file Pest del package che non dichiarano `uses(TestCase::class)` contano su `wm-package/tests/Pest.php` (`uses(Wm\WmPackage\Tests\TestCase::class)->in(__DIR__)`), che viene caricato solo quando Pest parte da `wm-package/`. Dal root di camminiditalia quel file non si carica, il test gira senza applicazione Laravel e **qualunque facade** ha radice `null` (`Bus::fake()` è solo il primo a esplodere, con `TypeError: BusFake::__construct(): Argument #1 ($dispatcher) … null given`). Verificato con due test temporanei (poi cancellati): con `uses(Tests\TestCase::class)` `Bus::fake()` funziona; senza, anche `Cache::get()` fallisce. Riguarda 53 dei 140 file Pest del package (13 dei quali usano `Bus::fake()`), fra cui `EcTrackToSearchableArrayFromToTest.php` e `UgcControllerTaxonomyWhereAsyncFallbackTest.php`. **Non blocca questo lavoro**: tutti i test nuovi o modificati del piano dichiarano `uses(TestCase::class, …)` esplicitamente. Non corretto: fuori scope (sono file di altri ticket).

## Decisioni

- Nessun tag Orchestrator aggiunto al ticket (né di ambiente né di contenuto): scelta del dev, bastano quelli già presenti.
- Stima: il dev ha fissato il totale a 5h (misurato 2,1h + stimato 2,9h), contro la proposta di `wm-estimate` di 7,15h dopo un giro di revisione.
- Task 3: due uscite pubbliche restano **senza filtro** per decisione del dev (2026-09-23): `EditorialContentController::viewEcGeojson()` (geojson di POI/tracce/media) e `EcPoiService::getAssociatedEcPois()` (rotta `/{ecTrack}/associated_ec_pois`). Emerse dalla verifica dello Step 4 del Task 3; le altre chiamate a `getGeojson()` trovate sono usi interni.
- **Modifiche chieste dopo il test manuale (2026-09-24), a piano già approvato:**
  - il campo "Località mostrate" passa dal `MultiSelect` nativo di Nova (una `<select multiple>` del browser, in cui il dev non riusciva a scegliere una sola voce) al `Multiselect` di Outl1ne, lo stesso degli altri campi multipli della Resource App;
  - con il `Multiselect` di Outl1ne serve `->saveAsJSON()`: senza, il campo decide se salvare come JSON guardando i cast del modello per la chiave `properties->taxonomy_where_display`, che non esiste (il cast è su `properties`), e quindi scrive una **stringa** JSON dentro `properties` invece di un array. Verificato leggendo `vendor/outl1ne/nova-multiselect-field/src/Multiselect.php` e con una simulazione in tinker senza salvare;
  - il campo "Località mostrate" è stato spostato subito sotto "Show Ele To (General options)" e prima dei tab FEwebapp / FE: mobile, perché riguarda entrambi (richiesta del dev, 2026-09-25);
  - la voce "Taxonomy Where" entra nel menu "Taxonomies" di camminiditalia (`app/Providers/NovaServiceProvider.php`), visibile **solo all'Administrator** (scelta del dev). Era stata tolta di proposito perché in camminiditalia le where non si usavano (oc:8311); con oc:8588 diventano un dato mostrato al cliente. La policy resta quella del package (lettura per tutti, modifica solo Administrator): la restrizione riguarda la sola voce di menu. Per questa modifica è stato creato lo stesso branch anche nel repo principale.
- **Nomi delle where importate da osmfeatures, corretti alla radice** (richiesta del dev, 2026-09-24). Nel primo import di prova, su 7910 comuni 7099 avevano il nome inglese uguale all'id OSM (`{"en":"R41895","it":"Filacciano"}`) e le 20 regioni avevano il nome italiano solo sotto `en`. Causa: `ImportTaxonomyWhere` salvava `$item['name'] ?? $item['id']` in un campo tradotto con `app.locale = en`, e `FetchTaxonomyWhereGeometryJob::syncNameFromDetail()` scriveva il nome vero solo in `it`. Correzioni: l'import scrive il nome sempre sotto `it` e, se manca, lascia il nome vuoto invece dell'id; il job sostituisce ogni traduzione uguale all'id. Nessun comando di correzione dei dati: il dev ripristina il DB dal backup e rifà import e riallineamento. Una prima versione con un comando di correzione in SQL è stata scartata a favore di questa.
- **Traduzioni dei nomi da `osm_tags`** (richiesta del dev, 2026-09-24, dopo il primo reimport). Con i nomi solo sotto `it` e `app.locale = en`, Nova e ogni lettura di `$where->name` li mostravano vuoti. Il dettaglio di osmfeatures ha in `properties.osm_tags` le traduzioni `name:<lang>` (per le regioni 20+ lingue, per quasi tutti i comuni solo `name`). Regola scelta dal dev: per le 5 lingue della piattaforma (it, en, de, fr, es) si usa `name:<lang>` se c'è, `it` ripiega sul nome base, e le lingue ancora vuote ricevono il nome `it` (in sua assenza, il primo nome trovato nell'ordine delle 5 lingue). Le altre lingue di OSM non si salvano. Scartata l'alternativa di un ripiego sulla lingua disponibile nel modello `TaxonomyWhere`: con tutte e 5 le lingue valorizzate non serve.
- **Riallineamento completo nel test** (2026-09-24): dopo il reimport delle where si è lanciato `wm:resync-taxonomy-where --app=1` senza `--only-legacy`, perché il ricalcolo automatico a fine import aveva lasciato tutte le tracce con la sola regione (vedi Follow-up). Esito: 1283 tracce su 1285 con regione e comuni; le 2 restanti non hanno corrispondenze.
- **Review formale (`wm-review-ticket`, 2026-09-24): approvato con riserve.** Corretti nello stesso ciclo, su indicazione del dev: il job UGC che poteva scrivere `taxonomy_where: []` cancellando il valore precedente; le voci senza nome prodotte dall'aggregato SQL; l'avviso (con conferma, o `--force`) del comando quando ci sono where ancora senza geometria; la normalizzazione del valore dell'opzione nel campo Nova; i commenti che citavano file di lavoro non committati; e la pulizia segnalata (costante `osmfeatures` unica, helper per livello e sorgente, whitelist del nome tabella anche in `availableCategories()`, `applyTaxonomyWhereDisplayToFeature()` al posto del controllo copiato in tre punti, helper di test condivisi, `getValidName()` marcato `@deprecated`, una sola passata in `classifyFormat()`). Lasciati fuori di proposito, perché cambiano il comportamento: lingue lette da `config/wm-app-languages.php`, visibilità di Taxonomy Where a livello di policy, filtro dei `related_pois` con l'App della traccia.
- **Decisioni ancora aperte dopo la review** (comportamento invariato finché il dev non decide):
  - A. filtrare anche `api/ec/poi/{id}` e `api/ec/track/{id}/associated_ec_pois` (oggi senza filtro per decisione del 2026-09-23; l'help del campo Nova cita anche il "dettaglio POI");
  - B. aggiornare `updated_at` delle tracce durante la rigenerazione, perché le app che riscaricano le tappe offline confrontando `updated_at` (`EcTrackController.php:166`) vedano il cambiamento — non verificato nel codice di webmapp-app;
  - C. "nessuna categoria selezionata" riporta comunque l'uscita alla forma vecchia (via chiavi estranee e voci senza nome): identica a oggi per i dati già nella forma vecchia, diversa per quelli nel formato di oc:8487;
  - D. il job di dettaglio riscrive sempre le 5 lingue: un nome corretto a mano in Nova viene perso al successivo import o "Retry".

## Follow-up

- **Ricalcolo in blocco a fine import delle where partito prima delle geometrie** (trovato nel test del 2026-09-24): `ImportTaxonomyWhere` lancia il ricalcolo di `taxonomy_where` su tutti gli EC (oc:8487) subito dopo l'import, mentre le geometrie dei comuni arrivano dopo, dai `FetchTaxonomyWhereGeometryJob` in coda. Risultato osservato: tutte le 1285 tracce di camminiditalia con la sola regione e nessun comune, senza backup. È la trappola già nota «sync sincrona subito dopo il dispatch asincrono dei job che ne producono l'input» (oc:8486). Correzione proposta: far partire il ricalcolo dal `->finally()` di un batch dei job di dettaglio. Mitigazione in questo ticket: il comando di riallineamento avvisa se ci sono where senza geometria.
- Il ramo GeoHub dell'import delle where (`executeGeohubImport()`, `HasTaxonomyWhereImportHelpers`) non è stato verificato per gli stessi problemi corretti nel ramo osmfeatures (nome sotto la lingua di `app.locale`, nome vuoto salvato come `"[]"`).
- `_taxonomy_where_backup` (la vecchia lista dei comuni) compare nelle uscite lasciate senza filtro (`EditorialContentController::viewEcGeojson()`, `EcPoiService::getAssociatedEcPois()`, `App::getGeojson()`) e negli export: non è un dato sensibile, ma è carico in più, e non esiste un comando per toglierlo.
- `RegenerateTaxonomyWhereOutputsJob` indicizza tutte le tracce dell'app in un solo job (~2 s ogni 200 tracce su camminiditalia): su consumer molto grandi può superare il `timeout` del supervisor; valutare un job per blocco.
- Nessun riepilogo dei record non riallineati oltre al `Log::warning` per record e al `--dry-run` successivo.
- Un `taxonomy_where` filtrato e vuoto esce come `[]` e non `{}` in `EcTrackResource`: wm-core e wp-geohub lo gestiscono, ma la forma non è uniforme.
- Mancano test HTTP su `EcTrackResource` e test su filtro e aggregazione Elasticsearch con l'opzione attiva; mancano test dedicati su `afterCommit`, `ShouldBeUniqueUntilProcessing` e whitelist del nome tabella.
- Il dispatch di `RegenerateTaxonomyWhereOutputsJob` in `AppObserver::saved()` non è protetto da try/catch: con Redis non raggiungibile, il salvataggio dell'App fallisce (la sync well-known accanto invece è protetta).
- Rilanciare PHPStan di wm-package in un ambiente con vendor allineato (per esempio `php-forestas`, come da `wm-package/CLAUDE.md`) prima del merge.
- I 53 file Pest del package senza `uses(TestCase::class)` non si possono eseguire dal root di un consumer (vedi Bug trovati): valutare se aggiungere l'`uses()` esplicito o documentare che vanno lanciati solo da un container in cui il package è la root di Pest.
