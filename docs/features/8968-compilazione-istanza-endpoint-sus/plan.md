> Ticket: oc:8968
> Stato: seguito, con 14 divergenze annotate in notes.md

# La compilazione di un'istanza — piano implementativo

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** dare al Catasto Sentieri tre endpoint con cui uno sportello esterno crea un'istanza di accatastamento, ne carica gli allegati uno per chiamata e la trasmette.

**Architecture:** il dominio sta nel `wm-package` — schema dei campi, Form Request, service, controller — e nello shard `forestas` restano solo le rotte che danno al canale il nome del proprio ente. Lo schema dei 27 campi del format diventa la fonte unica da cui derivano validazione, campi obbligatori e Field di Nova.

**Tech Stack:** Laravel 12, PHP ≥8.1, PostgreSQL con PostGIS, Nova 5, Pest, Scramble per la documentazione delle API.

**Spec:**
- `wm-package/docs/features/8968-compilazione-istanza-endpoint-sus/overview.md` (completa)
- `forestas/docs/features/8968-compilazione-istanza-endpoint-sus/overview.md` (parte shard)

## Global Constraints

- **Mai eseguire `git commit`, `git add`, `git push` né creare branch**: i commit del piano sono istruzioni per il dev. Vale anche per i subagent.
- **Non lanciare `vendor/bin/pest` senza prima verificare l'isolamento del database di test** secondo `forestas/.claude/rules/test.md`: il database reale contiene dati importati da processi lunghi.
- Identificatori nel codice **sempre in inglese**; commenti, docblock e messaggi di commit in italiano, con i termini tecnici in inglese.
- **Nel package il nome dello sportello non compare mai**, né in classi, né in metodi, né in commenti: «mai il nome dello sportello: questo codice servirà anche Lombardia e Toscana».
- PHP minimo `>8.1`: **mai `const` dentro un trait**, vanno sulla classe.
- **Le geometrie PostGIS passano sempre da SQL puro**, mai dall'ORM: risalvare un modello geometrico via Eloquent corrompe la geometria.
- Le traduzioni del package stanno in `resources/lang/*.json`, nelle cinque lingue `de`, `en`, `es`, `fr`, `it`.
- `composer format` senza scope riformatta l'intero repo: passare sempre lo scope dei file toccati.
- **Ordine di merge:** prima il package, poi lo shard bumpa il puntatore del submodule e applica la propria parte nello stesso commit.
- Il servizio DEM va **sempre fakato nei test**: nessun test chiama il servizio vero.

## Review Focus

Classi di input che la spec implica e che nessun task esercita per sua natura; ciascuna ha il test aggiunto al task che possiede il codice.

1. **Payload con una chiave che il format non prevede** — deve essere conservato in `api_data` e non far fallire la chiamata (Task 3).
2. **Tracciato che non interseca nessun settore** — la creazione fallisce con un errore leggibile, non con un 500, e non lascia righe (Task 7).
3. **Reinvio della stessa fotografia dopo un timeout** — non deve contare due volte né far superare il limite di cinque (Task 8).
4. **Conferma ripetuta su un'istanza già trasmessa** — risponde con il risultato finale, non con un errore (Task 9).
5. **Istanza di un'altra origine o in un altro stato** — upload e conferma su un'istanza d'ufficio, o già approvata, rispondono 404 o 409 e non la toccano (Task 7, 8, 9).

---

## File Structure

**Nel `wm-package`:**

| File | Responsabilità |
|---|---|
| `src/TrailRegistry/Schema/ApplicationField.php` | un campo del format: nome, tipo, label, valori, obbligatorietà, regole |
| `src/TrailRegistry/Schema/ApplicationSchema.php` | i 27 campi, con i metodi che ne derivano regole, obbligatori e forma per Nova |
| `src/TrailRegistry/Rules/DifficultyMatchesActivities.php` | la difficoltà come coppia attività → livello |
| `src/Http/Requests/TrailRegistry/StoreApplicationRequest.php` | validazione della creazione, derivata dallo schema |
| `src/Http/Requests/TrailRegistry/StoreAttachmentRequest.php` | validazione dell'upload |
| `src/TrailRegistry/Services/ApplicationDemService.php` | calcolo DEM e scrittura, condiviso fra job e controller |
| `src/Http/Controllers/Api/TrailRegistry/StoreApplicationController.php` | creazione |
| `src/Http/Controllers/Api/TrailRegistry/StoreAttachmentController.php` | upload di un allegato |
| `src/Http/Controllers/Api/TrailRegistry/ConfirmApplicationController.php` | chiusura della compilazione |
| `src/TrailRegistry/Exceptions/ApplicationIncompleteException.php` | cosa manca per confermare |

**Modificati nel `wm-package`:** `TrailRegistry/Nova/TrailApplication.php`, `TrailRegistry/Models/TrailApplication.php`, `TrailRegistry/TrailRegistryService.php`, `TrailRegistry/Jobs/UpdateTrailApplicationDemJob.php`, `Services/Models/EcTrackService.php`, `Http/Clients/DemClient.php`, `Http/Controllers/Api/MediaController.php`, `config/wm-package.php`, `resources/lang/*.json`.

**Nel `forestas`:** `routes/sus.php`, `tests/Feature/Sus/`.

---

### Task 1: Lo schema dei campi come fonte unica

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-lo-schema-dei-campi-come-fonte-unica)

**Repo:** `wm-package`

**Files:**
- Create: `src/TrailRegistry/Schema/ApplicationField.php`
- Create: `src/TrailRegistry/Schema/ApplicationSchema.php`
- Modify: `src/TrailRegistry/Nova/TrailApplication.php:234-270` (`susDataSchema()` legge lo schema invece di tenerlo)
- Modify: `src/TrailRegistry/Models/TrailApplication.php:249` (`requiredApiFields()` deriva dallo schema)
- Test: `tests/Unit/TrailRegistry/ApplicationSchemaTest.php`

**Interfaces:**
- Produces:
  - `ApplicationField::__construct(string $name, string $type, string $label, ?array $values, bool $required, array $rules, ?string $displayType)` — `$required` vale per l'obbligatorietà **incondizionata**; un campo condizionale ha `required: false` e la condizione dentro `$rules`. `$displayType` è il tipo con cui il campo si mostra quando differisce da quello con cui si valida.
  - `ApplicationField::toArray(): array` — la forma che `PropertiesPanel::jsonForm()` consuma già: `['name' => …, 'type' => …, 'label' => …, 'values' => …]`, con `type` uguale a `displayType` quando c'è.
  - `ApplicationSchema::fields(): array<int, ApplicationField>`
  - `ApplicationSchema::rules(): array<string, array>` — le regole Laravel per campo, chiavi prefissate come le attende la Form Request del Task 3.
  - `ApplicationSchema::requiredFieldNames(): list<string>` — i soli campi con `required: true`.
  - `ApplicationSchema::toNovaSchema(): array` — l'array dei `toArray()`, nell'ordine del format.
  - `ApplicationSchema::version(): string` — la versione dello schema, usata dal Task 7.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('espone i 27 campi del format nell ordine della numerazione', function () {
    expect(ApplicationSchema::fields())->toHaveCount(27);
    expect(ApplicationSchema::fields()[0]->name)->toBe('from');
});

it('non mette fra gli obbligatori i campi la cui obbligatorieta dipende da un altro campo', function () {
    expect(ApplicationSchema::requiredFieldNames())
        ->not->toContain('soggetto_gestore_other')
        ->not->toContain('soggetto_rilevatore_other')
        ->not->toContain('soggetto_rilevatore_email')
        ->not->toContain('soggetto_manutentore_other')
        ->not->toContain('soggetto_manutentore_cf')
        ->not->toContain('soggetto_custode')
        ->not->toContain('difficulty');
});

it('esprime le dipendenze fra campi come regole Laravel native', function () {
    expect(ApplicationSchema::rules()['soggetto_gestore_other'])
        ->toContain('required_if:soggetto_gestore,third_party');
});

it('valida last_survey_date come data non futura ma la mostra come testo', function () {
    $field = collect(ApplicationSchema::fields())->firstWhere('name', 'last_survey_date');

    expect($field->rules)->toContain('date')->toContain('before_or_equal:today');
    expect($field->toArray()['type'])->toBe('text');
});

it('si lascia ridefinire dallo shard', function () {
    // lo shard registra la propria implementazione nel container
    app()->bind(ApplicationSchema::class, fn () => new class extends ApplicationSchema {
        public static function fields(): array { return [new ApplicationField('solo_questo', 'text', 'Solo questo', null, true, ['string'], null)]; }
    });

    expect(app(ApplicationSchema::class)::requiredFieldNames())->toBe(['solo_questo']);
});

it('da a Nova la stessa forma che la Resource aveva prima', function () {
    $signage = collect(ApplicationSchema::toNovaSchema())->firstWhere('name', 'existing_signage');

    expect($signage['type'])->toBe('select');
    expect(collect($signage['values'])->pluck('value')->all())
        ->toBe(['good_condition', 'dilapidated', 'absent']);
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Esegui: `vendor/bin/pest tests/Unit/TrailRegistry/ApplicationSchemaTest.php`
Atteso: FAIL, la classe non esiste.

- [ ] **Step 3: Implementa `ApplicationField` e `ApplicationSchema`**

I 27 campi con nomi, tipi, label e valori si prendono **verbatim** da `susDataSchema()` (`src/TrailRegistry/Nova/TrailApplication.php:234-270`), compreso il numero di campo del format nel commento a fine riga. L'obbligatorietà incondizionata è l'elenco di `requiredApiFields()` (`src/TrailRegistry/Models/TrailApplication.php:249`), che va riletto e non riscritto a memoria.

Le regole per campo: `from`/`to` stringa; `distance`/`ascent` numerici; `duration_forward`/`duration_backward` interi; `activities` array con `in:` sui sette identificatori; `difficulty` la Rule class del Task 2; `existing_signage` `in:good_condition,dilapidated,absent`; le e-mail `email`; `soggetto_custode_presente` booleano; `maintenance_frequency_months` intero positivo. Le cinque dipendenze condizionali come `required_if`.

- [ ] **Step 4: Fai leggere lo schema ai due consumatori**

`susDataSchema()` restituisce `ApplicationSchema::toNovaSchema()`; `requiredApiFields()` restituisce `ApplicationSchema::requiredFieldNames()`. I docblock esistenti vanno riscritti per dire che l'elenco non vive più qui.

- [ ] **Step 5: Esegui i test e verifica che passino**

Esegui: `vendor/bin/pest tests/Unit/TrailRegistry/ApplicationSchemaTest.php tests/Feature/TrailRegistry/TrailApplicationApiDataFieldsTest.php tests/Feature/TrailRegistry/TrailApplicationApiDataTest.php`
Atteso: PASS. I due test di Feature esistenti provano che Nova continua a vedere gli stessi campi.

- [ ] **Step 6: Commit**

```bash
git add src/TrailRegistry/Schema src/TrailRegistry/Nova/TrailApplication.php src/TrailRegistry/Models/TrailApplication.php tests/Unit/TrailRegistry/ApplicationSchemaTest.php
git commit -m "refactor(oc:8968): lo schema dei campi diventa la fonte unica del canale"
```

---

### Task 2: La difficoltà si valida come coppia attività → livello

**Repo:** `wm-package`

**Files:**
- Create: `src/TrailRegistry/Rules/DifficultyMatchesActivities.php`
- Test: `tests/Unit/TrailRegistry/DifficultyMatchesActivitiesTest.php`

**Interfaces:**
- Consumes: `ApplicationSchema` (Task 1) la referenzia fra le regole di `difficulty`.
- Produces: `DifficultyMatchesActivities` implementa `Illuminate\Contracts\Validation\ValidationRule`.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('accetta un livello che appartiene alla scala dell attivita', function () {
    expect(validateDifficulty(['hiking' => 'EE']))->toBeTrue();
});

it('rifiuta un livello che esiste ma appartiene a un altra scala', function () {
    expect(validateDifficulty(['mtb' => 'EEA']))->toBeFalse();
});

it('rifiuta un attivita che non e fra le sette', function () {
    expect(validateDifficulty(['sci-alpinismo' => 'EE']))->toBeFalse();
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Esegui: `vendor/bin/pest tests/Unit/TrailRegistry/DifficultyMatchesActivitiesTest.php`
Atteso: FAIL, la classe non esiste.

- [ ] **Step 3: Implementa la Rule**

Per ogni coppia, risolve la famiglia di `taxonomy_terms` dell'attività — `hiking-difficulty`, `mtb-difficulty`, `horse-difficulty`, `nordic-walking-difficulty`, `educational-trails-difficulty`, `soft-mobility-difficulty`, `inclusivity-difficulty` — e verifica che il livello sia un termine di quella famiglia. I termini si interrogano a database, non si riscrivono nel codice: cinque famiglie su sette hanno identifier con il prefisso dell'import.

- [ ] **Step 4: Esegui i test e verifica che passino**

Esegui: `vendor/bin/pest tests/Unit/TrailRegistry/DifficultyMatchesActivitiesTest.php`
Atteso: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/TrailRegistry/Rules tests/Unit/TrailRegistry/DifficultyMatchesActivitiesTest.php
git commit -m "feat(oc:8968): la difficolta si valida come coppia attivita-livello"
```

---

### Task 3: Le Form Request derivate dallo schema

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-le-form-request-derivate-dallo-schema)

**Repo:** `wm-package`

**Files:**
- Create: `src/Http/Requests/TrailRegistry/StoreApplicationRequest.php`
- Create: `src/Http/Requests/TrailRegistry/StoreAttachmentRequest.php`
- Test: `tests/Feature/TrailRegistry/StoreApplicationRequestTest.php`

**Interfaces:**
- Consumes: `ApplicationSchema::rules()` (Task 1), `DifficultyMatchesActivities` (Task 2).
- Produces:
  - `StoreApplicationRequest::rules(): array` — regole dallo schema, più `name` obbligatorio e `geometry` file obbligatorio.
  - `StoreAttachmentRequest::rules(): array` — `collection` fra le sei registrate, `file` obbligatorio.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('accetta una consegna completa', function () {
    $this->postJson(route('sus.applications.store'), completePayload())
        ->assertCreated();
});

it('dice campo per campo quale regola ha fallito', function () {
    $this->postJson(route('sus.applications.store'), completePayload(['municipality_email' => 'non-una-email']))
        ->assertStatus(422)
        ->assertJsonPath('errors.municipality_email.0', fn ($m) => str_contains($m, 'email'));
});

it('pretende il soggetto gestore terzo solo quando la select lo prevede', function () {
    $this->postJson(route('sus.applications.store'), completePayload([
        'soggetto_gestore' => 'third_party',
        'soggetto_gestore_other' => null,
    ]))->assertStatus(422)->assertJsonValidationErrors('soggetto_gestore_other');

    $this->postJson(route('sus.applications.store'), completePayload([
        'soggetto_gestore' => 'forestas',
        'soggetto_gestore_other' => null,
    ]))->assertCreated();
});

it('rifiuta una lunghezza implausibile, non solo un valore non numerico', function () {
    $this->postJson(route('sus.applications.store'), completePayload(['distance' => 12000]))
        ->assertStatus(422)->assertJsonValidationErrors('distance');
});

// Review Focus 1
it('accetta e conserva una chiave che il format non prevede', function () {
    $this->postJson(route('sus.applications.store'), completePayload(['campo_nuovo_del_format' => 'x']))
        ->assertCreated();

    expect(TrailApplication::latest('id')->first()->api_data)
        ->toHaveKey('campo_nuovo_del_format');
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Esegui: `vendor/bin/pest tests/Feature/TrailRegistry/StoreApplicationRequestTest.php`
Atteso: FAIL, la rotta non esiste ancora. I test di questo task si completano con il Task 7, che fornisce l'endpoint: qui si scrivono e si lasciano rossi solo quelli di validazione pura, spostando gli altri al Task 7 se l'ordine di esecuzione lo richiede.

- [ ] **Step 3: Implementa le due Form Request**

`authorize()` restituisce `true`, come `SusLoginRequest`: l'autorizzazione la fa il middleware del canale. Le regole dei campi dichiarati arrivano da `ApplicationSchema::rules()`; i valori numerici acquisiscono un vincolo di plausibilità (`between:`) oltre al tipo, perché le unità divergono dal format e non sono ancora concordate. Nessuna regola impedisce le chiavi non dichiarate: il payload intero va conservato.

- [ ] **Step 4: Esegui i test di validazione e verifica che passino**

Esegui: `vendor/bin/pest tests/Feature/TrailRegistry/StoreApplicationRequestTest.php`
Atteso: PASS per i test di validazione; quelli che chiamano l'endpoint passano al Task 7.

- [ ] **Step 5: Commit**

```bash
git add src/Http/Requests/TrailRegistry tests/Feature/TrailRegistry/StoreApplicationRequestTest.php
git commit -m "feat(oc:8968): le Form Request del fascicolo derivano dallo schema"
```

---

### Task 4: Il calcolo del DEM, condiviso e con un timeout

**Repo:** `wm-package`

**Files:**
- Create: `src/TrailRegistry/Services/ApplicationDemService.php`
- Modify: `src/Http/Clients/DemClient.php:22-40` (timeout e traduzione dell'errore)
- Modify: `src/Services/Models/EcTrackService.php:64-67` (timeout opzionale)
- Modify: `src/TrailRegistry/Jobs/UpdateTrailApplicationDemJob.php:65-100` (usa il service)
- Modify: `config/wm-package.php` (il valore del timeout)
- Test: `tests/Feature/TrailRegistry/ApplicationDemServiceTest.php`

**Interfaces:**
- Produces:
  - `ApplicationDemService::computeAndStore(TrailApplication $application, ?int $timeout = null): bool` — chiama il servizio, scrive `properties.dem_data` e `geometry`, restituisce `false` se il servizio non ha risposto. **Non solleva** quando il servizio fallisce.
  - `EcTrackService::fetchDemTechData(array $geojson, ?int $timeout = null): array` — firma estesa, il parametro è opzionale e il comportamento esistente non cambia.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('scrive i dati tecnici e la geometria tridimensionale', function () {
    fakeDemResponding();
    $application = TrailApplication::factory()->create();

    expect(app(ApplicationDemService::class)->computeAndStore($application))->toBeTrue();

    $application->refresh();
    expect($application->properties['dem_data'])->toHaveKeys(['distance', 'ascent', 'duration_forward']);
});

it('non solleva e restituisce false quando il servizio non risponde', function () {
    fakeDemTimingOut();
    $application = TrailApplication::factory()->create();

    expect(app(ApplicationDemService::class)->computeAndStore($application, 3))->toBeFalse();
});

it('non lascia uscire il corpo della risposta del servizio', function () {
    fakeDemFailingWith('<html>errore interno del servizio</html>');
    $application = TrailApplication::factory()->create();

    app(ApplicationDemService::class)->computeAndStore($application);

    expect(collect(Log::channel('stack'))->isEmpty())->toBeFalse();  // l'errore si logga
})->todo('verifica che il messaggio dell eccezione non contenga il body');
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Esegui: `vendor/bin/pest tests/Feature/TrailRegistry/ApplicationDemServiceTest.php`
Atteso: FAIL, il service non esiste.

- [ ] **Step 3: Aggiungi il timeout al client**

`DemClient::getTechData()` applica `->timeout($seconds)` al client HTTP, con il valore da `config('wm-package.dem.timeout_seconds')` e default **5**, sovrascrivibile dal chiamante. Nessun retry. Il messaggio dell'eccezione **non contiene più il body della risposta né il nome di un job**: il body si logga, al chiamante arriva un messaggio nostro.

- [ ] **Step 4: Estrai la scrittura dal job nel service**

`ApplicationDemService::computeAndStore()` contiene la query che oggi sta in `UpdateTrailApplicationDemJob::handle()` (righe 85-100): `jsonb_set` su `properties.dem_data` e `geometry` riscritta con `ST_Multi(ST_Force3D(...))`. **SQL puro, mai l'ORM.** Il job diventa un chiamante del service e non duplica la query.

- [ ] **Step 5: Esegui i test e verifica che passino**

Esegui: `vendor/bin/pest tests/Feature/TrailRegistry/ApplicationDemServiceTest.php tests/Feature/TrailRegistry/TrailApplicationDemJobTest.php`
Atteso: PASS. Il test del job esistente prova che il comportamento asincrono non è cambiato.

- [ ] **Step 6: Commit**

```bash
git add src/TrailRegistry/Services src/Http/Clients/DemClient.php src/Services/Models/EcTrackService.php src/TrailRegistry/Jobs/UpdateTrailApplicationDemJob.php config/wm-package.php tests/Feature/TrailRegistry/ApplicationDemServiceTest.php
git commit -m "feat(oc:8968): il calcolo DEM e condiviso fra job e chiamata sincrona, con timeout"
```

---

### Task 5: Il job non rifà un calcolo già fatto

**Repo:** `wm-package`

**Files:**
- Modify: `src/TrailRegistry/Jobs/UpdateTrailApplicationDemJob.php:65-72`
- Modify: `src/Models/Abstracts/MultiLineString.php` (rilascio del lock)
- Test: `tests/Feature/TrailRegistry/TrailApplicationDemJobTest.php` (esteso)

**Interfaces:**
- Consumes: `ApplicationDemService` (Task 4), `MultiLineString::needsDem()`.
- Produces: `MultiLineString::releaseDemLock(): void` — toglie il lock preso in `booted()`.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('non chiama il servizio quando i dati tecnici ci sono gia', function () {
    $application = TrailApplication::factory()->withDemData()->create();
    fakeDemCountingCalls();

    (new UpdateTrailApplicationDemJob($application->id))->handle(app(ApplicationDemService::class));

    expect(demCallCount())->toBe(0);
});

it('lascia riaccodabile il calcolo quando quello sincrono e fallito', function () {
    $application = TrailApplication::factory()->create();   // booted() ha preso il lock
    $application->releaseDemLock();

    expect($application->acquireDemLock())->toBeTrue();
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Esegui: `vendor/bin/pest tests/Feature/TrailRegistry/TrailApplicationDemJobTest.php`
Atteso: FAIL sul conteggio delle chiamate e su `releaseDemLock()`.

- [ ] **Step 3: Metti il controllo nel job**

`handle()` verifica `needsDem()` dopo aver caricato l'istanza e prima di chiamare il servizio: se il dato c'è già, esce. Vale per qualunque strada abbia creato l'istanza, non solo per il canale API, e non richiede nessuna variabile da impostare e rimettere a posto.

- [ ] **Step 4: Implementa `releaseDemLock()`**

Toglie la chiave `dem-lock:<tabella>:<id>` dalla cache Redis. Serve al Task 7: quando il calcolo sincrono fallisce, il lock preso da `booted()` resterebbe per un'ora e `dispatchDemIfMissing()` non riaccoderebbe.

- [ ] **Step 5: Esegui i test e verifica che passino**

Esegui: `vendor/bin/pest tests/Feature/TrailRegistry/TrailApplicationDemJobTest.php`
Atteso: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/TrailRegistry/Jobs/UpdateTrailApplicationDemJob.php src/Models/Abstracts/MultiLineString.php tests/Feature/TrailRegistry/TrailApplicationDemJobTest.php
git commit -m "fix(oc:8968): il job DEM non rifa un calcolo gia presente"
```

---

### Task 6: La transizione dalla compilazione all'istruttoria

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6-la-transizione-dalla-compilazione-allistruttoria)

**Repo:** `wm-package`

**Files:**
- Create: `src/TrailRegistry/Exceptions/ApplicationIncompleteException.php`
- Modify: `src/TrailRegistry/TrailRegistryService.php` (metodo nuovo)
- Test: `tests/Feature/TrailRegistry/ConfirmCompilationTest.php`

**Interfaces:**
- Produces:
  - `TrailRegistryService::confirmCompilation(TrailApplication $application): void` — solleva `ApplicationIncompleteException` se manca qualcosa, altrimenti porta lo stato a `UnderReview`. **Non riserva il numero**, che nasce con l'istanza.
  - `ApplicationIncompleteException::missing(): array` — `['fields' => [...], 'attachments' => [...]]`.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('porta in istruttoria un fascicolo completo', function () {
    $application = TrailApplication::factory()->complete()->create();

    app(TrailRegistryService::class)->confirmCompilation($application);

    expect($application->refresh()->status)->toBe(TrailApplicationStatus::UnderReview);
});

it('rifiuta e dice cosa manca, lasciando il fascicolo in compilazione', function () {
    $application = TrailApplication::factory()->complete()->create();
    $application->clearMediaCollection('report');

    try {
        app(TrailRegistryService::class)->confirmCompilation($application);
        $this->fail('doveva sollevare');
    } catch (ApplicationIncompleteException $e) {
        expect($e->missing()['attachments'])->toContain('report');
    }

    expect($application->refresh()->status)->toBe(TrailApplicationStatus::UnderCompilation);
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Esegui: `vendor/bin/pest tests/Feature/TrailRegistry/ConfirmCompilationTest.php`
Atteso: FAIL, il metodo non esiste.

- [ ] **Step 3: Implementa il metodo e l'eccezione**

`confirmCompilation()` chiama `missingForConfirmation()` del modello e, se uno dei due elenchi non è vuoto, solleva. Altrimenti scrive lo stato. Il docblock dichiara perché la regola vive qui e non nel controller: descrive quando un'istanza smette di essere compilabile, cioè dominio.

- [ ] **Step 4: Esegui i test e verifica che passino**

Esegui: `vendor/bin/pest tests/Feature/TrailRegistry/ConfirmCompilationTest.php`
Atteso: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/TrailRegistry/Exceptions src/TrailRegistry/TrailRegistryService.php tests/Feature/TrailRegistry/ConfirmCompilationTest.php
git commit -m "feat(oc:8968): la chiusura della compilazione e un metodo del service"
```

---

### Task 7: L'endpoint di creazione

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-7-lendpoint-di-creazione)

**Repo:** `wm-package`

**Files:**
- Create: `src/Http/Controllers/Api/TrailRegistry/StoreApplicationController.php`
- Test: `tests/Feature/TrailRegistry/StoreApplicationTest.php`

**Interfaces:**
- Consumes: `StoreApplicationRequest` (Task 3), `ApplicationDemService` (Task 4), `releaseDemLock()` (Task 5), `ApplicationSchema::version()` (Task 1), `TrailGeometryReader::wktFrom()`, `TrailRegistryService::reserve()`.
- Produces: risposta `201` con `['id' => int, 'tech_data' => array|null, 'dem_status' => 'computed'|'processing']`. **Nessun numero di sentiero in risposta.**

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('crea l istanza in compilazione e risponde con id e dati tecnici', function () {
    fakeDemResponding();

    $response = $this->postJson(route('sus.applications.store'), completePayload())
        ->assertCreated()
        ->assertJsonPath('dem_status', 'computed');

    $application = TrailApplication::find($response->json('id'));
    expect($application->status)->toBe(TrailApplicationStatus::UnderCompilation);
    expect($application->source)->toBe('api');
    expect($application->getMedia('original_geometry'))->toHaveCount(1);
});

it('non restituisce il numero del sentiero, benche sia gia riservato', function () {
    fakeDemResponding();

    $response = $this->postJson(route('sus.applications.store'), completePayload())->assertCreated();

    expect($response->json())->not->toHaveKey('code');
    expect(TrailApplication::find($response->json('id'))->activeCode)->not->toBeNull();
});

it('conserva il payload intero piu la versione dello schema', function () {
    fakeDemResponding();

    $id = $this->postJson(route('sus.applications.store'), completePayload())->json('id');

    expect(TrailApplication::find($id)->api_data)->toHaveKey('_schema_version');
});

it('dichiara il calcolo in elaborazione quando il servizio non risponde, senza fallire', function () {
    fakeDemTimingOut();

    $this->postJson(route('sus.applications.store'), completePayload())
        ->assertCreated()
        ->assertJsonPath('dem_status', 'processing');
});

it('non scrive i valori dichiarati fra i valori manuali', function () {
    fakeDemResponding();

    $id = $this->postJson(route('sus.applications.store'), completePayload([
        'distance' => 12.4,
        'duration_forward' => 180,
    ]))->json('id');

    // `classifyField()` restituisce MANUAL appena `manual_data` non e' vuoto:
    // il dichiarato vincerebbe sul calcolato e finirebbe pubblicato.
    expect(TrailApplication::find($id)->properties['manual_data'] ?? [])->toBeEmpty();
});

// Review Focus 2
it('rifiuta un tracciato fuori da ogni settore senza lasciare righe', function () {
    $before = TrailApplication::count();

    $this->postJson(route('sus.applications.store'), completePayload(['geometry' => gpxOutsideSardinia()]))
        ->assertStatus(422)
        ->assertJsonStructure(['error']);

    expect(TrailApplication::count())->toBe($before);
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Esegui: `vendor/bin/pest tests/Feature/TrailRegistry/StoreApplicationTest.php`
Atteso: FAIL, il controller non esiste.

- [ ] **Step 3: Implementa `StoreApplicationController::__invoke(StoreApplicationRequest $request): JsonResponse`**

Controller a singola azione, come `SusPingController`. **Tutto in una transazione**: `create()` con `source = 'api'` e `status = UnderCompilation`, la geometria letta con `TrailGeometryReader::wktFrom()`, `reserve()` come fa la Resource Nova — catturando `SectorNotFoundException|SectorExhaustedException` e traducendole in un 422 `{error: …}` — poi `writeApiData()` con il payload intero più la chiave `_schema_version`, il file in `original_geometry`, e infine il calcolo sincrono con il timeout breve. Se il calcolo fallisce, `releaseDemLock()` e `dem_status: processing`.

Annotazioni Scramble `#[Group]` sulla classe e `#[Response]` per ogni status, come in `SusAuthController.php:41-46`.

- [ ] **Step 4: Esegui i test e verifica che passino**

Esegui: `vendor/bin/pest tests/Feature/TrailRegistry/StoreApplicationTest.php tests/Feature/TrailRegistry/StoreApplicationRequestTest.php`
Atteso: PASS, compresi i test del Task 3 che chiamavano l'endpoint.

- [ ] **Step 5: Commit**

```bash
git add src/Http/Controllers/Api/TrailRegistry tests/Feature/TrailRegistry/StoreApplicationTest.php
git commit -m "feat(oc:8968): endpoint di creazione dell istanza"
```

---

### Task 8: L'endpoint di upload di un allegato

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-8-lendpoint-di-upload-di-un-allegato)

**Repo:** `wm-package`

**Files:**
- Create: `src/Http/Controllers/Api/TrailRegistry/StoreAttachmentController.php`
- Test: `tests/Feature/TrailRegistry/StoreAttachmentTest.php`

**Interfaces:**
- Consumes: `StoreAttachmentRequest` (Task 3), `TrailApplication::addPhoto()`, le sei collection di `registerMediaCollections()`.
- Produces: risposta `201` con `['collection' => string, 'count' => int]`.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('aggiunge l allegato alla collection indicata', function () {
    $application = TrailApplication::factory()->create();

    $this->postJson(route('sus.applications.attachments.store', $application), [
        'collection' => 'report',
        'file' => UploadedFile::fake()->create('relazione.pdf'),
    ])->assertCreated();

    expect($application->getMedia('report'))->toHaveCount(1);
});

// Review Focus 3
it('non conta due volte la stessa fotografia rimandata dopo un timeout', function () {
    $application = TrailApplication::factory()->create();
    $photo = UploadedFile::fake()->image('sentiero.jpg');

    $this->postJson(route('sus.applications.attachments.store', $application), ['collection' => 'photos', 'file' => $photo])->assertCreated();
    $this->postJson(route('sus.applications.attachments.store', $application), ['collection' => 'photos', 'file' => $photo])->assertCreated();

    expect($application->getMedia('photos'))->toHaveCount(1);
});

it('rifiuta la sesta fotografia distinta', function () {
    $application = TrailApplication::factory()->withPhotos(5)->create();

    $this->postJson(route('sus.applications.attachments.store', $application), [
        'collection' => 'photos',
        'file' => UploadedFile::fake()->image('sesta.jpg'),
    ])->assertStatus(422);
});

// Review Focus 5
it('non accetta allegati per un istanza gia trasmessa', function () {
    $application = TrailApplication::factory()->underReview()->create();

    $this->postJson(route('sus.applications.attachments.store', $application), [
        'collection' => 'report',
        'file' => UploadedFile::fake()->create('relazione.pdf'),
    ])->assertStatus(409);
});

it('non accetta allegati per un istanza nata d ufficio', function () {
    $application = TrailApplication::factory()->office()->create();

    $this->postJson(route('sus.applications.attachments.store', $application), [
        'collection' => 'report',
        'file' => UploadedFile::fake()->create('relazione.pdf'),
    ])->assertStatus(404);
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Esegui: `vendor/bin/pest tests/Feature/TrailRegistry/StoreAttachmentTest.php`
Atteso: FAIL, il controller non esiste.

- [ ] **Step 3: Implementa `StoreAttachmentController::__invoke(StoreAttachmentRequest $request, TrailApplication $application): JsonResponse`**

Verifica che l'istanza sia del canale API (`source === 'api'`, altrimenti 404: per il chiamante quell'istanza non esiste) e in `under_compilation` (altrimenti 409). Le fotografie passano da `addPhoto()`, che deduplica sul contenuto; **il limite di cinque si applica dopo la deduplicazione**, contando i media già presenti. Le altre cinque collection sono `singleFile()` e un reinvio sostituisce.

- [ ] **Step 4: Esegui i test e verifica che passino**

Esegui: `vendor/bin/pest tests/Feature/TrailRegistry/StoreAttachmentTest.php tests/Feature/TrailRegistry/TrailApplicationMediaTest.php`
Atteso: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Http/Controllers/Api/TrailRegistry/StoreAttachmentController.php tests/Feature/TrailRegistry/StoreAttachmentTest.php
git commit -m "feat(oc:8968): endpoint di upload di un allegato"
```

---

### Task 9: L'endpoint di conferma

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-9-lendpoint-di-conferma)

**Repo:** `wm-package`

**Files:**
- Create: `src/Http/Controllers/Api/TrailRegistry/ConfirmApplicationController.php`
- Test: `tests/Feature/TrailRegistry/ConfirmApplicationEndpointTest.php`

**Interfaces:**
- Consumes: `TrailRegistryService::confirmCompilation()` e `ApplicationIncompleteException` (Task 6), `ApplicationDemService` (Task 4).
- Produces: risposta `200` con `['id' => int, 'status' => string, 'tech_data' => array|null, 'dem_status' => string]`; `422` con `['error' => string, 'missing' => ['fields' => [...], 'attachments' => [...]]]`. **Nessun numero di sentiero.**

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('trasmette un fascicolo completo e risponde con il risultato finale', function () {
    $application = TrailApplication::factory()->complete()->create();

    $this->postJson(route('sus.applications.confirm', $application))
        ->assertOk()
        ->assertJsonPath('status', 'under_review')
        ->assertJsonStructure(['id', 'status', 'tech_data', 'dem_status']);
});

it('elenca cosa manca e lascia il fascicolo in compilazione', function () {
    $application = TrailApplication::factory()->complete()->create();
    $application->clearMediaCollection('maintenance_plan');

    $this->postJson(route('sus.applications.confirm', $application))
        ->assertStatus(422)
        ->assertJsonPath('missing.attachments.0', 'maintenance_plan');

    expect($application->refresh()->status)->toBe(TrailApplicationStatus::UnderCompilation);
});

// Review Focus 4
it('risponde con il risultato finale anche alla seconda conferma', function () {
    $application = TrailApplication::factory()->complete()->create();

    $this->postJson(route('sus.applications.confirm', $application))->assertOk();
    $this->postJson(route('sus.applications.confirm', $application))
        ->assertOk()
        ->assertJsonPath('status', 'under_review');
});

it('dichiara il calcolo in elaborazione se i dati tecnici mancano ancora', function () {
    $application = TrailApplication::factory()->complete()->withoutDemData()->create();

    $this->postJson(route('sus.applications.confirm', $application))
        ->assertOk()
        ->assertJsonPath('dem_status', 'processing');
});

it('non restituisce il numero del sentiero', function () {
    $application = TrailApplication::factory()->complete()->create();

    expect($this->postJson(route('sus.applications.confirm', $application))->json())
        ->not->toHaveKey('code');
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Esegui: `vendor/bin/pest tests/Feature/TrailRegistry/ConfirmApplicationEndpointTest.php`
Atteso: FAIL, il controller non esiste.

- [ ] **Step 3: Implementa `ConfirmApplicationController::__invoke(TrailApplication $application): JsonResponse`**

Verifica l'origine come nel Task 8. **Se l'istanza è già in `under_review` risponde `200` con il risultato finale** invece di un errore: una conferma ripetuta dopo un timeout di rete non deve far credere al chiamante di non aver trasmesso. Altrimenti chiama `confirmCompilation()` e traduce `ApplicationIncompleteException` in `422` con `missing`. `dem_status` vale `processing` solo quando i valori mancano **del tutto**: su un risultato parziale si restituisce ciò che c'è, perché `needsDem()` considera il dato presente e nessun ricalcolo ripartirebbe.

- [ ] **Step 4: Esegui i test e verifica che passino**

Esegui: `vendor/bin/pest tests/Feature/TrailRegistry/ConfirmApplicationEndpointTest.php`
Atteso: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Http/Controllers/Api/TrailRegistry/ConfirmApplicationController.php tests/Feature/TrailRegistry/ConfirmApplicationEndpointTest.php
git commit -m "feat(oc:8968): endpoint di conferma della compilazione"
```

---

### Task 10: Gli allegati di un'istanza non escono dalla rotta pubblica dei media

**Repo:** `wm-package`

**Files:**
- Modify: `src/Http/Controllers/Api/MediaController.php:16-22`
- Test: `tests/Feature/TrailRegistry/ApplicationMediaPrivacyTest.php`

**Interfaces:**
- Produces: `MediaController::show()` risponde `404` per un media di `TrailApplication`.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('non consegna l url di un allegato di un istanza', function () {
    $application = TrailApplication::factory()->complete()->create();
    $media = $application->getFirstMedia('report');

    $this->getJson(route('api.media.geojson', $media))->assertNotFound();
});

it('continua a consegnare i media degli altri modelli', function () {
    $poi = EcPoi::factory()->withMedia()->create();

    $this->getJson(route('api.media.geojson', $poi->getFirstMedia()))->assertOk();
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Esegui: `vendor/bin/pest tests/Feature/TrailRegistry/ApplicationMediaPrivacyTest.php`
Atteso: FAIL, l'endpoint risponde 200 anche per gli allegati dell'istanza.

- [ ] **Step 3: Nega in base al modello proprietario**

`show()` risponde `404` quando `$media->model_type` è quello della `TrailApplication` risolta da `TrailRegistryClasses::application()`. **Non un elenco di collection**: un elenco sarebbe una blacklist, e la settima collection che qualcuno aggiungerà sarebbe pubblica per difetto. Il docblock dice perché.

- [ ] **Step 4: Esegui i test e verifica che passino**

Esegui: `vendor/bin/pest tests/Feature/TrailRegistry/ApplicationMediaPrivacyTest.php`
Atteso: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Http/Controllers/Api/MediaController.php tests/Feature/TrailRegistry/ApplicationMediaPrivacyTest.php
git commit -m "fix(oc:8968): gli allegati di un istanza non escono dalla rotta pubblica dei media"
```

---

### Task 11: Le rotte del canale

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-11-le-rotte-del-canale)

**Repo:** `forestas`

**Files:**
- Modify: `routes/sus.php:17-20`
- Test: `tests/Feature/Sus/SusApplicationRoutesTest.php`

**Interfaces:**
- Consumes: i tre controller del package (Task 7, 8, 9).
- Produces: le rotte `sus.applications.store`, `sus.applications.attachments.store`, `sus.applications.confirm`.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('espone le tre rotte sotto il prefisso del canale', function () {
    expect(route('sus.applications.store'))->toContain('api/v1/sus/trail-applications');
});

it('non lascia passare chi non e il client del canale', function () {
    $this->actingAs(User::factory()->create(), 'api')
        ->postJson(route('sus.applications.store'), [])
        ->assertForbidden();
});

it('limita la frequenza delle chiamate', function () {
    $routes = collect(Route::getRoutes())->firstWhere('uri', 'api/v1/sus/trail-applications');

    expect(collect($routes->gatherMiddleware())->filter(fn ($m) => str_starts_with($m, 'throttle:')))
        ->not->toBeEmpty();
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Esegui: `vendor/bin/pest tests/Feature/Sus/SusApplicationRoutesTest.php`
Atteso: FAIL, le rotte non esistono.

- [ ] **Step 3: Aggiungi le tre rotte**

Dentro il gruppo autenticato di `routes/sus.php`, che ha già `auth:api` e `LogSusRequest`, con un `throttle:` proprio — oggi il limite sta solo sul gruppo `auth`. Puntano ai controller del package, senza sottoclassi nello shard.

- [ ] **Step 4: Esegui i test e verifica che passino**

Esegui: `vendor/bin/pest tests/Feature/Sus/`
Atteso: PASS, compresi i sei test del canale già esistenti.

- [ ] **Step 5: Verifica che gli endpoint compaiano nella documentazione**

Apri `/docs/api/sus` e controlla che le tre chiamate ci siano con i loro parametri. Il filtro pubblica le sole rotte con URI sotto `api/v1/sus/` (`AppServiceProvider.php:92`).

- [ ] **Step 6: Commit**

```bash
git add routes/sus.php tests/Feature/Sus/SusApplicationRoutesTest.php
git commit -m "feat(oc:8968): le tre rotte del canale verso i controller del package"
```

---

### Task 12: La documentazione

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-12-la-documentazione)

**Repo:** `wm-package`

**Files:**
- Create: `docs/knowledge/<argomento>.md` — il nome si sceglie leggendo `ls docs/knowledge/`, secondo il criterio della skill
- Modify: `CLAUDE.md` (una riga sotto `## Conoscenza`)
- Modify: le annotazioni Scramble dei tre controller (Task 7, 8, 9)
- Modify: `resources/lang/*.json` nelle cinque lingue

- [ ] **Step 1: Scrivi la pagina di conoscenza**

Dichiara il confine: il package offre le operazioni del fascicolo — apri, aggiungi un allegato, chiudi — e lo shard ci monta sopra il canale del proprio ente, con le rotte, il client e i middleware. Più il motivo per cui lo schema dei campi è ridefinibile dallo shard. Vale per chiunque monti il package, quindi sta qui e non in forestas.

- [ ] **Step 2: Completa la documentazione degli endpoint**

Ogni parametro porta il **numero di campo del format**, nella forma «campo 29 del format — *Origine (da)*»; il link al foglio sta una volta sola nell'intestazione della pagina. Vanno dichiarate le **unità che viaggiano nel payload** — `distance` in chilometri con un decimale, `duration_forward` e `duration_backward` in minuti interi, mentre il format chiede metri e `ORE:MINUTI` — e in modo esplicito che **questa chiamata non si corregge, si rifà**.

- [ ] **Step 3: Aggiungi le traduzioni mancanti**

Ogni stringa nuova rivolta a una persona va in `resources/lang/*.json` in tutte e cinque le lingue, senza lasciare chiavi mancanti in nessun file.

- [ ] **Step 4: Verifica la formattazione, con scope**

Esegui: `vendor/bin/pint src/TrailRegistry src/Http/Controllers/Api/TrailRegistry src/Http/Requests/TrailRegistry`
Atteso: pulito. **Mai `composer format` senza scope**, che riformatterebbe l'intero repo.

- [ ] **Step 5: Esegui PHPStan sui moduli toccati**

Esegui: `vendor/bin/phpstan analyse src/TrailRegistry src/Http/Controllers/Api src/Http/Requests`
Atteso: No errors.

- [ ] **Step 6: Commit**

```bash
git add docs/knowledge CLAUDE.md resources/lang src/Http/Controllers/Api/TrailRegistry
git commit -m "docs(oc:8968): il confine fra le operazioni del fascicolo e il canale dell ente"
```

---

## Dopo i task: il rilascio

1. **Prima il package:** PR su `wm-package` verso `develop`, squash merge.
2. **Poi lo shard:** PR su `forestas` verso `develop` con il puntatore del submodule aggiornato **e** le rotte, nello stesso commit. L'ordine inverso rompe cose che non sembrano collegate.
3. **Nessuna migration**: la colonna `api_data` e il vincolo sullo stato esistono già da oc:8960.
4. **Su UAT**, prima di consegnare l'ambiente a Engineering, va spento il reset notturno che tronca `trail_applications`: finché esiste, un'istanza creata il pomeriggio non c'è più la mattina dopo e l'upload sul suo id risponde 404.
5. **Prima del collaudo con Engineering**, segnalare la divergenza sulle unità: chilometri e minuti interi contro i metri e gli `ORE:MINUTI` del format. Dopo il collaudo il contratto è congelato.
