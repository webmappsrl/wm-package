> Ticket: oc:8679

# Notes — L'import da Geohub copia i link di POI e tracce come testo

## Deviazioni dal piano

Nessuna deviazione nel codice. Una sola nella verifica, vedi sotto.

## Divergenze dal piano, task per task

### Task 5 — Verifiche

Il test Pest `tests/Unit/Services/Import/DataTransformerRelatedUrlTest.php` non si può lanciare da
maphub: il `TestCase` del package non viene caricato (`Target class [config] does not exist`, vedi
`docs/knowledge/testare-il-package.md`), e la CI del package non arriva ai test. Gli stessi 10 casi
sono stati verificati con uno script PHP temporaneo nel container `php-maphub`, il mapping con
`config()` da `tinker`, e il percorso reale con `GeohubImportService::transformProperties()` su
righe finte (link vero → oggetto, `"false"` e `"[]"` → `null`, `audio` invariato). Il test resta
nel package per quando la CI tornerà a girare, ma **non è mai stato eseguito con Pest**: né rosso
né verde.

Pint e PHPStan (livello 5, `phpstan.neon.dist` del package) sono passati sui file toccati, dal
container `php-maphub`.

`EcPoiRowProcessorTest` non è stato lanciato: il package `TestCase` usa `RefreshDatabase`, e
l'import Excel è cambiato solo in un commento.

## Bug trovati

- `jsonToArray` e `nullableJsonToArray` vanno in TypeError quando il JSON decodificato non è un
  array (`array_filter(json_decode("false"))`). Non corretti qui: vedi Follow-up.

## Decisioni

- Nessun tag aggiunto al ticket oltre a quelli già presenti (scelta della dev).
- La ricerca nelle trascrizioni delle call è fallita (cartella Drive non accessibile all'account
  collegato): il dialogo è proseguito senza.
- Scartata la correzione di `nullableJsonToArray` (decisa all'inizio): i link usano un convertitore
  dedicato, e modificare codice che funziona non serve a questo ticket.
- `relatedUrlToArray` copia la logica di `EcPoiRowProcessor::normalizeRelatedUrlToAssoc` invece di
  spostarla: il metodo Excel è privato e coperto da un solo caso di test, e spostarlo avrebbe
  modificato un import che funziona. Le due copie si rimandano a vicenda con un commento.
- Il re-import sovrascrive `properties` per intero: non dipende da questo fix, va scritto come
  avviso al tester.
- Quando non ci sono link, `related_url` resta in `properties` con valore `null` (la chiave non
  sparisce): `transformMappedFields` assegna sempre il campo (`GeohubImportService.php:801`).

## Review (06/10/2026, wm-review-ticket)

Approvato con riserve, nessun bloccante. Cleanup lasciati aperti:

- l'export Excel delle tracce (`EcTrackExcelExporter.php:263`) ora scrive `related_url` con gli
  slash escapati (`https:\/\/…`), perché `json_encode` è senza `JSON_UNESCAPED_SLASHES`;
- casi non contabili senza il database di Geohub, identici nell'import Excel: lista JSON
  (`'["https://a.it"]'`) e stringa JSON (`'"https://a.it"'`) diventano `null`, `{"Sito":""}`
  resta un link vuoto. Da coprire nel ticket di unificazione.

## Follow-up

- Unificare `DataTransformer::relatedUrlToArray` e `EcPoiRowProcessor::normalizeRelatedUrlToAssoc`,
  dopo aver aggiunto test all'import Excel.
- Rendere robusti `jsonToArray` e `nullableJsonToArray` sui JSON che non sono array.
- Verificare gli altri campi json delle tracce copiati grezzi (`slope`, `mbtiles`, `activities`,
  `themes`, `searchable`, `dem_data`, `osm_data`, `manual_data`).
- Re-import di Itinera Romanica PLUS su Maphub dev, da concordare con il tester (Task 8), dopo il
  riavvio dei worker Horizon.
