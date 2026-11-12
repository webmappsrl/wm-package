# Export GPX e KML di tracce e POI

## Come funziona oggi

Rotte in `routes/api.php` (`/api/ec/{track|poi}/[download/]{id}.{gpx|kml}`), documento costruito da
`GeometryComputationService::getModelGeometryAsGpx()` / `getModelGeometryAsKml()`. Vale quanto segue:

- **Quota:** una Z che, arrotondata a 2 decimali, vale 0 conta come quota mancante: niente `<ele>`
  nel GPX, solo `lon,lat` nel KML. Su UAT forestas, secondo i conteggi della review di oc:8953
  (10/11/2026), 389 tracce su 769 hanno tutte le Z a 0, e tutti i POI: quei GPX escono senza
  `<ele>`, ed è voluto.
- **Contenuto senza geometria:** i due metodi restituiscono `null` e le rotte rispondono
  `404 {"code": 404, "error": "Geometry not found"}`.
- **Nome del file** (rotte `download/`, compreso `download/{id}.geojson`): `filename` in ASCII e
  `filename*` in UTF-8. `/` e `\` diventano `-`; se la traslitterazione ASCII resta vuota,
  `filename` usa l'id.
- **Chi le chiama:** secondo la review di oc:8953 nessun frontend Webmapp; il componente
  `wm-feature-useful-urls` genera GPX e KML nel browser, senza passare dal backend.

## Perché così

- **Niente gisconverter per l'export** (oc:8953): `ndamiens/gisconverter` non implementa `toGPX()`
  per `MultiLineString` (lancia `UnimplementedMethod`), e la colonna delle tracce è
  `multiLineStringz` per migration; per le geometrie che gestisce restituisce solo un frammento,
  senza `<gpx>` o `<kml>` intorno, e scarta la Z. Resta usato per la conversione inversa, da GPX e
  KML a GeoJSON.
- **Z = 0 come quota mancante** (oc:8953): scriverla produrrebbe profili con salti fino al livello
  del mare. Un punto davvero a 0 m perde la quota, caso raro e innocuo.
- **404 da un controllo sul `null`, non da un'eccezione** (oc:8953): il `try` delle azioni avvolge
  solo `_getEcModelFromType()`, e il suo `catch` usa `$e->getCode()` come stato HTTP, che con un
  codice 0 o stringa produrrebbe un altro 500.
- **`HeaderUtils::makeDisposition()` per il nome del file** (oc:8953): tiene integri accenti e
  lineette in `filename*`. Lancia un'eccezione se uno dei due nomi contiene `/` o `\`, o se il
  fallback contiene caratteri non ASCII o `%`: per questo il nome si ripulisce prima.

## Come ci siamo arrivati

- **Export con gisconverter e macro mai registrate** (fino a oc:8953, superato): le rotte GPX e
  KML hanno sempre risposto 500, per tre cause sovrapposte — le macro `gpx` e `kml` non esistevano,
  gisconverter non produceva il GPX delle multilinee, e `createDownloadFileName()` leggeva
  `Content-Disposition` anche sulle rotte che non lo passano. Nessuno se n'era accorto perché
  nessun client le chiamava.
