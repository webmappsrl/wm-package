> Ticket: oc:8488

# Config app: allineamento a GeoHub senza interventi manuali dopo l'import

## Cosa cambia

Dopo l'import one-off di un'app da GeoHub, il `config.json` prodotto da Maphub coincide con quello di GeoHub su tutte le sezioni che devono coincidere, **senza nessun passaggio manuale**.

Questo documento è organizzato per **causa**, non per sintomo. Il ticket elenca sei sintomi osservati; l'analisi mostra **tre cause**, non sei: 18 delle 20 divergenze osservate condividono la stessa causa radice (1), le restanti 2 condividono un'altra causa radice (2), e una quarta causa (4) non produce ancora nessuna divergenza osservata ma distruggerebbe il fix delle altre tre al primo re-import. Si lavora sulle cause: per ognuna sono elencate le divergenze che produce, quelle osservate e quelle latenti.

---

## Parte 1 — Il metodo

### Come sono state misurate le divergenze

Confronto tra `https://geohub.webmapp.it/api/app/webmapp/28/config.json` (Itinera Romanica) e il config locale di Maphub app 3, **ricalcolato** con `base-config.json`.

Il ricalcolo è essenziale: l'endpoint `config.json` di Maphub serve un file già scritto su storage, che al momento della misura era di una run di import precedente. Misurare su quello ha già prodotto una diagnosi errata dentro questo stesso ticket (vedi `notes.md`).

### Un avvertimento metodologico che vincola la verifica

I default dello stub `create_apps_table` coincidono **byte-per-byte** con i valori reali di GeoHub app 28:

```
stub  primary_color '#de1b0d'   default_feature_color '#de1b0d'
      font_family_header 'Roboto Slab'   font_family_content 'Roboto'
      tiles '["{\"webmapp\":\"https://api.webmapp.it/tiles/{z}/{x}/{y}.png\"}"]'
```

Conseguenza: leggere `apps.primary_color = '#de1b0d'` su app 3 **non prova** che l'import abbia scritto quella colonna. Nessuna affermazione sull'import può poggiare su app 28, e la verifica di accettazione è costruita per non dipendere da dati reali. Vedi Parte 5.

### Osservata contro latente

Una divergenza è **osservata** se compare nel diff su app 28. È **latente** se ha la stessa causa ma i due lati coincidono su questa app — per caso, non per design. Le latenti non sono meno reali: sono la stessa causa che aspetta un'app diversa. Le tratta lo stesso fix.

---

## Parte 2 — Le divergenze osservate

20 divergenze. 18 sono differenze di valore o chiavi assenti nel config; 2 riguardano quando e se il config viene prodotto.

```
                                 MAPHUB       GEOHUB
THEME.primary                    (assente)  ← "#de1b0d"
THEME.defaultFeatureColor        (assente)  ← "#de1b0d"
THEME.fontFamilyHeader           (assente)  ← "Roboto Slab"
THEME.fontFamilyContent          (assente)  ← "Roboto"
MAP.show_track_direction_arrow   false      ← true
MAP.tiles                        []         ← [{"webmapp": "https://api.webmapp.it/..."}]
MAP.controls                     ["data"]   ← ["data", "tiles"]
OPTIONS.startUrl                 null       ← "/main/explore"
OPTIONS.showEditLink             null       ← false
OPTIONS.skipRouteIndexDownload   null       ← true
OPTIONS.show_favorites           null       ← true
OPTIONS.show_scale               null       ← true
OPTIONS.showGpxDownload          null       ← false
OPTIONS.showKmlDownload          null       ← false
OPTIONS.showEmbeddedHtml         (assente)  ← false
OPTIONS.showGetDirections        (assente)  ← false
OPTIONS.showMediaName            (assente)  ← false
WEBAPP.draw_poi_show             (assente)  ← false
──────────────────────────────────────────────────────
HOME[].layer                     id GeoHub  ← id locali      (intermittente)
tutto il file                    run prec.  ← run corrente   (il file servito è stale)
```

Il delta THEME è misurato prima che oc:8367 entrasse in `develop`: la forma di allora era `{"primary_color": null, …}` in snake_case. Dopo oc:8367 le stesse chiavi risultano assenti. La causa non cambia, cambia come si manifesta.

---

## Parte 3 — Le cause

### CAUSA 1 — L'import copia colonne; il config ha smesso di leggere colonne

**18 divergenze osservate su 20, e 25 latenti.**

`ImportAppJob::transformData()` ha un contratto unico e implicito:

```php
$diff = array_diff(array_keys($data), Schema::getColumnListing('apps'));
$transformedData = array_diff_key($data, array_flip($diff));
```

*"Copia le colonne GeoHub che esistono anche in locale, con lo stesso nome. Scarta il resto, in silenzio."*

Il contratto era corretto quando Maphub era una copia schema-per-schema di GeoHub. Non lo è più: il livello di configurazione di Maphub si è progressivamente allontanato dalle colonne — dentro `properties`, dentro una tabella pivot, o da nessuna parte — e **l'import non è mai stato aggiornato di conseguenza**. Non è un bug puntuale in 47 punti: è un contratto scaduto.

Il perimetro esatto, dal diff schema autoritativo `Schema::connection('geohub')->getColumnListing('apps')` (155 colonne) contro il locale (118): **42 colonne** esistono solo su GeoHub, più **4** colonne theme e **1** colonna `tiles` che esistono in entrambi ma il cui punto di lettura si è spostato. **47 campi in tutto.**

Il contratto scaduto si rompe in cinque modi distinti, che vanno distinti perché richiedono interventi diversi.

---

#### 1a — Doppia sorgente: la colonna esiste, ma nessuno la legge più

L'import scrive la colonna. Il config legge `properties`. La colonna è viva nello schema e morta nel codice.

```php
// AppConfigService::config_section_theme()  — oc:8367, già in develop
$theme = $this->app->properties['theme'] ?? [];   // ← legge QUI
// e la colonna apps.primary_color resta al valore scritto dall'import, mai letta
```

oc:8367 (già in `develop` dal merge RDO #269) ha dichiarato esplicitamente queste 4 colonne *"colonne DB morte, mai scritte da Nova, orfane ma inerti"*. L'import scrive esattamente quelle.

| Divergenza osservata | Colonna GeoHub | Destinazione reale |
|---|---|---|
| `THEME.primary` | `primary_color` | `properties.theme.primary_color` |
| `THEME.defaultFeatureColor` | `default_feature_color` | `properties.theme.default_feature_color` |
| `THEME.fontFamilyHeader` | `font_family_header` | `properties.theme.font_family_header` |
| `THEME.fontFamilyContent` | `font_family_content` | `properties.theme.font_family_content` |

**4 osservate, 0 latenti.** Nessuna latente perché tutte e 4 divergono già.

Aggravante che spiega la forma osservata: `properties.theme` su app 3 contiene le 4 chiavi a `null`. Non è l'import: è Nova. I campi `theme_tab()` scrivono `properties->theme->*`, e il primo salvataggio dell'app materializza i default come valori reali. Per questo il fix deve poter sovrascrivere un `null` esplicito, non solo una chiave assente.

---

#### 1b — Il campo viene scartato: la destinazione è `properties`, e la colonna locale non esiste

Il config legge `properties` e ha già un campo Nova. La coppia scrittura/lettura in Maphub è coerente e funzionante. Manca solo che l'import ci scriva — e non lo fa, perché non esiste una colonna omonima da cui partire.

```php
// AppConfigService:430 — legge properties, con un default
$data['MAP']['show_track_direction_arrow'] = (bool) ($properties['show_track_direction_arrow'] ?? false);

// Nova/App.php:1291 — scrive properties
Boolean::make(__('Show Track Direction Arrow'), 'properties->show_track_direction_arrow')->default(false)

// Schema locale: la colonna show_track_direction_arrow NON esiste
// → transformData la scarta → il config emette il proprio default
```

**6 campi** (il "gruppo A"). Su app 28 solo uno diverge; gli altri cinque coincidono **per caso**.

| Campo | Divergenza | Stato su app 28 |
|---|---|---|
| `show_track_direction_arrow` | `MAP.show_track_direction_arrow` | **osservata**: locale `false`, GeoHub `true` |
| `show_travel_mode` | `OPTIONS.showTravelMode` | latente: entrambi `false` |
| `show_features_in_viewport` | `OPTIONS.showFeaturesInViewport` | latente: entrambi `false` |
| `min_zoom_features_in_viewport` | `OPTIONS.minZoomFeaturesInViewport` | latente: default hardcoded `10` = GeoHub `10` |
| `max_zoom_features_in_viewport` | `OPTIONS.maxZoomFeaturesInViewport` | latente: default hardcoded `12` = GeoHub `12` |
| `show_download_tiles_button` | `OPTIONS.showDownloadTiles{,Button}` | latente: entrambi `false`. Unico rename: chiave locale `show_download_tiles` |

**1 osservata, 5 latenti.** Le cinque latenti sono il caso più insidioso: coincidono su questa app e diventeranno divergenze silenziose sulla prossima, senza che nulla segnali il cambio.

---

#### 1c — Non esiste nessuna destinazione, e il config legge un attributo inesistente

Il config legge `$this->app->X` dove `X` non è né una colonna, né un accessor, né una chiave `properties`. Eloquent restituisce `null`. Questa manifestazione produrrebbe `null` **anche con un import perfetto**: non c'è nessun posto in cui scrivere.

```php
// AppConfigService::config_section_options()
$data['OPTIONS']['startUrl'] = $this->app->start_url;   // ← attributo inesistente → null
```

Ed è la manifestazione che il frontend punisce più duramente. `wm-core/src/store/conf/conf.reducer.ts` fa `OPTIONS: {...state.OPTIONS, ...conf.OPTIONS}`: emettere `startUrl: null` **sovrascrive** il default `/main/map` del frontend con `null`. Emettere `null` è peggio che omettere la chiave.

**27 campi** (il "gruppo B"), e **36 siti di lettura**: cinque campi sono letti in due sezioni con guard diversi.

| Campo | Divergenza | Stato su app 28 |
|---|---|---|
| `start_url` | `OPTIONS.startUrl` | **osservata**: `null` ← `/main/explore` |
| `show_edit_link` | `OPTIONS.showEditLink` | **osservata**: `null` ← `false` |
| `skip_route_index_download` | `OPTIONS.skipRouteIndexDownload` | **osservata**: `null` ← `true` |
| `show_favorites` | `OPTIONS.show_favorites` | **osservata**: `null` ← `true` |
| `table_details_show_scale` | `OPTIONS.show_scale` **e** `TABLES.details.hide_scale` | **osservata**: `null` ← `true` |
| `table_details_show_gpx_download` | `OPTIONS.showGpxDownload` **e** `TABLES.details.showGpxDownload` | **osservata**: `null` ← `false` |
| `table_details_show_kml_download` | `OPTIONS.showKmlDownload` **e** `TABLES.details.showKmlDownload` | **osservata**: `null` ← `false` |
| `table_details_show_geojson_download` | `OPTIONS.showGeojsonDownload` **e** `TABLES.details` | latente: il cast `(bool) null` dà `false` = GeoHub `false` |
| `table_details_show_shapefile_download` | `OPTIONS.showShapefileDownload` **e** `TABLES.details` | latente: idem |
| `offline_enable` | `OFFLINE.enable` | latente: entrambi `false` |
| `offline_force_auth` | `OFFLINE.forceAuth` | latente: entrambi `false` |
| `tracks_on_payment` | `OFFLINE.tracksOnPayment` | latente: entrambi `false` |
| `enable_routing` | `ROUTING.enable` | latente: non emessa, guard `api == 'elbrus'` |
| 14 × `table_details_show_*` | `TABLES.details.*` | latenti: non emesse, guard `api == 'elbrus'` |

**7 osservate, 20 latenti.**

I cinque a doppia lettura sono il punto più facile da sbagliare: `TABLES` sta dentro `in_array($this->app->api, ['elbrus'])`, `OPTIONS` **non** ha guard. Migrare solo il ramo `TABLES` lascerebbe `null` esattamente tre delle divergenze osservate.

Un secondo punto facile da sbagliare: le 14 letture di `TABLES` sono **negazioni** (`hide_ascent = ! $this->app->table_details_show_ascent`). `! null` è `true`, quindi un porting meccanico a `properties` **nasconderebbe** il dato per un'app non configurata. Serve un default esplicito.

---

#### 1d — Non esiste nessuna destinazione, e nessuno legge il campo

Il campo esiste su GeoHub, il suo config lo emette, e in Maphub non è letto da **nessuna riga di codice**. La chiave risulta assente, non `null`.

**9 campi** (il "gruppo C"), di cui 4 con un consumer reale e 5 senza ragione di esistere in Maphub.

| Campo | Divergenza | Stato |
|---|---|---|
| `show_embedded_html` | `OPTIONS.showEmbeddedHtml` | **osservata**: assente ← `false` |
| `show_get_directions` | `OPTIONS.showGetDirections` | **osservata**: assente ← `false` |
| `show_media_name` | `OPTIONS.showMediaName` | **osservata**: assente ← `false` |
| `draw_poi_show` | `WEBAPP.draw_poi_show` | **osservata**: assente ← `false` |
| `feature_image`, `icon_notify` | — | fuori scope: nessun consumer in Maphub |
| `filter_theme`, `filter_theme_exclude`, `filter_theme_label` | — | fuori scope: **sostituiti** in Maphub da `filter_layer*`, divergenza intenzionale |

**4 osservate, 0 latenti** (le 5 escluse non sono latenti: non devono arrivare).

---

#### 1e — Doppia sorgente: l'import scrive una colonna, il config legge una relazione

Qui la colonna esiste **e** l'import la scrive correttamente. Ma il config non la legge: legge una pivot che nessuno popola.

```php
// App::tiles() — la sorgente reale
belongsToMany(Tile::class, 'app_tile')->withPivot('sort_order')->orderBy('app_tile.sort_order')

// app 3: select * from app_tile → 0 righe (esistono solo per app 1 e 2, seed locali)
// mentre apps.tiles contiene il valore GeoHub
```

È il *"manca la scelta dello sfondo cartografico"* riportato dal cliente.

| Divergenza osservata | |
|---|---|
| `MAP.tiles` | `[]` ← `[{"webmapp": "https://api.webmapp.it/tiles/{z}/{x}/{y}.png"}]` |
| `MAP.controls` | `["data"]` ← `["data", "tiles"]` (il controllo `tiles` non compare) |

**2 osservate, 0 latenti.**

Due fatti che vincolano il fix, entrambi verificati:

- **GeoHub non ha una tabella `tiles`** né una pivot (`Schema::connection('geohub')->hasTable('tiles')` → false): label e icone sono hardcoded lato GeoHub, Maphub le ha normalizzate in tabella + seeder. Il solo dato importabile è la colonna, e il match va fatto per `attribution`.
- **La colonna è doppiamente codificata** — un array che contiene una *stringa* JSON, non un array di oggetti:
  ```
  GEOHUB app 28   '["{\"webmapp\":\"https://api.webmapp.it/tiles/{z}/{x}/{y}.png\"}"]'
  ```

Su GeoHub si usano **9 `attribution`** distinte; la tabella `tiles` locale ne ha **3**. Mancano `CyclOSM`, `OSM`, `notile`, `GOMBITELLI`, `Humanitarian`, `CARG` (11 slot app su 68).

---

#### Riepilogo della causa 1

| Manifestazione | Campi | Osservate | Latenti | Cosa manca |
|---|---|---|---|---|
| 1a colonna viva, lettura in `properties` | 4 | 4 | 0 | escludere le colonne dal payload + scrivere `properties.theme.*` |
| 1b nessuna colonna, lettura in `properties` | 6 | 1 | 5 | **solo** scrivere `properties.*` |
| 1c nessuna colonna, lettura di attributo inesistente | 27 | 7 | 20 | scrivere `properties.*` **e** spostare 36 siti di lettura |
| 1d nessuna colonna, nessuna lettura | 4 (+5 esclusi) | 4 | 0 | scrivere `properties.*`, spostare la lettura, **e** esporre la chiave |
| 1e colonna scritta, lettura da pivot | 1 | 2 | 0 | popolare `app_tile` dalla colonna doppio-codificata |
| **Totale** | **42** | **18** | **25** | |

Il conteggio torna: 4 + 6 + 27 + 4 + 1 = 42 campi, di cui 5 (theme ×4 + `tiles`) esistono come colonna locale e 37 no.

---

### CAUSA 2 — Il remap della HOME e la scrittura del config sono agganciati al momento sbagliato

**2 divergenze osservate: `HOME[].layer` contiene gli id GeoHub (intermittente), e l'intero file servito appartiene a una run precedente.**

Sono due sintomi della stessa causa: entrambi dipendono da **quando** un certo pezzo di lavoro viene eseguito rispetto all'import.

**Il remap della HOME.** La HOME è importata con gli id layer di GeoHub (131-137 per app 3). `UpdateAppConfigHomeLayerIdsJob` li rimappa, ma è dispatchato da `ImportAppJob` **subito dopo** `$batch->dispatch()` del batch layer, non al suo completamento, e attende che l'intera coda `geohub-import` si svuoti con **5 tentativi × 2 min = 10 minuti** contro un import di ~30.

> **Correzione al ticket.** Il ticket descrive due run con esiti opposti come prova. Non regge: nella run A i log non contengono **né** `Config home aggiornata` **né** `Coda non vuota`, che è incompatibile con "il job ha atteso e poi abbandonato" ed è compatibile con un import interrotto a mano. Il difetto della finestra fissa è reale **per lettura del codice**, non per evidenza empirica. La distinzione run A / run B va rimossa dal ticket.

Questa causa ha un secondo effetto, **più grave e non nel ticket**: finché il DB contiene id GeoHub, **aprire e salvare l'app da Nova distrugge la HOME**.

```php
// Nova/App.php::layer_layout() — le options sono SOLO id locali, e solo layer diretti
Select::make('Layer', 'layer')->options(fn () => Layer::where('app_id', $this->model()->id)…)

// ConfigHomeResolver::buildLayerElement() — riscrive anche il title
$layer = Layer::find($element['layer']);
if ($layer) { $element['title'] = $layer->getStringName() ?: 'Layer #'.$layer->id; }
```

Un valore fuori dalle options non è preservato: il browser posta la prima opzione, e il resolver riscrive il titolo col nome del layer così ottenuto. **Ogni box viene riassegnato a un layer diverso, con titolo coerente** — corruzione indistinguibile da una configurazione voluta.

> **Verificato empiricamente, non solo teorizzato.** Sul test di import reale di app 3, i log mostrano `Coda 'geohub-import' è vuota (doppio check)` e `Config home aggiornata per App ID 3` alle **07:14:29**. L'app risulta salvata da Nova alle **07:57:08**, 43 minuti dopo: il salvataggio ha trovato id già corretti e non ha corrotto nulla, ma **per coincidenza di tempistica**, non perché il salvataggio "sistemi" gli id. Se il salvataggio fosse avvenuto nella finestra di attesa (fino a 10 minuti, spesso di più su un import di 30), lo avrebbe fatto.

Terzo effetto: il remap **non è idempotente**. `firstWhere('properties.geohub_id', $layerId)` con `$layerId` già locale può trovare un layer diverso. Oggi latente (id locali 1-9, `geohub_id` 131-137, self-join sulle collisioni → 0 righe), ma gli id locali crescono.

**La scrittura del config.** Il ticket attribuiva il file stale a `AppController::config`. Non è lì: `GeohubImportService::persistQuietly()` silenzia gli observer durante l'import (`unsetEventDispatcher()`/`saveQuietly()`/`setEventDispatcher()`), quindi `AppObserver::saved()` — l'unico punto che scrive il config al salvataggio di un'App — non scatta mai. Il file su storage resta quello di prima: un config stale non è vuoto, è sbagliato in modo plausibile, e ha già prodotto una diagnosi errata dentro questo ticket.

> **Un'ipotesi scartata dopo verifica empirica.** `persistQuietly()` ha anche un difetto in sé — l'`unset` è statico sulla classe base `Model`, spegne gli observer di *tutti* i modelli del worker, e se il salvataggio lancia un'eccezione il dispatcher resta spento oltre il previsto. Sembrava quindi necessario correggerlo per chiudere questa causa. **Non lo è**: sui 35 job falliti del test di import reale, **zero** sono falliti dentro `saveQuietly()` (14 errori di rete e 15 media 404 avvengono prima del salvataggio, 5 timeout AWS sono un job separato, 1 query con troppi parametri è una `SELECT`). Il fix scelto sotto non dipende comunque da questo comportamento — quindi resta fuori da oc:8488: non causa nessuna divergenza e non ha manifestazioni note.

**Il fix per entrambe: agganciare il lavoro al completamento del solo batch che serve.** Non un batch padre che aggrega tutte le entità — la scrittura del config al `finally()` del batch layer dipende solo dai dati già presenti sulla riga `apps` (già corretti dalla causa 1, indipendentemente da ec_poi/ec_track/ugc) e dai layer, non dal resto dell'import.

> **Aggiornamento post-review (fix Finding 4/5/6, vedi `notes.md`).** La frase sopra descrive correttamente il *trigger* (quando si scrive), non l'intero *contenuto* del config: `config_section_map()` legge anche `getAllPoiTaxonomies()` (ec_poi + taxonomy_activity/poi_types), il `feature_image` per-layer (ec_media) e `MAP.bbox`/`MAP.filters.activities` (ec_track) — tutti popolati da batch indipendenti dal batch layer, senza garanzia di completamento relativa (oc:8094). Il batch layer può quindi completare — e `finalizeAppImport()` scrivere un config fresco rispetto ai layer — mentre quei batch sono ancora in corso, producendo un config temporaneamente privo di quelle sezioni. Poiché l'import usa `persistQuietly()`, nessun observer rimedierebbe più tardi.
>
> Fix: i batch di `ec_media`, `ec_poi`, `ec_track`, `taxonomy_activity`, `taxonomy_poi_types` (`ImportAppJob::CONFIG_DEPENDENT_BATCHES` — non `taxonomy_theme`, che non alimenta nessuna chiave di `config_section_map()`) agganciano anch'essi un `finally()` che accoda (non sincrono) un `UpdateAppConfigJob` di refresh best-effort per lo stesso app id — le chiamate ridondanti da batch che finiscono quasi in contemporanea collassano sull'`uniqueFor(): 600` già esistente su `UpdateAppConfigJob` (Task 7).
>
> **Secondo giro di review (Finding 5, risolto su richiesta esplicita del dev):** questo refresh, nella prima stesura, scriveva **incondizionatamente** — ignorando se il batch layer della stessa import era ancora in corso o era fallito, annullando di fatto il gate descritto sopra ("mai un config con `MAP.layers` incompleto") in quasi ogni import normale. Fix: `ImportAppJob::layerBatchIsPublishReady(int $appId): bool`, un gate basato su un sentinel in cache (`wm-package:import-layer-batch:{appId}`, scritto come prima istruzione di `processDependencies()` — prima di dispatchare qualunque batch, per chiudere la corsa in cui un batch di `CONFIG_DEPENDENT_BATCHES` finisce prima che il batch layer sia anche solo dispatchato) che tutti i `finally()` di `CONFIG_DEPENDENT_BATCHES` controllano prima di accodare `UpdateAppConfigJob::dispatch()`. Scrive SOLO se il batch layer (quando esiste per questo import) risulta confermato integro (`Bus::findBatch()` → finito, nessun fallimento, non cancellato).

```php
// oggi: dispatch indipendente, subito dopo il batch, senza aspettarlo
$batch = Bus::batch($jobs)->name("...")->onQueue(...);
$batch->dispatch();
if ($entityModelKey === 'layer') {
    dispatch(new UpdateAppConfigHomeLayerIdsJob($appId));
}

// fix: agganciato al completamento del batch layer. static, FQCN, nessun $this catturato
// (Finding 1: una closure non-static qui cattura implicitamente $this -> GeohubImportService
// -> Connection PDO/Logger, non serializzabili -> ogni dispatch reale del batch fallisce)
$batch = Bus::batch($jobs)->name("...")->onQueue(...)->allowFailures();
if ($entityModelKey === 'layer') {
    $batch->finally(
        static fn (Batch $batch) => ImportAppJob::finalizeAppImport($appId, $batch)
        // 1. rimappa SEMPRE gli id HOME (idempotente, nessuna attesa sulla coda) —
        //    anche se il batch ha avuto failures: un remap parziale non è un danno,
        //    solo incompleto, comunque meglio di una HOME mai rimappata
        // 2. scrive il config (writeAppConfigOnAws) SOLO se il batch è integro (nessuna
        //    failure/cancellazione) — un config appena riscritto ma con MAP.layers
        //    incompleto sarebbe peggio dello stale attuale
    );
}
$batch->dispatch();
```

`allowFailures()` sul solo batch layer: se un layer fallisce, gli altri completano comunque e il `finally()` scatta comunque — ma il remap HOME e la scrittura del config non condividono più lo stesso gate (fix post-review, vedi `notes.md`): il remap gira sempre (un remap parziale — non un danno, solo incompleto), solo la scrittura del config viene saltata quando il batch non è integro.

Con questo fix, il config viene scritto **una volta**, subito dopo che i layer esistono, senza bisogno di un salvataggio manuale da Nova — anche se il dev può comunque farlo, in sicurezza, perché a quel punto la HOME contiene già solo id locali.

---

### CAUSA 4 — `fill()` sostituisce `properties`: la causa che distruggerebbe il fix

**0 divergenze osservate oggi. Ne produrrebbe 47 al primo re-import dopo il fix.**

`importData()` fa `$model->fill($transformedData)`, `properties` è castato `array` con `$guarded = []` e nessun mutator di merge. Provato in sola lettura su app 3:

```
PRIMA: 28 chiavi
DOPO fill(): 2 chiavi -> geohub_id, geohub_synced_at
```

Ogni re-import azzera tutte le `properties` configurate da Nova. Non è una divergenza osservata perché il flusso previsto parte sempre da un DB ripristinato. Ma tutta la soluzione delle cause 1a–1e scrive in `properties`: senza questo fix, il primo re-import su un DB non ripristinato si porterebbe via l'intero risultato del ticket.

È una causa, non un rischio: va chiusa insieme alle altre.

---

## Parte 4 — Non è una causa di divergenza

### L'endpoint `config` ri-encoda, e il suo fallback è morto

Nessuno dei due difetti produce una divergenza da GeoHub. Sono difetti dello **strumento con cui le divergenze si misurano**, e come tali hanno prodotto la diagnosi errata del punto 5 del ticket.

- **doppia codifica**: `StorageService::getAppConfigJson()` ritorna `?string` e `AppController::config` la passa a `response()->json()`, che la ri-encoda. `curl .../3/config.json | jq type` → `string`, mentre `base-config.json` → `object`. Due rotte, due tipi di ritorno per lo stesso contenuto.
- **ramo morto**: `?? $app->BuildConfJson($app->id)` chiama un metodo che **non esiste** (1 sola occorrenza nel repo: la chiamata). Su storage vuoto è un `BadMethodCallException` → 500. Mai osservato solo perché MinIO locale sopravvive ai ripristini del DB.

Verificato che **nessun consumer frontend usa questa rotta**: `wm-core/src/store/conf/conf.service.ts` fa `getConf()` su `environmentSvc.confUrl`, che è `${awsApi}/${appId}/config.json` — il file su S3/CDN, tipizzato `ICONF`, senza doppio parse.

**Applicando il criterio di questo documento — si lavora solo sulle cause delle divergenze — questi due difetti cadono fuori scope.** Restano inclusi per decisione esplicita del dev, presa prima della riorganizzazione per causa: sono 2-3 righe, e lasciarli fuori significa che il prossimo che indaga un import inciampa negli stessi due difetti avendo in mano un documento che li descrive. Se il criterio va applicato alla lettera, questa parte si scorpora in un ticket separato senza toccare nient'altro.

Lo storage-first **resta**: era stata valutata l'ipotesi di far ricalcolare sempre il config, poi scartata dal dev — il frontend non legge questa rotta, quindi il ricalcolo non porterebbe beneficio all'app e caricherebbe una rotta pubblica di 12+ sezioni, bbox in PostGIS e layer serializzati.

---

## Parte 5 — Requisiti, per causa

### Trasversale: il criterio di emissione

- [ ] Una chiave di config viene **omessa se e solo se il valore sorgente è `null`**. Il criterio è `! is_null()`, **non** `empty()` e **non** `is_string()`
- [ ] Motivazione da scrivere nel codice: `false` e `0` sono valori legittimi. Il criterio di oc:8367 (`! is_string($value) || $value === ''`) è corretto per le 6 chiavi theme, che sono stringhe, e sbagliato per i 37 campi boolean/int — `is_string(false)` è `false`, li ometterebbe tutti. Con `empty()` si perderebbero tutti i `false` e gli zeri, cioè 7 delle 18 divergenze osservate, e un `download_track_enable: false` deliberato diventerebbe chiave assente → il default frontend `true` vincerebbe → funzionalità riattivata in silenzio su ogni tenant

### CAUSA 1 — rinnovare il contratto dell'import

- [ ] **Una sola mappa dichiarativa** delle chiavi `properties` importate, con due consumer che devono concordare sul nome: l'import che scrive e Nova che genera i campi editabili
- [ ] La mappa **non** contiene la struttura delle sezioni di config: sono irregolari (`TABLES.details.hide_ascent` è una negazione, `OPTIONS.show_scale` non è derivabile dal nome, 5 campi vanno in due sezioni). Restano esplicite in `AppConfigService`, che però le legge tutte tramite **un solo helper** che applica il criterio di emissione
- [ ] I campi Nova sono **generati** dalla mappa, non scritti a mano: il disallineamento mappa↔Nova diventa **irrappresentabile** invece che rilevabile, e sparisce l'allowlist che servirebbe altrimenti (in `Nova/App.php` ci sono già 31 campi `properties->*` che non sono chiavi di config: `analytics_*`, `wp_*`, `min_app_version`, `theme->*`). Supera il rischio che oc:8367 aveva documentato e accettato per le sue 6 chiavi
- [ ] I 4 campi theme di oc:8367 restano scritti a mano in `theme_tab()`, con label e help propri. La divergenza fra i due meccanismi è **deliberata** e va annotata nel codice

**1a** — [ ] Le 4 colonne theme vengono **escluse** dal payload colonnare dell'import e scritte in `properties.theme.*`. `config_section_theme()` **non viene modificato**: oc:8367 è già in `develop` e produce le chiavi camelCase corrette. Le colonne morte non vengono droppate (out of scope di oc:8367)
**1a** — [ ] Un valore GeoHub non-null **sovrascrive** un `null` locale esplicito: `properties.theme` contiene 4 chiavi a `null` appena qualcuno apre il tab Theme in Nova, e senza questa regola il colore finale dipenderebbe dall'ordine delle azioni umane

**1b** — [ ] I 6 campi vengono scritti in `properties.<chiave>`. **Nessuna** modifica ad `AppConfigService` né a Nova: la destinazione è già letta e il campo esiste già. Unico rename: `show_download_tiles_button` → `show_download_tiles`

**1c** — [ ] I 27 campi vengono scritti in `properties.<chiave>`, e **tutti i 36 siti di lettura** passano a leggere da lì, inclusi i due siti dei 5 campi a doppia lettura
**1c** — [ ] Le 14 negazioni di `TABLES` usano un default esplicito: `! $this->prop('table_details_show_ascent', true)`. `! null` è `true`, quindi un porting meccanico nasconderebbe il dato per un'app non configurata
**1c** — [ ] Il guard `api == 'elbrus'` su `TABLES` e `ROUTING` **non viene toccato**; i 5 campi a doppia lettura vengono migrati **anche** nel ramo `OPTIONS`, che non ha guard

**1d** — [ ] I 4 campi con consumer vengono scritti in `properties` ed esposti alle chiavi GeoHub: `OPTIONS.showEmbeddedHtml`, `OPTIONS.showGetDirections`, `OPTIONS.showMediaName`, `WEBAPP.draw_poi_show`
**1d** — [ ] I 5 senza consumer non vengono importati (vedi Out of scope)

**1e** — [ ] Il parser di `apps.tiles` gestisce la forma **doppiamente codificata** reale, non `[{attribution: url}]`
**1e** — [ ] L'import popola la pivot `app_tile` con `sort_order` pari all'ordine nell'array. `App::tiles()` ordina già per `sort_order`, quindi il primo elemento resta il basemap di default in app
**1e** — [ ] Il match sui `Tile` avviene per `attribution`. Un'`attribution` senza `Tile` locale **non viene scartata in silenzio**: viene creato un `Tile` nuovo con `attribution` e `server_xyz` da GeoHub. `label` è una colonna `json` **NOT NULL** con `HasTranslations`, quindi va scritta come array di traduzioni, mai come stringa nuda. `icon`/`link` nulli — basemap funzionante con label grezza, rifinibile a mano: rientra nell'unica eccezione ammessa dal principio guida. L'evento è loggato
**1e** — [ ] **Un `Tile` esistente non viene mai modificato**, solo letto o creato se assente. `TileObserver::saved()` dispatcha `UpdateAppConfigJob` **per ogni app collegata al tile**: modificarne uno condiviso durante l'import di *una* app riscriverebbe il config di app non correlate. La creazione è sicura (`apps()` vuota → early return)

### CAUSA 2 — agganciare remap e scrittura al completamento del batch layer, e sbarrare la corruzione da Nova

- [ ] `queueEntityImport()` per l'entità `layer` usa `->allowFailures()` sul proprio batch — precedente già nel package, `GeohubImportService::importAllByModel()`. Senza, un singolo layer fallito cancella il batch e scarta i pendenti, perché `BaseImportJob::logImportFailure()` rilancia
- [ ] Il job di remap + scrittura config è agganciato al `->finally()` **del solo batch layer**, non a un batch padre che aggrega tutte le entità: il *trigger* della prima scrittura dipende dai dati già sulla riga `apps` (già corretti dalla causa 1) e dai layer, non dal resto dell'import. **Aggiornamento post-review (Finding 4/5)**: il *contenuto* del config dipende anche da `ec_poi`/`ec_media`/`ec_track`/`taxonomy_activity`/`taxonomy_poi_types` (non `ugc`/`taxonomy_theme`) — questi batch agganciano anch'essi un refresh best-effort, gated su `ImportAppJob::layerBatchIsPublishReady()` per non pubblicare mai un config con `MAP.layers` incompleto. Vedi il callout sopra e `notes.md`
- [ ] Il `finally()` esegue, in ordine: 1) remap degli id HOME, 2) `writeAppConfigOnAws()` — la scrittura è **esplicita**, non passa dagli observer, quindi non dipende da `persistQuietly()`
- [ ] `isQueueEmpty()`, `maxAttempts` e `release(120)` vengono rimossi da `UpdateAppConfigHomeLayerIdsJob`: nessuna attesa a finestra fissa, il job scatta al completamento reale del batch
- [ ] Il remap è **idempotente**: un valore che è già un id locale di questa app non viene rimappato
- [ ] La `Select` del box layer **non può distruggere un id non risolto**: se `config_home` contiene un id non presente nelle `options()`, il salvataggio non lo riassegna silenziosamente a un altro layer
- [ ] Le `options()` includono anche `associatedLayers()`, allineandosi a ciò che il job risolve — altrimenti il guard produce box non editabili in silenzio
- [ ] **`UpdateAppConfigJob` implementa `ShouldBeUnique`**: oggi dichiara solo `ShouldQueue`, quindi il suo `uniqueId()` è **codice morto** (Laravel lo consulta solo con quell'interfaccia, come fanno correttamente altri 5 job del package). Senza, il dispatch dal `finally()` si somma a quelli di `TileObserver`/`LayerObserver`/`FeatureCollection*`: N ricalcoli concorrenti che scrivono la stessa chiave S3 senza lock
- [ ] Nessun fix a `persistQuietly()`: verificato sui 35 job falliti del test di import reale che **zero** sono falliti dentro `saveQuietly()`. Il fix di questa causa non dipende comunque da quel comportamento. Resta fuori scope: non causa nessuna divergenza e non ha manifestazioni note

### CAUSA 4 — il merge di `properties`

- [ ] `transformData()` **fonde** `properties` con quelle già presenti invece di sostituirle
- [ ] La semantica del merge è definita e scritta: un valore GeoHub non-null vince, un valore GeoHub assente lascia intatto il locale, e una chiave che GeoHub **non emette più** resta in `properties` (scrittura monotona crescente). Nessun percorso di rimozione in questo ciclo: è una scelta, non una dimenticanza

### Parte 4 (per decisione del dev, non perché causa una divergenza)

- [ ] `AppController::config` risponde con un JSON **object**, non con una stringa JSON
- [ ] **Prima** di toccare la response, censire i consumer non verificati (`wm-webapp`, plugin WordPress): un consumer che oggi compensa la doppia codifica con un doppio parse si rompe
- [ ] L'implementazione non introduce un `200` con body `null`: un file troncato o corrotto deve ricalcolare, non servire un successo vuoto
- [ ] Il ramo morto `BuildConfJson` è sostituito da un fallback che, su storage vuoto, ricalcola **una volta** e scrive
- [ ] Storage-first invariato; `config` e `baseConfig` restano due rotte distinte con la semantica attuale

### i18n

- [ ] Le stringhe generate per i campi Nova sono presenti in `resources/lang/it.json` **e** `resources/lang/en.json`, testo base inglese. Sono le uniche due lingue del package

---

## Parte 6 — Rischi

- **Un layer fallito produce un remap parziale.** Conseguenza accettata di `allowFailures()` sul batch layer: gli altri layer completano e il `finally()` scatta comunque, ma i box HOME che puntavano al layer fallito restano con l'id GeoHub. Non un danno — nessuna corruzione, solo incompleto — ma da segnalare in log
- **25 divergenze latenti non sono verificabili su app 28.** Il fix le copre per costruzione, ma la verifica end-to-end su quest'app non le esercita: solo i test con payload sintetico lo fanno
- **La generazione dei campi Nova fa convivere due meccanismi** in `Nova/App.php`. Divergenza consapevole, da annotare perché il prossimo non "uniformi" nella direzione sbagliata
- **6 delle 9 `attribution` tiles non hanno un `Tile` locale.** La creazione on-the-fly produce un basemap funzionante ma con label grezza e senza icona, in una tabella **globale visibile a tutti gli admin**
- **Il match per `attribution` ignora `server_xyz`**: se un `Tile` locale omonimo punta altrove, l'app viene agganciata a quel basemap senza segnale
- **L'idempotenza del remap è euristica, non risolutiva**: id GeoHub e id locali vivono nello stesso spazio di interi, e un id GeoHub che sia *anche* un id locale valido è indistinguibile da un valore già rimappato. Non esiste marker. Oggi latente, e la regola scelta lo riformula senza eliminarlo
- **`config_home` viene sovrascritto in place** senza snapshot degli id GeoHub: un remap sbagliato non è reversibile dal DB locale
- **`WEBAPP.draw_poi_show` accende una UI di creazione POI** (default frontend `false`). Per un'app dove GeoHub lo ha a `true`, l'esposizione attiva un flusso il cui backend in Maphub non è verificato in questo ciclo
- **Il cambio di tipo della response di `config`** può rompere un consumer esterno non censito
- **Il fallback su storage vuoto introduce una scrittura dentro una GET pubblica non autenticata.** Già così in `baseConfig`, ma su `config` diventa raggiungibile senza throttle: un oggetto S3 cancellato può produrre stampede
- **Conflitti attesi con la linea RDO**, che tocca `Nova/App.php`, `AppConfigService.php` e `resources/lang/*.json` — gli stessi file di questo ticket. Conflitti di sola aggiunta, ma da rebasare spesso
- **Rollback: "nessuna migration" non significa "rollback facile".** Il codice si ripristina col bump inverso, ma i dati restano: `properties` con 37 chiavi che il codice ripristinato non legge, righe `app_tile` e `Tile` nuovi in una tabella globale, `config_home` rimappato in modo irreversibile, e il `config.json` su S3/CDN già sovrascritto senza invalidazione in scope

---

## Parte 7 — Out of scope

- **Nessuna migration.** La soluzione scrive in `properties`: nessuna colonna aggiunta a `apps`, e le colonne morte non vengono droppate (coerente con oc:8367)
- **`MAP.pois.taxonomies.where`** (0 contro 100) — oc:8486 e oc:8487
- **5 dei 9 campi del gruppo C**: `feature_image` e `icon_notify` non hanno consumer in Maphub; `filter_theme{,_exclude,_label}` sono stati **sostituiti** da `filter_layer*`, e riesumarli contraddirebbe una divergenza intenzionale
- **Nessuna modifica a `config_section_theme()`** né ai 4 campi theme di Nova: sono di oc:8367, già in `develop`
- **Nessun comando di backfill/resync e nessuna Action Nova di re-import.** L'import è one-off; nessun requisito di retroattività
- **Nessun percorso di rimozione** delle chiavi `properties` orfane né dei `Tile` creati on-the-fly
- **Nessuna modifica al motore di theming del frontend** (`wm-core`) né ai default che applica per le chiavi assenti
- **Nessuna invalidazione cache/CDN** sulla pipeline `writeAppConfigOnAws()` — preesistente, già follow-up di oc:8367
- **Nessun lock distribuito** sulla scrittura del config: `ShouldBeUnique` copre la concorrenza tra job, non tra job e richiesta HTTP a `base-config.json`
- **Nessuna modifica al repo maphub** oltre al bump del submodule e ai documenti di feature

### Differenze attese, che non sono divergenze

`APP.geohubId` (28 vs 3), `MAP.layers[].id` (131-137 vs id locali), payload layer più snello in locale, `JIDO_UPDATE_TIME` solo su GeoHub, sezione `WORDPRESS` solo in locale, forma di `TRANSLATIONS` e `APP.welcome`.

---

## Parte 8 — Dipendenze fra ticket

**oc:8367 — nessun vincolo di ordine.** È **già in `develop`**, arrivato col merge RDO #269 (era la PR #264 sul branch RDO). Verificato per **contenuto** e non per lineage: `git merge-base --is-ancestor` sul branch `feature/oc-8367-…` risponde NO, perché il codice è passato dal merge RDO e non dal branch. Dopo un merge di integrazione l'appartenenza si verifica con `git log -S <simbolo> -- <file>` o un diff diretto. Verifiche eseguite: `config_section_theme()` byte-identico alla versione oc:8367, `sanitizeHexColor()` in `helpers.php`, campi `secondary_color`/`tertiary_color` in `Nova/App.php`.

---

## Parte 9 — Moduli toccati

Tutto in **wm-package**. Nel repo maphub: solo bump del submodule + documenti di feature.

| File | Causa | Intervento |
|---|---|---|
| `src/Support/ImportedAppProperties.php` *(nuovo)* | 1 | Mappa dichiarativa: sorgente unica delle chiavi `properties` importate |
| `src/Jobs/Import/ImportAppJob.php` | 1, 2, 4 | `transformData()`: merge `properties`, esclusione colonne theme, scrittura 37 chiavi, parser tiles. `queueEntityImport()`: `allowFailures()` + `finally()` sul batch layer **e** sui 5 `CONFIG_DEPENDENT_BATCHES` (post-review, refresh best-effort gated su `layerBatchIsPublishReady()`) |
| `src/Services/Import/GeohubImportService.php` | 1e | Nuovo `resolveTile()`. `persistQuietly()` **non toccato** — verificato che il fix non ne dipende |
| `src/Services/Models/App/AppConfigService.php` | 1c, 1d | Helper con criterio `! is_null()`; 36 siti di lettura migrati (37 dopo il fix post-review su `MAP.pois.skipRouteIndexDownload`); 4 nuove esposizioni |
| `src/Nova/App.php` | 1, 2 | Campi generati dalla mappa, **distribuiti nelle tab esistenti** per sezione di config (post-review, non più una tab dedicata); 9 nuovi campi `track_technical_details->*`; `options()` e guard della Select layer |
| `src/Jobs/UpdateAppConfigJob.php` | 2 | `ShouldBeUnique` |
| `src/Jobs/UpdateAppConfigHomeLayerIdsJob.php` | 2 | Rimozione della finestra fissa; remap idempotente |
| `src/Nova/Flexible/Resolvers/ConfigHomeResolver.php` | 2 | Guard contro la riassegnazione silenziosa |
| `src/Http/Controllers/Api/AppController.php` | Parte 4 | `config()`: response object, fallback funzionante |
| `composer.json` | — | Aggiunta `outl1ne/nova-multiselect-field` (dipendenza mancante preesistente, non causata da oc:8488, scoperta durante il Task 5 — vedi `notes.md`) |
| `resources/lang/{it,en}.json` | 1 | Stringhe dei campi generati |

---

## Parte 10 — Verifica di accettazione

### La prova discriminante è nei test, non in un import

L'avvertimento della Parte 1 vincola la verifica: leggere il DB dopo un import di app 28 non prova nulla, perché i default dello stub coincidono coi valori GeoHub. E 25 delle 43 divergenze sotto la causa 1 sono **latenti** su quest'app, quindi un import non le esercita affatto.

La prova va costruita indipendente dai dati reali: test che passano all'import una riga GeoHub **sintetica**, con valori che non possono coincidere col default per costruzione.

```php
$row = [
    'primary_color' => '#0055aa',          // ≠ default '#de1b0d'
    'font_family_header' => 'Montserrat',  // ≠ default 'Roboto Slab'
    'start_url' => '/main/explore',
    'show_edit_link' => false,             // false: deve essere EMESSO, non omesso
    'show_track_direction_arrow' => true,  // deve sovrascrivere il false scritto da Nova
    'tiles' => '["{\"satellite\":\"https:\/\/example.test\/{z}\/{x}\/{y}.png\"}"]',
];
```

Copertura per causa: **1a** theme in `properties` e sovrascrittura dei `null` locali; **1b** le 6 chiavi scritte senza toccare config né Nova; **1c** i 36 siti, il criterio `! is_null()` con casi `false`/`0`, le negazioni `TABLES` con default, il guard elbrus preservato; **1d** le 4 nuove esposizioni; **1e** parser doppio-decode, pivot con `sort_order`, `Tile` creato con `label` come traduzioni, `Tile` esistente mai modificato, idempotenza; **2** `allowFailures()` sul batch layer, `finally()` che rimappa e scrive senza attesa a finestra fissa, remap idempotente, guard Nova, `ShouldBeUnique`, refresh best-effort dei `CONFIG_DEPENDENT_BATCHES` gated su `layerBatchIsPublishReady()` (post-review); **4** merge che preserva le chiavi Nova.

### L'end-to-end è uno step del dev

**Nessun import viene eseguito da Claude.** Il dev decide quando e su quale app, dopo aver ripristinato un backup pre-import.

Informazione per quella scelta, raccolta con query in sola lettura: su GeoHub ci sono **22 app `api=webmapp`** con valori diversi dai default Maphub, quindi discriminanti.

| App GeoHub | primary | font | tiles | Cosa esercita |
|---|---|---|---|---|
| 72 AcquaSorgente Monte Pisano | `#024B8C` | Roboto Slab | 2 | 1a, 1e con multi-basemap e `sort_order` |
| 49 Parco foreste Casentinesi | `#222F3D` | Roboto Slab | 2 | idem |
| 31 Gombitelli | `#de1b0d` | **Montserrat** | 1 | 1a sui font, 1e con `attribution` `GOMBITELLI` senza `Tile` locale |
| 28 Itinera Romanica | `#de1b0d` | Roboto Slab | 1 | caso del cliente — **non** una prova |

```bash
G=<GEOHUB_ID>; L=<LOCAL_ID>

# cause 1a-1d: nessuna differenza oltre a quelle attese
curl -s "https://geohub.webmapp.it/api/app/webmapp/$G/config.json" \
  | jq -S 'del(.HOME,.TRANSLATIONS,.LANGUAGES,.CREDITS,.DISCLAIMER,.MAP.layers,.MAP.pois,.MAP.controls,.JIDO_UPDATE_TIME,.APP)' > /tmp/g.json
curl -s "http://localhost:8000/api/app/webmapp/$L/config.json" \
  | jq -S 'del(.HOME,.TRANSLATIONS,.LANGUAGES,.CREDITS,.DISCLAIMER,.MAP.layers,.MAP.pois,.MAP.controls,.JIDO_UPDATE_TIME,.APP,.WORDPRESS)' > /tmp/l.json
diff /tmp/g.json /tmp/l.json

# causa 1e: il sintomo del cliente. Il del(.MAP.controls) sopra toglie gli SVG dal diff,
# non esenta il controllo dalla verifica
curl -s "http://localhost:8000/api/app/webmapp/$L/config.json" \
  | jq '[.MAP.controls.tiles[]? | select(.type=="button") | .name]'

# criterio trasversale: nessuna chiave a null
curl -s "http://localhost:8000/api/app/webmapp/$L/config.json" \
  | jq '[.OPTIONS|to_entries[]|select(.value==null)|.key]'          # atteso: []

# causa 2: id locali, nell'ordine dei layer GeoHub
curl -s "http://localhost:8000/api/app/webmapp/$L/config.json" | jq -c '[.HOME[]?|select(.box_type=="layer")|.layer]'

# Parte 4: response object, non stringa
curl -s "http://localhost:8000/api/app/webmapp/$L/config.json" | jq type   # atteso: "object"
```

E il criterio che non si misura con un comando, ma è il requisito del ticket: **nessun salvataggio manuale da Nova** in nessun punto della procedura.
