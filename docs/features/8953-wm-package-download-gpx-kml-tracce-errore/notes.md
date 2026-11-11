> Ticket: oc:8953

# Notes — il download GPX e KML delle tracce risponde sempre con un errore

## Divergenze dal piano, task per task

### Task 1: test

- La terza quota della prima linea della traccia di test è 120.5 invece di 120, così il test copre
  anche i decimali di `<ele>`.
- Dopo la review interna i test sono passati da 39 a 47: notazione esponenziale (POI con longitudine
  `0.00001`), KML del POI con Z = 0 e con Z = 0.004, nome vuoto sulle quattro rotte `download/`,
  coordinate della seconda `LineString`, nome non traslitterabile, caratteri di controllo nel nome.
- `makeDisposition()` mette le virgolette attorno a `filename` solo se servono (`filename=1.gpx`): i
  test che controllano il nome ASCII le accettano opzionali.

### Task 2: costruzione del documento

- Il piano prevedeva un solo metodo privato, per la formattazione dei numeri. Ne sono serviti sei
  (`decodeModelGeometry`, `exportName`, `writeGpxPoint`, `kmlTuple`, `elevation`,
  `formatCoordinate`), per non ripetere la stessa logica fra GPX e KML.
- `formatCoordinate()` scrive `0` al posto di `-0`.
- La quota si arrotonda a 2 decimali **prima** del controllo sullo 0: una Z come 0.004 sarebbe
  uscita come `<ele>0</ele>` (cleanup 7 della review).
- `exportName()` toglie i caratteri di controllo che XML 1.0 non ammette (cleanup 6).

### Task 4: controller

- Se `Str::ascii()` lascia vuoto il nome ASCII (nome tutto in caratteri non latini), `filename` usa
  l'id del contenuto, per non produrre un file nascosto `.gpx` (cleanup 5). Il `"` nel fallback
  diventa `'`.

## Bug trovati

Nessuno oltre le tre cause dell'overview. Nel codice preesistente il KML leggeva il nome da
`$this->name` (il service) invece che dal modello: corretto nel task 2.

## Decisioni

- **Overview corretta prima del piano.** Su richiesta del reviewer (review del 10/11/2026 su
  `e729ac0f`) le sezioni «Requisiti» e «Rischi» sono state riscritte sul posto, non annotate in
  fondo, perché il piano non era ancora stato scritto: il 404 nasce da un controllo esplicito sul
  `null` e non da un'eccezione, e le cifre vengono da UAT forestas e da cammini invece che dal DB
  locale.
- **`GeometryModel::getGpx()` ora dichiara `?string`**, file non previsto dal piano. Negli shard
  locali nessuno ridefinisce `getGpx()`; osm2cai2 ridefinisce `getKml(): string`
  (`app/Traits/SpatialDataTrait.php:296`), compatibile con il `?string` del package.
- **Review interna con `wm-review-ticket` (10/11/2026): approvato, nessun bloccante.** Applicati i
  cleanup 1, 2, 3, 5, 6, 7 e 9. Gli ipotetici sono stati contati sul DB locale di forestas: 0 quote
  fra 0 e 0.005, 0 geometrie vuote, 0 nomi con caratteri di controllo, 0 nomi senza caratteri
  latini.

## Follow-up

- **Leggibilità** (cleanup 4 e 8 della review, non applicati):
  - nei test le rotte sono ripetute in più dataset;
  - i decimali 7 e 2 vanno in costanti di classe;
  - `formatCoordinate()` andrebbe rinominata `formatDecimal()`, perché formatta anche la quota;
  - il commento di `exportName()` dice «lingua dello shard», ma il nome segue il locale corrente;
  - manca `@throws` su `decodeModelGeometry()`;
  - `$headers` non è inizializzato in `downloadEcGpx()` / `downloadEcKml()`;
  - la condizione `! empty($headers)` in `viewEcGeojson()` è ridondante.
- **Export in una classe dedicata** (cleanup 10): la serializzazione GPX/KML è fatta di funzioni pure
  e starebbe meglio fuori da `GeometryComputationService`, che supera le 1600 righe.
- **`ext-xmlwriter` in `composer.json`** (cleanup 11): oggi la dipendenza è implicita.
- **Macro globali con nomi generici** (cleanup 12): se uno shard registra una macro `gpx` o `kml`,
  vince l'ultima caricata, senza avviso.
- **Tracce molto lunghe:** il documento si costruisce in memoria; la traccia più lunga di forestas
  non è stata misurata.
- **Prova a mano dopo il deploy** su dev di forestas e su cammini, con le tre tracce tipo
  dell'overview; poi bump del puntatore del submodule in forestas.
