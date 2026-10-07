> Ticket: oc:8679

> Rivista il 07/10/2026 dopo la review dell'overview: logica unica in un trait condiviso con
> l'import Excel, recupero dei tre formati di link presenti sui POI di Geohub.

# L'import da Geohub copia i link di POI e tracce come testo: link rotti e link finti []/false

## Cosa cambia

L'import da Geohub converte `related_url` di POI e tracce in un oggetto `etichetta → indirizzo`
dentro `properties`, invece di copiarlo come stringa. Quando su Geohub il campo è vuoto, `[]`,
`false` o non interpretabile, sullo shard diventa `null`: l'app non mostra più righe di link finte.

| Su Geohub | POI su Geohub prod | Oggi sullo shard | Dopo |
|---|---|---|---|
| `{"Ufficio turistico":"https://…"}` | | stringa `"{\"Ufficio turistico\":…}"` | `{"Ufficio turistico": "https://…"}` |
| Stringa JSON `"https:\/\/…"` | 94 | stringa | `{url: url}` |
| Lista spezzata `["h","t","t","p",…]` | 18 | stringa | caratteri ricomposti → `{url: url}` |
| Lista WordPress `[{"net7webmap_related_url":"…"}]` | 18 | stringa | url non vuoti → `{url: url}` |
| `false`, `[]`, `""`, `null`, JSON non valido, lista WordPress con url vuoti (3), POI 39942 (due indirizzi mescolati, non ricostruibile) | | stringa `"false"` / `"[]"` | `null` |

Le tracce hanno solo oggetti JSON o valori vuoti, già gestiti. Dati verificati con query in sola
lettura su Geohub prod il 07/10/2026; tipi delle colonne: `ec_pois.related_url` `text`,
`ec_tracks.related_url` `json`.

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

- [ ] Una sola funzione di normalizzazione dei link, in un trait (`src/Traits/NormalizesRelatedUrl.php`,
      come `NormalizesHexColor`), usata da `DataTransformer` (import Geohub) e da
      `EcPoiRowProcessor` (import Excel). Regole: oggetto JSON → array `etichetta → url`
      scartando i valori non testuali; stringa JSON `"https:\/\/…"`, lista spezzata di caratteri
      ricomposta e lista WordPress `net7webmap_related_url` (solo url non vuoti) → `{url: url}`;
      stringa che inizia con `http://` o `https://` → `[url => url]`; spazi rimossi.
- [ ] `DataTransformer::relatedUrlToArray` usa il trait e restituisce `null` quando non ci sono link.
- [ ] In `config/wm-geohub-import.php` `related_url` di tracce (riga 397) e POI (riga 458) usa
      `['field' => 'related_url', 'transformer' => [DataTransformer::class, 'relatedUrlToArray']]`.
- [ ] Test unitari Pest (senza database) su `relatedUrlToArray` per i casi della tabella sopra, più
      un test che verifica il mapping di `related_url` in config per POI e tracce.
- [ ] `jsonToArray` e `nullableJsonToArray` restano identici.
- [ ] Bump del submodule in maphub dopo il merge in wm-package.
- [ ] (Da concordare con il tester) re-import di Itinera Romanica PLUS su Maphub dev con
      `php artisan wm:import-from-geohub` e verifica: 52 POI su 52 con link funzionante, nessun
      link `[]`/`false` su POI e tracce.

## Rischi

- **L'import Excel riconosce anche i formati nuovi**, perché usa lo stesso trait: una cella
  `[{"net7webmap_related_url":"…"}]` o `["h","t",…]` oggi dà un link perso o spazzatura, dopo
  dà il link. Il formato normale (indirizzi separati da virgola) non cambia.
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
- Il campo Nova per modificare i link delle tracce.
- Capire quale scrittura su Geohub abbia prodotto `[]` e `false`.

## Moduli toccati

| Repo | File |
|---|---|
| wm-package | `src/Traits/NormalizesRelatedUrl.php` (nuovo) |
| wm-package | `src/Services/Import/DataTransformer.php` (metodo nuovo, usa il trait) |
| wm-package | `src/Imports/Processors/EcPoiRowProcessor.php` (usa il trait) |
| wm-package | `config/wm-geohub-import.php` (righe 397 e 458) |
| wm-package | `tests/Unit/Services/Import/DataTransformerRelatedUrlTest.php` (nuovo) |
| maphub | puntatore del submodule `wm-package` |
