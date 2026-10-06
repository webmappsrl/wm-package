> Ticket: oc:8679

# L'import da Geohub copia i link di POI e tracce come testo: link rotti e link finti []/false

## Cosa cambia

L'import da Geohub converte `related_url` di POI e tracce in un oggetto `etichetta → indirizzo`
dentro `properties`, invece di copiarlo come stringa. Quando su Geohub il campo è vuoto, `[]`,
`false` o non interpretabile, sullo shard diventa `null`: l'app non mostra più righe di link finte.

| Su Geohub | Oggi sullo shard | Dopo |
|---|---|---|
| `{"Ufficio turistico":"https://…"}` | stringa `"{\"Ufficio turistico\":…}"` | `{"Ufficio turistico": "https://…"}` |
| `https://www.esempio.it` (testo libero, solo POI) | stringa `"https://www.esempio.it"` | `{"https://www.esempio.it": "https://www.esempio.it"}` |
| `false`, `[]`, `""`, `null`, JSON non valido, testo senza `http` | stringa `"false"` / `"[]"` | `null` |

## Perché

Il collaudo di Itinera Romanica PLUS (import Geohub 28 → maphubdev 3, 01/10/2026) ha trovato link
rotti su 52 POI su 52 che su Geohub ne hanno, e link finti `[]`/`false` su 97 POI e 57 tracce su 57.
La causa: in `config/wm-geohub-import.php` `related_url` è mappato come stringa grezza (righe 397 e
458), e le colonne json di Geohub arrivano dal query builder come testo. L'app tratta qualsiasi
stringa come indirizzo e ci mette davanti `https://`.

Il ticket chiede di convertire i link «come fa già per descrizione e testo breve». `jsonToArray`,
il convertitore di descrizione e testo breve, però non basta: con `"false"` va in TypeError
(`array_filter` su `false`) e con `"[]"` restituisce `[]` invece di non salvare il link.

## Requisiti

- [ ] Nuovo metodo pubblico `DataTransformer::relatedUrlToArray`, con le stesse regole di
      `EcPoiRowProcessor::normalizeRelatedUrlToAssoc` (import Excel): oggetto JSON → array
      `etichetta → url` scartando i valori non testuali; stringa che inizia con `http://` o
      `https://` → `[url => url]`; spazi rimossi. Restituisce `null` quando il risultato è vuoto.
- [ ] Un commento in `relatedUrlToArray` e in `normalizeRelatedUrlToAssoc` dice che le due logiche
      sono la stessa e vanno cambiate insieme.
- [ ] In `config/wm-geohub-import.php` `related_url` di tracce (riga 397) e POI (riga 458) usa
      `['field' => 'related_url', 'transformer' => [DataTransformer::class, 'relatedUrlToArray']]`.
- [ ] Test unitari Pest (senza database) su `relatedUrlToArray` per i casi della tabella sopra, più
      un test che verifica il mapping di `related_url` in config per POI e tracce.
- [ ] Nessuna modifica a codice esistente: `jsonToArray`, `nullableJsonToArray` ed
      `EcPoiRowProcessor` restano identici.
- [ ] Bump del submodule in maphub dopo il merge in wm-package.
- [ ] (Da concordare con il tester) re-import di Itinera Romanica PLUS su Maphub dev con
      `php artisan wm:import-from-geohub` e verifica: 52 POI su 52 con link funzionante, nessun
      link `[]`/`false` su POI e tracce.

## Rischi

- **Due copie della stessa logica** (`relatedUrlToArray` e `normalizeRelatedUrlToAssoc`): scelta
  consapevole per non modificare l'import Excel, che è privato e coperto da un solo caso di test.
  Mitigato dai commenti incrociati e da un ticket di unificazione.
- **Il re-import cancella le correzioni manuali sullo shard**: `transformProperties` ricostruisce
  `properties` da zero (`GeohubImportService.php:832-857`) e `fill` lo sostituisce
  (`GeohubImportService.php:232-238`). Non dipende da questo fix: va scritto nel messaggio al
  tester, che prima del re-import verifica di non aver corretto dati a mano su dev.
- **Testo senza `http`** (es. `www.comune.it`) diventa `null`, come già nell'import Excel. I link del
  collaudo sono tutti in JSON; su altre app non è contabile senza il database di Geohub.
- **I dati già importati restano sporchi** finché non si rifà l'import, su dev e sugli altri shard
  che hanno importato da Geohub.

## Out of scope

- Gli altri campi json delle tracce copiati grezzi (`slope`, `mbtiles`, `activities`, `themes`,
  `searchable`, `dem_data`, `osm_data`, `manual_data`, config righe 389-411).
- La fragilità di `jsonToArray` e `nullableJsonToArray` con valori come `"false"`.
- L'unificazione di `relatedUrlToArray` con `EcPoiRowProcessor::normalizeRelatedUrlToAssoc`.
- Il campo Nova per modificare i link delle tracce.
- Capire quale scrittura su Geohub abbia prodotto `[]` e `false`.

## Moduli toccati

| Repo | File |
|---|---|
| wm-package | `src/Services/Import/DataTransformer.php` (metodo nuovo) |
| wm-package | `src/Imports/Processors/EcPoiRowProcessor.php` (solo commento) |
| wm-package | `config/wm-geohub-import.php` (righe 397 e 458) |
| wm-package | `tests/Unit/Services/Import/DataTransformerRelatedUrlTest.php` (nuovo) |
| maphub | puntatore del submodule `wm-package` |
