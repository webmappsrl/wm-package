> Ticket: oc:9038
> Stato: seguito, con 4 divergenze annotate in notes.md

# Attività primaria del sentiero — piano implementativo

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** un sentiero porta un'attività primaria, scelta dall'operatore quando approva l'istanza e prefillata con quanto il SUS ha proposto.

**Architecture:** la primaria è una famiglia di `TaxonomyTerm` a scelta singola (`primary-activity`), installata da una migration del dominio come la percorribilità. Il payload del canale porta la proposta in `api_data`; l'Action di approvazione la mostra prefillata e scrive le scelte confermate come relazioni del nuovo `EcTrack`, dentro la stessa transazione; la decisione e la sua data restano in `trail_applications.properties`.

**Tech Stack:** Laravel 12, PHP 8.4 nel container (floor `>8.1`), PostgreSQL + PostGIS, Nova 5, Pest.

**Spec:** [overview.md](overview.md) (package) e [../../../../docs/features/9038-attivita-primaria-del-sentiero/overview.md](../../../../docs/features/9038-attivita-primaria-del-sentiero/overview.md) (shard).

## Global Constraints

- **Nessun commit durante l'esecuzione.** I blocchi `git commit` dei task sono istruzioni testuali: il primo commit lo fa il dev.
- **PHP minimo `>8.1`:** mai `const` dentro un trait.
- **Identificatori in inglese**, sempre, anche con dominio italiano. Commenti, docblock e documentazione in italiano.
- **Traduzioni del package in `resources/lang/*.json`** (de, en, es, fr, it), mai in `lang/`.
- **Test nel container:** `docker exec php-forestas bash -c "cd /var/www/html/wm-package && vendor/bin/pest --filter=<nome>"`.
- **Niente migration aggiuntive su tabelle esistenti:** si modifica la `create`. L'installer della famiglia è invece uno stub nuovo, perché crea dati e non struttura.
- **Identifier della famiglia:** `primary-activity`; termini figli `primary-activity-<identifier dell'attività>`. Il suffisso si ricava **togliendo il prefisso della famiglia**, mai spezzando sull'ultimo trattino: tre attività su sette ne contengono uno.
- **Le attività ammesse sono le sette di `ApplicationSchema::ACTIVITIES`**: `trekking`, `hiking`, `mtb`, `horse`, `accessible-trails`, `nordic-walking`, `educational-trails`. `trail`, `itinerary` e `walk` stanno nella stessa tabella ma **non** sono attività.

## Review Focus

Input che la spec implica e che nessun task eserciterebbe da sé — ognuno ha il suo test nel task che possiede il codice:

1. **Istanza nata d'ufficio** (`source = 'office'`, `api_data` vuoto, mai passata dalla validazione): le select dell'Action devono presentarsi vuote e l'approvazione deve riuscire lo stesso → Task 3 e Task 4.
2. **Termine della famiglia assente a database** benché il valore sia valido: l'approvazione deve fermarsi, non creare un sentiero senza primaria → Task 4.
3. **`primary_activity` valido ma `activities` assente o invalido:** il controllo incrociato non deve aggiungere un errore che confonde quello vero → Task 2.
4. **Attività proposta che lo schema non ammette più** (uno shard che restringe `ACTIVITIES`): la validazione la rifiuta in ingresso, e un `api_data` già salvato con quel valore non deve far cadere il prefill → Task 2 e Task 3.
5. **Rollback dell'approvazione:** le scritture di tassonomia dentro la transazione non devono lasciare un documento in Elasticsearch per una traccia che non esiste → Task 4.

---

### Task 1: La famiglia della primaria nasce con il dominio

**Repo:** `wm-package`. Realizza [R8](overview.md#r8) e, insieme a Task 4, [R5](overview.md#r5): è
l'accensione della famiglia per `EcTrack` a far comparire il `Choice` nella detail view della
Resource `EcTrack`, ed è
da lì che un operatore cambia la primaria in seguito. Senza questo task il dato si scriverebbe e non
si vedrebbe.

**Files:**
- Create: `src/TrailRegistry/Services/PrimaryActivityTaxonomyInstaller.php`
- Create: `database/migrations/trail_registry/zz_2026_11_24_000001_install_primary_activity_taxonomy.php.stub`
- Modify: `config/wm-package.php` — aggiungere `'primary_activity_taxonomy' => env('WM_TRAIL_REGISTRY_PRIMARY_ACTIVITY_TAXONOMY', 'primary-activity')` accanto a `walkability_taxonomy` dentro `features.trail_registry`
- Test: `tests/Feature/TrailRegistry/PrimaryActivityTaxonomyInstallerTest.php`

**Interfaces:**
- Consumes: niente.
- Produces: `PrimaryActivityTaxonomyInstaller::install(): void`; `PrimaryActivityTaxonomyInstaller::TAB = 'Activities'`; la famiglia con identifier da `config('wm-package.features.trail_registry.primary_activity_taxonomy')` e i sette termini figli.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('crea la famiglia a scelta singola nel tab delle attività', function () {
    app(PrimaryActivityTaxonomyInstaller::class)->install();

    $family = TaxonomyTerm::where('identifier', 'primary-activity')->whereNull('parent_id')->first();

    expect($family)->not->toBeNull()
        ->and($family->single_choice)->toBeTrue()
        ->and($family->is_system)->toBeTrue()
        ->and($family->properties['tab'])->toBe('Activities');
});

it('copia i nomi dalle attività esistenti invece di scriverli fissi', function () {
    TaxonomyActivity::create(['identifier' => 'hiking', 'name' => ['it' => 'Alte vie']]);

    app(PrimaryActivityTaxonomyInstaller::class)->install();

    $term = TaxonomyTerm::where('identifier', 'primary-activity-hiking')->first();

    expect($term->name)->toBe(['it' => 'Alte vie']);
});

it('crea un termine per ciascuna delle sette attività dello schema', function () {
    app(PrimaryActivityTaxonomyInstaller::class)->install();

    expect(TaxonomyTerm::where('identifier', 'like', 'primary-activity-%')->count())->toBe(7);
});

it('non crea un termine per le attività che attività non sono', function () {
    TaxonomyActivity::create(['identifier' => 'trail', 'name' => ['it' => 'Sentiero']]);

    app(PrimaryActivityTaxonomyInstaller::class)->install();

    expect(TaxonomyTerm::where('identifier', 'primary-activity-trail')->exists())->toBeFalse();
});

it('è idempotente: due installazioni non duplicano nulla', function () {
    app(PrimaryActivityTaxonomyInstaller::class)->install();
    app(PrimaryActivityTaxonomyInstaller::class)->install();

    expect(TaxonomyTerm::where('identifier', 'like', 'primary-activity%')->count())->toBe(8);
});

it('accende la famiglia sulle app esistenti solo per le tracce', function () {
    $app = App::factory()->create();

    app(PrimaryActivityTaxonomyInstaller::class)->install();

    $family = TaxonomyTerm::where('identifier', 'primary-activity')->whereNull('parent_id')->first();
    $models = DB::table('app_taxonomy_term')
        ->where('app_id', $app->id)->where('taxonomy_term_id', $family->id)->value('models');

    expect(json_decode((string) $models, true))->toBe(['EcTrack']);
});
```

Il nome di un'attività che a database non c'è vale il suo identifier: aggiungi anche un test che lo
pinna, perché è il caso di uno shard che non ha ancora seminato le attività.

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `docker exec php-forestas bash -c "cd /var/www/html/wm-package && vendor/bin/pest --filter=PrimaryActivityTaxonomyInstaller"`
Atteso: FAIL, la classe non esiste.

- [ ] **Step 3: Scrivi `PrimaryActivityTaxonomyInstaller`**

Modella la classe su `src/TrailRegistry/Services/WalkabilityTaxonomyInstaller.php`, che è il precedente da imitare riga per riga: `firstOrNew` sull'identifier, `fill()` con `single_choice`, `sort_order` e `properties.tab`, `is_system` assegnato **fuori** dal `fill()` perché non è fillable, poi i figli con `firstOrNew` su `<famiglia>-<suffisso>`, e infine `enableForExistingApps()` che chiama `$app->syncTaxonomiesForModel('EcTrack', [...])` su ogni App.

Due differenze dal precedente, entrambe volute:
- i suffissi non sono una costante ma vengono da `app(ApplicationSchema::class)->activities()`, così uno shard che ridefinisce lo schema ottiene i suoi;
- il `name` di ogni figlio si legge dalla `TaxonomyActivity` con lo stesso identifier (`TaxonomyActivity::where('identifier', $suffix)->first()?->name`), con l'identifier stesso come riserva quando quell'attività non esiste a database.

- [ ] **Step 4: Scrivi lo stub di migration**

Copia la struttura di `zz_2026_11_17_000002_install_walkability_taxonomy.php.stub`, compresa la
sospensione di Telescope — senza quella, in CI la migration muore con «current transaction is
aborted» per una ragione che di Telescope non parla — e il `down()` vuoto con la motivazione: i
termini possono già avere legami che li referenziano.

- [ ] **Step 5: Esegui i test e verifica che passino**

Run: lo stesso comando dello Step 2. Atteso: PASS.

- [ ] **Step 6: Verifica la migration su un database vero**

Run: `docker exec php-forestas php artisan vendor:publish --tag=wm-package-migrations && docker exec php-forestas php artisan migrate --pretend`
Atteso: la migration nuova compare nell'elenco e nessun errore.

- [ ] **Step 7: Commit**

```bash
git add src/TrailRegistry/Services/PrimaryActivityTaxonomyInstaller.php database/migrations/trail_registry/zz_2026_11_24_000001_install_primary_activity_taxonomy.php.stub config/wm-package.php tests/Feature/TrailRegistry/PrimaryActivityTaxonomyInstallerTest.php
git commit -m "feat(oc:9038): la famiglia dell'attività primaria nasce con il dominio"
```

---

### Task 2: Il payload porta e valida l'attività primaria

**Repo:** `wm-package`. Realizza [R1](overview.md#r1).

**Files:**
- Modify: `src/TrailRegistry/Schema/ApplicationSchema.php` — il campo nuovo in `fields()` dopo `difficulty` (campo 36-37), e `version()` da `'1'` a `'2'`
- Modify: `src/Http/Requests/TrailRegistry/StoreApplicationRequest.php:144-166` — il controllo incrociato in `withValidator()`
- Modify: `resources/lang/{de,en,es,fr,it}.json` — il messaggio nuovo
- Test: `tests/Unit/TrailRegistry/ApplicationSchemaTest.php`, `tests/Feature/TrailRegistry/StoreApplicationRequestTest.php`

**Interfaces:**
- Consumes: niente.
- Produces: la chiave `primary_activity` in `api_data`, con l'identifier di un'attività; `ApplicationSchema::version()` che restituisce `'2'`.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('esige l\'attività primaria', function () {
    expect(app(ApplicationSchema::class)->requiredFieldNames())->toContain('primary_activity');
});

it('alza la versione dello schema', function () {
    expect(app(ApplicationSchema::class)->version())->toBe('2');
});

it('ammette solo le sette attività come primaria', function () {
    expect(validateApplication(['primary_activity' => 'trail']))->toBeFalse();
    expect(validateApplication(['primary_activity' => 'hiking']))->toBeTrue();
});

it('rifiuta una primaria che non è fra le attività dichiarate', function () {
    $errors = applicationErrors([
        'activities' => ['hiking', 'mtb'],
        'primary_activity' => 'horse',
    ]);

    expect($errors->has('primary_activity'))->toBeTrue();
});

it('accetta una primaria che è fra le attività dichiarate', function () {
    $errors = applicationErrors([
        'activities' => ['hiking', 'mtb'],
        'primary_activity' => 'hiking',
    ]);

    expect($errors->has('primary_activity'))->toBeFalse();
});

it('non aggiunge l\'errore incrociato quando le attività sono già invalide', function () {
    $errors = applicationErrors([
        'activities' => 'non un array',
        'primary_activity' => 'hiking',
    ]);

    expect($errors->has('primary_activity'))->toBeFalse();
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `docker exec php-forestas bash -c "cd /var/www/html/wm-package && vendor/bin/pest --filter='ApplicationSchema|StoreApplicationRequest'"`
Atteso: FAIL sui sei test nuovi.

- [ ] **Step 3: Dichiara il campo nello schema**

In `fields()`, subito dopo `difficulty`:

```php
new ApplicationField('primary_activity', 'select', __('Primary activity'), null, true, ['required', 'string', 'in:'.implode(',', static::ACTIVITIES)], null), // 36
```

`values` resta `null`: l'elenco è quello delle attività, che la documentazione descrive a parte, e
un elenco scritto qui andrebbe tenuto allineato a mano con `ACTIVITIES`.

Poi porta `version()` a `'2'`.

- [ ] **Step 4: Estendi il controllo incrociato**

In `withValidator()`, dentro lo stesso `$validator->after(...)` che già confronta `difficulty` con
`activities`, aggiungi il confronto fra `primary_activity` e `activities`. Vale la stessa guardia
che c'è già: se `activities` o `primary_activity` portano errori propri, non aggiungere nulla —
l'errore vero è un altro e questo lo confonderebbe. Il messaggio nuovo è

> `The primary activity :activity is not among the proposed activities`

con la sua voce nei cinque file di lingua, italiano compreso.

- [ ] **Step 5: Esegui i test e verifica che passino**

Run: lo stesso comando dello Step 2. Atteso: PASS.

- [ ] **Step 6: Esegui l'intero dominio, per i test che la versione rompe**

Run: `docker exec php-forestas bash -c "cd /var/www/html/wm-package && vendor/bin/pest --filter=TrailRegistry"`
Atteso: PASS. I test che asseriscono `_schema_version` a `'1'` o che costruiscono un payload senza
`primary_activity` vanno aggiornati qui: sono 95 test in cinque file, e falliranno in blocco finché
il payload di prova non porta il campo nuovo.

- [ ] **Step 7: Commit**

```bash
git add src/TrailRegistry/Schema/ApplicationSchema.php src/Http/Requests/TrailRegistry/StoreApplicationRequest.php resources/lang tests/Unit/TrailRegistry tests/Feature/TrailRegistry
git commit -m "feat(oc:9038): il canale SUS esige l'attività primaria proposta"
```

---

### Task 3: L'Action di approvazione mostra le scelte prefillate

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3--un-select-per-famiglia-di-difficoltà-non-un-select-per-il-livello) e [notes.md](notes.md#task-3--il-contratto-su-activities-non-si-ottiene-con-saveasjson)

**Repo:** `wm-package`. Realizza [R3](overview.md#r3).

**Files:**
- Modify: `src/TrailRegistry/Nova/Actions/ApproveTrailApplication.php` — `fields()` oggi restituisce `[]`
- Test: `tests/Feature/TrailRegistry/ApproveTrailApplicationFieldsTest.php`

**Interfaces:**
- Consumes: la famiglia e i termini di Task 1; la chiave `primary_activity` di Task 2.
- Produces: tre campi di Action, con questi attributi, che Task 4 riceve in `ActionFields`:
  `activities` (array di identifier), `difficulty` (mappa attività→livello), `primary_activity`
  (un identifier).

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('prefilla le select con quanto il SUS ha proposto', function () {
    $application = TrailApplication::factory()->create(['api_data' => [
        'activities' => ['hiking', 'mtb'],
        'difficulty' => ['hiking' => 'E'],
        'primary_activity' => 'hiking',
    ]]);

    $fields = fieldsFor($application);

    expect($fields['activities']->value)->toBe(['hiking', 'mtb'])
        ->and($fields['primary_activity']->value)->toBe('hiking');
});

it('presenta select vuote su un\'istanza nata d\'ufficio', function () {
    $application = TrailApplication::factory()->create(['source' => 'office', 'api_data' => null]);

    $fields = fieldsFor($application);

    expect($fields['activities']->value)->toBeNull()
        ->and($fields['primary_activity']->value)->toBeNull();
});

it('non offre come attività i tipi di tracciato', function () {
    $options = fieldsFor(TrailApplication::factory()->create())['activities']->options;

    expect(array_keys($options))->not->toContain('trail', 'itinerary', 'walk')
        ->and(array_keys($options))->toContain('hiking');
});

it('ignora una proposta che lo schema non ammette più', function () {
    $application = TrailApplication::factory()->create(['api_data' => ['primary_activity' => 'parapendio']]);

    expect(fieldsFor($application)['primary_activity']->value)->toBeNull();
});
```

L'helper `fieldsFor()` costruisce una `NovaRequest` con `resourceId` valorizzato e indicizza per
`attribute` i campi restituiti da `fields()`.

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `docker exec php-forestas bash -c "cd /var/www/html/wm-package && vendor/bin/pest --filter=ApproveTrailApplicationFields"`
Atteso: FAIL, `fields()` restituisce un array vuoto.

- [ ] **Step 3: Implementa `fields()`**

L'istanza si risolve dalla request esattamente come fa `ReplaceTrailCodeNumber::activeCodeOf()`,
che è nella stessa cartella: `$request->resourceId ?? $this->applicationIdFromResources($request)`,
con la guardia sul valore `"all"` — l'Action è `onlyOnDetail`, quindi oggi quel caso non si dà, ma
la guardia costa una riga e un `find()` su `"all"` fallisce.

I tre campi:
- `Select::make(__('Activities'), 'activities')->options(...)->multiple()` — le opzioni sono le sette
  dello schema con l'etichetta della `TaxonomyActivity` corrispondente;
- `Select::make(__('Primary activity'), 'primary_activity')->options(...)` a scelta singola, con
  `->dependsOn('activities', ...)` perché offra solo le attività selezionate: `dependsOn` nelle
  Action funziona e il package lo usa già in `ReplaceTrailCodeNumber`;
- `Select::make(__('Difficulty'), 'difficulty')` per il livello, con le opzioni lette dai termini
  figli delle famiglie di difficoltà.

Il valore di partenza di ciascuno è `->default(fn () => ...)` letto da `api_data`, filtrato contro
le opzioni ammesse: un valore che non è fra le opzioni vale come assente.

- [ ] **Step 4: Esegui i test e verifica che passino**

Run: lo stesso comando dello Step 2. Atteso: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/TrailRegistry/Nova/Actions/ApproveTrailApplication.php tests/Feature/TrailRegistry/ApproveTrailApplicationFieldsTest.php
git commit -m "feat(oc:9038): l'approvazione propone le attività dichiarate dal SUS"
```

---

### Task 4: L'approvazione scrive le scelte sul sentiero

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4--il-test-sullindicizzazione-del-piano-non-avrebbe-rilevato-nulla)

**Repo:** `wm-package`. Realizza [R9](overview.md#r9), [R5](overview.md#r5), [R2](overview.md#r2).

**Files:**
- Modify: `src/TrailRegistry/TrailRegistryService.php:996-1041` — `approve()` accetta le scelte e le scrive dentro la transazione
- Modify: `src/TrailRegistry/Nova/Actions/ApproveTrailApplication.php:34-65` — `handle()` passa i campi al service e traduce il rifiuto in un messaggio
- Modify: `app/Http/Controllers/Api/Sus/SusTestDecisionController.php:35` (**shard forestas**) — l'altro chiamante di `approve()`
- Test: `tests/Feature/TrailRegistry/ApproveTrailApplicationTest.php`

**Interfaces:**
- Consumes: i tre campi di Task 3, dentro `ActionFields`.
- Produces: `TrailRegistryService::approve(TrailApplication $application, ?int $userId = null, array $choices = []): EcTrack`, dove `$choices` ha le chiavi `activities`, `difficulty`, `primary_activity`. Un array vuoto conserva il comportamento di oggi, così il chiamante dello shard non si rompe.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('porta sul sentiero le attività confermate e la primaria', function () {
    $track = approveWith(['activities' => ['hiking', 'mtb'], 'primary_activity' => 'hiking']);

    expect($track->taxonomyActivities->pluck('identifier')->all())->toContain('hiking', 'mtb')
        ->and($track->term('primary-activity')->identifier)->toBe('primary-activity-hiking');
});

it('lascia una sola primaria anche se la si riscrive', function () {
    $track = approveWith(['activities' => ['hiking'], 'primary_activity' => 'hiking']);

    expect($track->terms('primary-activity'))->toHaveCount(1);
});

it('ferma l\'approvazione se il termine della primaria non esiste', function () {
    TaxonomyTerm::where('identifier', 'primary-activity-hiking')->delete();

    expect(fn () => approveWith(['activities' => ['hiking'], 'primary_activity' => 'hiking']))
        ->toThrow(InvalidArgumentException::class);

    expect(TrailApplication::first()->status)->toBe(TrailApplicationStatus::UnderReview);
});

it('approva anche senza scelte, come un\'istanza d\'ufficio', function () {
    $track = approveWith([]);

    expect($track)->toBeInstanceOf(EcTrack::class);
});

it('non indicizza la traccia mentre la transazione è aperta', function () {
    EcTrack::disableSearchSyncing();
    $spy = Mockery::spy(EcTrackObserver::class);

    approveWith(['activities' => ['hiking'], 'primary_activity' => 'hiking']);

    // L'attach di tassonomia dentro la transazione non deve far partire
    // l'indicizzazione sincrona: in locale `scout.queue` è false.
    $spy->shouldNotHaveReceived('saved');
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `docker exec php-forestas bash -c "cd /var/www/html/wm-package && vendor/bin/pest --filter=ApproveTrailApplicationTest"`
Atteso: FAIL, `approve()` non accetta il terzo parametro.

- [ ] **Step 3: Scrivi le scelte dentro la transazione**

In `approve()`, dopo `createTrackFromApplication()` e prima di `confirm()`, aggiungi la scrittura
delle tre cose, **tutta dentro `EcTrack::withoutSyncingToSearch(fn () => ...)`**: senza quello
`TaxonomyTermableObserver` indicizza in modo sincrono una traccia non ancora committata, e un
rollback lascerebbe un documento fantasma — in locale `scout.queue` è `false`, quindi succede.

Le attività si attaccano con `syncWithoutDetaching()` sulle `TaxonomyActivity` corrispondenti, come
fa già `attachTrailType()`. Il termine della primaria si cerca per identifier
`<famiglia>-<attività>` e, **se non esiste, si solleva un'eccezione invece di registrare un
avviso**: è il punto in cui questo codice deve divergere da `attachTrailType()`, che logga e
prosegue lasciando sentieri senza tipo.

- [ ] **Step 4: Fai passare i campi dall'Action, e traduci il rifiuto**

`handle()` passa `$fields` al service. Il `try` che oggi cattura solo
`InvalidTrailApplicationTransitionException` deve catturare anche l'eccezione del termine mancante e
restituire `Action::danger(...)` con un messaggio leggibile: altrimenti l'operatore vede un 500 di
Nova senza spiegazione.

- [ ] **Step 5: Allinea il chiamante dello shard**

`SusTestDecisionController` chiama `approve()` e non passa scelte: con il default `[]` continua a
funzionare. Verifica che compili e che il suo test passi.

- [ ] **Step 6: Esegui i test e verifica che passino**

Run: `docker exec php-forestas bash -c "cd /var/www/html/wm-package && vendor/bin/pest --filter=TrailRegistry"`
Atteso: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/TrailRegistry/TrailRegistryService.php src/TrailRegistry/Nova/Actions/ApproveTrailApplication.php tests/Feature/TrailRegistry/ApproveTrailApplicationTest.php
git commit -m "feat(oc:9038): il sentiero nasce con le attività e la primaria confermate"
```

---

### Task 5: L'istanza conserva la decisione e la sua data

**Repo:** `wm-package`. Realizza [R4](overview.md#r4).

**Files:**
- Modify: `src/TrailRegistry/TrailRegistryService.php` — dentro la stessa transazione di Task 4
- Test: `tests/Feature/TrailRegistry/ApproveTrailApplicationTest.php`

**Interfaces:**
- Consumes: le scelte di Task 4.
- Produces: in `trail_applications.properties` le chiavi `approved_at` (ISO 8601) e `approved_activities`, `approved_difficulty`, `approved_primary_activity`.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('fissa nella domanda la decisione e il momento in cui è stata presa', function () {
    $application = applicationFor(['activities' => ['hiking'], 'primary_activity' => 'hiking']);

    approve($application, ['activities' => ['hiking', 'mtb'], 'primary_activity' => 'mtb']);

    $properties = $application->fresh()->properties;

    expect($properties['approved_primary_activity'])->toBe('mtb')
        ->and($properties['approved_activities'])->toBe(['hiking', 'mtb'])
        ->and($properties['approved_at'])->not->toBeNull();
});

it('non tocca la proposta ricevuta', function () {
    $application = applicationFor(['activities' => ['hiking'], 'primary_activity' => 'hiking']);

    approve($application, ['activities' => ['mtb'], 'primary_activity' => 'mtb']);

    expect($application->fresh()->api_data['primary_activity'])->toBe('hiking');
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `docker exec php-forestas bash -c "cd /var/www/html/wm-package && vendor/bin/pest --filter='decisione|proposta ricevuta'"`
Atteso: FAIL.

- [ ] **Step 3: Scrivi la decisione nelle properties**

Dentro la transazione, accanto all'`update` dello stato. Attenzione a non sovrascrivere le chiavi
già presenti (`dem_data`, `manual_data`, `app_id`): si fonde, non si rimpiazza.

Nota per chi legge il diff: queste `properties` vengono copiate per intero sul sentiero da
`createTrackFromApplication()`, che gira **prima** di questa scrittura — quindi la decisione resta
sulla domanda e non finisce nel sentiero appena creato. È voluto: sul sentiero il dato vive come
relazione.

- [ ] **Step 4: Esegui i test e verifica che passino**

Run: lo stesso comando dello Step 2. Atteso: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/TrailRegistry/TrailRegistryService.php tests/Feature/TrailRegistry/ApproveTrailApplicationTest.php
git commit -m "feat(oc:9038): la domanda conserva la decisione dell'istruttoria e la sua data"
```

---

### Task 6: Il SUS rilegge proposta e decisione, distinte

**Repo:** `wm-package`. Realizza [R6](overview.md#r6).

**Files:**
- Modify: `src/Http/Controllers/Api/TrailRegistry/ShowApplicationController.php:122-190`
- Test: `tests/Feature/TrailRegistry/ShowApplicationEndpointTest.php`

**Interfaces:**
- Consumes: le chiavi di `properties` scritte da Task 5.
- Produces: nella risposta della lettura, la chiave `approved_data` accanto a `submitted_data`.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('restituisce la decisione accanto alla proposta', function () {
    // istanza approvata con primaria diversa da quella proposta
    $response->assertJsonPath('submitted_data.primary_activity', 'hiking')
        ->assertJsonPath('approved_data.primary_activity', 'mtb');
});

it('non restituisce una decisione su una domanda non ancora approvata', function () {
    $response->assertJsonPath('approved_data', null);
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `docker exec php-forestas bash -c "cd /var/www/html/wm-package && vendor/bin/pest --filter=ShowApplicationEndpoint"`
Atteso: FAIL, la chiave non esiste.

- [ ] **Step 3: Esponi `approved_data`**

Una chiave nuova nella risposta, con il suo docblock come tutte le altre — è quello che Scramble
legge per costruire la pagina. È `null` in ogni stato diverso da `approved`, e il docblock deve
dirlo esplicitamente, come fa già quello di `code`: «**È `null` in ogni stato diverso da
`approved`, e non è un errore**».

- [ ] **Step 4: Esegui i test e verifica che passino**

Run: lo stesso comando dello Step 2. Atteso: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Http/Controllers/Api/TrailRegistry/ShowApplicationController.php tests/Feature/TrailRegistry/ShowApplicationEndpointTest.php
git commit -m "feat(oc:9038): la lettura della domanda distingue la proposta dalla decisione"
```

---

### Task 7: La documentazione delle API

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-7--un-paragrafo-invece-di-una-riga-nella-tabella-delle-dipendenze)

**Repo:** `wm-package` **e** `forestas`. Realizza [R7](overview.md#r7) e il suo [R1](../../../../docs/features/9038-attivita-primaria-del-sentiero/overview.md#r1).

**Files:**
- Modify: `src/Http/Controllers/Api/TrailRegistry/StoreApplicationController.php` — l'attributo `#[BodyParameter]` del campo nuovo e gli esempi `curl` delle righe 118-132
- Modify: `config/scramble.php` (**shard forestas**) — la sezione del campo 36 (riga 256), la spiegazione di `difficulty` (360), la tabella delle scale (367) e gli esempi (308-309)
- Test: `tests/Feature/Sus/SusApiDocumentationTest.php` (**shard forestas**)

**Interfaces:**
- Consumes: il campo di Task 2.
- Produces: niente che altri task usino.

- [ ] **Step 1: Scrivi il test che lega la prosa al campo**

Nello shard, perché è lì che vive la prosa. Il test pinna l'unico legame che oggi nessuno controlla:
che la pagina descriva i campi obbligatori del canale.

```php
it('documenta ogni campo obbligatorio del canale SUS', function () {
    $prose = config('scramble.info.description');

    foreach (app(ApplicationSchema::class)->requiredFieldNames() as $field) {
        expect($prose)->toContain($field);
    }
});
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `docker exec php-forestas bash -c "cd /var/www/html/forestas && vendor/bin/pest --filter=SusApiDocumentation"`
Atteso: FAIL, `primary_activity` non compare nella prosa.

- [ ] **Step 3: Scrivi l'attributo e gli esempi nel package**

Il `#[BodyParameter]` del campo nuovo, con descrizione ed esempio, e la riga
`-F "primary_activity=hiking"` negli esempi `curl` accanto a `activities` e `difficulty`.

- [ ] **Step 4: Scrivi la prosa nello shard**

Nella sezione del campo 36: cosa è l'attività primaria, che è obbligatoria, che il valore è uno
degli identifier delle attività e che deve essere fra quelli dichiarati in `activities`. La tabella
delle scale resta incompleta per mountain bike e ippovia, come oggi: dirlo esplicitamente invece di
lasciare un vuoto.

- [ ] **Step 5: Esegui il test e verifica che passi**

Run: lo stesso comando dello Step 2. Atteso: PASS.

- [ ] **Step 6: Rigenera la pagina e guardala**

Run: `docker exec php-forestas php artisan scramble:export` (o apri la pagina sul server locale)
Atteso: la sezione del campo 36 cita `primary_activity` e la regola.

- [ ] **Step 7: Commit**

```bash
# nel package
git add src/Http/Controllers/Api/TrailRegistry/StoreApplicationController.php
git commit -m "docs(oc:9038): il campo dell'attività primaria nella pagina delle API"

# nello shard
git add config/scramble.php tests/Feature/Sus/SusApiDocumentationTest.php
git commit -m "docs(oc:9038): la prosa del campo 36 descrive l'attività primaria"
```

---

## Sequenza di rilascio

Un vincolo che non è un task ma governa l'ordine del merge: **la pagina delle API va pubblicata
prima che la validazione del campo obbligatorio arrivi su un ambiente che il SUS interroga.**
Engineering sta scrivendo la maschera adesso, e dal rilascio in poi una domanda senza
`primary_activity` riceve 422. In pratica: Task 7 non va dopo Task 2 nel tempo del deploy, anche se
nel piano viene dopo nell'ordine di scrittura.
