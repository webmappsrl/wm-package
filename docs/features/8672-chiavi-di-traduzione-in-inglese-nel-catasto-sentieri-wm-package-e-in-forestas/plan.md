> Ticket: oc:8672

# Piano — Chiavi di traduzione in inglese nel Catasto Sentieri (parte wm-package)

Fonte: [overview.md](overview.md). La «Tabella delle chiavi» in fondo all'overview è l'elenco di
lavoro: ogni riga è una chiave da convertire, con i punti del codice in cui compare. La parte di
forestas ha un piano suo, in `forestas/docs/features/8672-…/plan.md`, e si fa dopo il merge di
questo.

**Repo:** `wm-package`. **Branch:** `feature/oc-8672-chiavi-di-traduzione-in-inglese-nel-catasto-sentieri-wm-package-e-in-forestas`,
da `origin/develop` (che contiene già oc:8567, `bdd9c059`).

**Comandi** (dentro il container, da `wm-package/`):
`docker exec -it php-forestas bash -c "cd wm-package && vendor/bin/pest --filter=<nome>"`.
La suite del package usa il database `wm_package` (`phpunit.xml.dist`): prima di lanciarla,
controlla che non esista un `phpunit.xml` locale senza le righe `DB_*` (regola in cima al
`CLAUDE.md` del package).

**Commit:** nessun commit durante l'esecuzione. I messaggi qui sotto sono proposte per il dev,
che decide se e quando committare.

---

## Task 1 — Test nuovo, prima del codice (deve fallire)

File: `tests/Feature/TrailRegistry/TrailRegistryTranslationKeysTest.php`.

1. In cima al test, i percorsi dello scope come costante:
   `src/TrailRegistry/`, `src/WmPackageServiceProvider.php`, `src/Nova/EcPoi.php`,
   `src/Nova/TaxonomyWhere.php`, `src/Nova/Actions/Concerns/HasTaxonomyWhereImportHelpers.php`.
   Con un commento: allargarli è un lavoro a parte.
2. L'elenco atteso `chiave inglese => testo italiano`, **copiato dalla «Tabella delle chiavi»
   dell'overview** (colonne «Chiave inglese» e «Chiave italiana»), che riporta il testo di oggi.
   Mai ricavarlo da `resources/lang/it.json`, nemmeno dopo averlo modificato.
3. Un helper che estrae le chiavi letterali con `token_get_all`: un `T_STRING` `__` seguito da
   `(` e da un `T_CONSTANT_ENCAPSED_STRING`. Il valore si decodifica come stringa PHP, per
   gestire gli apici con escape (`'e\'' `). Restituisce `file:riga => chiave`, più l'elenco delle
   chiamate con argomento non letterale.
4. Test:
   - **ogni chiave dell'elenco atteso** ha la voce in `resources/lang/en.json` (valore uguale
     alla chiave) e in `resources/lang/it.json` (valore uguale al testo italiano atteso);
   - **nessuna chiave italiana della tabella** compare ancora come chiave letterale nei percorsi
     dello scope;
   - **le etichette delle costanti** `MapLegendRenderer::ENTRIES` (indice 2) e
     `MapLegendRenderer::SIGNS` (indice 3), lette con `ReflectionClassConstant`, sono tutte
     chiavi dell'elenco atteso;
   - **le altre chiavi letterali senza voce** (già inglesi, per esempio `EC Poi`, `Info`, `DEM`)
     e le chiamate dinamiche si scrivono su `STDERR` come elenco: non fanno fallire il test.
5. Lancia il test: deve fallire sulle chiavi mancanti. Se passa, il test non verifica nulla.

## Task 2 — Voci nei JSON

File: `resources/lang/it.json`, `resources/lang/en.json`. Mai in `lang/`.

1. Per ogni riga della tabella, aggiungi `"<chiave inglese>": "<testo italiano>"` in `it.json` e
   `"<chiave inglese>": "<chiave inglese>"` in `en.json`, in fondo ai file (non sono ordinati).
2. Se la chiave inglese esiste già (`Number`, `Variant`, `Properties`, `Type`, `Code`, `Map`, …),
   non duplicarla: verifica che il valore italiano sia già quello atteso. Il controllo collisioni
   dell'overview dice che lo è; se non lo fosse, fermati e chiedi.
3. Togli le voci con chiave italiana che ora sono sostituite: `Sostituisci numero`,
   `Questa istanza non ha un codice attivo da sostituire.`, `Numero sostituito.`, `Numero`,
   `Variante`, `nessuna variante`, `già importata`,
   `Tipologie di POI associate a questo punto di interesse`. Prima di togliere `Numero` e
   `Variante` verifica con il tokenizer che nessun altro file del package li usi.
4. Valida i due file con `php -r 'json_decode(file_get_contents("…"), flags: JSON_THROW_ON_ERROR);'`
   e controlla che non ci siano chiavi duplicate (`json_decode` tiene l'ultima in silenzio).

## Task 3 — Conversione delle chiavi nel codice

Per ogni file, sostituisci `__('<chiave italiana>')` con `__('<chiave inglese>')` secondo la
tabella. Cambia solo l'argomento di `__()`: niente riformattazione, niente rinomine.

1. `src/TrailRegistry/Nova/TrailApplication.php`, `TrailRegistryCode.php`, `TrailRegistryAnomaly.php`
   (comprese le frasi HTML di aiuto alle righe 256-278, con apostrofi tipografici e `<strong>`)
2. `src/TrailRegistry/Nova/AnomalyDetailRenderer.php`, `AnomalyMapLegendRenderer.php`
3. `src/TrailRegistry/Nova/MapLegendRenderer.php`: le due chiamate letterali e **le etichette
   nelle costanti** `ENTRIES` e `SIGNS`
4. `src/TrailRegistry/Nova/Actions/ReplaceTrailCodeNumber.php`
5. `src/TrailRegistry/Models/TrailRegistryCode.php`, `TrailRegistryAnomaly.php`,
   `Concerns/ComposesTrailRegistryMap.php`
6. `src/WmPackageServiceProvider.php`: righe 465-467 (`Istanze`, `Registro dei codici`,
   `Anomalie`) e 807, 844 (`Catasto` → `Trail registry`)
7. `src/Nova/EcPoi.php:65`, `src/Nova/TaxonomyWhere.php:53`,
   `src/Nova/Actions/Concerns/HasTaxonomyWhereImportHelpers.php:136`

Dopo ogni gruppo lancia il test del Task 1: il numero di chiavi mancanti deve scendere. Alla fine
deve passare.

## Task 4 — Test esistenti

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-test-esistenti)

Aggiorna i test che usano letteralmente le chiavi italiane, sostituendole con quelle inglesi
della tabella:

- `tests/Feature/TrailRegistry/`: `TrailApplicationCreateFormTest`, `TrailRegistryShardResourcesTest`,
  `ReplaceTrailCodeNumberActionTest`, `MapLegendRendererTest`, `TrailRegistryNovaResourcesTest`,
  `MainMenuInjectionTest`, `MenuSectionInjectionTest` (stringa letterale `'Catasto'`),
  `TrailRegistryMapFieldTest`, `TrailRegistryAnomalyTypesTest`, `TrailRegistryCodeMapTest`
- `tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php:242`

L'elenco viene da una ricerca non esaustiva: rifai la ricerca con il tokenizer su `tests/` e con
`grep` sulle stringhe italiane della tabella, comprese quelle passate senza `__()`.

## Task 5 — Verifica

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-verifica)

1. Suite del package, dopo il controllo sull'isolamento: tutta verde.
2. `composer analyse` (PHPStan): nessun errore nuovo sui file toccati.
3. `vendor/bin/pint` **solo sui file toccati**, poi `git status`: scarta i file fuori dal lavoro.
4. Controllo a mano in Nova, sul Docker di forestas con `APP_LOCALE=it`: menu «Catasto» con le
   tre voci, una scheda di istanza, di codice e di anomalia (titoli, legenda della mappa, testo di
   aiuto delle anomalie). I testi devono essere quelli di oggi.

## Task 6 — Documentazione

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6-documentazione)

1. `notes.md` del cantiere: deviazioni, decisioni prese durante il lavoro, eventuali chiavi dove
   il nome della classe dava un inglese forzato.
2. La descrizione della PR del package, che va verso `develop`, deve dire:
   - le chiavi generiche che cambiano testo anche fuori dal Catasto, in tutti gli shard che
     aggiornano il package (`Details`, `Detail`, `Status`, `Source`; `Properties` in inglese),
     con l'elenco dell'overview;
   - che forestas va aggiornato nella stessa PR in cui porta la chiave del menu a
     `Trail registry`, altrimenti con la lingua inglese la sezione si sdoppia.

Commit proposto: `fix(oc:8672): chiavi di traduzione in inglese nel Catasto Sentieri`.
