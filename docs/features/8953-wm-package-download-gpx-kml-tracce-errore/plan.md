> Ticket: oc:8953

# Piano — il download GPX e KML delle tracce risponde sempre con un errore

Tutto il codice è nel repo `wm-package`, branch `feature/oc-8953-wm-package-download-gpx-kml-tracce-errore`
(PR draft #324 verso `develop`). Forestas aggiorna il puntatore del submodule solo dopo il merge nel
package.

I commit sono istruzioni per la dev: Claude non esegue `git commit` né `git push`.

**Test.** La suite del package usa il database `wm_package` (`phpunit.xml.dist`, righe 27-32): prima
di lanciarla si verifica che non esista un `phpunit.xml` locale nel package e che il database
`wm_package` esista nel Postgres dello shard. Si lancia dal container di forestas:

```bash
docker exec -it php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/Api/EcDownloadEndpointTest.php"
```

Se `wm-package/vendor` non esiste nel container, il percorso si ricava al momento e si chiede alla dev
prima di procedere.

---

## Task 1 — Test che riproducono il difetto (rossi)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-test)

**File:** `tests/Feature/Api/EcDownloadEndpointTest.php` (nuovo), `tests/fixtures/gpx/gpx.xsd` (nuovo).

1. Scaricare lo schema GPX 1.1 da `https://www.topografix.com/GPX/1/1/gpx.xsd` in
   `tests/fixtures/gpx/gpx.xsd` (cartella in minuscolo: nel container Linux `Fixtures` non si trova).
2. Helper nel file di test che creano i contenuti con geometria esplicita, passando la geometria da
   SQL (`DB::raw("ST_GeomFromText('…', 4326)")`, come in
   `LayerFeatureCollectionMapUserPresenceTest.php:46`) e `'osmid' => null`:
   - traccia `MULTILINESTRING Z ((10 43 100, 10.001 43.001 0, 10.002 43.002 120), (10.01 43.01 200, 10.011 43.011 210))`:
     due linee, un punto con Z = 0;
   - POI `POINT Z (9.5 40.1 350)` e POI `POINT Z (9.5 40.1 0)`;
   - nome `Via Don Giordi/Via della Musica – 100% & co` sul contenuto usato per il nome del file.
3. Casi, con `dataset` per traccia/POI e `download/`/visualizzazione dove la forma è la stessa:
   - le otto rotte GPX e KML rispondono 200 con `Content-Type` `application/gpx+xml` /
     `application/vnd.google-earth.kml+xml`;
   - GPX traccia: `DOMDocument` carica il corpo, radice `gpx` nel namespace GPX 1.1, un `<trk>`
     con `<name>`, due `<trkseg>`, 3 + 2 `<trkpt>`, `<ele>` sui punti con Z ≠ 0 e assente sul
     punto con Z = 0; `schemaValidate()` contro il fixture è `true`;
   - GPX POI: un `<wpt>` con `<ele>` e `<name>` in quest'ordine, e senza `<ele>` per il POI a Z = 0;
     valida contro lo schema;
   - KML traccia: radice `kml` nel namespace 2.2, `Document/name`, `Placemark/name`,
     `MultiGeometry` con due `LineString`; le `coordinates` hanno `lon,lat,ele` e `lon,lat` sul punto a 0;
   - KML POI: `Placemark/Point/coordinates` = `9.5,40.1,350`;
   - lat, lon ed ele non contengono `E` (notazione esponenziale);
   - `&` nel nome non rompe il documento (il caricamento con `DOMDocument` riesce e `<name>`
     restituisce il testo originale);
   - rotte `download/`: `Content-Disposition` contiene `attachment`, un `filename="…"` solo ASCII,
     senza `/`, `\` e `%`, e un `filename*=utf-8''…` con il nome codificato;
   - rotte senza `download/`: nessun `Content-Disposition`, e nessun errore con un nome presente;
   - nome vuoto: il file si chiama `{id}.gpx` / `{id}.kml`;
   - contenuto senza geometria: si sostituisce `GeometryComputationService` nel container con un
     mock parziale (`Mockery::mock(GeometryComputationService::class)->makePartial()`, con
     `getModelGeometryAsGeojson()` che restituisce `null`, registrato con `app()->instance()`),
     poi si **chiama la rotta**: stato 404 e corpo `{"code": 404, "error": "Geometry not found"}`,
     su GPX e KML di traccia e POI;
   - id inesistente: 404 come oggi;
   - non-regressione: `download/{id}.geojson` di traccia e POI risponde 200 con
     `Content-Disposition` costruito come sopra.
4. Lanciare il file: devono fallire tutti i casi GPX/KML con 500, e passare solo la non-regressione
   GeoJSON e l'id inesistente. Se falliscono per un motivo diverso (factory, migration, `TestCase`),
   si sistema il test prima di andare avanti.

Commit suggerito: `test(oc:8953): test delle rotte GPX e KML di tracce e POI`

## Task 2 — Costruzione del documento GPX e KML

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-costruzione-del-documento)

**File:** `src/Services/GeometryComputationService.php`.

1. `getModelGeometryAsGpx(GeometryModel $model): ?string` e
   `getModelGeometryAsKml(GeometryModel $model): ?string`: leggono `getModelGeometryAsGeojson()`
   e restituiscono `null` se è `null` (oggi il KML restituisce `'{}'`).
2. Decodificano il GeoJSON e smistano sul `type`: `MultiLineString`, `LineString` (trattata come
   multilinea di una linea) e `Point`. Un altro tipo lancia `InvalidArgumentException`, che arriva
   come 500 con la causa nel log.
3. Il nome viene da `$model->name` (oggi il KML legge per errore `$this->name`, cioè il service).
4. Il documento si costruisce con `XMLWriter` (estensione `xmlwriter`, già richiesta da Laravel):
   la codifica dei caratteri speciali nel nome la fa lui.
   - GPX: dichiarazione XML, `<gpx version="1.1" creator="Webmapp" xmlns="http://www.topografix.com/GPX/1/1">`;
     traccia → `<trk><name/><trkseg><trkpt lat lon><ele/></trkpt>…</trkseg>…</trk>`;
     POI → `<wpt lat lon><ele/><name/></wpt>`.
   - KML: dichiarazione XML, `<kml xmlns="http://www.opengis.net/kml/2.2"><Document><name/><Placemark><name/>`;
     traccia → `<MultiGeometry><LineString><coordinates>…</coordinates></LineString>…</MultiGeometry>`;
     POI → `<Point><coordinates>…</coordinates></Point>`.
5. Un metodo privato formatta un numero in decimale fisso senza esponente e senza zeri finali
   (`rtrim(rtrim(number_format($n, 7, '.', ''), '0'), '.')`; 7 decimali per lat/lon, 2 per ele).
6. Z assente o uguale a 0 → niente `<ele>` nel GPX, solo `lon,lat` nel KML.
7. Rimuovere l'uso di `Gisconverter` da questi due metodi, e i blocchi commentati `ST_AsKML` /
   `ST_AsGpx`. Se `Gisconverter` non resta usato altrove nel file, togliere anche l'import.

Commit suggerito: `fix(oc:8953): GPX e KML completi per tracce multilinea e POI, con la quota`

## Task 3 — Macro di risposta

**File:** `src/WmPackageServiceProvider.php`.

1. In `boot()` (o nel metodo `packageBooted()` se è lì che il provider registra le altre
   personalizzazioni: si sceglie leggendo il file) registrare con `Response::macro()`
   (`Illuminate\Support\Facades\Response`):
   - `gpx($content, $status = 200, array $headers = [])` → `response($content, $status, $headers)`
     con `Content-Type: application/gpx+xml`, se non già presente negli header;
   - `kml(...)` uguale con `application/vnd.google-earth.kml+xml`.

Commit suggerito: insieme al task 4.

## Task 4 — Controller: 404, `Content-Type` e nome del file

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-controller)

**File:** `src/Http/Controllers/Api/EditorialContentController.php`.

1. `viewEcGpx()` / `viewEcKml()`: dopo `getGpx()` / `getKml()`, se il contenuto è `null` rispondere
   `response()->json(['code' => 404, 'error' => 'Geometry not found'], 404)`. Il `try`/`catch`
   esistente non cambia.
2. `downloadEcGpx()` / `downloadEcKml()`: non impostano più `Content-Type: application/xml`; il
   tipo lo mette la macro.
3. `createDownloadFileName($ec, $headers)`:
   - se `$headers` non ha `Content-Disposition`, restituisce gli header invariati;
   - l'estensione si legge dall'header attuale, come oggi;
   - nome base: `$ec->ref` se presente (comportamento attuale lasciato com'è, vedi Out of scope),
     altrimenti `$ec->name`, altrimenti l'id;
   - nel nome UTF-8 si sostituiscono `/` e `\` con `-`; il fallback è `Str::ascii()` dello stesso
     nome, con `%` rimosso e `"` sostituito;
   - l'header si costruisce con `HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, "$name.$ext", "$fallback.$ext")`
     (`Symfony\Component\HttpFoundation\HeaderUtils`).
4. Lanciare il file di test del task 1: tutti verdi.

Commit suggerito: `fix(oc:8953): macro gpx e kml, 404 senza geometria e nome del file scaricato`

## Task 5 — Controlli

1. `composer analyse` dal container: nessun errore nuovo sui file toccati (baseline in
   `phpstan-baseline.neon`).
2. `composer format`, poi `git status`: si tengono solo i file del lavoro, gli altri si scartano
   (Pint riformatta tutto il repo).
3. Rilanciare il file di test.
4. Prova in locale su forestas (DB locale, sola lettura):
   `curl -i http://localhost:<porta>/api/ec/track/download/<id>.gpx` su una traccia con quote,
   una con tutte le Z a 0, una con più linee, cercate con una query; stesso per `.kml` e per un POI.

## Task 6 — Documentazione e chiusura

1. `notes.md` del cantiere (deviazioni, decisioni, follow-up), anche solo con «Nessuna deviazione».
2. Pagina di conoscenza: valutare con la dev se serve una pagina su «export GPX/KML dei contenuti»
   (perché niente gisconverter, Z = 0 come quota mancante) o se basta il cantiere.
3. Dopo il merge nel package: prova a mano su dev di forestas e su cammini con le tre tracce tipo
   dell'overview, apertura del GPX in OsmAnd o gpx.studio e del KML in Google Earth web; poi bump del
   puntatore in forestas (seguendo `docs/howto/migration-wm-package.md` del package).

Commit suggerito: `docs(oc:8953): note del fix GPX e KML`
