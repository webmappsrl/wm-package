# Ricerca dei POI nell'app

## Come funziona oggi

L'app (wm-core) cerca i POI nel file `pois.geojson` dell'app, senza Elastic: per ogni POI unisce
`JSON.stringify(properties.name)` e `properties.searchable` e cerca il testo digitato come
sottostringa, senza distinguere maiuscole e minuscole (`store/features/ec/utils.ts`, filtro
`filterFeaturesByInputTyped`). Un POI senza i due campi non si trova.

I due campi li aggiunge `App::getAllPoisGeojson()` mentre genera il file, e non sono salvati:

- **`name`**: `getTranslations('name')` della colonna, cioè l'oggetto con le sole lingue
  valorizzate.
- **`searchable`**: `EcPoi::getSearchableString($app)`, calcolato per l'app che genera il file.
  `poi_searchables` dell'app («POI Search In» in Nova) sceglie i campi fra `name`, `description`,
  `excerpt`, `osmid`, `taxonomyPoiTypes`; una lista vuota, o un valore che non è una lista di
  stringhe, vuol dire tutti. Descrizione ed estratto si leggono da `properties`. Ogni campo è
  scritto nel formato di Geohub (`{it:Testo,en:Text}`, senza virgolette), con gli accenti leggibili,
  senza tag HTML e senza a capo; i campi vuoti non aggiungono nulla. Se il calcolo fallisce il POI
  resta nel file con `searchable` vuoto e un warning nel log (`App::searchableForPoi()`).

Il file si rigenera con `BuildAppPoisGeojsonJob` (unico per app): al salvataggio e alla
cancellazione di un POI (`EcPoiObserver`), al cambio dei tipi di un POI
(`TaxonomyPoiTypeablesObserver`), al cambio di `poi_searchables` (`AppObserver`), a fine import da
Geohub dei POI e delle immagini (`ImportAppJob::dispatchAfterEcImportBatch()`), dopo il ricalcolo
delle località (`RegenerateTaxonomyWhereOutputsJob`), con l'action Nova e con il comando
`wm:build-pois-geojson`. Finché non si rigenera, l'API serve il file già salvato sullo storage.
Il job tiene il lock di unicità fino a fine esecuzione: un build della stessa app lanciato mentre
ne gira un altro viene scartato senza errori. A fine import, se fallisce il ricalcolo delle
località il file si rigenera comunque; se fallisce il build, non ne parte un secondo.

I temi dei POI non sono fra i campi di ricerca, come su Geohub.

## Perché così

- **`searchable` calcolato nel file e non salvato sul POI** (oc:8677): dipende da `poi_searchables`
  dell'app, quindi resta allineato alla configurazione dell'app e non dipende da come il POI è stato
  salvato (l'import salva senza observer). È lo schema di Geohub (`EcPoi::getGeojson()`).
- **Lista vuota = tutti i campi, descrizione compresa** (oc:8677): è ciò che fa Geohub, e un import
  deve riprodurre la ricerca che il cliente ha. Il prezzo è il peso del file, che contiene la
  descrizione due volte (in `properties` e in `searchable`). Misure del 15/10/2026: nell'app 3 di
  maphub dev le descrizioni pesano circa 1,7 MB su circa 2 MB di `properties` dei 211 POI; il
  `pois.geojson` di Geohub dell'app 28, che ha già `searchable` con la descrizione, pesa 4,3 MB.
  Chi vuole la ricerca solo per nome seleziona `name` in «POI Search In».
- **`name` dalla colonna e non da `properties.name`** (oc:8677): `properties.name` manca sui POI
  salvati senza observer, mentre la colonna c'è sempre.

## Come ci siamo arrivati

- **`getSearchableString($app_id)` di Geohub, portato in wm-package e mai chiamato** (oc:8677,
  superato): andava in errore con qualsiasi app, perché `App` era la facade e non il modello;
  leggeva descrizione ed estratto da colonne che in wm-package stanno in `properties`; scriveva gli
  accenti come escape (`Localit\u00e0`), che la ricerca di «Località» non trova.
- **Ricerca solo per nome e tipo di POI con lista vuota** (oc:8677, scartata): avrebbe tenuto
  leggero il file, ma la ricerca sarebbe stata peggiore di quella che il cliente ha su Geohub.
