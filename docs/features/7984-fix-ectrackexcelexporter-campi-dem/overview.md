> Ticket: oc:7984

# Fix EcTrackExcelExporter — usa classifyField per i campi DEM

## Cosa cambia

`EcTrackExcelExporter` smetterà di leggere i campi DEM direttamente da `properties.*` e userà `classifyField` (trait `HasDemClassification`) per determinare il valore corretto da esportare, seguendo la stessa logica di priorità usata da Nova e da `EcTrackResource`.

## Perché

L'export Excel ("Scarica Tracce") mostrava valori stantii letti da `properties.ascent` ecc., che non riflettono la cascata MANUAL → OSM → DEM. Nova invece usa `classifyField` e mostra il valore corretto. La divergenza confonde gli utenti che confrontano Nova con l'Excel.

## Requisiti

- [ ] I campi `ascent`, `descent`, `ele_min`, `ele_max`, `ele_from`, `ele_to`, `distance`, `duration_forward`, `duration_backward` nel metodo `map()` di `EcTrackExcelExporter` usano `$track->classifyField($track, '<field>')['currentValue']` invece di `data_get($track, 'properties.<field>')`
- [ ] Il comportamento per valori null (caso EMPTY) rimane invariato: la cella Excel mostra stringa vuota
- [ ] Nessun import aggiuntivo nell'Exporter: `EcTrack` ha già `use HasDemClassification`

## Rischi

- **Cambio di valore in export esistenti**: le tracce con `manual_data` o `osm_data` valorizzati mostreranno valori diversi rispetto ai precedenti export. È il comportamento *corretto*, ma va comunicato agli utenti.
- **$track non è EcTrack**: se l'Exporter venisse usato con un Model che non ha il trait, la chiamata a `classifyField` fallirebbe. Attualmente l'unico chiamante è `DownloadEcTrackAction` che passa sempre `EcTrack` — rischio contenuto.

## Out of scope

- Il campo `distance_comp` non è un campo DEM e non viene modificato
- Non si scrivono nuovi test (la logica di `classifyField` è già coperta da `HasDemClassificationTest`)
- Nessuna modifica al repo principale `camminiditalia`

## Moduli toccati

| File | Repo | Tipo modifica |
|---|---|---|
| `src/Exporters/EcTrackExcelExporter.php` | `wm-package` | Modifica 9 letture dirette → `classifyField` |
