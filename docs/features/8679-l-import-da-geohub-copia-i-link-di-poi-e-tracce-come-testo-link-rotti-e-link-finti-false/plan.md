> Ticket: oc:8679

> Riscritto il 07/10/2026 dopo la review dell'overview. Il piano del primo ciclo (due copie della
> logica, commento incrociato) è nella storia di git, al commit 5a327589. Come deciso allo scrum
> del 07/10, il codice di quel commit si rifà secondo l'overview rivista, non si corregge.

# Piano — L'import da Geohub copia i link di POI e tracce come testo

Repo: **wm-package** (codice, test, documentazione), poi **maphub** (solo bump del submodule).
Branch: `feature/oc-8679-related-url-import-geohub` in wm-package (già esistente, PR #299); lo
stesso nome in maphub.
I commit sono istruzioni per la dev: nessun `git commit`/`push` senza il suo sì esplicito.

## Task 1 — Test che falliscono (wm-package)

File `tests/Unit/Services/Import/DataTransformerRelatedUrlTest.php` (Pest, nessun database).
Restano i 10 casi del primo ciclo e il test sul mapping in config; si aggiungono i tre formati
della review:

| Input | Atteso |
|---|---|
| `'"https:\/\/www.a.it\/"'` (stringa JSON) | `['https://www.a.it/' => 'https://www.a.it/']` |
| `'["h","t","t","p","s",":","/","/","a",".","i","t"]'` (lista spezzata) | `['https://a.it' => 'https://a.it']` |
| `'[{"net7webmap_related_url":"https://a.it"},{"net7webmap_related_url":""}]'` (WordPress) | `['https://a.it' => 'https://a.it']` |
| `'[{"net7webmap_related_url":""}]'` (WordPress vuoto) | `null` |

Verifica: i tre casi nuovi falliscono con il codice attuale.

## Task 2 — Trait `NormalizesRelatedUrl` (wm-package)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2--trait-normalizesrelatedurl)

File nuovo `src/Traits/NormalizesRelatedUrl.php`, sul modello di `NormalizesHexColor`:
`protected function normalizeRelatedUrl(mixed $value): array`. Nessuna `const` nel trait (PHP 8.1).

Regole, in questo ordine:

1. `null` o `''` → `[]`.
2. Array:
   - lista fatta solo di stringhe di un carattere → caratteri ricomposti, poi regola 3 sulla
     stringa ottenuta. Va prima del punto successivo, che altrimenti produce
     `{"h":"h","t":"t",…}`;
   - altrimenti per ogni elemento: array con chiave `net7webmap_related_url` non vuota →
     `url => url`; valore stringa/numerico con chiave stringa → `etichetta => url`; con chiave
     numerica e non vuoto → `url => url`; il resto si scarta.
3. Stringa, dopo `trim`:
   - vuota → `[]`;
   - inizia con `{`, `[` o `"` → `json_decode`; se il risultato è array o stringa si riapplicano
     le regole, altrimenti `[]`;
   - inizia con `http://` o `https://` → `[t => t]`;
   - altrimenti `[]`.

## Task 3 — `DataTransformer` usa il trait (wm-package)

In `src/Services/Import/DataTransformer.php`: `use NormalizesRelatedUrl;`, togliere il metodo
privato `normalizeRelatedUrl` e il commento di rimando all'import Excel. `relatedUrlToArray($value): ?array`
resta pubblico e restituisce `null` quando il trait restituisce `[]`.

Il mapping in `config/wm-geohub-import.php` (righe 397 e 458) è già quello giusto dal primo ciclo.

Verifica: il test del Task 1 passa tutto.

## Task 4 — `EcPoiRowProcessor` usa il trait (wm-package)

In `src/Imports/Processors/EcPoiRowProcessor.php`: `use NormalizesRelatedUrl;`, le chiamate a
`normalizeRelatedUrlToAssoc` in `mergeRelatedUrl` diventano `normalizeRelatedUrl`, e il metodo
privato `normalizeRelatedUrlToAssoc` (con il suo commento di rimando) si cancella: se restasse con
lo stesso nome del metodo del trait, PHP userebbe in silenzio quello della classe.
`mergeRelatedUrl` non cambia.

## Task 5 — Verifiche (wm-package)

Come nel primo ciclo (vedi `notes.md`): i test Pest del package non si lanciano da maphub.

- i casi del Task 1 e il caso di `EcPoiRowProcessorTest` (`'https://example.com'` → assoc)
  verificati con uno script PHP temporaneo nel container `php-maphub`;
- `vendor/bin/pint` solo sui file toccati (`composer format` riformatta tutto il repo);
- `vendor/bin/phpstan analyse` sui file toccati.

Commit suggerito: `fix(oc:8679): logica dei related_url in un trait condiviso, recupero dei formati Geohub`.

## Task 6 — Documentazione (wm-package)

- `notes.md` del cantiere: la review del 07/10 e cosa è cambiato rispetto al primo ciclo;
- `docs/knowledge/import-geohub-e-taxonomy.md`: la riga sulla conversione di `related_url`
  (passa dal controllo `wm-context-guard`).

## Task 7 — PR e bump (wm-package → maphub)

1. Push sul branch della PR #299 di wm-package (verso `develop`), review dell'overview e del codice, merge.
2. In maphub: bump del puntatore `wm-package` al commit del merge, nessun'altra modifica.
   Nessuna migration nuova (verificare con `publish-missing-migrations --dry-run`).
   Commit suggerito: `fix(oc:8679): bump wm-package per conversione related_url import Geohub`.
3. PR di maphub verso `develop`.

## Task 8 — Re-import su Maphub dev (da concordare con il tester)

Invariato dal primo ciclo: il tester conferma che su dev non ci sono correzioni a mano su POI e
tracce di Itinera Romanica PLUS; dopo il deploy si riavviano i worker Horizon;
`php artisan wm:import-from-geohub app <id-geohub-app>`; verifica: 52 POI su 52 con link
funzionante (es. Geohub 2554 → Maphub 325), nessun `"[]"`/`"false"` su POI e tracce (es. Geohub
2366 → Maphub 211 senza link).
