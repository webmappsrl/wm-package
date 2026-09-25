# TaxonomyWhere (wm-package)

> Last updated: 2026-09-23

## Overview

`TaxonomyWhere` rappresenta un'area geografica amministrativa (regione, provincia, comune, municipio, quartiere) o un settore CAI, usata per classificare spazialmente tracce (`EcTrack`) e punti di interesse (`EcPoi`). Fornisce la logica base di import, deduplicazione e recupero asincrono della geometria PostGIS da sorgenti esterne (OSMFeatures, OSM2CAI).

I progetti che dipendono da wm-package possono estendere sia il modello Eloquent che la risorsa Nova per aggiungere sorgenti di import o comportamenti specifici.

---

## Eloquent Model

### Classe e namespace

`Wm\WmPackage\Models\TaxonomyWhere`

### Gerarchia di ereditarietà

```
TaxonomyWhere
  └── Wm\WmPackage\Models\Abstracts\Taxonomy
        └── Wm\WmPackage\Models\Abstracts\Polygon
              └── Wm\WmPackage\Models\Abstracts\GeometryModel
                    └── Illuminate\Database\Eloquent\Model
```

### Table & Database

- **Tabella:** `taxonomy_wheres`

| Colonna | Tipo | Note |
|---------|------|------|
| `id` | `bigint` | PK auto-increment |
| `name` | `text` | Translatable (array JSON) |
| `geometry` | `geography(multipolygon)` | **PostGIS** — nullable, popolata in background |
| `properties` | `jsonb` | Metadata strutturati (vedi sotto) |
| `created_at` | `timestamp` | — |
| `updated_at` | `timestamp` | — |

> **Nota:** le colonne `osmfeatures_id` e `admin_level` che esistevano in versioni precedenti sono state consolidate dentro `properties`. Esiste un indice parziale su `(properties->>'osmfeatures_id')`.

#### Chiavi rilevanti in `properties`

| Chiave | Tipo | Sorgente | Descrizione |
|--------|------|----------|-------------|
| `osmfeatures_id` | string | OSMFeatures | ID univoco OSMFeatures (es. `A12345`) |
| `admin_level` | int | OSMFeatures | Livello amministrativo OSM (4, 6, 8, 9, 10) |
| `source` | string | tutte | `'osmfeatures'`, `'osm2cai'` (o valori aggiuntivi definiti nel progetto) |
| `source_updated_at` | ISO8601 string | OSMFeatures / OSM2CAI | Data di aggiornamento alla sorgente |
| `osm2cai_id` | int | OSM2CAI | ID settore CAI |
| `code` | string | OSM2CAI | Codice settore CAI |
| `full_code` | string | OSM2CAI | Codice completo settore CAI |
| `human_name` | string | OSM2CAI | Nome leggibile settore CAI |
| `manager` | string | OSM2CAI | Gestore del settore CAI |

### Fillable

```php
protected $fillable = ['name', 'geometry', 'properties'];
```

Sovrascrive il fillable del parent `Taxonomy` (che include anche `import_method`, `identifier`, `description`, `excerpt`, `icon`).

### Casts

Ereditati da `Taxonomy`:

| Attributo | Cast |
|-----------|------|
| `name` | `array` (translatable) |
| `description` | `array` (translatable) |
| `excerpt` | `array` (translatable) |
| `properties` | `array` |

### Relationships

| Metodo | Tipo | Modello target | Tabella pivot | Note |
|--------|------|----------------|---------------|------|
| `ecTracks()` | `MorphToMany` | `EcTrack` (configurabile via `wm-package.ec_track_model`) | `taxonomy_whereables` | FK `taxonomy_where_id`; pivot `TaxonomyWhereable` |
| `layers()` | `MorphToMany` | `Layer` | `taxonomy_whereables` | FK `taxonomy_where_id`; pivot `TaxonomyWhereable` |
| `ecPois()` | `MorphToMany` | `EcPoi` | ereditato da `Taxonomy` | via chiave morfologica `whereable` |

> **Tabella `taxonomy_whereables`:** colonne `taxonomy_where_id` (FK), `taxonomy_whereable_id`, `taxonomy_whereable_type` (morph). Il pivot usa il model intermedio `TaxonomyWhereable`.

### Traits

| Trait | Provenienza | Scopo |
|-------|-------------|-------|
| `HasTranslations` (Spatie) | `Taxonomy` | Supporto multilingua per `name`, `description`, `excerpt` |
| `HasFactory` | `Taxonomy` | Factory per test |
| `FeatureCollectionMapTrait` | `TaxonomyWhere` | Renderizza la geometria come FeatureCollection per il campo Nova |
| `HasSafeTranslatable` | `GeometryModel` | Wrapper sicuro per `HasTranslations` |
| `InteractsWithMedia` (Spatie) | `GeometryModel` | Gestione media allegati (collection `default`) |

### Metodi accessori notevoli

| Metodo | Descrizione |
|--------|-------------|
| `getOsmfeaturesId(): ?string` | Legge `properties['osmfeatures_id']` |
| `getAdminLevel(): ?int` | Legge `properties['admin_level']` con cast a `int` |
| `getSource(): ?string` | Legge `properties['source']` |
| `getRelationKey(): string` | Ritorna `'whereable'` (chiave morph usata dal parent) |
| `getFeatureCollectionMap(): array` | Geometria come FeatureCollection con stile (bordo blu `rgba(37,99,235,1)`, fill `rgba(37,99,235,0.2)`) |
| `getJson(): array` | Rappresentazione array ripulita (rimuove `pivot`, `import_method`, `source`, `user_id`, ecc.) — ereditato da `Taxonomy` |
| `getGeojson(): ?array` | Esporta la geometria come GeoJSON via `GeoJsonService` — ereditato da `GeometryModel` |

### Events & Observers

Registrato tramite `Taxonomy::boot()`:

- **`TaxonomyObserver`**
  - `creating`: valida unicità del campo `identifier` (se valorizzato)
  - `updating`: normalizza `identifier` con `Str::slug()`

---

## Nova Resource

### Classe e namespace

`Wm\WmPackage\Nova\TaxonomyWhere`

### Gerarchia di ereditarietà

```
Wm\WmPackage\Nova\TaxonomyWhere
  └── Wm\WmPackage\Nova\AbstractTaxonomyResource
        └── Laravel\Nova\Resource
```

### Parent Class

`AbstractTaxonomyResource` definisce campi base (ID, Name, Description, IconSelect, Properties/Code), ricerca su `id` e `name`, e metodi vuoti per filtri, azioni, lenses. `Wm\WmPackage\Nova\TaxonomyWhere` sovrascrive tutti questi con campi, filtri e azioni specifici per le aree geografiche.

### Fields

| Campo | Tipo Nova | Visibilità | Descrizione |
|-------|-----------|------------|-------------|
| `ID` | `ID` | index, detail | PK — sortable |
| `name` | `Text` | index, detail, form | Nome (translatable) |
| `source` | `Text` (computed) | solo index | Legge `getSource()` — readonly |
| `Geometry` | `FeatureCollectionMap` | solo detail | Mappa interattiva con geometria PostGIS |
| `Geometry` | `Boolean` | solo index | `true` se geometry non è null |
| `Proprietà` | `PropertiesPanel` | detail, form | Pannello collassabile JSON properties |

> **Nota:** il label "Geometry" appare due volte in index — è intenzionale. Un campo `Boolean` mostra presenza/assenza in lista, mentre il `FeatureCollectionMap` è visibile solo nel dettaglio.

### Filters

| Filtro | Classe | Tipo | Opzioni |
|--------|--------|------|---------|
| Sorgente | `TaxonomyWhereSourceFilter` | select | OSMFeatures / OSM2CAI |
| Admin Level | `TaxonomyWhereAdminLevelFilter` | select | Regione (L4), Provincia (L6), Comune (L8), Municipio (L9), Quartiere (L10) |
| Geometria | `TaxonomyWhereHasGeometryFilter` | select | Presente / Assente |

> **Nota:** `TaxonomyWhereSourceFilter` include solo `osmfeatures` e `osm2cai`. Se il progetto aggiunge sorgenti custom (es. `geohub_conf_32`), il filtro non le copre a meno che non venga sovrascritto nella risorsa app-level.

### Actions

| Azione | Classe | Standalone | Descrizione |
|--------|--------|------------|-------------|
| Import TaxonomyWhere | `ImportTaxonomyWhere` | Si | Importa aree da OSMFeatures (vari admin level) o settori da OSM2CAI; dispatch job geometria in background; sincronizza track al termine |
| Crea Layer | `CreateLayerFromTaxonomyWhere` | No | Crea un `Layer` per ogni `TaxonomyWhere` selezionata, copia `feature_image` via Spatie Media |
| Ricarica Geometry | `RetryTaxonomyWhereGeometryFetch` | No | Ri-dispatcha `FetchTaxonomyWhereGeometryJob` sui record selezionati |
| Sincronizza Taxonomy Where su EC Features | `SyncEcTaxonomyWhereAction` | Si | Chiama `GeometryComputationService::syncTaxonomyWhere()` su tutte le `EcTrack` e gli `EcPoi` (dispatch in coda, oc:8487) |

### Lenses

Nessuna.

### Metrics

Nessuna.

### Authorization & Policies

Nessun `TaxonomyWherePolicy` dedicato nel wm-package. L'accesso a Nova è controllato dal gate globale del progetto (tipicamente `!$user->hasRole('Guest')`). Non ci sono restrizioni per ruolo aggiuntive sulla risorsa stessa.

### Nova Menu

La risorsa è pensata per essere inserita in una sezione **Taxonomies** del menu Nova. Il posizionamento esatto è definito nel `NovaServiceProvider` del progetto.

---

## Behaviors & Business Logic

### Geometria asincrona

La geometria PostGIS non viene mai impostata in modo sincrono durante l'import. Viene recuperata tramite job in coda:

- **`FetchTaxonomyWhereGeometryJob`** — per record OSMFeatures: chiama `OsmfeaturesClient::getAdminAreaDetail()`, aggiorna `geometry` via `ST_GeomFromGeoJSON()` raw SQL.
- **`FetchOsm2caiSectorGeometryJob`** — per record OSM2CAI: chiama `Osm2caiClient::getSectorDetail()`, arricchisce `properties` (`code`, `full_code`, `human_name`, `manager`), aggiorna `geometry`.

Entrambi i job hanno 3 tentativi con backoff di 60 secondi.

### Deduplicazione all'import

I record esistenti sono identificati via `properties->>'osmfeatures_id'` o `properties->>'osm2cai_id'`. Se `source_updated_at` nell'API non è più recente del valore già salvato in `properties`, il record viene saltato.

### Sincronizzazione tracks

Ogni import termina con `GeometryComputationService::syncTaxonomyWhere()`, che assegna la taxonomy_where corretta sia a ogni `EcTrack` sia a ogni `EcPoi` in base alla geometria (oc:8487).

### Assegnazione user_id

Se la tabella `taxonomy_wheres` ha la colonna `user_id` (verificata via `Schema::hasColumn`), l'utente viene ereditato dall'`App` selezionata durante l'import.

### Azione "Crea Layer"

`CreateLayerFromTaxonomyWhere` crea il `Layer`, lo collega alla `TaxonomyWhere` e tenta di scaricare la `feature_image` (se presente in `properties`) via Spatie Media Library nella collection `default` del Layer. In caso di errore media, la creazione del Layer prosegue e l'errore viene loggato con `Log::warning`.

---

## Related Jobs

| Job | Trigger | Descrizione |
|-----|---------|-------------|
| `FetchTaxonomyWhereGeometryJob` | Import OSMFeatures / RetryGeometryFetch | Scarica geometry da OSMFeatures via `osmfeatures_id` |
| `FetchOsm2caiSectorGeometryJob` | Import OSM2CAI | Scarica geometry da OSM2CAI via `osm2cai_id` |

---

## Related Services

| Service | Utilizzo |
|---------|---------|
| `GeometryComputationService` | `syncTaxonomyWhere()` post-import (EcTrack + EcPoi) |
| `OsmfeaturesClient` | API OSMFeatures |
| `Osm2caiClient` | API OSM2CAI |

---

## Formato di `properties.taxonomy_where` e località mostrate

> Aggiunto con oc:8588. Riguarda `properties->taxonomy_where` di `EcTrack`, `EcPoi`, `UgcPoi` e
> `UgcTrack` — non la tabella `taxonomy_wheres` descritta sopra, che resta il catalogo delle aree
> geografiche importate. `properties->taxonomy_where` è invece la "fotografia" (denormalizzata,
> non FK) delle aree in cui ricade quella singola traccia/poi, scritta dai job di sync descritti
> in `docs/features/8487-generalizzare-sync-taxonomy-where-ecpoi/`.

### Forma del dato

Ogni voce di `properties->taxonomy_where` è chiavata per `osmfeatures_id` (es. `R40784`) e ha la
forma:

```json
{
  "R40784": {
    "it": "Lazio",
    "en": "Lazio",
    "_admin_level": 4,
    "_source": "osmfeatures"
  }
}
```

cioè `{<lingue>, _admin_level, _source}` — non `{name, admin_level, source}`. oc:8487 aveva
introdotto quest'ultima forma come formato unificato per tutti gli scrittori; oc:8588 l'ha
rovesciata perché sia wm-core (componente `<wm-txn-where>`) sia wp-geohub (`single_track.php`)
leggono solo la forma vecchia con le lingue come chiavi dirette e i metadati prefissati con `_`
— con la forma unificata la sezione "Dove" smetteva di comparire in entrambi i consumer. Il
dettaglio della decisione è in
`docs/features/8588-mostrare-solo-la-regione-non-il-comune-nel-dettaglio-tappa/`; la nota di
superamento sul cantiere originale è in fondo a
`docs/features/8487-generalizzare-sync-taxonomy-where-ecpoi/notes.md`.

`Wm\WmPackage\Services\TaxonomyWhereDisplayService` è il punto unico che sa leggere tutte e tre le
forme mai scritte in produzione (quella più vecchia senza `_admin_level`, quella legacy con
`_admin_level`/`_source`, quella oc:8487 con `name`/`admin_level`/`source`) e normalizzarle sempre
alla forma vecchia in uscita (`normalize()`, `toLegacyEntry()`).

### Categorie e opzione App

Ogni voce viene classificata in una categoria (`TaxonomyWhereDisplayService::categoryOf()`):

- `"<N>"` — l'`admin_level` come stringa (es. `"4"` per una regione, `"8"` per un comune);
- `"source:<valore>"` — quando manca l'`admin_level` ma è presente una sorgente (es.
  `"source:osm2cai"` per un settore CAI);
- `null` — quando non c'è né l'uno né l'altro; queste voci passano il filtro solo quando non c'è
  nessuna categoria selezionata (array vuoto, "mostra tutto" — con una selezione non vuota non la
  superano mai, perché `categoryOf()` non torna mai `null` tra le categorie scelte).

L'App ha un'opzione in `properties->taxonomy_where_display` (campo Nova "Località mostrate",
`Wm\WmPackage\Nova\App`, `MultiSelect` sulle categorie disponibili per quell'App): un array di
categorie selezionate. Nessuna selezione (array vuoto o opzione assente) significa "mostra tutto".
Con una selezione non vuota, solo le voci di categoria corrispondente restano visibili nelle uscite
pubbliche — tipicamente `["4"]` per mostrare solo la regione ed escludere il comune, da cui il nome
del ticket.

Salvare l'App in Nova con un valore di "Località mostrate" cambiato (rispetto a quello
precedentemente persistito) mette automaticamente in coda la rigenerazione delle uscite
pubbliche (`AppObserver::saved()` confronta il valore prima/dopo e dispatcha
`RegenerateTaxonomyWhereOutputsJob`): non serve lanciare a mano il comando artisan per un cambio
di sola opzione, l'operatore Nova non deve saperlo.

### Dove si applica il filtro (e dove no, di proposito)

Il filtro (`GeometryModel::applyTaxonomyWhereDisplay()`, via
`TaxonomyWhereDisplayService::filter()`) si applica nelle uscite pubbliche:

- `EcTrackResource` e `EcPoiResource` (risposte API delle risorse; `EcTrackResource` è anche la
  fonte del json statico della traccia, scritto da `UpdateEcTrackAwsJob`);
- `EcTrackController` (endpoint dal vivo — il ramo senza file statico, e `multiple()`);
- `App::getAllPoisGeojson()` (`pois.geojson`);
- `EcTrack::toSearchableArray()` (campo `taxonomyWheres` indicizzato su Elasticsearch/Scout);
- `UgcController` (uscite pubbliche UGC, stesso metodo `applyTaxonomyWhereDisplay()`).

**Non** si applica, per scelta esplicita (non un'omissione):

- `GeoJsonService::getModelAsGeojson()` — il geojson "di lavoro" usato
  internamente (es. per ricalcoli, sync, export interni) deve vedere il dato completo, non filtrato;
- `getSearchableString()` — la stringa di ricerca full-text non va ristretta alle sole categorie
  mostrate, altrimenti una traccia diventerebbe non trovabile per il nome del comune anche quando
  il comune non è più visualizzato;
- `EditorialContentController::viewEcGeojson()` e `EcPoiService::getAssociatedEcPois()` — uscite
  considerate "tecniche"/di servizio piuttosto che di presentazione, lasciate volutamente invariate
  in questo ciclo.

Un job interno che rilegge e risalva `properties` (i job di sync di oc:8487, il resync di oc:8588)
non deve mai passare dal filtro: `applyTaxonomyWhereDisplay()` è pensato solo per una risposta in
uscita, non per un valore che poi viene riscritto — altrimenti una categoria nascosta oggi
sparirebbe dal dato salvato, non solo dalla vista.

### Riallineamento dei dati esistenti (`wm:resync-taxonomy-where`)

Il comando `wm:resync-taxonomy-where --app=<id>` (`WmResyncTaxonomyWhereCommand`,
`TaxonomyWhereResyncService`) riporta alla forma vecchia i record ancora nella forma oc:8487 o in
quella più vecchia. È conservativo: calcola un valore via SQL locale (`GeometryComputationService`)
e, solo se vuoto, ricade su osmfeatures; se anche questo non produce nulla il record non viene
toccato (loggato come warning). Alla prima scrittura salva il valore precedente in
`properties->_taxonomy_where_backup` (una tantum: le esecuzioni successive non lo sovrascrivono più).

In un consumer la cui `taxonomy_wheres` contiene solo where importate da GeoHub (senza
`admin_level`), il calcolo locale trova comunque un'intersezione geometrica e vince quindi su
osmfeatures: il risultato scritto sostituisce le voci precedenti con voci solo-sorgente (categoria
`"source:<valore>"`, non `"<N>"` — vedi sopra); il valore precedente resta comunque recuperabile in
`properties->_taxonomy_where_backup`.

L'opzione `--queue=<nome>` (default `default`) sceglie la coda su cui accodare sia il batch sia il
job di rigenerazione finale — **deve essere una coda letta da un worker Horizon**: accodare su una
coda senza supervisor mette il comando in stato di "accodato" senza che nulla giri mai (bug
corretto in questo ciclo: il default precedente era una coda, `geometric-computations`, non letta
da nessun supervisor Horizon di camminiditalia).

**Avviso e conferma per le where senza geometria** (fix round 1, review): una `taxonomy_where` con
`geometry IS NULL` non può intersecare nulla (`ST_Intersects` la esclude sempre), tipicamente
perché i job di dettaglio dell'import (`FetchTaxonomyWhereGeometryJob`) sono ancora in coda. Ad
ogni esecuzione **senza** `--dry-run` e **senza** `--outputs-only`, il comando conta quante
`taxonomy_wheres` sono in questo stato (`TaxonomyWhereResyncService::countWheresMissingGeometry()`,
globale, non per App — la tabella non ha `app_id`); se il numero è maggiore di zero stampa un
avviso e chiede conferma (default: **no**) prima di procedere, perché il riallineamento
troverebbe solo una parte delle località e salverebbe nel backup (`_taxonomy_where_backup`) un
valore parziale. Se la conferma viene rifiutata (anche automaticamente, sotto `--no-interaction`,
dove `confirm()` ritorna il default senza chiedere nulla) il comando non accoda nulla e **fallisce**
(`exit code` diverso da 0), con un messaggio che indica di rilanciare con `--force`. L'opzione
`--force` salta la domanda (uso non interattivo, es. una pipeline di deploy) ma non l'avviso.
Con `--dry-run` lo stesso conteggio viene solo stampato ("Where senza geometria: N."), senza
chiedere conferma; con `--outputs-only` non c'è né conteggio né avviso (quel ramo non ricalcola
nulla).

La regenerazione è in due fasi, non contestuale al ricalcolo riga per riga:

1. un batch di `ConservativeSyncTaxonomyWhereJob` (uno per record da riallineare, sulla coda scelta
   con `--queue`);
2. alla `finally()` del batch (quindi anche se qualche job del batch fallisce, grazie ad
   `allowFailures()`), un singolo `RegenerateTaxonomyWhereOutputsJob` per l'App (stessa coda), che
   rigenera i json statici delle tracce, il documento Elasticsearch e `pois.geojson` — non richiama
   osmfeatures e non tocca il dato salvato. Con `--outputs-only` il ricalcolo salta e va in coda
   solo questo job.

`RegenerateTaxonomyWhereOutputsJob` è `ShouldBeUniqueUntilProcessing` (il lock si libera quando il
job inizia, non quando finisce, così un dispatch arrivato durante l'esecuzione non viene scartato)
con lock su Redis (`uniqueVia()` forza `Cache::store('redis')`, non lo store configurato di
default): con `CACHE_STORE=database` il lock fallirebbe con `25P02` su Postgres perché il job viene
dispatchato da dentro la transazione che salva l'App in Nova (`AppObserver::saved()`), stesso
pattern già noto di `BuildAppPoisGeojsonJob`. Il job chiama anche `$this->afterCommit()` nel
costruttore (non una property tipizzata: il trait `Queueable` già usato dalla classe dichiara
`$afterCommit` senza tipo, e una ridichiarazione tipizzata è un conflitto fatale in composizione —
stesso pattern già in `UpdateLayerGeometryJob`): parte solo
dopo il commit di quella transazione, altrimenti (in camminiditalia
`queue.connections.redis.after_commit=false` e `scout.queue=false`) un worker abbastanza veloce
può eseguirlo prima del commit e reindicizzare con l'opzione "Località mostrate" ancora vecchia.

Ordine di rilascio in produzione:

1. `pg_dump` di `ec_tracks`, `ec_pois`, `ugc_pois`, `ugc_tracks` (backup pre-riallineamento);
2. deploy del fix;
3. riavvio di Horizon (i job in coda vanno rieseguiti con il codice nuovo, non con quello vecchio
   ancora in memoria nei worker);
4. `wm:resync-taxonomy-where --app=<id> --dry-run` (fotografia di quanti record sono in ciascun
   formato, prima di scrivere — mostra anche quante `taxonomy_wheres` sono senza geometria);
5. se il conteggio "Where senza geometria" non è zero, attendere che i job di dettaglio
   dell'import (`FetchTaxonomyWhereGeometryJob`) finiscano di scaricarla, ripetendo il
   `--dry-run` finché non arriva a zero — lanciare il comando con where ancora prive di geometria
   (accettando l'avviso, o forzando con `--force`) riallinea solo una parte delle località e
   salva nel backup un valore parziale;
6. lo stesso comando senza `--dry-run`, con `--queue=<coda letta da un worker Horizon>` se il
   default non va bene per il consumer (scrive, dispatcha il batch; se ci sono ancora where senza
   geometria chiede conferma — vedi sopra);
7. impostazione dell'opzione "Località mostrate" in Nova, se il cliente la vuole diversa dal
   default "mostra tutto";
8. di nuovo `--dry-run`, per vedere cosa resta non riallineato (record senza geometria, senza
   risultato SQL né osmfeatures — questi restano loggati ma non bloccano il resto).

### Segnaposto nel nome delle where osmfeatures (fix in `ImportTaxonomyWhere`, oc:8588)

L'import da sorgente OSMFeatures (`ImportTaxonomyWhere::handleOsmfeatures()`) salvava a volte l'id
OSM (es. `R41895`) al posto del nome, sotto la traduzione `en` di `taxonomy_wheres.name` — un
campo Spatie `HasTranslations` (colonna `text`, JSON serializzato). Causa: `'name' => $item['name']
?? $item['id']`, dove `$item['name']` è già la stringa risolta da `OsmfeaturesClient
::getAdminAreasIds()` (preferenza `it` poi `en`, altrimenti `null` — mai una mappa di traduzioni)
assegnata come stringa semplice a un campo tradotto. Spatie la salva sotto la *locale corrente
dell'applicazione* (`app.locale`, `en` sia in sviluppo sia in test per questo progetto), non sotto
la lingua del contenuto: il nome italiano finiva quindi etichettato come inglese, e quando
`$item['name']` era `null` (nessuna traduzione `it`/`en` affidabile) ci finiva l'id OSM stesso.

Fix: il nome, quando presente, va scritto esplicitamente sotto `it` (`['it' => $name]`, mai una
stringa semplice — i dati sono italiani, indipendentemente da `app.locale`); quando assente non si
scrive mai l'id al suo posto, si lascia il nome vuoto (`[]` in creazione, nessuna chiave `name`
nell'update, per non toccare un nome già buono su un record esistente) — sarà
`FetchTaxonomyWhereGeometryJob::syncNameFromDetail()` a valorizzarlo scaricando il dettaglio
OSMFeatures. Lo stesso job, nello stesso ciclo, è stato corretto per sostituire il segnaposto su
**ogni** traduzione il cui valore coincide con l'id OSM (prima solo `it`), coerente col fatto che
ora il segnaposto può in teoria comparire su qualunque lingua.

**Per correggere i dati già importati con il bug non esiste un comando dedicato**: l'unica via è
rifare l'import da OSMFeatures. L'import salta però le where già presenti il cui
`properties->source_updated_at` è già maggiore o uguale a quello restituito dall'API
(`ImportTaxonomyWhere::handleOsmfeatures()`, righe ~102-113, logica non modificata da questo fix):
rilanciarlo su un DB che contiene ancora le where con il segnaposto **non corregge i nomi**, le
where vengono semplicemente saltate. Per ottenere la correzione bisogna partire da un DB in cui
quelle where non esistono ancora (restore di un backup precedente al primo import sbagliato,
oppure eliminazione mirata delle righe interessate prima di rilanciare l'import).

### Traduzioni del nome nelle 5 lingue della piattaforma (oc:8588, richiesta successiva del dev)

Il dettaglio OSMFeatures (`GET /api/v2/features/admin-areas/{id}`) espone `properties.name` (nome
base) e `properties.osm_tags`, che contiene il tag `name` e le traduzioni `name:<lang>` — fino a
~25 lingue per un'area OSM completa (es. una regione), spesso una sola (`name`, senza traduzioni)
per un comune. Le lingue della piattaforma sono `it`, `en`, `de`, `fr`, `es` (le stesse di
`resources/lang/*.json`).

`OsmfeaturesClient::getAdminAreaDetail()` restituisce, oltre a `name` (invariato per
compatibilità, ma non ripiega più sull'id OSM quando manca — vedi sopra), una nuova chiave
`names`: mappa `lang => nome` con **tutte** le traduzioni `name:<lang>` trovate in `osm_tags`,
senza filtro. Il filtro sulle sole 5 lingue della piattaforma è responsabilità del chiamante.

`FetchTaxonomyWhereGeometryJob::syncNameFromDetail()` applica questa regola per ciascuna delle 5
lingue della piattaforma:

1. usa `names[<lang>]` (da `osm_tags['name:<lang>']`) se presente e non vuota;
2. per `it`, se manca `names['it']`, usa il nome base (`detail['name']`);
3. le lingue della piattaforma ancora senza nome ricevono il nome `it` già risolto ai punti 1-2, o
   — se anche `it` manca — la prima traduzione risolta tra quelle disponibili, nell'ordine di
   `PLATFORM_LANGUAGES` (`it, en, de, fr, es`): se solo `name:de` e `name:fr` sono presenti (niente
   `it`, niente nome base), `de` vince come fallback perché precede `fr` in quell'ordine — non è
   una scelta arbitraria tra le traduzioni disponibili, è deterministica sull'ordine della
   costante;
4. se il dettaglio non ha nessun nome (né base né traduzioni), il nome esistente **non viene
   toccato** — non si scrive mai l'id OSM come nome, regola invariata.

Il risultato **sostituisce sempre** le 5 traduzioni della piattaforma (il dettaglio è la fonte più
completa), anche quando la where aveva già un nome "affidabile" — la guardia di uscita anticipata
della versione precedente (`hasReliableName && !isPlaceholder → return`) è stata rimossa. Una
lingua già presente fuori dalle 5 della piattaforma (es. `sc` da GeoHub) non viene toccata: si
scrive solo con `setTranslation()` sui singoli codici lingua della piattaforma, le altre chiavi
dell'array di traduzioni restano intatte. Le lingue OSM fuori dalle 5 (es. `ko`, `ja`) non vengono
mai salvate.

**La sincronizzazione del nome si applica solo a record `osmfeatures`** (fix round 1, review):
`syncNameFromDetail()` sincronizza le 5 lingue solo quando `properties['source']` della where è
`'osmfeatures'` oppure assente/vuoto (record legacy pre-`b6ddbda6`/oc:8469, quando né l'import
osmfeatures né il modello stampavano ancora `source` — la migration di backfill dell'identifier di
quel fix usa esplicitamente `COALESCE(properties->>'source', '')`, prova diretta che in produzione
esistono/esistevano righe osmfeatures senza `source`). Un record con `source` esplicito e diverso
(es. `'geohub'`) non viene toccato nel nome anche se ha ancora un `osmfeatures_id`: può capitare
perché `HasTaxonomyWhereImportHelpers::executeGeohubImport()` trova un record esistente per
`identifier`/`geohub_id` e fa `array_merge($existing->properties ?? [], $properties)` senza
rimuovere una eventuale chiave `osmfeatures_id` residua di un import osmfeatures precedente sullo
stesso record — in quel caso il nome è curato da GeoHub, non dal dettaglio OSMFeatures. Questo
scenario è raggiungibile anche perché `RetryTaxonomyWhereGeometryFetch::dispatchGeometryJob()`
dispatcha `FetchTaxonomyWhereGeometryJob` per qualunque record con `getOsmfeaturesId()` non vuoto,
a prescindere dalla sorgente — quella action non è stata toccata in questo fix, solo il job.
L'aggiornamento della geometria non è condizionato da questa guardia: continua per ogni sorgente,
esattamente come prima.

## Notes

- La colonna `geometry` è di tipo `geography(multipolygon)` PostGIS — può essere null se il job non è ancora stato eseguito o è fallito.
- `TaxonomyWhereSourceFilter` non include sorgenti custom aggiunte a livello di progetto: i progetti che aggiungono sorgenti devono sovrascrivere `filters()` nella risorsa Nova app-level.
- Il modello supporta l'override della classe `EcTrack` tramite `config('wm-package.ec_track_model')`.
