> Ticket: oc:8543

# Piano — Inversione della traccia: attivazione su Forestas

> **Il piano valido è la sezione [Terzo ciclo (24/09/2026)](#terzo-ciclo-24092026) in fondo.**
> I task 1-11 qui sotto appartengono al secondo ciclo: sono eseguiti o superati e **non vanno
> rieseguiti**. Restano come storia, perché commit e `notes.md` vi fanno riferimento.

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

---

## Terzo ciclo (24/09/2026)

> **Per chi esegue:** usa `superpowers:subagent-driven-development` o `superpowers:executing-plans`,
> task per task. I passi hanno le checkbox (`- [ ]`). **Nessun `git commit`, `git add` o
> `git push`**: i blocchi «Commit» sono istruzioni per lo sviluppatore, che committa da sé.

**Obiettivo:** l'Action «Inverti verso della traccia» fa scegliere all'utente se invertire la
geometria e quali coppie di dati scambiare; tutta l'operazione sta in `EcTrackService::reverse()`.

**Architettura:** `reverse()` inverte la geometria via SQL (`GeometryComputationService::reverseGeometry()`,
già esistente), scambia le coppie scelte con un update mirato della colonna `properties` e, dopo il
commit, accoda una catena dedicata e reindicizza la traccia. La catena con la geometria invertita
nasce da un metodo comune a `updateDataChain()`, meno quattro esclusioni.

**Stack:** Laravel 12 nel container `php-forestas` (PHP 8.4), Nova 5.7.6, PostgreSQL + PostGIS,
Scout con il driver Elasticsearch di Matchish, Pest.

**Specifica:** [overview.md](overview.md), sezioni «Terzo ciclo (23/09/2026)» e «Terzo ciclo —
revisione del 24/09/2026». Dove si contraddicono vale la seconda.

**Repo e branch:** tutto in `wm-package`, branch
`feature/oc-8543-inversione-della-traccia-attivazione-su-forestas`. In `forestas` nessuna modifica.

### Requisiti validi — elenco unico

Questo è l'unico elenco da seguire. Le checkbox dei cicli precedenti, nell'overview e nei task 1-11
sopra, **non vanno eseguite**.

| # | Requisito | Task |
|---|---|---|
| R1 | Blocco dei job legati alla geometria estratto in `geometryDependentJobs()`, coda in `publicationJobs()`; `updateDataChain()` accoda gli stessi job nello stesso ordine | 1 |
| R2 | Via `$forceGeometryChain` e `$chain[0]->afterCommit()` da `updateDataChain()` | 1 |
| R3 | `EcTrackService::reverse(EcTrack $track, bool $geometry, array $swaps): array` | 2 |
| R4 | Catena con geometria invertita: `UpdateEcTrackDemJob`, `UpdateEcTrackSlopeValues`, `UpdateEcTrackGenerateElevationChartImage`, `GenerateEcTrackPBFBatch`, `UpdateEcTrackAwsJob`, `UpdateEcTrackAppRelationsInfoJob`, `UpdateEcTrackOrderRelatedPoi` (esclusi `UpdateEcTrackManualDataJob`, `UpdateEcTrackCurrentDataJob`, `UpdateEcTrack3DDemJob`, `SyncModelTaxonomyWhereJob`) | 2 |
| R5 | Catena con solo scambi: `GenerateEcTrackPBFBatch`, `UpdateEcTrackAwsJob` | 2 |
| R6 | Coppie: `ascent`/`descent`, `ele_from`/`ele_to`, `duration_forward`/`duration_backward` in `properties.manual_data`; `from`/`to` al primo livello di `properties`. `distance`, `ele_min`, `ele_max` mai toccati | 2 |
| R7 | Coppia con un solo valore: il valore passa all'altro campo, quello di partenza resta vuoto. `null` e stringa vuota contano come assenti | 2 |
| R8 | Scambi scritti con un update mirato della sola colonna `properties`, mai `save()`/`saveQuietly()` del modello | 2 |
| R9 | Dopo il commit: prima la catena, poi la reindicizzazione con `fresh()->searchable()` in un `try/catch` che scrive nel log | 2 |
| R10 | Tracce con `osmid` (colonna o `properties.osmid`): nessuna scrittura, errore | 2, 4 |
| R11 | Tutti i flag spenti: nessuna scrittura, errore «Seleziona almeno un'operazione.» | 2, 4 |
| R12 | `toSearchableArray()`: `ascent` da `classifyField()['currentValue']`, cast `(int)` | 3 |
| R13 | Classe `ReverseTrackDirectionAction` (via `ReverseEcTrackGeometryAction`), nome «Inverti verso della traccia» | 4 |
| R14 | Flag «Inverti geometria» sempre visibile, acceso; un flag «Scambia :first / :second» per ogni coppia valorizzata, spento, con i valori attuali nell'`help()` («—» se manca) | 4 |
| R15 | Messaggio finale «Geometria invertita: sì/no. Scambiati: … / Nessuno. Ricalcolo in corso.» | 4 |
| R16 | Via `DIRECTION_DEPENDENT_FIELDS`, `getOverriddenFields()`, `use HasDemClassification`, avviso sugli override, docblock vecchio, chiavi di traduzione vecchie | 4 |
| R17 | Restano: solo Administrator, `->sole()`, ciclo su `$models`, `InteractsWithQueue`/`Queueable` | 4 |
| R18 | Suite completa due volte (develop e branch) nel container, confronto in `notes.md`; verifica a mano in Nova | 5 |

### Vincoli globali

- PHP minimo del package `>8.1`: niente `const` dentro un trait (le costanti qui sono su classi).
- Le geometrie passano sempre da SQL puro, mai dall'ORM.
- Le traduzioni del package stanno in `resources/lang/*.json` (`en.json` e `it.json`; de/es/fr non
  contengono traduzioni di Action, decisione del secondo ciclo).
- Commenti e messaggi di commit in italiano; termini tecnici in inglese.
- `composer format` riformatta tutto il repo: dopo averlo lanciato, tieni solo i file di questo
  lavoro (`git status`, poi `git checkout -- <file estranei>`).
- Test nel container: `docker exec php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest <args>"`.
  Il DB dei test è `wm_package` (da `phpunit.xml.dist`): prima di lanciare verifica che in
  `wm-package/` **non** esista un `phpunit.xml` locale, che avrebbe la precedenza.

### Punti da controllare in review

Casi che la specifica implica e che è facile rompere; ciascuno ha un test nel task indicato.

1. `manual_data` salvato come stringa JSON invece che come array (dati vecchi): scambio e lettura
   devono funzionare lo stesso (task 2, test `it_swaps_when_manual_data_is_a_json_string`).
2. `from`/`to` non scalari (array di traduzioni ereditati da GeoHub): l'`help()` non deve andare in
   errore (task 4, test `help_shows_non_scalar_values`).
3. Nova senza risorsa selezionata (`selectedResources()` vuoto o `null` con «seleziona tutto»):
   solo il flag della geometria, nessun errore (task 4, test `fields_without_selection`).
4. Scambio chiesto su una coppia che nel frattempo non ha più valori, con la geometria spenta:
   nessuna scrittura e nessun job (task 2, test `it_does_nothing_when_requested_pairs_are_empty`).
5. Elasticsearch che fallisce dopo il commit: catena accodata, nessuna eccezione verso Nova, errore
   nel log (task 2, test `it_dispatches_the_chain_even_if_reindexing_fails`).

---

### Task 1 — Metodo comune per il blocco geometria in `EcTrackService`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1--comando-dei-test)

**File:**
- Modifica: `src/Services/Models/EcTrackService.php` (`updateDataChain()`, oggi righe ~351-397)
- Test: `tests/Unit/Services/EcTrackService/UpdateDataChainTest.php`

**Interfacce prodotte:**
- `public function geometryDependentJobs(EcTrack $track, array $except = []): array` — lista di job
  nell'ordine attuale, senza quelli le cui classi sono in `$except`.
- `public function publicationJobs(EcTrack $track): array` — `UpdateEcTrackAwsJob`,
  `UpdateEcTrackAppRelationsInfoJob`, `UpdateEcTrackOrderRelatedPoi`.
- `updateDataChain(EcTrack $track)` senza secondo parametro.

- [ ] **Passo 1: prima di toccare il codice, lancia su `develop` il test esistente** e annota l'esito
  in `notes.md`. Il primo test di `UpdateDataChainTest` elenca i job in un ordine che non sembra
  quello del codice (PBF in fondo, `UpdateEcTrackAppRelationsInfoJob` assente): potrebbe fallire già
  oggi, e in quel caso non è una regressione di questo lavoro.

```bash
cd /Users/bongiu/Documents/geobox2/forestas/wm-package && git stash list && git switch --detach origin/develop
docker exec php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest tests/Unit/Services/EcTrackService/UpdateDataChainTest.php"
git switch feature/oc-8543-inversione-della-traccia-attivazione-su-forestas
```

- [ ] **Passo 2: scrivi il test che fissa la catena di `updateDataChain()`** in fondo a
  `UpdateDataChainTest`:

```php
    public function test_update_data_chain_keeps_the_same_jobs_in_the_same_order()
    {
        // L'estrazione di geometryDependentJobs()/publicationJobs() sposta righe, non le cambia:
        // la catena standard deve restare identica (oc:8543).
        $track = EcTrack::factory()->createQuietly();
        $track->geometry = 'LINESTRING(1 1 0, 2 2 0)';
        $track->saveQuietly();

        $this->ecTrackService->updateDataChain($track);

        Bus::assertChained([
            UpdateEcTrackDemJob::class,
            UpdateEcTrackManualDataJob::class,
            UpdateEcTrackCurrentDataJob::class,
            UpdateEcTrack3DDemJob::class,
            UpdateEcTrackSlopeValues::class,
            SyncModelTaxonomyWhereJob::class,
            UpdateEcTrackGenerateElevationChartImage::class,
            GenerateEcTrackPBFBatch::class,
            UpdateEcTrackAwsJob::class,
            UpdateEcTrackAppRelationsInfoJob::class,
            UpdateEcTrackOrderRelatedPoi::class,
        ]);
    }

    public function test_geometry_dependent_jobs_skips_the_excluded_classes()
    {
        $track = EcTrack::factory()->createQuietly();

        $jobs = $this->ecTrackService->geometryDependentJobs($track, [
            UpdateEcTrackManualDataJob::class,
            UpdateEcTrackCurrentDataJob::class,
        ]);

        $this->assertSame([
            UpdateEcTrackDemJob::class,
            UpdateEcTrack3DDemJob::class,
            UpdateEcTrackSlopeValues::class,
            SyncModelTaxonomyWhereJob::class,
            UpdateEcTrackGenerateElevationChartImage::class,
            GenerateEcTrackPBFBatch::class,
        ], array_map(fn ($job) => $job::class, $jobs));
    }
```

  Aggiungi in testa al file `use Wm\WmPackage\Jobs\Track\UpdateEcTrackAppRelationsInfoJob;`.

- [ ] **Passo 3: lancia i due test e verifica che il secondo fallisca** («Call to undefined method
  geometryDependentJobs»). Il primo deve già passare: descrive la catena di oggi.

```bash
docker exec php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest tests/Unit/Services/EcTrackService/UpdateDataChainTest.php"
```

- [ ] **Passo 4: implementa.** In `EcTrackService` sostituisci `updateDataChain()` e il suo docblock
  con:

```php
    /**
     * I job del ricalcolo che dipendono dalla geometria, nell'ordine in cui vanno accodati.
     *
     * È la lista comune a updateDataChain() e reverse(): un job aggiunto qui entra anche nella
     * catena dell'inversione, a meno che reverse() non lo escluda di proposito (oc:8543).
     *
     * @param  array<int, class-string>  $except  classi da togliere dalla lista
     * @return array<int, object>
     */
    public function geometryDependentJobs(EcTrack $track, array $except = []): array
    {
        $jobs = [
            new UpdateEcTrackDemJob($track),
            new UpdateEcTrackManualDataJob($track),
            new UpdateEcTrackCurrentDataJob($track),
            new UpdateEcTrack3DDemJob($track),
            new UpdateEcTrackSlopeValues($track),
            new SyncModelTaxonomyWhereJob($track),
            new UpdateEcTrackGenerateElevationChartImage($track),
            new GenerateEcTrackPBFBatch($track),
        ];

        return array_values(array_filter(
            $jobs,
            fn ($job) => ! in_array($job::class, $except, true)
        ));
    }

    /**
     * I job che portano la traccia ad app, mappa e relazioni: la coda di ogni catena di update.
     *
     * @return array<int, object>
     */
    public function publicationJobs(EcTrack $track): array
    {
        return [
            new UpdateEcTrackAwsJob($track),
            new UpdateEcTrackAppRelationsInfoJob($track),
            new UpdateEcTrackOrderRelatedPoi($track),
        ];
    }

    public function updateDataChain(EcTrack $track)
    {
        $chain = [];
        if (isset($track->properties['osmid']) && $track->properties['osmid']) {
            $chain[] = new UpdateEcTrackFromOsmJob($track);
        }
        // $layers = $track->associatedLayers;
        // // Verifica se ci sono layers associati
        // if ($layers && $layers->count() > 0) {
        //     foreach ($layers as $layer) {
        //         $chain[] = new UpdateLayerTracksJob($layer);
        //     }
        // }
        if ($track->wasChanged('geometry')) {
            array_push($chain, ...$this->geometryDependentJobs($track));
        }

        array_push($chain, ...$this->publicationJobs($track));

        Bus::chain($chain)->dispatch();
    }
```

  Il blocco commentato sui layer c'è anche su `develop`: lascialo com'è. `createDataChain()` non si
  tocca: ha una lista diversa (niente PBF, niente `UpdateEcTrackAppRelationsInfoJob`) e non è nello
  scope.

- [ ] **Passo 5: lancia i test e verifica che passino.** Stesso comando del passo 3.

- [ ] **Passo 6: controlla che nessun altro chiami `updateDataChain()` con due argomenti.**

```bash
grep -rn "updateDataChain(" src tests
```

  Atteso: nessuna chiamata con `forceGeometryChain` fuori dalla vecchia Action, che il task 4 riscrive.

- [ ] **Commit** (lo esegue lo sviluppatore):

```bash
git add src/Services/Models/EcTrackService.php tests/Unit/Services/EcTrackService/UpdateDataChainTest.php
git commit -m "refactor(oc:8543): estrai il blocco geometria di updateDataChain in un metodo comune"
```

---

### Task 2 — `EcTrackService::reverse()`

**File:**
- Modifica: `src/Services/Models/EcTrackService.php`
- Crea: `tests/Unit/Services/EcTrackService/ReverseTest.php`

**Interfacce consumate:** `geometryDependentJobs()`, `publicationJobs()` (task 1);
`GeometryComputationService::reverseGeometry(MultiLineString $model): void` (già presente).

**Interfacce prodotte:**
- `public const REVERSE_SWAP_PAIRS` — `chiave => [contenitore|null, primo, secondo, etichetta primo, etichetta secondo]`,
  chiavi `ascent_descent`, `ele_from_ele_to`, `duration_forward_duration_backward`, `from_to`.
- `public const REVERSE_EXCLUDED_JOBS` — le quattro classi escluse.
- `public function directionPairs(EcTrack $track): array` — `chiave => [valore primo, valore secondo]`
  solo per le coppie con almeno un valore; `null` al posto del valore mancante.
- `public function isOsmTrack(EcTrack $track): bool`
- `public function reverse(EcTrack $track, bool $geometry, array $swaps): array` — restituisce le
  chiavi effettivamente scambiate; lancia `InvalidArgumentException` se la traccia è OSM o se non c'è
  nulla da fare.

- [ ] **Passo 1: scrivi i test.** Crea `tests/Unit/Services/EcTrackService/ReverseTest.php`:

```php
<?php

namespace Tests\Unit\Services\EcTrackService;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Wm\WmPackage\Jobs\Pbf\GenerateEcTrackPBFBatch;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncModelTaxonomyWhereJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrack3DDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAppRelationsInfoJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackCurrentDataJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackGenerateElevationChartImage;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackManualDataJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackOrderRelatedPoi;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackSlopeValues;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Models\EcTrackService;

class ReverseTest extends AbstractEcTrackServiceTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        CountingEngine::$updates = 0;
        // I test del package non registrano ScoutServiceProvider: senza il singleton ogni
        // app(EngineManager::class) è un'istanza nuova e gli engine registrati qui si perdono.
        $this->app->singleton(EngineManager::class, fn ($app) => new EngineManager($app));
        app(EngineManager::class)->extend('counting', fn () => new CountingEngine);
        app(EngineManager::class)->extend('failing', fn () => new FailingEngine);
        config(['scout.driver' => 'counting']);
    }

    private function track(array $properties = [], string $wkt = 'MULTILINESTRING Z((0 0 0, 1 1 100, 2 2 200))'): EcTrack
    {
        // osmid esplicito: la factory lo valorizza a caso nel 70% dei casi, e una traccia OSM
        // è in sola lettura per l'inversione.
        return EcTrack::factory()->createQuietly([
            'geometry' => DB::raw("ST_GeomFromText('{$wkt}', 4326)"),
            'properties' => $properties,
            'osmid' => null,
        ]);
    }

    private function wkt(EcTrack $track): string
    {
        return DB::selectOne('SELECT ST_AsText(geometry::geometry) AS wkt FROM ec_tracks WHERE id = ?', [$track->id])->wkt;
    }

    private function storedProperties(EcTrack $track): array
    {
        return EcTrack::query()->findOrFail($track->id)->properties;
    }

    public function test_it_excludes_exactly_four_jobs_from_the_geometry_block()
    {
        $this->assertSame([
            UpdateEcTrackManualDataJob::class,
            UpdateEcTrackCurrentDataJob::class,
            UpdateEcTrack3DDemJob::class,
            SyncModelTaxonomyWhereJob::class,
        ], EcTrackService::REVERSE_EXCLUDED_JOBS);
    }

    public function test_geometry_only_reverses_the_geometry_and_keeps_the_data()
    {
        $properties = ['manual_data' => ['ascent' => 500, 'descent' => 300], 'from' => 'A', 'to' => 'B'];
        $track = $this->track($properties);

        $swapped = $this->ecTrackService->reverse($track, true, []);

        $this->assertSame([], $swapped);
        $this->assertSame('MULTILINESTRING Z ((2 2 200,1 1 100,0 0 0))', $this->wkt($track));
        $this->assertSame($properties['manual_data'], $this->storedProperties($track)['manual_data']);
        $this->assertSame('A', $this->storedProperties($track)['from']);
        Bus::assertChained([
            UpdateEcTrackDemJob::class,
            UpdateEcTrackSlopeValues::class,
            UpdateEcTrackGenerateElevationChartImage::class,
            GenerateEcTrackPBFBatch::class,
            UpdateEcTrackAwsJob::class,
            UpdateEcTrackAppRelationsInfoJob::class,
            UpdateEcTrackOrderRelatedPoi::class,
        ]);
    }

    public function test_swaps_only_keep_the_geometry_and_use_the_short_chain()
    {
        $track = $this->track([
            'manual_data' => ['ascent' => 500, 'descent' => 300, 'ele_from' => 10, 'ele_to' => 90],
            'from' => 'A',
            'to' => 'B',
        ]);
        $before = $this->wkt($track);

        $swapped = $this->ecTrackService->reverse($track, false, ['ascent_descent', 'from_to']);

        $this->assertSame(['ascent_descent', 'from_to'], $swapped);
        $this->assertSame($before, $this->wkt($track));
        $stored = $this->storedProperties($track);
        $this->assertSame(300, $stored['manual_data']['ascent']);
        $this->assertSame(500, $stored['manual_data']['descent']);
        $this->assertSame(10, $stored['manual_data']['ele_from']);
        $this->assertSame('B', $stored['from']);
        $this->assertSame('A', $stored['to']);
        Bus::assertChained([GenerateEcTrackPBFBatch::class, UpdateEcTrackAwsJob::class]);
    }

    public function test_a_pair_with_one_value_moves_it_and_empties_the_origin()
    {
        $track = $this->track(['manual_data' => ['ascent' => 500, 'descent' => null], 'from' => 'A']);

        $this->ecTrackService->reverse($track, false, ['ascent_descent', 'from_to']);

        $stored = $this->storedProperties($track);
        $this->assertSame(500, $stored['manual_data']['descent']);
        $this->assertArrayNotHasKey('ascent', $stored['manual_data']);
        $this->assertSame('A', $stored['to']);
        $this->assertArrayNotHasKey('from', $stored);
    }

    public function test_distance_and_elevation_extremes_are_never_touched()
    {
        $track = $this->track(['manual_data' => ['ascent' => 1, 'distance' => 12.5, 'ele_min' => 5, 'ele_max' => 50]]);

        $this->ecTrackService->reverse($track, true, array_keys(EcTrackService::REVERSE_SWAP_PAIRS));

        $stored = $this->storedProperties($track)['manual_data'];
        $this->assertSame(12.5, $stored['distance']);
        $this->assertSame(5, $stored['ele_min']);
        $this->assertSame(50, $stored['ele_max']);
    }

    public function test_it_swaps_when_manual_data_is_a_json_string()
    {
        $track = $this->track(['manual_data' => json_encode(['ascent' => 500, 'descent' => 300])]);

        $this->ecTrackService->reverse($track, false, ['ascent_descent']);

        $stored = $this->storedProperties($track)['manual_data'];
        $this->assertSame(300, $stored['ascent']);
        $this->assertSame(500, $stored['descent']);
    }

    public function test_direction_pairs_lists_only_pairs_with_a_value()
    {
        $track = $this->track([
            'manual_data' => ['ascent' => 500, 'ele_from' => '', 'duration_forward' => null],
            'to' => 'B',
        ]);

        $this->assertSame([
            'ascent_descent' => [500, null],
            'from_to' => [null, 'B'],
        ], $this->ecTrackService->directionPairs($track));
    }

    public function test_nothing_selected_throws_and_writes_nothing()
    {
        $track = $this->track(['manual_data' => ['ascent' => 500]]);
        $before = $this->wkt($track);

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->ecTrackService->reverse($track, false, []);
        } finally {
            $this->assertSame($before, $this->wkt($track));
            Bus::assertNothingDispatched();
        }
    }

    public function test_it_does_nothing_when_requested_pairs_are_empty()
    {
        $track = $this->track(['manual_data' => ['distance' => 3]]);

        $swapped = $this->ecTrackService->reverse($track, false, ['ascent_descent']);

        $this->assertSame([], $swapped);
        Bus::assertNothingDispatched();
        $this->assertSame(0, CountingEngine::$updates);
    }

    public function test_osm_tracks_are_read_only()
    {
        $byColumn = $this->track();
        $byColumn->osmid = 123;
        $byColumn->saveQuietly();
        $byProperty = $this->track(['osmid' => 456]);

        foreach ([$byColumn, $byProperty] as $track) {
            $before = $this->wkt($track);
            try {
                $this->ecTrackService->reverse($track, true, []);
                $this->fail('Una traccia OSM non deve essere invertita');
            } catch (InvalidArgumentException) {
                $this->assertSame($before, $this->wkt($track));
            }
        }
        Bus::assertNothingDispatched();
    }

    public function test_it_reindexes_the_track_once()
    {
        $track = $this->track(['manual_data' => ['ascent' => 500]]);

        $this->ecTrackService->reverse($track, true, []);
        $this->assertSame(1, CountingEngine::$updates);

        $this->ecTrackService->reverse($track, false, ['ascent_descent']);
        $this->assertSame(2, CountingEngine::$updates);
    }

    public function test_it_dispatches_the_chain_even_if_reindexing_fails()
    {
        config(['scout.driver' => 'failing']);
        Log::spy();
        $track = $this->track();

        $this->ecTrackService->reverse($track, true, []);

        Bus::assertChained([
            UpdateEcTrackDemJob::class,
            UpdateEcTrackSlopeValues::class,
            UpdateEcTrackGenerateElevationChartImage::class,
            GenerateEcTrackPBFBatch::class,
            UpdateEcTrackAwsJob::class,
            UpdateEcTrackAppRelationsInfoJob::class,
            UpdateEcTrackOrderRelatedPoi::class,
        ]);
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($message) => str_contains($message, 'Bulk update error'));
    }

    public function test_manual_data_survives_the_dem_recalculation()
    {
        // Il test che mancava nei cicli precedenti: senza Bus::fake() sul job che scrive
        // properties, gli override devono restare dopo il ricalcolo DEM. Si esegue a mano solo
        // UpdateEcTrackDemJob (DemClient finto): AWS, PBF e profilo scriverebbero su storage
        // esterni, stessa classe di incidente di oc:8251 (oc:8543).
        $track = $this->track(['manual_data' => ['ascent' => 500, 'descent' => 300]]);

        $this->ecTrackService->reverse($track, true, ['ascent_descent']);
        (new UpdateEcTrackDemJob(EcTrack::query()->findOrFail($track->id)))->handle($this->ecTrackService);

        $stored = $this->storedProperties($track);
        $this->assertSame(['ascent' => 300, 'descent' => 500], $stored['manual_data']);
        $this->assertSame(300, $stored['dem_data']['ascent']);
    }
}

class CountingEngine extends NullEngine
{
    public static int $updates = 0;

    public function update($models)
    {
        static::$updates += $models->count();
    }
}

class FailingEngine extends NullEngine
{
    public function update($models)
    {
        throw new \Exception('Bulk update error');
    }
}
```

  Note per chi esegue:
  - `AbstractEcTrackServiceTest` usa `DatabaseTransactions`: le callback di `DB::afterCommit()`
    partono quando si chiude la transazione aperta da `reverse()`, perché Laravel conta la
    transazione del test come livello di partenza. Se un test trova la catena vuota, verifica questo
    punto prima di cambiare il codice.
  - `MockGeometryComputationService` estende quello vero e non ridefinisce `reverseGeometry()`:
    l'inversione nei test è quella reale.
  - Se il valore numerico torna come stringa da `properties` (`'300'` invece di `300`), correggi gli
    assert con `assertEquals`, non il codice: lo scambio non deve cambiare i tipi.

- [ ] **Passo 2: lancia i test e verifica che falliscano** («Undefined constant REVERSE_EXCLUDED_JOBS»,
  «Call to undefined method reverse»).

```bash
docker exec php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest tests/Unit/Services/EcTrackService/ReverseTest.php"
```

- [ ] **Passo 3: implementa.** In `EcTrackService` aggiungi agli `use`:

```php
use InvalidArgumentException;
use Throwable;
```

  e, dopo `publicationJobs()`:

```php
    /**
     * Coppie di dati che dipendono dal verso di percorrenza (oc:8543).
     * chiave => [contenitore, primo campo, secondo campo, etichetta primo, etichetta secondo];
     * contenitore `manual_data` = properties.manual_data, `null` = primo livello di properties.
     * distance, ele_min ed ele_max non dipendono dal verso e non compaiono.
     */
    public const REVERSE_SWAP_PAIRS = [
        'ascent_descent' => ['manual_data', 'ascent', 'descent', 'Ascent', 'Descent'],
        'ele_from_ele_to' => ['manual_data', 'ele_from', 'ele_to', 'Starting Point Elevation', 'Ending Point Elevation'],
        'duration_forward_duration_backward' => ['manual_data', 'duration_forward', 'duration_backward', 'Duration Forward', 'Duration Backward'],
        'from_to' => [null, 'from', 'to', 'Departure', 'Arrival'],
    ];

    /**
     * Job del blocco geometria che l'inversione non accoda (oc:8543):
     * - UpdateEcTrackManualDataJob: i manuali li decide l'utente con gli scambi; il job li
     *   ricalcolerebbe dal primo livello di properties, sovrascrivendo lo scambio (oc:8642);
     * - UpdateEcTrackCurrentDataJob: in coda non fa nulla (getDirty() è vuoto su un modello
     *   riletto dal DB, e la riga di getDemDataFields() va comunque in errore);
     * - UpdateEcTrack3DDemJob: la quota di ogni punto non cambia invertendo il verso;
     * - SyncModelTaxonomyWhereJob: dipende dalla forma della traccia, non dal verso.
     */
    public const REVERSE_EXCLUDED_JOBS = [
        UpdateEcTrackManualDataJob::class,
        UpdateEcTrackCurrentDataJob::class,
        UpdateEcTrack3DDemJob::class,
        SyncModelTaxonomyWhereJob::class,
    ];

    /**
     * Le tracce OSM sono in sola lettura per l'inversione: il verso si corregge su OSM, e al primo
     * salvataggio UpdateEcTrackFromOsmJob riscriverebbe comunque la geometria (oc:8543).
     */
    public function isOsmTrack(EcTrack $track): bool
    {
        return $track->osmid !== null || ! empty($track->properties['osmid'] ?? null);
    }

    /**
     * Le coppie che dipendono dal verso e hanno almeno un valore sulla traccia.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function directionPairs(EcTrack $track): array
    {
        $properties = $this->decodeArray($track->properties);
        $pairs = [];
        foreach (self::REVERSE_SWAP_PAIRS as $key => [$container, $first, $second]) {
            $bag = $container === null ? $properties : $this->decodeArray($properties[$container] ?? null);
            $firstValue = $this->presentValue($bag[$first] ?? null);
            $secondValue = $this->presentValue($bag[$second] ?? null);
            if ($firstValue !== null || $secondValue !== null) {
                $pairs[$key] = [$firstValue, $secondValue];
            }
        }

        return $pairs;
    }

    /**
     * Inverte il verso di una traccia: la geometria se richiesto, e le sole coppie scelte.
     * Richiamabile da Nova, artisan o API. Dopo il commit accoda la catena dedicata e
     * reindicizza la traccia (oc:8543).
     *
     * @param  array<int, string>  $swaps  chiavi di REVERSE_SWAP_PAIRS da scambiare
     * @return array<int, string> le chiavi effettivamente scambiate
     *
     * @throws InvalidArgumentException traccia OSM, oppure nessuna operazione scelta
     */
    public function reverse(EcTrack $track, bool $geometry, array $swaps): array
    {
        if ($this->isOsmTrack($track)) {
            throw new InvalidArgumentException("Track {$track->id} comes from OpenStreetMap: correct its direction there.");
        }

        $swaps = array_values(array_intersect(array_keys(self::REVERSE_SWAP_PAIRS), $swaps));
        if (! $geometry && $swaps === []) {
            throw new InvalidArgumentException('Nothing to do: reverse the geometry or swap at least one pair.');
        }

        $swapped = DB::transaction(function () use ($track, $geometry, $swaps) {
            if ($geometry) {
                $this->geometryComputationService->reverseGeometry($track);
            }

            return $this->swapPairs($track, $swaps);
        });

        if (! $geometry && $swapped === []) {
            return [];
        }

        $jobs = $geometry
            ? [...$this->geometryDependentJobs($track, self::REVERSE_EXCLUDED_JOBS), ...$this->publicationJobs($track)]
            // Solo scambi: bastano le tile (duration_forward da manual_data) e il JSON su AWS
            // (EcTrackResource passa da classifyField()); verificato il 24/09.
            : [new GenerateEcTrackPBFBatch($track), new UpdateEcTrackAwsJob($track)];

        // Prima la catena, poi l'indice: un errore di Elasticsearch non deve impedire il
        // ricalcolo né arrivare a Nova come errore su un'inversione già scritta.
        DB::afterCommit(function () use ($track, $jobs) {
            Bus::chain($jobs)->dispatch();
            $this->reindexForSearch($track);
        });

        return $swapped;
    }

    /**
     * Scambia le coppie scelte scrivendo la sola colonna properties: niente save(), che farebbe
     * partire l'observer, e niente saveQuietly(), che farebbe transitare la geometria dall'ORM.
     *
     * @param  array<int, string>  $swaps
     * @return array<int, string>
     */
    protected function swapPairs(EcTrack $track, array $swaps): array
    {
        if ($swaps === []) {
            return [];
        }

        $properties = $this->decodeArray(
            DB::table($track->getTable())->where('id', $track->id)->lockForUpdate()->value('properties')
        );

        $swapped = [];
        foreach ($swaps as $key) {
            [$container, $first, $second] = self::REVERSE_SWAP_PAIRS[$key];
            $bag = $container === null ? $properties : $this->decodeArray($properties[$container] ?? null);
            $firstValue = $this->presentValue($bag[$first] ?? null);
            $secondValue = $this->presentValue($bag[$second] ?? null);
            if ($firstValue === null && $secondValue === null) {
                continue;
            }

            unset($bag[$first], $bag[$second]);
            if ($secondValue !== null) {
                $bag[$first] = $secondValue;
            }
            if ($firstValue !== null) {
                $bag[$second] = $firstValue;
            }

            if ($container === null) {
                $properties = $bag;
            } else {
                $properties[$container] = $bag === [] ? null : $bag;
            }
            $swapped[] = $key;
        }

        if ($swapped !== []) {
            DB::table($track->getTable())
                ->where('id', $track->id)
                ->update(['properties' => json_encode($properties)]);
        }

        return $swapped;
    }

    protected function reindexForSearch(EcTrack $track): void
    {
        try {
            // fresh(): l'update mirato ha scritto sul DB, non sul modello in memoria.
            $track->fresh()?->searchable();
        } catch (Throwable $e) {
            Log::error("Reindicizzazione della traccia {$track->id} dopo l'inversione fallita: {$e->getMessage()}");
        }
    }

    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return is_string($value) ? (json_decode($value, true) ?: []) : [];
    }

    private function presentValue(mixed $value): mixed
    {
        return $value === null || $value === '' ? null : $value;
    }
```

  Se `EcTrackService` o `BaseService` hanno già un helper equivalente a `decodeArray()` (cerca
  `json_decode` nel file), usa quello invece di aggiungerne un altro.

- [ ] **Passo 4: lancia i test e verifica che passino.** Stesso comando del passo 2.

- [ ] **Commit** (lo esegue lo sviluppatore):

```bash
git add src/Services/Models/EcTrackService.php tests/Unit/Services/EcTrackService/ReverseTest.php
git commit -m "feat(oc:8543): aggiungi EcTrackService::reverse con scambi scelti e catene dedicate"
```

---

### Task 3 — `ascent` nell'indice come valore corrente

**File:**
- Modifica: `src/Models/EcTrack.php` (`toSearchableArray()`, riga ~743)
- Crea: `tests/Unit/Models/EcTrackSearchableArrayTest.php`

- [ ] **Passo 1: scrivi il test.**

```php
<?php

namespace Wm\WmPackage\Tests\Unit\Models;

use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Tests\TestCase;

class EcTrackSearchableArrayTest extends TestCase
{
    public function test_ascent_is_the_manual_value_when_present()
    {
        $track = EcTrack::factory()->createQuietly([
            'properties' => ['manual_data' => ['ascent' => '450'], 'dem_data' => ['ascent' => 300]],
        ]);

        $this->assertSame(450, $track->toSearchableArray()['ascent']);
    }

    public function test_ascent_falls_back_to_the_dem_value()
    {
        $track = EcTrack::factory()->createQuietly([
            'properties' => ['dem_data' => ['ascent' => 300]],
        ]);

        $this->assertSame(300, $track->toSearchableArray()['ascent']);
    }

    public function test_ascent_ignores_the_legacy_first_level_value()
    {
        // Il primo livello di properties è un'eredità di GeoHub (oc:8642): non deve più vincere.
        $track = EcTrack::factory()->createQuietly([
            'properties' => ['ascent' => 999, 'dem_data' => ['ascent' => 300]],
        ]);

        $this->assertSame(300, $track->toSearchableArray()['ascent']);
    }
}
```

  Se `toSearchableArray()` va in errore per relazioni mancanti (layer, tassonomie) sulla traccia di
  factory, crea i dati minimi che chiede invece di cambiare il metodo.

- [ ] **Passo 2: lancia il test e verifica che fallisca** (il terzo caso restituisce 999, il primo 0).

```bash
docker exec php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest tests/Unit/Models/EcTrackSearchableArrayTest.php"
```

- [ ] **Passo 3: implementa.** Sostituisci la riga di `ascent` in `toSearchableArray()`:

```php
            'ascent' => (int) ($this->classifyField($this, 'ascent')['currentValue'] ?? 0),
```

- [ ] **Passo 4: lancia il test e verifica che passi.**

- [ ] **Commit** (lo esegue lo sviluppatore):

```bash
git add src/Models/EcTrack.php tests/Unit/Models/EcTrackSearchableArrayTest.php
git commit -m "fix(oc:8543): indicizza ascent con il valore corrente, come distance e duration_forward"
```

---

### Task 4 — `ReverseTrackDirectionAction`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4--rinomina-con-mv)

**File:**
- Rinomina: `src/Nova/Actions/ReverseEcTrackGeometryAction.php` → `src/Nova/Actions/ReverseTrackDirectionAction.php` (`git mv`)
- Rinomina: `tests/Feature/Nova/Actions/ReverseEcTrackGeometryActionTest.php` → `tests/Feature/Nova/Actions/ReverseTrackDirectionActionTest.php` (`git mv`)
- Modifica: `src/Nova/EcTrack.php` (import e registrazione)
- Modifica: `resources/lang/en.json`, `resources/lang/it.json`

**Interfacce consumate:** `EcTrackService::reverse()`, `directionPairs()`, `isOsmTrack()`,
`REVERSE_SWAP_PAIRS` (task 2).

- [ ] **Passo 1: rinomina i file.**

```bash
git mv src/Nova/Actions/ReverseEcTrackGeometryAction.php src/Nova/Actions/ReverseTrackDirectionAction.php
git mv tests/Feature/Nova/Actions/ReverseEcTrackGeometryActionTest.php tests/Feature/Nova/Actions/ReverseTrackDirectionActionTest.php
```

  `git mv` modifica l'indice di git ma non crea commit: è ammesso. Se preferisci non toccare
  l'indice, usa `mv` e lascia allo sviluppatore il `git add`.

- [ ] **Passo 2: riscrivi il test.** Sostituisci l'intero contenuto di
  `ReverseTrackDirectionActionTest.php`:

```php
<?php

namespace Wm\WmPackage\Tests\Feature\Nova\Actions;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\ActionRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Mockery;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\Actions\ReverseTrackDirectionAction;
use Wm\WmPackage\Nova\EcTrack as EcTrackResource;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\Tests\TestCase;

class ReverseTrackDirectionActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        config(['scout.driver' => 'null']);
    }

    private function track(array $properties = [], string $wkt = 'MULTILINESTRING Z((0 0 0, 1 1 100, 2 2 200))'): EcTrack
    {
        // osmid esplicito: la factory lo valorizza a caso nel 70% dei casi, e una traccia OSM
        // è in sola lettura per l'inversione.
        return EcTrack::factory()->createQuietly([
            'geometry' => DB::raw("ST_GeomFromText('{$wkt}', 4326)"),
            'properties' => $properties,
            'osmid' => null,
        ]);
    }

    private function wkt(EcTrack $track): string
    {
        return DB::selectOne('SELECT ST_AsText(geometry::geometry) AS wkt FROM ec_tracks WHERE id = ?', [$track->id])->wkt;
    }

    private function runAction(EcTrack $track, array $values)
    {
        return DB::transaction(fn () => (new ReverseTrackDirectionAction)->handle(
            new ActionFields(collect($values), collect()),
            collect([$track])
        ));
    }

    private function fieldsFor(?EcTrack $track, $selection = 'one'): array
    {
        $request = Mockery::mock(NovaRequest::class)->makePartial();
        $request->shouldReceive('selectedResources')->andReturn(match ($selection) {
            'one' => collect([$track]),
            'none' => collect(),
            'all' => null,
        });

        return (new ReverseTrackDirectionAction)->fields($request);
    }

    /**
     * canSee()/canRun() sono agganciati in EcTrack::actions(): vanno risolti dalla Resource,
     * non con `new ReverseTrackDirectionAction` (oc:8569).
     */
    private function resolveAction(NovaRequest $request): ReverseTrackDirectionAction
    {
        return collect((new EcTrackResource(new EcTrack))->actions($request))
            ->first(fn ($action) => $action instanceof ReverseTrackDirectionAction);
    }

    public function test_fields_show_the_geometry_flag_and_one_flag_per_valued_pair()
    {
        $fields = $this->fieldsFor($this->track(['manual_data' => ['ascent' => 500], 'from' => 'A', 'to' => 'B']));

        $this->assertSame(
            ['reverse_geometry', 'swap_ascent_descent', 'swap_from_to'],
            array_map(fn ($field) => $field->attribute, $fields)
        );
        // Boolean::resolveDefaultValue() restituisce il default solo in una richiesta di Action.
        $actionRequest = ActionRequest::create('/');
        $this->assertTrue($fields[0]->resolveDefaultValue($actionRequest));
        $this->assertFalse($fields[1]->resolveDefaultValue($actionRequest));
        $this->assertStringContainsString('500', $fields[1]->helpText);
        $this->assertStringContainsString('—', $fields[1]->helpText);
    }

    public function test_fields_without_selection()
    {
        foreach (['none', 'all'] as $selection) {
            $fields = $this->fieldsFor(null, $selection);
            $this->assertSame(['reverse_geometry'], array_map(fn ($field) => $field->attribute, $fields));
        }
    }

    public function test_help_shows_non_scalar_values()
    {
        $fields = $this->fieldsFor($this->track(['from' => ['it' => 'Partenza', 'en' => 'Start']]));

        $this->assertStringContainsString('Partenza', $fields[1]->helpText);
    }

    public function test_all_flags_off_is_an_error_and_writes_nothing()
    {
        $track = $this->track(['manual_data' => ['ascent' => 500]]);
        $before = $this->wkt($track);

        $result = $this->runAction($track, ['reverse_geometry' => false, 'swap_ascent_descent' => false]);

        $this->assertArrayHasKey('danger', $result);
        $this->assertSame($before, $this->wkt($track));
        Bus::assertNothingDispatched();
    }

    public function test_osm_track_is_an_error_and_writes_nothing()
    {
        $track = $this->track(['osmid' => 123]);
        $before = $this->wkt($track);

        $result = $this->runAction($track, ['reverse_geometry' => true]);

        $this->assertArrayHasKey('danger', $result);
        $this->assertSame($before, $this->wkt($track));
        Bus::assertNothingDispatched();
    }

    public function test_it_reverses_a_multipart_geometry_including_the_order_of_the_parts()
    {
        $track = $this->track([], 'MULTILINESTRING Z((0 0 0, 1 1 100),(10 10 200, 11 11 300))');

        $this->runAction($track, ['reverse_geometry' => true]);

        $this->assertSame('MULTILINESTRING Z ((11 11 300,10 10 200),(1 1 100,0 0 0))', $this->wkt($track));
    }

    public function test_final_message_says_what_was_done()
    {
        app()->setLocale('en');
        $track = $this->track(['manual_data' => ['ascent' => 500, 'descent' => 300]]);

        $result = $this->runAction($track, ['reverse_geometry' => false, 'swap_ascent_descent' => true]);

        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('Geometry reversed: No', $result['message']);
        $this->assertStringContainsString('Ascent / Descent', $result['message']);
    }

    public function test_administrator_can_see_and_run_the_action()
    {
        RolesAndPermissionsService::seedDatabase();
        $administrator = User::factory()->create();
        $administrator->assignRole('Administrator');
        Auth::login($administrator);
        $request = NovaRequest::create('/');
        $request->setUserResolver(fn () => $administrator);

        $action = $this->resolveAction($request);

        $this->assertTrue($action->authorizedToSee($request));
        $this->assertTrue($action->authorizedToRun($request, $this->track()));
    }

    public function test_editor_cannot_see_or_run_the_action()
    {
        RolesAndPermissionsService::seedDatabase();
        $editor = User::factory()->create();
        $editor->assignRole('Editor');
        Auth::login($editor);
        $request = NovaRequest::create('/');
        $request->setUserResolver(fn () => $editor);

        $action = $this->resolveAction($request);

        $this->assertFalse($action->authorizedToSee($request));
        $this->assertFalse($action->authorizedToRun($request, $this->track()));
    }
}
```

  Note per chi esegue:
  - `Action::danger()` e `Action::message()` in Nova 5 restituiscono un `ActionResponse`: se l'accesso
    per chiave (`$result['danger']`) non funziona, controlla in
    `vendor/laravel/nova/src/Actions/ActionResponse.php` come si leggono tipo e testo, e adatta gli
    assert.
  - `helpText` e `resolveDefaultValue()`: verifica i nomi in
    `vendor/laravel/nova/src/Fields/Field.php` (Nova 5.7.6) e adatta, senza cambiare cosa si verifica.
  - Il driver Scout `null` evita scritture su Elasticsearch dai test.

- [ ] **Passo 3: lancia il test e verifica che fallisca** (classe `ReverseTrackDirectionAction` non
  trovata).

```bash
docker exec php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest tests/Feature/Nova/Actions/ReverseTrackDirectionActionTest.php"
```

- [ ] **Passo 4: riscrivi l'Action.** Contenuto completo di `src/Nova/Actions/ReverseTrackDirectionAction.php`:

```php
<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Models\EcTrackService;

/**
 * Inverte il verso di percorrenza di una traccia: l'utente sceglie se invertire la geometria e
 * quali coppie di dati che dipendono dal verso scambiare. Raccoglie le scelte e chiama
 * EcTrackService::reverse(), che fa l'intera operazione e accoda il ricalcolo (oc:8543).
 */
class ReverseTrackDirectionAction extends Action
{
    use InteractsWithQueue, Queueable;

    public function name()
    {
        return __('Reverse Track Direction');
    }

    /**
     * Un flag per la geometria, sempre presente, e uno per ogni coppia valorizzata sulla traccia
     * selezionata. selectedResources() vale sia all'apertura della finestra sia all'esecuzione
     * (ActionRequest usa lo stesso trait InteractsWithResourcesSelection).
     */
    public function fields(NovaRequest $request): array
    {
        $fields = [
            Boolean::make(__('Reverse geometry'), 'reverse_geometry')->default(true),
        ];

        $track = $request->selectedResources()?->first();
        if (! $track instanceof EcTrack) {
            return $fields;
        }

        foreach (app(EcTrackService::class)->directionPairs($track) as $key => [$firstValue, $secondValue]) {
            [, , , $firstLabel, $secondLabel] = EcTrackService::REVERSE_SWAP_PAIRS[$key];
            $fields[] = Boolean::make(
                __('Swap :first / :second', ['first' => __($firstLabel), 'second' => __($secondLabel)]),
                'swap_'.$key
            )
                ->default(false)
                ->help(__($firstLabel).': '.$this->display($firstValue).' — '.__($secondLabel).': '.$this->display($secondValue));
        }

        return $fields;
    }

    /**
     * ->sole() in EcTrack::actions() vale solo lato UI: il server passerebbe comunque a handle()
     * tutte le risorse di una richiesta diretta, quindi si itera su $models (oc:8543).
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(EcTrackService::class);
        $geometry = (bool) $fields->get('reverse_geometry');
        $swaps = array_values(array_filter(
            array_keys(EcTrackService::REVERSE_SWAP_PAIRS),
            fn ($key) => (bool) $fields->get('swap_'.$key)
        ));

        if (! $geometry && $swaps === []) {
            return Action::danger(__('Select at least one operation.'));
        }

        foreach ($models as $track) {
            if ($service->isOsmTrack($track)) {
                return Action::danger(__('This track comes from OpenStreetMap: its direction must be corrected on OpenStreetMap.'));
            }
        }

        $swappedLabels = [];
        foreach ($models as $track) {
            foreach ($service->reverse($track, $geometry, $swaps) as $key) {
                [, , , $firstLabel, $secondLabel] = EcTrackService::REVERSE_SWAP_PAIRS[$key];
                $swappedLabels[$key] = __($firstLabel).' / '.__($secondLabel);
            }
        }

        return Action::message(__('Geometry reversed: :geometry. Swapped: :pairs. Recalculation in progress.', [
            'geometry' => $geometry ? __('Yes') : __('No'),
            'pairs' => $swappedLabels === [] ? __('None') : implode(', ', $swappedLabels),
        ]));
    }

    private function display(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        return is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
```

- [ ] **Passo 5: aggiorna la registrazione** in `src/Nova/EcTrack.php`: l'import
  `use Wm\WmPackage\Nova\Actions\ReverseEcTrackGeometryAction;` diventa
  `use Wm\WmPackage\Nova\Actions\ReverseTrackDirectionAction;`, e in `actions()`
  `(new ReverseEcTrackGeometryAction)` diventa `(new ReverseTrackDirectionAction)`. `->sole()`,
  `canSee` e `canRun` restano. Nel commento sopra `$administratorOnly` sostituisci «inverte la
  geometria in place» con «inverte il verso della traccia».

- [ ] **Passo 6: aggiorna le traduzioni.** In `resources/lang/en.json` e `it.json` togli le tre chiavi
  vecchie (`Reverse Track Geometry` e i due messaggi che iniziano con `Track geometry reversed.`) e
  aggiungi, prima della `}` finale, mantenendo il JSON valido:

  `en.json`:
```json
    "Reverse Track Direction": "Reverse Track Direction",
    "Reverse geometry": "Reverse geometry",
    "Swap :first / :second": "Swap :first / :second",
    "Select at least one operation.": "Select at least one operation.",
    "This track comes from OpenStreetMap: its direction must be corrected on OpenStreetMap.": "This track comes from OpenStreetMap: its direction must be corrected on OpenStreetMap.",
    "Geometry reversed: :geometry. Swapped: :pairs. Recalculation in progress.": "Geometry reversed: :geometry. Swapped: :pairs. Recalculation in progress.",
    "Ascent": "Ascent",
    "Descent": "Descent",
    "Starting Point Elevation": "Starting Point Elevation",
    "Ending Point Elevation": "Ending Point Elevation",
    "Duration Forward": "Duration Forward",
    "Duration Backward": "Duration Backward",
    "Departure": "Departure",
    "Arrival": "Arrival"
```

  `it.json`:
```json
    "Reverse Track Direction": "Inverti verso della traccia",
    "Reverse geometry": "Inverti geometria",
    "Swap :first / :second": "Scambia :first / :second",
    "Select at least one operation.": "Seleziona almeno un'operazione.",
    "This track comes from OpenStreetMap: its direction must be corrected on OpenStreetMap.": "Questa traccia viene da OpenStreetMap: il verso va corretto su OpenStreetMap.",
    "Geometry reversed: :geometry. Swapped: :pairs. Recalculation in progress.": "Geometria invertita: :geometry. Scambiati: :pairs. Ricalcolo in corso.",
    "Ascent": "Salita",
    "Descent": "Discesa",
    "Starting Point Elevation": "Quota di partenza",
    "Ending Point Elevation": "Quota di arrivo",
    "Duration Forward": "Durata andata",
    "Duration Backward": "Durata ritorno",
    "Departure": "Partenza",
    "Arrival": "Arrivo"
```

  Prima di aggiungerle, cerca ogni chiave nei due file (`grep -n '"Ascent"' resources/lang/*.json`):
  se esiste già, non duplicarla. Nota per la review: le sei etichette dei dati DEM sono le stesse
  chiavi del tab DEM di Nova (`AbstractGeometryResource::getDemTabFields()`), che finora in italiano
  restavano in inglese: con questa aggiunta si traducono anche lì.

  Verifica: `jq empty resources/lang/en.json resources/lang/it.json` e
  `grep -c 'Reverse Track Geometry\|Track geometry reversed' resources/lang/*.json` (atteso: 0).

- [ ] **Passo 7: lancia i test e verifica che passino.** Stesso comando del passo 3, poi anche:

```bash
grep -rn "ReverseEcTrackGeometryAction\|forceGeometryChain\|DIRECTION_DEPENDENT_FIELDS\|getOverriddenFields" src tests resources
```

  Atteso: nessun risultato. `.claude/rules/nova.md` cita la vecchia Action solo come esempio storico
  con il ticket: lascialo.

- [ ] **Commit** (lo esegue lo sviluppatore):

```bash
git add src/Nova/Actions/ReverseTrackDirectionAction.php src/Nova/EcTrack.php resources/lang/en.json resources/lang/it.json tests/Feature/Nova/Actions/ReverseTrackDirectionActionTest.php
git add -u src/Nova/Actions tests/Feature/Nova/Actions
git commit -m "feat(oc:8543): Action Inverti verso della traccia con scelta di geometria e scambi"
```

---

### Task 5 — Suite completa, PHPStan, verifica a mano, note

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5--suite-completa)

**File:**
- Modifica: `docs/features/8543-inversione-della-traccia-attivazione-su-forestas/notes.md`

- [ ] **Passo 1: suite completa su `develop`.** Prima verifica l'isolamento: `ls phpunit.xml` in
  `wm-package/` deve rispondere «No such file». Poi:

```bash
cd /Users/bongiu/Documents/geobox2/forestas/wm-package && git status --short   # deve essere pulito, o fai stash
git switch --detach origin/develop
docker exec php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest --log-junit /tmp/pest-develop.xml" | tail -30
git switch feature/oc-8543-inversione-della-traccia-attivazione-su-forestas
```

- [ ] **Passo 2: suite completa sul branch.**

```bash
docker exec php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest --log-junit /tmp/pest-branch.xml" | tail -30
```

  Criterio: nessun test fallito in più sul branch rispetto a `develop`, e tutti i test di
  `ReverseTest`, `ReverseTrackDirectionActionTest`, `UpdateDataChainTest` (i due nuovi) ed
  `EcTrackSearchableArrayTest` verdi. Confronta gli elenchi dei falliti:

```bash
docker exec php-forestas bash -c "grep -o 'testcase name=\"[^\"]*\" class=\"[^\"]*\"[^>]*>\s*<failure' /tmp/pest-develop.xml | sort > /tmp/f-dev.txt; grep -o 'testcase name=\"[^\"]*\" class=\"[^\"]*\"[^>]*>\s*<failure' /tmp/pest-branch.xml | sort > /tmp/f-branch.txt; comm -13 /tmp/f-dev.txt /tmp/f-branch.txt"
```

  Se `grep` non trova le righe per come Pest formatta il JUnit, apri i due XML e confronta i falliti
  a mano. I due elenchi vanno in `notes.md` e nel commento sulla PR.

- [ ] **Passo 3: PHPStan sui file toccati.**

```bash
docker exec php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/phpstan analyse src/Services/Models/EcTrackService.php src/Nova/Actions/ReverseTrackDirectionAction.php src/Models/EcTrack.php src/Nova/EcTrack.php"
```

- [ ] **Passo 4: formattazione solo dei file del lavoro.**

```bash
docker exec php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pint src/Services/Models/EcTrackService.php src/Nova/Actions/ReverseTrackDirectionAction.php src/Models/EcTrack.php src/Nova/EcTrack.php tests/Unit/Services/EcTrackService/ReverseTest.php tests/Unit/Services/EcTrackService/UpdateDataChainTest.php tests/Unit/Models/EcTrackSearchableArrayTest.php tests/Feature/Nova/Actions/ReverseTrackDirectionActionTest.php"
git status --short
```

- [ ] **Passo 5: verifica a mano in Nova su Forestas locale** (la esegue lo sviluppatore, con un utente
  Administrator; il submodule locale deve essere su questo branch e `horizon` riavviato con
  `php artisan horizon:terminate`). Per ogni traccia, prima annota i valori mostrati nella scheda.

  Scegli le tracce con:

```bash
docker exec php-forestas php artisan tinker --execute="
echo 'tutte le coppie: ', \Wm\WmPackage\Models\EcTrack::whereNotNull(\DB::raw(\"properties->'manual_data'->>'ascent'\"))->whereNotNull(\DB::raw(\"properties->>'from'\"))->whereNotNull(\DB::raw(\"properties->>'to'\"))->value('id'), PHP_EOL;
echo 'un solo valore in from/to: ', \Wm\WmPackage\Models\EcTrack::whereNotNull(\DB::raw(\"properties->>'from'\"))->whereNull(\DB::raw(\"properties->>'to'\"))->value('id'), PHP_EOL;
"
```

  1. **Traccia con tutte le coppie**, dalla lista delle tracce: apri l'Action. Attesi: «Inverti
     geometria» acceso, un «Scambia» per ogni coppia con i valori sotto. Lancia con la sola
     geometria: messaggio «Geometria invertita: Sì. Scambiati: Nessuno»; dopo che Horizon ha finito,
     il profilo altimetrico è al contrario e partenza/arrivo invariati. Rilancia con le stesse scelte
     per rimetterla com'era.
  2. **Stessa traccia, dalla scheda**: apri l'Action e verifica che mostri le stesse coppie. Lancia
     con la geometria spenta e «Scambia Salita / Discesa» acceso: la scheda mostra i valori scambiati,
     la geometria non cambia. Rilancia uguale per ripristinare.
  3. **Traccia con un solo valore in partenza/arrivo**: scambia partenza/arrivo; il valore passa
     all'altro campo e il primo resta vuoto. Rilancia per ripristinare.
  4. **Tutti i flag spenti**: errore «Seleziona almeno un'operazione.», nessuna modifica.
  5. **Utente Editor**: l'Action non compare.
  6. **Ricerca**: dopo il punto 2, cerca la traccia per partenza nell'app o via API di ricerca e
     verifica che l'indice mostri la partenza nuova.

- [ ] **Passo 6: aggiorna `notes.md`.** Aggiungi in fondo una sezione `## Terzo ciclo (24/09/2026)`
  con:
  - `### Decisioni` — il merge di `develop` (`3c86ec66`) e il motivo; tag `forestas` associato al
    ticket il 24/09; le decisioni della revisione del 24/09 (punti 1-9 dell'overview); la rinomina
    della classe;
  - `### Suite` — i due elenchi di test falliti (develop e branch) e l'esito del confronto;
  - `### Follow-up` — nota da aggiungere su oc:8642 («`ascent` nell'indice è fatto in oc:8543; quando
    toglie i due job dalle catene standard, controllare `REVERSE_EXCLUDED_JOBS`»); Scout sincrono su
    tutta la piattaforma, da valutare a parte; lo stato di `UpdateDataChainTest` su `develop`
    trovato al task 1.
  - le divergenze da questo piano, se ce ne sono, con un rimando dal task corrispondente
    (`> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#...)`).

- [ ] **Commit** (lo esegue lo sviluppatore):

```bash
git add docs/features/8543-inversione-della-traccia-attivazione-su-forestas/
git commit -m "docs(oc:8543): overview, piano e note del terzo ciclo"
```

### Commit del terzo ciclo, in ordine

```
refactor(oc:8543): estrai il blocco geometria di updateDataChain in un metodo comune
feat(oc:8543): aggiungi EcTrackService::reverse con scambi scelti e catene dedicate
fix(oc:8543): indicizza ascent con il valore corrente, come distance e duration_forward
feat(oc:8543): Action Inverti verso della traccia con scelta di geometria e scambi
docs(oc:8543): overview, piano e note del terzo ciclo
```

Il push del branch, compreso il commit di merge `3c86ec66`, lo decide lo sviluppatore dopo aver
avvisato Carla Cupani.
