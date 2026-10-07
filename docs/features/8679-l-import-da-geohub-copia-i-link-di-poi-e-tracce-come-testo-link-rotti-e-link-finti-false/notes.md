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

---

# Secondo ciclo — 07/10/2026

La review dell'overview del 07/10 (Rubens, nella description del ticket) ha chiesto due correzioni:
una sola funzione in un trait condiviso fra import Geohub ed Excel, e il recupero dei tre formati
di link presenti sui POI di Geohub prod (stringa JSON, lista spezzata, lista WordPress). Allo
scrum del 07/10 si è deciso che, con un problema nell'overview, il codice si rifà: il commit
5a327589 non è stato corretto ma riscritto secondo l'overview rivista.

## Decisioni

- `overview.md` e `plan.md` sono stati corretti nel testo, con una riga in testa che lo dice,
  invece di annotare in fondo: l'overview è il riferimento con cui si rivede il codice, e un corpo
  che dice il contrario del codice tradirebbe chi legge solo quello. Le versioni del primo ciclo
  sono nella storia di git.
- L'import Excel cambia solo quanto viene dal trait: `mergeRelatedUrl` non è stato toccato. Una
  cella con la stringa JSON fra virgolette resta gestita come prima (split sulle virgole): quei
  formati vengono dal database di Geohub, non da un foglio Excel.
- Nessun requisito nuovo sui test oltre a quelli della review (scelta della dev).
- Tag del ticket non rivisti in questo ciclo (rinviati dalla dev).
- La cartella Drive delle call risulta vuota all'account collegato: le trascrizioni del 01, 06 e
  07/10 sono state trovate cercando su tutto Drive.

## Divergenze dal piano, task per task

### Task 2 — Trait NormalizesRelatedUrl

Nel ciclo sugli elementi, un elemento senza etichetta (chiave numerica) si accetta solo se inizia
con `http://` o `https://`, come la stringa semplice. Il piano accettava qualsiasi stringa non
vuota. Motivo: la review finale ha trovato che il POI Geohub 39942
(`["hhttps://www.comune.castelnuovo.si.it/…","t","t","p","s",…]`, primo elemento lungo e poi
caratteri singoli) non è una lista spezzata pulita e finiva nel ciclo, producendo 33 link finti
invece del `null` previsto dalla review. Verificato con il valore reale letto dal database Geohub
locale. Aggiunti due casi di test: la lista sporca e la lista di indirizzi `["https://a.it"]`.

La regola vale anche per l'import Excel, che usa lo stesso trait: una cella scritta come lista JSON
con elementi senza `http` (`["www.comune.it"]`) prima dava `www.comune.it => www.comune.it`, ora
non dà link. L'export Excel scrive indirizzi separati da virgola, quindi il caso non si produce
nel giro export → import; i valori già salvati hanno sempre l'etichetta come chiave e non passano
da questa regola.

## Verifiche

I test Pest del package non si lanciano da maphub (vedi il Task 5 del primo ciclo; lanciati da
maphub userebbero il database di sviluppo con `RefreshDatabase`). Verificato con uno script PHP
passato da stdin al container `php-maphub`: 22 casi su 22, cioè i 17 di `relatedUrlToArray`
(compreso il valore reale del POI 39942) e 5 di `mergeRelatedUrl` dell'import Excel, fra cui il
caso di `EcPoiRowProcessorTest`. Con il codice del primo ciclo i tre casi nuovi con un link
restituivano `null`. Pint e PHPStan passano sui file toccati.

## Review (07/10/2026, wm-review-ticket)

Da correggere, un bloccante: il POI 39942 (vedi sopra), corretto prima del commit. Cleanup non
bloccanti lasciati: il `trim` sugli url WordPress, la doppia decodifica JSON nell'import Excel
(voluta, vedi Decisioni).

## Follow-up

- Nella copia locale di Geohub (agosto 2025) 194 POI e 197 tracce hanno oggetti di link con valori
  che non sono indirizzi (`""`, `www.…`): diventano link finti come prima di questo ticket. Fuori
  dalla review, che considera gli oggetti già gestiti; non verificato su prod.
- Restano validi i follow-up del primo ciclo, tranne l'unificazione, fatta qui.
