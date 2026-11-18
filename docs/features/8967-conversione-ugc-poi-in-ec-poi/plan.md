> Ticket: oc:8967

# Piano — Conversione UGC POI in EC POI

Riferimento: [overview.md](overview.md). Classificazione: **package** (codice e test in
`wm-package`); in `camminiditalia` solo il gitlink del submodule.

Branch: `feature/oc-8967-conversione-ugc-poi-in-ec-poi` in `wm-package` (PR #341 verso `develop`,
già aperta in draft con l'overview). In `camminiditalia` un branch con lo stesso nome per il bump
del gitlink, **dopo** il merge della PR del package (regola di `wm-package/CLAUDE.md`).

**Nessun commit viene eseguito senza conferma esplicita del dev**: i comandi `git commit` qui sotto
sono istruzioni, non azioni automatiche.

Comando per i test del package (da `camminiditalia/`, `CLAUDE.md` del repo, oc:8637):

```bash
docker run --rm --network camminiditalia_default -e DB_HOST=postgres-camminiditalia \
  -v "$PWD/wm-package:/app" -w /app wm-phpfpm:8.4 vendor/bin/pest <file-o-filtro>
```

---

## Task 1 — Test della conversione (prima del codice, devono fallire)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1--test-della-conversione)

**File:** `wm-package/tests/Feature/Nova/ConvertUgcPoiToEcPoiTest.php` (nuovo)

Setup, sul modello di `tests/Unit/EcDescriptionTiptapTest.php`:
- `DatabaseTransactions`;
- `Storage::fake('wmfe')`, `Storage::fake('conf')`, `Storage::fake('pois')`;
- `Queue::fake()` e `Bus::fake()` (le miniature in coda non servono ai test: si verifica il file
  originale);
- utente Administrator autenticato con `actingAs()` (l'action usa `auth()->user()`, trappola
  oc:8486 in `.claude/rules/test.md`);
- App, UgcPoi e media creati con le factory del package (`UgcPoiFactory`, `MediaFactory`) o con
  `addMedia()` su un'immagine generata con GD in `sys_get_temp_dir()`.

L'action va eseguita **dentro `Laravel\Nova\Actions\Transaction::run()`**, come fa Nova
(`DispatchAction.php:183`): senza la transazione esterna il bug non si riproduce.

```php
$run = fn (Collection $models) => Transaction::run(
    fn () => (new ConvertUgcPoiToEcPoi)->handle(new ActionFields(collect(), collect()), $models)
);
```

Casi, uno per test:

1. **UgcPoi senza foto** → nasce un EcPoi con nome, geometria e `app_id` dell'UgcPoi; l'UgcPoi ha
   `ec_poi_id`; il messaggio è di successo.
2. **UgcPoi con 2 foto** → l'EcPoi ha 2 media; per ognuno: id e uuid diversi dall'originale, file
   esistente su `wmfe` al proprio `getPathRelativeToRoot()`, stesso contenuto (hash) del file
   originale; i media dell'UgcPoi restano invariati (stessi id, file presenti).
3. **Posizione della foto** → il media copiato ha la stessa `geometry` (confronto con
   `ST_AsText(geometry::geometry)`) del media originale, diversa dal punto dell'EcPoi.
4. **File mancante** → si cancella dal disco il file della seconda di due foto: nessun EcPoi creato,
   nessun `ec_poi_id`, nessuna riga `media` nuova, **e nessun file rimasto su `wmfe`** per la prima
   foto già copiata (si confronta l'elenco dei file del disco prima e dopo); il messaggio è di
   errore e cita l'UgcPoi.
5. **Selezione multipla con un fallimento** → tre UgcPoi, il secondo con file mancante: primo e terzo
   convertiti, secondo no; il messaggio elenca convertiti e non convertiti.
6. **Errore SQL assorbito durante la conversione** (vincolo «nessun catch dentro il savepoint»):
   un listener `EcPoi::creating` registrato nel test che, per un nome preciso, esegue
   `try { DB::statement('select 1/0'); } catch (\Throwable) {}` — imita `MediaObserver::handleException`,
   che intercetta e prosegue. Selezione di due UgcPoi, il primo con quel nome: il primo non viene
   convertito, il secondo sì, e `Transaction::run` termina senza `25P02`.
7. **UgcPoi già convertito** → con `ec_poi_id` valorizzato viene saltato e citato nel messaggio
   (comportamento attuale, invariato).

Verifica che falliscano (2, 3, 4, 5, 6 rossi sul codice attuale; 1 e 7 verdi):

```bash
docker run ... vendor/bin/pest tests/Feature/Nova/ConvertUgcPoiToEcPoiTest.php
```

## Task 2 — Riscrittura di `ConvertUgcPoiToEcPoi::handle()`

**File:** `wm-package/src/Nova/Actions/ConvertUgcPoiToEcPoi.php`

Struttura:

```php
public function handle(ActionFields $fields, Collection $models)
{
    $converted = [];
    $alreadyConverted = [];
    $failed = []; // [ugcPoiId => motivo leggibile]

    foreach ($models as $model) {
        if (! empty($model->properties['ec_poi_id'])) {
            $alreadyConverted[] = $model->id;
            continue;
        }

        $copiedMedia = [];
        try {
            // Savepoint: un errore annulla solo questo UgcPoi. Dentro la closure
            // NESSUN catch: l'eccezione deve uscire, altrimenti su Postgres la
            // transazione di Nova resta invalidata (25P02, oc:8967).
            DB::transaction(function () use ($model, &$copiedMedia) {
                $ecPoi = EcPoi::create([...]);           // come oggi
                foreach ($model->media as $media) {
                    $copiedMedia[] = $this->copyMedia($media, $ecPoi);
                }
                $properties = $model->properties;
                $properties['ec_poi_id'] = $ecPoi->id;
                $model->properties = $properties;
                $model->saveQuietly();
            });
            $converted[] = $model->id;
        } catch (Throwable $e) {
            $this->deleteCopiedFiles($copiedMedia);
            Log::error('ConvertUgcPoiToEcPoi: UgcPoi '.$model->id.' non convertito', ['exception' => $e]);
            $failed[$model->id] = $this->failureReason($e);
        }
    }

    return $this->resultMessage($converted, $alreadyConverted, $failed);
}
```

`copyMedia(Media $media, EcPoi $ecPoi): Media`:
- se `! Storage::disk($media->disk)->exists($media->getPathRelativeToRoot())` lancia
  `MediaFileMissingException` (classe nuova in `src/Exceptions/`, accanto a `OsmClientException`
  e alle altre eccezioni del package) —
  `copy()` con file mancante crea una foto da 0 byte senza errori (provato);
- `$media->copy($ecPoi, $media->collection_name, $media->disk, '', fn (FileAdder $adder) =>
  $adder->withCustomProperties(array_merge($media->custom_properties ?? [], ['geometry' => <geometria del media>])))`.
  `withCustomProperties()` **sostituisce** l'array (`FileAdder.php:202-207`), quindi il merge è
  obbligatorio. Il formato della geometria da passare (WKT da `ST_AsText` o il valore grezzo della
  colonna) si sceglie con il test 3: `MediaObserver` la assegna a `$media->geometry` così com'è.

`deleteCopiedFiles(array $copiedMedia)`: per ogni media copiato cancella la cartella base del media
sul suo disco (`dirname($media->getPathRelativeToRoot())`, che contiene originale e `conversions/`).
Le righe sono già sparite col rollback del savepoint, quindi niente `$media->delete()`. Errori della
pulizia solo loggati: non devono nascondere il motivo del fallimento.

`failureReason(Throwable $e)`: testo per l'utente, tradotto. File mancante → «foto non trovata sullo
storage»; ogni altro errore → messaggio generico «errore durante la copia», **senza** il messaggio
grezzo dell'eccezione (SQL, path S3), che resta nel log.

`resultMessage(...)`: `Action::message()` se `$failed` è vuoto, altrimenti `Action::danger()`, con
gli id convertiti, già convertiti e non convertiti con motivo.

Verifica: tutti i test del Task 1 verdi.

## Task 3 — Traduzioni

**File:** `wm-package/resources/lang/{en,it,fr,es,de}.json` (regola: mai in `lang/`)

Chiavi in inglese, una chiave per ogni messaggio nuovo e per i due esistenti oggi non tradotti
(«Conversion completed successfully», «…already have an associated EcPoi…»). Placeholder con
`:ids` / `:reason`. Verifica: ogni chiave nuova presente in tutti e cinque i file.

## Task 4 — `afterCommit()` sulla catena di `updateDataChain()`

**File:** `wm-package/src/Services/Models/EcPoiService.php`

`PendingChain` (Laravel 12.50) non ha `afterCommit()`: si imposta sul **primo job** della catena,
che è quello effettivamente accodato (`PendingChain::dispatch()`); i successivi partono solo dopo di
lui.

```php
$chain = [
    (new SyncModelTaxonomyWhereJob($model))->afterCommit(),
    new UpdateEcPoiDemJob($model),
];
```

`SyncModelTaxonomyWhereJob` usa il trait `Queueable` (`src/Jobs/TaxonomyWhere/SyncModelTaxonomyWhereJob.php:18`),
che fornisce `afterCommit()`.

Test in `ConvertUgcPoiToEcPoiTest.php` o in un file dedicato
(`tests/Unit/Services/EcPoiServiceUpdateDataChainTest.php`): con `Bus::fake()` si verifica che il
job accodato abbia `$job->afterCommit === true` — `Bus::fake()` non onora `afterCommit()`, si
controlla la proprietà, non lo scarto (trappola oc:8571 in `.claude/rules/test.md`).

## Task 5 — Verifica manuale in locale (camminiditalia)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5--verifica-manuale-in-locale)

Con gli script in scratchpad già usati per la diagnosi, sul codice nuovo:

1. UgcPoi di test con foto vera su MinIO → «Run action» dal vero endpoint Nova
   (`POST /nova-api/ugc-pois/action?action=convert-to-ecpoi`): HTTP 200, URL della foto dell'EcPoi
   risponde **200** e le miniature compaiono dopo che Horizon le ha generate.
2. Stessa prova con il file della foto cancellato da MinIO: nessun 500, messaggio di errore in Nova,
   nessun EcPoi, nessun file residuo.
3. Prova della race con Horizon attivo e transazione aperta 5 s: nessun `failed_jobs`
   `ModelNotFoundException`, l'EcPoi ha `ele` e `taxonomy_where` dopo il commit.

Pulizia di tutto ciò che viene creato. Esiti riportati in `notes.md`.

## Task 6 — Pint e PHPStan

```bash
docker exec laravel-camminiditalia bash -c "cd wm-package && vendor/bin/pint src/Nova/Actions/ConvertUgcPoiToEcPoi.php src/Services/Models/EcPoiService.php tests/Feature/Nova/ConvertUgcPoiToEcPoiTest.php"
```

Mai `composer format` senza scope (riformatta tutto il repo). PHPStan del consumer nel review-gate.

## Task 7 — Commit e PR (solo dopo conferma esplicita)

In `wm-package`, sul branch della PR #341:

```bash
git add src/Nova/Actions/ConvertUgcPoiToEcPoi.php src/Services/Models/EcPoiService.php \
        src/Exceptions/MediaFileMissingException.php resources/lang/*.json \
        tests/Feature/Nova/ConvertUgcPoiToEcPoiTest.php
git commit -m "fix(oc:8967): conversione UgcPoi in EcPoi con foto tramite Media::copy()"
git add docs/features/8967-conversione-ugc-poi-in-ec-poi/ docs/knowledge/<pagina> CLAUDE.md
git commit -m "docs(oc:8967): piano, note e conoscenza della conversione UgcPoi in EcPoi"
```

PR #341 da draft a pronta per la review. Dopo il merge in `develop` di wm-package, in
`camminiditalia` un commit dedicato al solo gitlink (`chore(oc:8967): gitlink wm-package alla
correzione della conversione UgcPoi in EcPoi`) nella PR del ticket verso `develop`.

## Dopo il rilascio

Il cliente rilancia «Convert To EcPoi» sull'UgcPoi 143 in produzione (da scrivere nella risposta
sul ticket).
