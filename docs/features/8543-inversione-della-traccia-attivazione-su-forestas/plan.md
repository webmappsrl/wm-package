> Ticket: oc:8543

# Piano — Inversione della traccia: attivazione su Forestas

Tutti i file sono in `wm-package` (nessuna modifica a `maphub`, coerente con l'overview: l'attivazione
su Forestas resta fuori scope).

> **Secondo ciclo (22/09/2026):** questo piano sostituisce quello del primo ciclo (già eseguito,
> commit `77934325`, PR [wm-package#278](https://github.com/webmappsrl/wm-package/pull/278)) con i
> task per le correzioni chieste dalla review ([5274604695](https://github.com/webmappsrl/wm-package/pull/278#pullrequestreview-5274604695),
> changes requested). I task del primo ciclo (creazione dell'Action, registrazione, traduzioni
> base) restano validi e non si ripetono qui, tranne dove esplicitamente riaperti.

**Nota di correzione emersa scrivendo questo piano** (non c'era nell'overview): la review e
l'overview citano `App.php:153-154` come pattern per `canSee`/`canRun` limitato a un ruolo, ma
quelle righe sono in realtà `$superAdminOnly = fn ($req) => RolesAndPermissionsService::allows($req)`
— un controllo per **email** in una whitelist (`config('wm-package.super_admin_emails')`), non per
ruolo. Applicarlo alla lettera avrebbe ristretto l'azione a `team@webmapp.it`, non a chi ha ruolo
Administrator (la decisione confermata dal dev in overview). Il pattern corretto per un controllo
di **ruolo** esiste comunque nel package, solo altrove: `App.php:843,848` (a livello di campo) fa
`optional($request->user())->hasRole('Administrator')`. Il Task 6 sotto usa questo secondo pattern,
esteso a un'Action (`canSee`/`canRun` invece di `canSee` su un `Field`).

---

## Task 1 — Spostare l'inversione della geometria in `GeometryComputationService`

**File:** `src/Services/GeometryComputationService.php`

Nuovo metodo pubblico `reverseGeometry(GeometryModel $model): void`. Sostituisce l'`ST_Reverse`
scritto oggi dentro l'Action (`ReverseEcTrackGeometryAction.php:60-63`).

**Include la correzione MultiLineString multi-parte** (bug più serio segnalato in review): un
singolo `ST_Reverse` inverte i vertici dentro ogni parte ma non riordina le parti tra loro — su
`ec_tracks.geometry`, dichiarata `geography('geometry', 'multiLineStringz')`
(`database/migrations/create_ec_tracks_table.php.stub:22`), e con 5 tracce sul DB locale di
Forestas a più parti (id 82, 334, 364, 486, 763 — la 334 ne ha 12), l'`ST_Reverse` da solo lascia
l'ordine delle parti invariato.

```php
public function reverseGeometry(GeometryModel $model): void
{
    $table = $model->getTable();

    // ST_Reverse inverte i vertici dentro ogni parte ma non l'ordine delle parti di una
    // MultiLineString: serve esploderle con ST_Dump, invertirle singolarmente, poi
    // ricomporle in ordine di `path` discendente (l'ultima parte diventa la prima).
    // Cast ::geometry perché la colonna è geography e ST_Reverse/ST_Dump non hanno overload
    // per quel tipo; ST_Force3D preserva la quota Z persa da nessun passaggio ma esplicitata
    // per chiarezza; il cast finale ::geography torna al tipo di colonna.
    DB::statement(
        "UPDATE {$table} AS t
         SET geometry = agg.geom
         FROM (
             SELECT
                 id,
                 ST_Force3D(ST_Multi(ST_Collect(ST_Reverse(geom) ORDER BY path DESC)))::geography AS geom
             FROM (
                 SELECT id, (dump).path[1] AS path, (dump).geom AS geom
                 FROM (
                     SELECT id, ST_Dump(geometry::geometry) AS dump
                     FROM {$table}
                     WHERE id = ?
                 ) AS d
             ) AS parts
             GROUP BY id
         ) AS agg
         WHERE t.id = agg.id",
        [$model->id]
    );
}
```

Verificata a mano contro l'esempio della review: su
`MULTILINESTRING Z((0 0 0, 1 1 100),(10 10 200, 11 11 300))` la query produce
`MULTILINESTRING Z((11 11 300,10 10 200),(1 1 100,0 0 0))` — stessa attesa del test proposto sotto
(Task 4).

## Task 2 — `forceGeometryChain` su `EcTrackService::updateDataChain()`

**File:** `src/Services/Models/EcTrackService.php` (righe 327-356)

Oggi la catena di ricalcolo parte solo se `$track->wasChanged('geometry')`, condizione sempre
falsa con la scrittura SQL pura del Task 1 (Eloquent non la rileva). Nuovo parametro con default
`false`:

```php
public function updateDataChain(EcTrack $track, bool $forceGeometryChain = false)
{
    $chain = [];
    if (isset($track->properties['osmid']) && $track->properties['osmid']) {
        $chain[] = new UpdateEcTrackFromOsmJob($track);
    }

    if ($track->wasChanged('geometry') || $forceGeometryChain) {
        $chain[] = new UpdateEcTrackDemJob($track);
        $chain[] = new UpdateEcTrackManualDataJob($track);
        $chain[] = new UpdateEcTrackCurrentDataJob($track);
        $chain[] = new UpdateEcTrack3DDemJob($track);
        $chain[] = new UpdateEcTrackSlopeValues($track);
        $chain[] = new UpdateModelWithGeometryTaxonomyWhere($track);
        $chain[] = new UpdateEcTrackGenerateElevationChartImage($track);
        $chain[] = new GenerateEcTrackPBFBatch($track);
    }

    $chain[] = new UpdateEcTrackAwsJob($track);
    $chain[] = new UpdateEcTrackAppRelationsInfoJob($track);
    $chain[] = new UpdateEcTrackOrderRelatedPoi($track);

    Bus::chain($chain)->dispatch()->afterCommit();
}
```

**Verificato (non solo assunto):** i 3 chiamanti esistenti (`EcTrackObserver::updated()`,
`EcPoiObserver::saved()` riga 58, `EcPoiEcTrackObserver` riga 84) passano tutti un solo argomento
— il nuovo parametro con default `false` non cambia il loro comportamento.

**Aggiunta non richiesta esplicitamente dalla review ma necessaria:** `->afterCommit()` sul
`Bus::chain(...)->dispatch()` finale. Oggi non c'è, perché finora `updateDataChain()` viene
chiamato solo dall'interno degli observer (`EcTrackObserver` ha `public $afterCommit = true`,
quindi il metodo gira già dopo il commit della transazione del salvataggio Eloquent). L'Action
invece lo chiamerà da dentro la transazione Nova (`Actions\Transaction::run()`, non ancora
committata a quel punto) — stesso motivo per cui `UpdateEcTrackDemJob::dispatch()` ha già
`->afterCommit()` nel primo ciclo. `afterCommit()` su una catena senza transazione attiva (il caso
degli observer) dispatcha comunque subito: nessun impatto sui chiamanti esistenti.

## Task 3 — Riscrivere `ReverseEcTrackGeometryAction` come orchestrazione pura

**File:** `src/Nova/Actions/ReverseEcTrackGeometryAction.php`

Nessuna riga SQL. Stesso pattern di `ExecuteEcTrackDataChainAction:63` (`app(EcTrackService::class)`).

```php
public function handle(ActionFields $fields, Collection $models)
{
    $geometryService = app(GeometryComputationService::class);
    $ecTrackService = app(EcTrackService::class);

    $overriddenFieldsByTrack = [];

    foreach ($models as $ecTrack) {
        /** @var EcTrack $ecTrack */
        $geometryService->reverseGeometry($ecTrack);
        $ecTrack->refresh();

        $overriddenFields = $this->getOverriddenFields($ecTrack);
        if (! empty($overriddenFields)) {
            $overriddenFieldsByTrack[$ecTrack->id] = $overriddenFields;
        }

        $ecTrackService->updateDataChain($ecTrack, forceGeometryChain: true);
    }

    if (empty($overriddenFieldsByTrack)) {
        return Action::message(__('Track geometry reversed. Recalculation in progress. This may take a while.'));
    }

    $overriddenFieldNames = array_values(array_unique(array_merge(...array_values($overriddenFieldsByTrack))));

    // Secondo ciclo: Action::danger() invece di Action::message() — quest'ultimo è reso in
    // verde da Nova, identico al messaggio di successo, e un avviso che sembra una conferma
    // non viene letto (pattern già usato 18 volte nel package, es. ImportTaxonomyWhere.php:60).
    return Action::danger(__(
        'Track geometry reversed. Recalculation in progress. This may take a while. Warning: manual overrides present on: :fields — verify they still match the new direction.',
        ['fields' => implode(', ', $overriddenFieldNames)]
    ));
}
```

Il testo del messaggio di successo cambia (aggiunta "This may take a while", secondo ciclo): la
catena ora rigenera anche tile PBF e immagine profilo altimetrico, più pesante della sola DEM del
primo ciclo — va aggiornata la stringa in `resources/lang/*.json` (Task 5), non solo il codice.

Import da aggiungere: `GeometryComputationService`, `EcTrackService`. Rimuovere l'import di
`UpdateEcTrackDemJob` e `DB`, non più usati nell'Action.

## Task 4 — Test Pest: geometria multi-parte

**File:** `tests/Feature/Nova/Actions/ReverseEcTrackGeometryActionTest.php`

Aggiungere il test proposto in review (verificato a mano contro il Task 1), **scritto prima del
fix — deve fallire sul codice del primo ciclo**:

```php
public function test_it_reverses_a_multipart_geometry_including_the_order_of_the_parts()
{
    // Due tratti distinti e non contigui: la traccia va da (0 0) a (11 11).
    // Dopo l'inversione deve andare da (11 11) a (0 0) — quindi non basta
    // ribaltare i vertici dentro ogni tratto, va ribaltato anche l'ordine dei tratti.
    $track = $this->createTrackWithGeometry([], "MULTILINESTRING Z((0 0 0, 1 1 100),(10 10 200, 11 11 300))");

    (new ReverseEcTrackGeometryAction)->handle(
        new ActionFields(collect(), collect()),
        collect([$track])
    );

    $wkt = DB::selectOne(
        'SELECT ST_AsText(geometry::geometry) as wkt FROM ec_tracks WHERE id = ?',
        [$track->id]
    )->wkt;

    $this->assertEquals(
        'MULTILINESTRING Z ((11 11 300,10 10 200),(1 1 100,0 0 0))',
        $wkt
    );
}
```

`createTrackWithGeometry()` va esteso con un secondo parametro opzionale per la geometria WKT
(default quella già usata dai test esistenti, a parte singola), per non duplicare il metodo.

Dopo che il test passa, verificare a mano (non con un test automatico — nessuna traccia di
produzione nei test) anche sulla traccia reale **id 334** in locale (12 parti), come indicato in
review.

## Task 5 — Test Pest: `forceGeometryChain` e catena completa

**File:** `tests/Feature/Nova/Actions/ReverseEcTrackGeometryActionTest.php`

Il test esistente `test_it_dispatches_the_dem_recalculation_job` verificava solo
`UpdateEcTrackDemJob`. Con la catena completa del Task 2/3, sostituirlo con un'asserzione sull'intera
catena dispatchata (usando `Bus::assertChained` sui job attesi quando `wasChanged('geometry')` è
falso ma `forceGeometryChain` è vero):

```php
public function test_it_dispatches_the_full_recalculation_chain_even_though_eloquent_never_detects_the_write()
{
    // Sostituisce test_it_dispatches_the_recalculation_even_though_eloquent_never_detects_the_write
    // del primo ciclo: non basta più il solo job DEM, la catena forzata da forceGeometryChain
    // ne accoda dieci (EcTrackService::updateDataChain()).
    Event::fake(['eloquent.updated: '.EcTrack::class]);

    $track = $this->createTrackWithGeometry();

    (new ReverseEcTrackGeometryAction)->handle(
        new ActionFields(collect(), collect()),
        collect([$track])
    );

    Event::assertNotDispatched('eloquent.updated: '.EcTrack::class);
    Bus::assertChained([
        UpdateEcTrackDemJob::class,
        UpdateEcTrackManualDataJob::class,
        UpdateEcTrackCurrentDataJob::class,
        UpdateEcTrack3DDemJob::class,
        UpdateEcTrackSlopeValues::class,
        UpdateModelWithGeometryTaxonomyWhere::class,
        UpdateEcTrackGenerateElevationChartImage::class,
        GenerateEcTrackPBFBatch::class,
        UpdateEcTrackAwsJob::class,
        UpdateEcTrackAppRelationsInfoJob::class,
        UpdateEcTrackOrderRelatedPoi::class,
    ]);
}
```

Verificare la sintassi esatta di `Bus::assertChained` contro la versione di Laravel installata
(accetta anche istanze oltre alle classi, ma qui i job non hanno costruttori con side-effect da
verificare oltre alla classe). Il test `test_it_reverses_the_geometry_in_the_database` e
`test_it_warns_about_manual_overrides_on_direction_dependent_fields` /
`test_it_does_not_warn_without_manual_overrides` restano invariati nella struttura, solo
nell'implementazione sottostante (chiamano `reverseGeometry()` indirettamente tramite l'Action).

## Task 6 — Cleanup: test locale-indipendente sul warning

**File:** `tests/Feature/Nova/Actions/ReverseEcTrackGeometryActionTest.php:113`

`test_it_does_not_warn_without_manual_overrides` cerca la stringa inglese `'Warning'`: con locale
`it` il test passerebbe sempre, indipendentemente dal codice. Sostituire l'assert con qualcosa di
non legato alla lingua — il nome del campo che comparirebbe in `:fields` se ci fosse un warning:

```php
$this->assertStringNotContainsString('ascent', json_encode($result));
```

E per `test_it_warns_about_manual_overrides_on_direction_dependent_fields`, verificare che il
messaggio sia effettivamente quello di `Action::danger()` (struttura della risposta, non solo il
testo) — l'assert attuale su `json_encode($result)` contenente `'ascent'` resta valido, ma va
verificato che `ActionResponse::danger()` serializzi il testo nello stesso punto di
`ActionResponse::message()` (stessa struttura, campo diverso solo nel colore reso da Nova).

## Task 7 — Permessi: azione riservata al ruolo Administrator

**File:** `src/Nova/EcTrack.php` (riga 107, metodo `actions()`)

Vedi la nota di correzione in testa a questo piano: il pattern usato è quello per **ruolo**
(`hasRole('Administrator')`, come in `App.php:843,848`), non quello per super-admin via email
(`App.php:153-154`).

```php
$administratorOnly = fn (NovaRequest $request) => optional($request->user())->hasRole('Administrator');

// nella lista di actions():
(new ReverseEcTrackGeometryAction)
    ->sole()
    ->canSee($administratorOnly)
    ->canRun($administratorOnly),
```

## Task 8 — Traduzioni mancanti (gap, non richiesto esplicitamente dalla review)

**File:** `resources/lang/{de,es,fr}.json`

Verificato: le chiavi `"Reverse Track Geometry"` e i due messaggi di risposta sono presenti solo in
`en.json`/`it.json` (primo ciclo). Il requisito originale ("tutti i `resources/lang/*.json`
esistenti: de, en, es, fr, it") non era stato completato. Aggiungere le 3 chiavi ai 3 file mancanti
— pattern verificato in `resources/lang/it.json:324-326` — con il testo aggiornato del Task 3
(messaggio di successo con "This may take a while").

## Task 9 — Rebase con `develop`

Fatto per ultimo, il più vicino possibile alla fine del ciclo (rischio già accettato in overview:
se `develop` avanza ancora, il conflitto potrebbe non essere più solo questo — verificato ora,
22/09/2026, un solo file in conflitto):

**File in conflitto:** `.claude/rules/nova.md`. `develop` (via oc:8569) ha aggiunto
`src/TrailRegistry/Nova/**` ai `paths:` e due voci sulle Action Nova (comportamento di
`$request->resourceId` con `dependsOn()`, `canRun()` preso dall'istanza della Resource nei test);
questo branch (oc:8543) ha aggiunto le voci su `->sole()` lato server e `->afterCommit()` nelle
Action. Nessun conflitto di merito: unire entrambi i set di voci, non scegliere tra i due.

```bash
git fetch origin develop
git rebase origin/develop
# risolvere .claude/rules/nova.md tenendo entrambe le sezioni aggiunte (oc:8543 + oc:8569)
```

## Task 10 — CI: sblocco `composer install` sugli advisory

**File:** `composer.json` (sezione `config`)

Verificato sui log della CI attuale (job P8.3/P8.4 di questa stessa PR,
`gh api repos/webmappsrl/wm-package/actions/jobs/<id>/logs`): il blocco non è specifico di questa
PR, è strutturale — riguarda ogni PR del repo dal momento in cui Composer ha iniziato a bloccare la
risoluzione su `laravel/framework 11.*` (matrice CI) per 7 advisory, più altri 5 che si aggiungono
per `ebess/advanced-nova-media-library` (che nel suo `require` ammette anche Laravel 8-10, quindi
allarga l'insieme di versioni che Composer deve considerare durante la risoluzione).

**Verificato ID per ID** (query a `packagist.org/api/security-advisories`), non un `ignore`
generico:

| ID | Cosa | Si applica alla versione risolta? |
|---|---|---|
| `PKSA-m5cs-t1y6-qpcs` | Signed URL Path Confusion (`<12.61.1`) | **Sì** — nessuna patch esiste sul ramo 11.x, fix solo da 12.61.1 |
| `PKSA-3r5d-mb8f-1qw9` | CRLF injection default email rule (`<12.60.0`) | **Sì** — stesso motivo, CVE-2026-48019 |
| `PKSA-mdq4-51ck-6kdq` | Duplicato del precedente (fonte diversa, stesso CVE) | **Sì** — stesso motivo |
| `PKSA-8qx3-n5y5-vvnd` | File Validation Bypass (`>=11.0,<11.44.1`) | No, già risolto in 11.44.1+ — bloccato solo perché il resolver considera l'intero intervallo `11.*` |
| `PKSA-q46n-4fdk-zjr4` | XSS route param debug (`>=11.9,<11.36`) | No, già risolto in 11.36+ |
| `PKSA-qzrn-rnz3-85w1` | XSS request param debug (`>=11.9,<11.36`) | No, già risolto in 11.36+ |
| `PKSA-w7xr-vk7n-rstm` | Env manipulation via query string (`<11.31.0`) | No, già risolto in 11.31+ |
| `PKSA-p3rx-dfwp-73ny` | Image upload bypass (Laravel 8.x) | No — richiesto solo perché `ebess` ammette Laravel 8 nel suo `require`, mai installato qui |
| `PKSA-ckwp-rt7t-c46m` | SQL Server LIMIT/OFFSET injection (Laravel 6-8.40) | No — il package usa PostgreSQL, non SQL Server; versione mai installata |
| `PKSA-4npr-btr6-zhny` | Unexpected bindings QueryBuilder (Laravel 6-8.24) | No — versione mai installata |
| `PKSA-njrm-6dtg-m2pc` | XSS in Blade (Laravel <8.75) | No — versione mai installata |
| `PKSA-985r-hryy-555b` | Unexpected bindings QueryBuilder (Laravel 6-8.22) | No — versione mai installata |

**Decisione da confermare col dev prima di applicare** (non è puramente tecnica): i primi 3 ID
sono un rischio reale accettato per continuare a testare Laravel 11.x in CI, perché Laravel non ha
mai rilasciato una patch 11.x per quelle due CVE (fix solo da 12.60/12.61). Le alternative sono:
(a) ignorarli con motivazione esplicita (questo task), accettando che l'ambiente CI del leg L11
resti su un Laravel non patchato per queste 2 CVE — non i consumer, che tipicamente installano
`^12`/`^13`; oppure (b) togliere `11.*` dalla matrice CI (`run-tests.yml:15`) se il supporto a
Laravel 11 non è più necessario — decisione più ampia della singola PR, di competenza del dev.
Gli altri 9 ID sono ignorabili senza contropartita: la versione realmente installabile non è mai
quella affetta.

```json
"config": {
    "sort-packages": true,
    "allow-plugins": { "...": "..." },
    "policy": {
        "advisories": {
            "ignore-id": [
                "PKSA-m5cs-t1y6-qpcs",
                "PKSA-3r5d-mb8f-1qw9",
                "PKSA-mdq4-51ck-6kdq",
                "PKSA-8qx3-n5y5-vvnd",
                "PKSA-q46n-4fdk-zjr4",
                "PKSA-qzrn-rnz3-85w1",
                "PKSA-w7xr-vk7n-rstm",
                "PKSA-p3rx-dfwp-73ny",
                "PKSA-ckwp-rt7t-c46m",
                "PKSA-4npr-btr6-zhny",
                "PKSA-njrm-6dtg-m2pc",
                "PKSA-985r-hryy-555b"
            ]
        }
    }
}
```

## Task 11 — Verifica esecuzione test (best-effort, non bloccante per il merge del codice)

Il blocco licenza Nova impedisce di eseguire `composer test` nel Docker standalone di
`wm-package`. Verificato in questa sessione: `php-forestas` monta il submodule reale
(`/var/www/html/forestas/vendor/wm/wm-package` è un symlink a
`/var/www/html/forestas/wm-package`, licenza Nova valida su questo container), ma il database
`wm_package` richiesto da `phpunit.xml.dist` **non esiste ancora** su `postgres-forestas` (da
creare una tantum con PostGIS, oc:8469 — già noto, non nuovo di questo ciclo).

Se il dev vuole sbloccare l'esecuzione ora:
1. Creare il database `wm_package` con estensione PostGIS su `postgres-forestas`.
2. Eseguire `cd wm-package && ../vendor/bin/pest` da dentro `php-forestas`, con `DB_HOST`
   sovrascritto per puntare a `postgres-forestas` (il default `db` di `phpunit.xml.dist` è il
   nome del servizio nel docker-compose standalone del package, non esiste in questa rete).

Se non è una priorità per questo ciclo, resta un follow-up in `notes.md` — il codice dei Task 1-8
va comunque scritto e revisionato, ma senza la prova di esecuzione richiesta esplicitamente dalla
review finché questo blocco non si risolve.

---

## Ordine di esecuzione consigliato

1-8 (codice: service, chain, action, permessi, test, traduzioni) → 9 (rebase, per ultimo, così il
conflitto si risolve una volta sola sul codice finale) → 10 (CI, richiede conferma del dev sulla
decisione di rischio) → 11 (best-effort).

## Commit

Un commit per gruppo logico, tutti con lo stesso scope `oc:8543`:

```
refactor(oc:8543): sposta l'inversione della geometria in GeometryComputationService
fix(oc:8543): gestisci MultiLineString multi-parte nell'inversione della geometria
fix(oc:8543): rilancia l'intera data chain dopo l'inversione, non solo il DEM
fix(oc:8543): limita l'azione di inversione al ruolo Administrator
fix(oc:8543): aggiungi traduzioni mancanti de/es/fr per l'azione di inversione
chore(oc:8543): rebase su develop
chore(oc:8543): ignora gli advisory Composer che bloccano composer install in CI
```
