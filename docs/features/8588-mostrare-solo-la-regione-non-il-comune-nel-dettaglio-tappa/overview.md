> Ticket: oc:8588

# Mostrare solo la regione (non il comune) nel dettaglio tappa

## Cosa cambia

- **Formato unico di `properties.taxonomy_where` in scrittura, quello "vecchio"**: tutti gli scrittori di wm-package (calcolo SQL di `GeometryComputationService::syncTaxonomyWhere()` / `writeTaxonomyWhereIfEmpty()`, fallback osmfeatures di `SyncModelTaxonomyWhereJob`, vecchio job via API usato dagli UGC) scrivono la forma
  `{ "<id>": { "it": "...", "en": "...", ..., "_admin_level": 4, "_source": "osmfeatures" } }`
  cioè nomi appiattiti per lingua al primo livello (solo chiavi lingua riconosciute, le altre scartate), `_admin_level` con underscore, `source` rinominato `_source`. Il formato SQL introdotto da oc:8487 (`{name: {...}, admin_level, source}`) non viene più scritto. Le chiavi dei where non cambiano (stesse regole di oggi: `osmfeatures_id` → `osm2cai_id` → `id` per il SQL, id `R…` per osmfeatures).
- **Nuova opzione per App** (Nova, `App`, salvata in `properties`, stesso pattern di `properties->analytics_app_enabled`): "Località mostrate" — **select multipla**; **nessuna voce selezionata = nessun filtro** (default, comportamento identico a oggi per tutti gli altri progetti). Le voci selezionabili sono le **categorie** di where realmente presenti nei `taxonomy_where` di EcTrack, EcPoi, UgcPoi e UgcTrack dell'app, più i valori già salvati (anche se oggi nessun record li contiene).
- **Categoria di una voce**, calcolata da una funzione unica usata sia per popolare la select sia per filtrare, sempre come stringa:
  - `"<N>"` se `_admin_level` / `admin_level` è un valore numerico valido (etichette tradotte: Regione 4, Provincia 6, Comune 8, "Livello N" per gli altri);
  - altrimenti `"source:<valore>"` se c'è una sorgente (es. where GeoHub da oc:8486, senza livello amministrativo);
  - altrimenti `null` (formato più vecchio): `null`, stringa vuota e chiave assente valgono tutti "nessun livello". Le voci con categoria `null` passano solo quando non c'è nessun filtro.
- **Filtro solo sull'uscita pubblica**, mai sul dato salvato e mai dentro `GeoJsonService::getModelAsGeojson()` (che resta invariato perché usato anche da job interni: DEM, pendenze, grafico altimetrico, export Excel). Un metodo sul modello base (`GeometryModel`) produce `taxonomy_where` filtrato per le categorie scelte nell'App proprietaria del record (`app_id`), nella forma vecchia anche se il dato salvato è in un'altra forma, e la lista `taxonomyWheres` derivata. Lo usano solo i punti che producono l'uscita pubblica (elenco esatto verificato nel piano):
  - json statico della traccia/POI (`UpdateEcTrackAwsJob` e l'equivalente EcPoi) — letto dal dettaglio di wm-core (`<wm-txn-where>`) e da `wp-geohub` `single_track.php`;
  - endpoint geojson della traccia (`EcTrackController::getGeojson()`) e FeatureCollection UGC (`UgcController::getFeatureCollection()`);
  - `EcTrack::toSearchableArray()` per `taxonomyWheres` in Elasticsearch — card in lista/ricerca (app `search-box`, `poi-box`; `wp-geohub` `grid_track.php`) **e anche** filtro (`ElasticsearchController.php:158`, `TermQuery`) e aggregazioni/faccette (`:184-185`): l'opzione definisce le località che l'app conosce, in uscita e nei filtri.
  - `pois.geojson` dell'App.
  La stringa di ricerca full-text (`EcTrack::getSearchableString()`) resta **non filtrata**: cercando un comune per testo la tappa continua a comparire.
- L'opzione dell'App si legge una volta per App (cache in memoria per la durata della richiesta o del job), non una query per record.
- Se un record non ha nessuna voce delle categorie scelte, in uscita `taxonomy_where` è vuoto (non si mostra nulla, non si ripiega su "tutto").
- **Nuovo comando artisan di riallineamento** (nome indicativo `wm:resync-taxonomy-where`), con `--app=`, `--only-legacy` (solo record senza `_admin_level` o nel formato oc:8487), `--outputs-only`, `--dry-run`:
  1. per ogni EcTrack/EcPoi/UGC dell'app accoda **una catena per record**: sync **conservativo** → rigenerazione del json statico → riindicizzazione Scout del record. La rigenerazione parte sempre dopo il sync di quel record;
  2. il sync conservativo prova il calcolo SQL locale e poi osmfeatures, e **scrive solo se ottiene un risultato non vuoto**; prima di scrivere salva il valore precedente in `properties._taxonomy_where_backup`; se non ottiene nulla lascia il valore attuale e registra il record come "non riallineato" nel riepilogo/log;
  3. a fine batch, una volta per app, rigenera `pois.geojson` (`->finally()` sul batch, stesso schema di oc:8487/oc:8488);
  4. `--outputs-only` salta il sync e rigenera solo le uscite (nessuna chiamata osmfeatures);
  5. `--dry-run` non scrive e non chiama osmfeatures: conta i record per formato (più vecchio senza livello, API con `_admin_level`, oc:8487) e quanti **oggi** non hanno nessuna voce delle categorie scelte.
- **Al salvataggio dell'App in Nova, se l'opzione è cambiata**, si accoda automaticamente la sola rigenerazione delle uscite (equivalente di `--outputs-only`): cambiare il valore in Nova cambia davvero ciò che vede l'utente, senza ricordarsi di lanciare un comando.
- Il job automatico al salvataggio di un EC (`SyncModelTaxonomyWhereJob` nella data chain, oc:8487) cambia solo il formato scritto: il suo comportamento di azzeramento quando non trova nulla resta quello deciso in oc:8487.
- Per camminiditalia l'opzione viene impostata a **Regione** (dato, non codice).

## Perché

Nel collaudo della release 13.1.17 il cliente Cammini d'Italia ha segnalato che il "Comune" mostrato nel dettaglio tappa è spesso impreciso; in call si è deciso di mostrare solo la regione. Allo scrum del 2026-09-23 è stato stabilito che l'intervento è backend, in wm-package, come opzione scelta per App da chi gestisce la piattaforma, senza toccare il frontend e senza cancellare le geometrie (servono ai filtri).

Verificato durante l'analisi:

- Il "Comune" non viene da `LayerAttributesService` di camminiditalia (che già filtra per regione, ma sul Layer): viene dal `taxonomy_where` della singola EcTrack, che osmfeatures popola con comuni (8) e regioni (4) insieme, esposto grezzo nel geojson.
- Il dettaglio tappa di wm-core (`track-properties.component.html:44-47` → `taxonomy-where.component.ts`) legge **`taxonomy_where` grezzo**, non `taxonomyWheres`, riconosce solo la forma `{lingua: nome, _admin_level}` e mostra solo i livelli 4, 6, 8: sul formato scritto da oc:8487 scarterebbe tutte le voci e nasconderebbe l'intera sezione "Dove" dopo il ricalcolo previsto da quel ticket.
- `wp-geohub` (`shortcodes/single_track.php:86-89, 322-369`) legge lo stesso json statico della traccia e la stessa forma vecchia (`_admin_level` 4 e 8, lingue al primo livello): secondo consumer esterno che si romperebbe col formato oc:8487. Per questo si riporta la scrittura al formato vecchio invece di tradurre solo in uscita.
- Nel codice applicativo (`app/`, `resources/`) dei consumer clonati in locale (carg, forestas, geobox, maphub, osm2cai2) nessuno legge il formato oc:8487 (`name`/`admin_level`); verifica fatta sui cloni locali, non sui branch cliente remoti.
- Nel DB di sviluppo, 1040 EcTrack su 1284 hanno `taxonomy_where` nel formato più vecchio senza alcun livello e senza regione (solo comuni/province): senza riallineamento, con il filtro "Regione" resterebbero vuote.
- Il bulk di oc:8487 non può riallinearle: passa `preserveOnNoMatch: true` e non ha fallback osmfeatures, e nel DB camminiditalia di sviluppo `taxonomy_wheres` ha 0 righe. Il job per singolo record invece azzera a `{}` prima di provare osmfeatures (`GeometryComputationService.php:56-58`, `SyncModelTaxonomyWhereJob.php:33-50`): per questo il comando usa una variante conservativa.
- `taxonomy_wheres` non ha `app_id`: una select popolata dalla tabella sarebbe vuota per camminiditalia. La query sui JSON degli EC costa ~70 ms sulle 1284 tracce (misurato), accettabile perché eseguita solo all'apertura del form App.

## Requisiti

- [ ] Tutti gli scrittori EC di `taxonomy_where` in wm-package (SQL bulk e scoped, fallback osmfeatures in `SyncModelTaxonomyWhereJob`) scrivono la forma `{id: {<lingue>..., _admin_level, _source}}`; nessuno scrive più `name`/`admin_level`/`source`; nei nomi solo chiavi lingua riconosciute
- [ ] Il vecchio job via API usato dagli UGC scrive la stessa forma (aggiunta di `_source`, resto invariato)
- [ ] Le chiavi dei where restano quelle di oggi
- [ ] `getOrderedTaxonomyWheres()`/`getValidName()` continuano a leggere tutte e tre le forme esistenti nel DB (più vecchia senza livello, API con `_admin_level`, SQL oc:8487)
- [ ] Funzione unica di calcolo della categoria (stringa `"<N>"`, `"source:<valore>"` o `null`), che tratta come equivalenti intero/stringa per il livello e `null`/vuoto/assente
- [ ] Nuova opzione App in Nova, select multipla, default vuoto (= nessun filtro), valori = categorie presenti nei `taxonomy_where` di EcTrack/EcPoi/UgcPoi/UgcTrack dell'app più i valori già salvati; help del campo che spiega: il dettaglio dell'app mostra solo Regione/Provincia/Comune, le card mostrano l'ultima voce, le uscite vengono rigenerate in coda al salvataggio
- [ ] Etichette dell'opzione e delle categorie tradotte in tutti i file lingua di wm-package (`resources/lang/{it,en,de,es,fr}.json`), testo base in inglese come le altre label Nova del package
- [ ] Metodo sul modello base che restituisce `taxonomy_where` filtrato (forma vecchia) e `taxonomyWheres` derivata, secondo l'opzione dell'App proprietaria, con lettura dell'opzione una volta per App
- [ ] `GeoJsonService::getModelAsGeojson()` invariato; il filtro applicato solo nei punti di uscita pubblica elencati nel piano (json statico EcTrack/EcPoi, endpoint geojson, FeatureCollection UGC, `pois.geojson`, `toSearchableArray()`)
- [ ] `getSearchableString()` resta non filtrata
- [ ] Nessuna voce delle categorie scelte → `taxonomy_where` vuoto e `taxonomyWheres` vuota in uscita
- [ ] Con nessuna categoria selezionata (o opzione assente) l'uscita è identica a oggi a parità di dato salvato
- [ ] Comando artisan di riallineamento con `--app=`, `--only-legacy`, `--outputs-only`, `--dry-run`: catena per record (sync conservativo → json statico → Scout), backup in `properties._taxonomy_where_backup`, scrittura solo se il risultato non è vuoto, riepilogo dei record non riallineati, rigenerazione di `pois.geojson` a fine batch
- [ ] Al salvataggio dell'App, se l'opzione cambia, si accoda la sola rigenerazione delle uscite
- [ ] Test PHPUnit/Pest:
  - forma scritta dal SQL e dal fallback (con scarto delle chiavi non lingua);
  - calcolo della categoria (intero, stringa, null, assente, sorgente);
  - filtro con una e con più categorie, per livello e per sorgente;
  - lettura delle tre forme; nessuna selezione = nessun cambiamento; nessuna voce → vuoto;
  - `getModelAsGeojson()` non filtrato; ricerca full-text non filtrata;
  - filtro `TermQuery` e aggregazione Elasticsearch con l'opzione attiva;
  - regressione su `EcPoiRegionFilter` e `TaxonomyWhereAbleModel::scopeByWhereProperty()` con record nel formato vecchio unificato;
  - opzioni della select calcolate dai dati dell'app;
  - comando: `--dry-run` conta senza scrivere; il sync conservativo non sovrascrive quando osmfeatures risponde vuoto o va in errore; il backup viene scritto;
  - salvataggio App con opzione cambiata → rigenerazione accodata (Bus fake)
- [ ] Nessuna modifica a wm-core, webmapp-app, wp-geohub

## Rischi

- **Si rovescia una scelta di oc:8487 appena entrata in develop** (formato unificato `name/admin_level/source`): test e documentazione di oc:8487 che verificano quel formato vanno aggiornati nello stesso ciclo; se oc:8487 è già stato rilasciato, in produzione esistono record nel formato SQL — li legge comunque il metodo di uscita e li riporta alla forma vecchia il comando.
- **Rollback del codice**: tornare al formato oc:8487 romperebbe di nuovo wm-core e wp-geohub al primo ricalcolo, e tocca test e documentazione di due ticket. I dati restano leggibili da `getOrderedTaxonomyWheres()` in ogni caso.
- **Perdita di dati durante il riallineamento**: mitigata dal sync conservativo (scrive solo se ha un risultato non vuoto), dal backup nel campo `_taxonomy_where_backup` e dal `pg_dump` obbligatorio. **Ordine di rilascio obbligatorio**: `pg_dump` di `ec_tracks`, `ec_pois`, `ugc_pois`, `ugc_tracks` → deploy → riavvio Horizon (i worker non ricaricano le classi Job modificate) → comando `--dry-run` → comando → opzione App a "Regione" (accoda da sola la rigenerazione delle uscite) → controllo del riepilogo dei record non riallineati.
- **Filtro e faccette Elasticsearch ridotti alle categorie scelte**: per le app con l'opzione impostata, una ricerca strutturata per comune via API non trova più nulla e le faccette mostrano solo le categorie scelte. Voluto (coerente con ciò che l'app mostra), limitato alle app che impostano l'opzione.
- **Volume di chiamate osmfeatures** durante il riallineamento (~1300 EcTrack + EcPoi + UGC per camminiditalia): mitigato dall'accodamento con retry e backoff, e da `--only-legacy`.
- **Stato misto finché le code lavorano**: dopo un cambio dell'opzione, finché la rigenerazione accodata non termina, alcune uscite sono filtrate e altre no.
- **Uscite che non si possono richiamare**: tracce già scaricate offline sulle app mobili e cache HTTP/CDN restano coi dati vecchi finché non vengono aggiornate dal client o scadono. Limite accettato.
- **PBF**: esclusi dalla rigenerazione perché non dovrebbero contenere `taxonomy_where`; da verificare nel codice di `GenerateEcTrackPBFBatch` durante il piano, e aggiungere se lo contengono.
- **Un solo filtro per record**: json statico e documento Elasticsearch sono uno per record, quindi il filtro è quello dell'App proprietaria (`app_id`) anche quando il contenuto è mostrato in un'altra App. Per camminiditalia (una sola App) non ha effetto.
- **Il primo job interno che copiasse `properties` da un'uscita pubblica** salverebbe il dato filtrato: mitigato dal lasciare `getModelAsGeojson()` non filtrato, ma resta una convenzione. Allo stesso modo il "formato unico" in scrittura è una convenzione, non un vincolo del campo: un nuovo scrittore futuro può romperla.
- **`wp-geohub` e `_source`**: il ciclo di ripiego di `single_track.php:342/369` salta solo `_admin_level`; se una voce non ha né la lingua richiesta né `it`/`en`, potrebbe mostrare il valore di `_source` come nome. Caso raro (le voci osmfeatures hanno sempre `it`/`en`), accettato.
- **Categorie che il dettaglio non mostra**: `<wm-txn-where>` rende solo 4, 6 e 8; selezionare una sorgente o un altro livello lascia vuota la sezione "Dove" del dettaglio pur valorizzando le card. Spiegato nell'help del campo.
- **Tappe senza le categorie scelte mostrano "niente"** (dettaglio e card): se il riallineamento non va a buon fine su alcuni record, restano vuoti finché non vengono riallineati. Il riepilogo del comando li elenca.
- **Tappe fuori dall'Italia o senza regione**: con "Regione" restano solo le voci di livello 4, che all'estero è cantone o région; San Marino e Vaticano (livello 2) restano senza località. Accettato per camminiditalia.
- **Where GeoHub con `_source` ma senza livello** sono selezionabili solo per sorgente: l'opzione non distingue due tipi di where diversi dalla stessa sorgente. Accettato per questo ciclo.
- **Ordine delle voci con più categorie selezionate**: le card mostrano l'ultimo elemento di `taxonomyWheres` (livello crescente), quindi con Regione + Comune si vedrebbe il comune. Spiegato nell'help del campo.
- **Query della select su JSON** senza indice: ~70 ms oggi, cresce linearmente coi record. Da rivalutare (cache) se i volumi crescono.

## Out of scope

- Qualsiasi modifica al frontend (wm-core/webmapp-app) e a `wp-geohub`, incluso far leggere a `<wm-txn-where>` sia `admin_level` sia `_admin_level` (follow-up possibile se un giorno si vorrà tornare al formato unificato).
- Filtro sulla stringa di ricerca full-text.
- Filtro diverso per App quando lo stesso contenuto è mostrato in più App.
- Correzione dell'imprecisione del dato "comune" alla fonte (osmfeatures).
- Modifica del comportamento di azzeramento del job automatico di oc:8487.
- Migrazione del meccanismo UGC al calcolo SQL di oc:8487 (gli UGC cambiano solo per la chiave `_source` e ricevono il filtro in uscita).
- Filtro per regione del Layer (`LayerAttributesService` di camminiditalia), già a posto.

## Moduli toccati

Tutto nel submodule **wm-package** (camminiditalia riceve solo il bump del submodule e la valorizzazione dell'opzione in Nova, nessun file di codice):

- `src/Services/GeometryComputationService.php` — forma scritta dal SQL
- `src/Jobs/TaxonomyWhere/SyncModelTaxonomyWhereJob.php` — forma scritta dal fallback osmfeatures
- job/servizio UGC via API (`UgcController::enrichUgcWithTaxonomyWhere()` e job correlato) — aggiunta `_source`
- `src/Models/Abstracts/GeometryModel.php` — calcolo della categoria, metodo di uscita filtrato, `getOrderedTaxonomyWheres()`
- punti di uscita pubblica: `UpdateEcTrackAwsJob` e l'equivalente EcPoi, `EcTrackController::getGeojson()`, `UgcController::getFeatureCollection()`, costruzione di `pois.geojson`
- `src/Models/EcTrack.php` — `toSearchableArray()`
- `src/Nova/App.php` — nuova opzione; aggancio al salvataggio dell'App per la rigenerazione
- nuovo comando artisan di riallineamento e job per il sync conservativo
- `resources/lang/{it,en,de,es,fr}.json`
- test in `tests/` di wm-package; aggiornamento test e documentazione di oc:8487 (`docs/features/8487-generalizzare-sync-taxonomy-where-ecpoi/`)

---

## Aggiornamento dopo l'implementazione (2026-09-24)

Questa overview è la spec approvata e non viene riscritta. Durante il test manuale e le review lo scope si è allargato; il dettaglio e i motivi sono in [notes.md](notes.md) (sezioni *Decisioni* e *Divergenze dal piano*). In sintesi, rispetto a *Moduli toccati* e *Out of scope*:

- **camminiditalia non riceve solo il bump del submodule**: `app/Providers/NovaServiceProvider.php` aggiunge la voce "Taxonomy Where" al menu "Taxonomies", visibile solo all'Administrator, con il test `tests/Feature/TaxonomyWhereMenuVisibilityTest.php`.
- **Import e nomi delle where** (erano fuori da questa overview): `src/Nova/Actions/ImportTaxonomyWhere.php` (il nome non è più l'id OSM né dipende da `app.locale`), `src/Jobs/TaxonomyWhere/FetchTaxonomyWhereGeometryJob.php` (nomi nelle 5 lingue della piattaforma da `osm_tags`, solo per where con sorgente osmfeatures o senza sorgente), `src/Http/Clients/OsmfeaturesClient.php` (`getAdminAreaDetail()` restituisce anche le traduzioni `names` e non ripiega più sull'id).
- **Uscite pubbliche**: il json statico della traccia e dei POI collegati passa da `src/Http/Resources/EcTrackResource.php` ed `EcPoiResource.php` (non da `UpdateEcTrackAwsJob`, che usa la resource).
- **Rimaste senza filtro per decisione del dev**: `EditorialContentController::viewEcGeojson()` ed `EcPoiService::getAssociatedEcPois()`.
- **"Correzione dell'imprecisione del dato alla fonte"** resta fuori scope per il dato *comune* in sé, ma la qualità dei nomi importati da osmfeatures è stata corretta in questo ciclo.
