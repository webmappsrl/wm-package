> Ticket: oc:8679

# Piano — L'import da Geohub copia i link di POI e tracce come testo

Repo: **wm-package** (codice, test, documentazione), poi **maphub** (solo bump del submodule).
Branch in entrambi: `feature/oc-8679-related-url-import-geohub`.
I commit sono istruzioni per la dev: nessun `git commit`/`push` senza il suo sì esplicito.

## Task 1 — Test che falliscono (wm-package)

File nuovo `tests/Unit/Services/Import/DataTransformerRelatedUrlTest.php` (Pest, nessun database).

Casi su `(new DataTransformer)->relatedUrlToArray($input)`:

| Input | Atteso |
|---|---|
| `'{"Ufficio turistico":"https://www.comune.levanto.sp.it/"}'` | `['Ufficio turistico' => 'https://www.comune.levanto.sp.it/']` |
| `'false'` | `null` |
| `'[]'` | `null` |
| `null` | `null` |
| `''` | `null` |
| `'  https://www.esempio.it  '` | `['https://www.esempio.it' => 'https://www.esempio.it']` |
| `'{"Sito": false, "Info": "https://a.it"}'` | `['Info' => 'https://a.it']` |
| `'{"rotto'` | `null` (nessuna eccezione) |
| `'www.comune.it'` | `null` |
| `'{}'` | `null` |

Più un test sul mapping:
`config('wm-geohub-import.import_mapping.ec_poi.properties.mapping.related_url')` e
`config('wm-geohub-import.import_mapping.ec_track.properties.mapping.related_url')` sono uguali a
`['field' => 'related_url', 'transformer' => [DataTransformer::class, 'relatedUrlToArray']]`.

Verifica: `vendor/bin/pest tests/Unit/Services/Import/DataTransformerRelatedUrlTest.php` → falliscono
(metodo inesistente, mapping ancora stringa).

## Task 2 — `DataTransformer::relatedUrlToArray` (wm-package)

In `src/Services/Import/DataTransformer.php` aggiungere:

- `public function relatedUrlToArray($value): ?array` → chiama `normalizeRelatedUrl()` e
  restituisce `null` se il risultato è `[]`;
- `private function normalizeRelatedUrl(mixed $value): array` → stesse regole di
  `EcPoiRowProcessor::normalizeRelatedUrlToAssoc` (righe 339-379): null/'' → `[]`; array → tiene
  solo valori stringa/numerici, chiavi numeriche diventano `url => url`; stringa → `trim`, se
  inizia con `{` decodifica e ricorre, se inizia con `http://`/`https://` → `[t => t]`, altrimenti
  `[]`.
- Commento PHPDoc: «Stessa logica di `EcPoiRowProcessor::normalizeRelatedUrlToAssoc`: se cambi
  una, cambia anche l'altra (oc:8679).»

Nessuna modifica a `jsonToArray` e `nullableJsonToArray`.

## Task 3 — Mapping in config (wm-package)

In `config/wm-geohub-import.php`, riga 397 (`ec_track`) e riga 458 (`ec_poi`):

```php
'related_url' => ['field' => 'related_url', 'transformer' => [DataTransformer::class, 'relatedUrlToArray']],
```

`DataTransformer` è già importato in testa al file (riga 15).

Verifica: il test del Task 1 passa tutto.

## Task 4 — Commento incrociato nell'import Excel (wm-package)

In `src/Imports/Processors/EcPoiRowProcessor.php`, sopra `normalizeRelatedUrlToAssoc` (riga 339),
solo un commento: «Stessa logica di `DataTransformer::relatedUrlToArray` (import Geohub): se cambi
una, cambia anche l'altra (oc:8679).» Nessuna modifica al codice.

## Task 5 — Verifiche (wm-package)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5--verifiche)

- `vendor/bin/pest tests/Unit/Services/Import/DataTransformerRelatedUrlTest.php tests/Unit/Imports/Processors/EcPoiRowProcessorTest.php`
  (nel container `php-forestas`, o in quello di maphub se attivo);
- `vendor/bin/pint` solo sui file toccati (`composer format` riformatta tutto il repo);
- `vendor/bin/phpstan analyse` sui file toccati.

Commit suggerito: `fix(oc:8679): import Geohub converte related_url di POI e tracce`.

## Task 6 — Documentazione (wm-package)

- `notes.md` del cantiere;
- aggiornare `docs/knowledge/import-geohub-e-taxonomy.md` con la riga sulla conversione di
  `related_url` (passa dal controllo `wm-context-guard`).

## Task 7 — PR e bump (wm-package → maphub)

1. PR di wm-package verso `develop`, merge.
2. In maphub: bump del puntatore `wm-package` al commit del merge, nessun'altra modifica.
   Nessuna migration nuova, quindi `publish-missing-migrations` non serve (verificare con
   `--dry-run`).
   Commit suggerito: `fix(oc:8679): bump wm-package per conversione related_url import Geohub`.
3. PR di maphub verso `develop`.

## Task 8 — Re-import su Maphub dev (da concordare con il tester)

Prima di lanciarlo: il tester conferma che su dev non ci sono correzioni a mano su POI e tracce
di Itinera Romanica PLUS (il re-import sostituisce `properties` per intero).

- dopo il deploy, riavviare i worker Horizon: un worker già avviato usa ancora il mapping vecchio;
- `php artisan wm:import-from-geohub app <id-geohub-app>` sullo shard dev;
- verifica: 52 POI su 52 con link funzionante (es. Geohub 2554 → Maphub 325), nessun
  `related_url` uguale a `"[]"`/`"false"` su POI e tracce (es. Geohub 2366 → Maphub 211 senza link).
