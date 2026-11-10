> Ticket: oc:8960

# L'istanza dal SUS — piano di implementazione

> **Per chi esegue:** usa `superpowers:subagent-driven-development` (consigliato) o
> `superpowers:executing-plans` per implementare task per task. I passi usano checkbox
> (`- [ ]`) per il tracciamento.

**Goal:** `TrailApplication` accoglie e conserva i dati che il richiedente dichiara sul SUS e i sei
allegati del format, in sola lettura per l'operatore, con lo stato nuovo `under_compilation` per le
domande non ancora consegnate per intero.

**Architecture:** nessuna colonna nuova. Il payload del SUS vive in `properties → api_data`, scritto
una volta sola; gli allegati in sei collection della Media Library; lo stato nuovo è un valore
dell'enum che le autorizzazioni esistenti — tutte whitelist su `UnderReview` — già negano. Niente
dei dati nuovi passa sull'`EcTrack` creata dall'approvazione.

**Tech Stack:** PHP 8.1+, Laravel 10-13, Nova 5, PostgreSQL con PostGIS, Spatie Media Library, Pest.

**Spec:** [overview.md](overview.md)

## Global Constraints

- Identificatori in inglese, **tranne** i quattro soggetti: `soggetto_gestore`,
  `soggetto_rilevatore`, `soggetto_manutentore`, `soggetto_custode`. Non tradurli.
- PHP minimo `>8.1`: mai `const` dentro un trait.
- Le colonne e gli attributi che Nova mostra si dichiarano nullable: Nova costruisce i campi anche
  su un'istanza vuota, e un attributo non nullable fa rispondere 500 alla pagina.
- Le traduzioni del package stanno in `resources/lang/*.json` — de, en, es, fr, it — non in `lang/`.
  Ogni stringa nuova passata a `__()` va aggiunta in tutte e cinque.
- Ogni scrittura in `properties` da SQL parte da
  `CASE WHEN jsonb_typeof(properties) = 'object' THEN properties ELSE '{}'::jsonb END` (oc:8571).
- `manual_data` non si tocca: i valori tecnici dichiarati restano in `api_data`.
- Le autorizzazioni non si modificano: sono whitelist su `status === UnderReview` e negano già il
  valore nuovo. Si scrivono i test che lo dimostrano.
- I test girano nel container dello shard: `docker exec -it php-forestas bash`, poi `composer test`
  da `wm-package/`. Prima di lanciarli, leggi `.claude/rules/test.md` dello shard per l'isolamento
  del database.
- Commit: `feat(oc:8960): …`. I commit li esegue il dev, non chi implementa.

## Propedeuticità e parallelismo

Due vincoli diversi, da non confondere: le **dipendenze di interfaccia** — un task ha bisogno di ciò
che un altro produce — e i **file condivisi**, che sono il limite vero quando si lavora in parallelo
su worktree separati.

| Task | Dipende da | File che tocca |
|---|---|---|
| 1 — stato `under_compilation` | — | `Enums/TrailApplicationStatus.php` · `Models/TrailApplication.php` (solo il docblock di classe) · le due migration · `resources/lang/*.json` (4 chiavi) · `tests/Unit/.../TrailRegistryEnumsTest.php` |
| 2 — collection degli allegati | — | `Models/TrailApplication.php` (costanti e `registerMediaCollections()`) · test nuovo |
| 3 — `api_data` e obbligatori | **2** (l'elenco degli allegati obbligatori nomina le collection) | `Models/TrailApplication.php` (metodi nuovi) · test nuovo |
| 4 — tab dei dati dichiarati | **3** (`API_DATA_KEY`) | `Nova/TrailApplication.php` (`fields()`) · `resources/lang/*.json` (etichette) · test nuovo |
| 5 — stato tradotto | **1** (`label()`) | `Nova/TrailApplication.php` (riga 120) · `Nova/Filters/TrailApplicationStatusFilter.php` · test nuovo |
| 6 — approvazione | **1, 2, 3** | `Nova/Actions/ApproveTrailApplication.php` · due test esistenti |
| 7 — verifica finale | tutti | nessuno |

**Tre file sono condivisi fra task**, e vanno trattati come sezioni critiche:

- `Models/TrailApplication.php` — task **1, 2, 3**. Toccano parti diverse (docblock, collection,
  metodi), ma su worktree separati il merge è a carico di chi integra. Conviene tenerli **in
  sequenza**, nell'ordine 1 → 2 → 3.
- `Nova/TrailApplication.php` — task **4 e 5**. Il 5 cambia una riga sola (la 120), il 4 aggiunge un
  Tab: se partono insieme, fai finire prima il 5.
- `resources/lang/*.json` — task **1 e 4**. Aggiungono chiavi diverse allo stesso file: il conflitto
  è banale da risolvere, ma esiste.

**Come si parallelizza davvero:**

```
Task 1 ──┬──────────────→ Task 5 ───────────────┐
         │                                      │
         └─→ Task 2 → Task 3 ──┬──→ Task 4 ─────┼──→ Task 7
                               └──→ Task 6 ─────┘
```

- **Task 1 e Task 2 possono partire insieme** solo accettando un merge sul modello; se si lavora su
  un branch solo, falli in sequenza.
- **Appena finito il Task 1, il Task 5 è libero** e gira in parallelo a 2 e 3: tocca solo la
  Resource Nova e il filtro.
- **Appena finito il Task 3, 4 e 6 sono liberi e indipendenti fra loro**: il 4 sta nella Resource, il
  6 nell'Action. È il punto in cui il parallelismo rende di più.
- **Il Task 7 chiude**, e va eseguito su tutto integrato: non ha senso su un ramo solo.

Il percorso critico è `1 → 2 → 3 → 6 → 7`: è la catena più lunga, e nessun parallelismo la
accorcia.

## Requisiti dell'overview che qui non hanno un task

Non sono dimenticanze: vivono negli endpoint, che sono la macro area 2 del tag 709.

- **Le unità che l'API accetta** (`distance` in km, le durate in minuti) e il **numero di campo del
  format** accanto a ogni parametro: sono decisioni di contratto, si realizzano scrivendo il
  Request e la pagina delle API.
- **La validazione**: della forma alla creazione, della completezza alla conferma. Qui si prepara
  solo ciò su cui la validazione poggia, cioè `missingForConfirmation()` (Task 3).
- **Il calcolo del DEM una volta sola**: chiudere il dispatch automatico in `booted()` ha senso solo
  quando esiste il percorso sincrono che calcola per la risposta. Finché quello non c'è, togliere
  l'asincrono lascerebbe le istanze senza dati tecnici.

## Review Focus

Cinque condizioni che la spec implica e che nessun task eserciterebbe da sé. Il test di ciascuna è
aggiunto al task che possiede il codice.

1. **`properties` è `[]` e non `{}`** quando si scrive `api_data`: su un array `||` concatena e la
   chiave sparisce senza errore → Task 3.
2. **Il payload porta chiavi che non sappiamo mappare**: devono restare in `api_data`, non essere
   scartate → Task 3.
3. **Un'istanza senza `api_data`** — quella creata d'ufficio in Nova — aperta nel detail: i Field
   sui dati dichiarati non devono rompere la pagina → Task 4.
4. **La stessa foto reinviata dopo un timeout**: non deve diventare la sesta di cinque → Task 2.
5. **Un'istanza `under_compilation` aperta in Nova**: index e detail devono funzionare, e nessuna
   Action deve girarci sopra → Task 6.

---

### Task 1: Lo stato `under_compilation`

**Files:**
- Modify: `src/TrailRegistry/Enums/TrailApplicationStatus.php`
- Modify: `src/TrailRegistry/Models/TrailApplication.php` (docblock di classe, righe 26-29)
- Modify: `database/migrations/trail_registry/zz_2026_09_09_000001_create_trail_applications_table.php.stub:65`
- Modify (repo forestas): `database/migrations/2026_09_10_073030_zz_2026_09_09_000001_create_trail_applications_table.php:65`
- Test: `tests/Unit/TrailRegistry/TrailRegistryEnumsTest.php`

**Interfaces:**
- Produces: `TrailApplicationStatus::UnderCompilation` con valore `'under_compilation'`;
  `TrailApplicationStatus::label(): string`, che restituisce la stringa tradotta.

- [ ] **Step 1: Aggiorna il test dell'enum**

In `tests/Unit/TrailRegistry/TrailRegistryEnumsTest.php`, il test «espone i tre stati dell
istruttoria» diventa:

```php
it('espone i quattro stati dell istruttoria', function () {
    expect(array_map(fn ($c) => $c->value, TrailApplicationStatus::cases()))
        ->toBe(['under_compilation', 'under_review', 'rejected', 'approved']);
});

it('traduce ogni stato per l operatore', function () {
    foreach (TrailApplicationStatus::cases() as $status) {
        expect($status->label())->not->toBe($status->value);
    }
});
```

- [ ] **Step 2: Lancia il test e verifica che fallisca**

Run: `composer test -- --filter=TrailRegistryEnums`
Atteso: FAIL, l'array ha tre valori e `label()` non esiste.

- [ ] **Step 3: Aggiungi il valore e il metodo all'enum**

`case UnderCompilation = 'under_compilation';` come **primo** case, perché precede l'istruttoria nel
ciclo di vita. Aggiungi `public function label(): string` che mappa ogni case a `__()` con una
chiave in inglese: `Under compilation`, `Under review`, `Rejected`, `Approved`.

Riscrivi il docblock della classe: oggi dichiara «Tre valori e non cinque: "presentata" non è
raggiungibile (un'istanza che non passa la prevalidazione non viene scritta)». Ora i valori sono
quattro, e `under_compilation` è la domanda che il SUS ha creato e non ha ancora finito di
consegnare — l'istanza esiste, il numero è riservato, gli allegati non sono tutti arrivati.

- [ ] **Step 4: Aggiungi le cinque traduzioni**

In `resources/lang/{de,en,es,fr,it}.json`, le quattro chiavi degli stati. In italiano: «In
compilazione», «In istruttoria», «Respinta», «Approvata».

- [ ] **Step 5: Riscrivi il docblock del modello**

`src/TrailRegistry/Models/TrailApplication.php`, righe 26-29: «Non esistono istanze non
prevalidate: se il controllo formale non passa, l'API rifiuta e Nova non salva. Ogni riga qui porta
quindi già il proprio codice riservato» resta vero — il numero si riserva alla creazione anche in
`under_compilation` — ma va aggiunto che un'istanza può esistere senza avere ancora tutti gli
allegati, e che quello è lo stato `under_compilation`.

- [ ] **Step 6: Estendi il vincolo CHECK nei due file**

In entrambe le migration, `CHECK (status IN ('under_review', 'rejected', 'approved'))` diventa
`CHECK (status IN ('under_compilation', 'under_review', 'rejected', 'approved'))`.

- [ ] **Step 7: Esegui l'`ALTER` sul database locale**

```bash
docker exec php-forestas php artisan tinker --execute="
\DB::statement('ALTER TABLE trail_applications DROP CONSTRAINT trail_applications_status_check');
\DB::statement(\"ALTER TABLE trail_applications ADD CONSTRAINT trail_applications_status_check CHECK (status IN ('under_compilation','under_review','rejected','approved'))\");
"
```

**La stessa coppia di istruzioni va eseguita a mano su dev.forestas e su UAT al rilascio**: quella
migration è già stata eseguita ovunque, quindi modificare il file non cambia il vincolo esistente.
Senza, la prima scrittura con `under_compilation` risponde `SQLSTATE[23514]`.

- [ ] **Step 8: Lancia il test e verifica che passi**

Run: `composer test -- --filter=TrailRegistryEnums`
Atteso: PASS.

- [ ] **Step 9: Commit**

```bash
git add src/TrailRegistry/Enums/TrailApplicationStatus.php src/TrailRegistry/Models/TrailApplication.php database/migrations/trail_registry/ resources/lang/ tests/Unit/TrailRegistry/TrailRegistryEnumsTest.php
git commit -m "feat(oc:8960): stato under_compilation e label tradotta sull'enum"
```

---

### Task 2: Le sei collection degli allegati

**Files:**
- Modify: `src/TrailRegistry/Models/TrailApplication.php` (costanti e `registerMediaCollections()`)
- Test: `tests/Feature/TrailRegistry/TrailApplicationMediaTest.php` (nuovo)

**Interfaces:**
- Consumes: niente dal Task 1.
- Produces: le costanti `REPORT_COLLECTION = 'report'`, `TRAIL_DATA_SHEET_COLLECTION =
  'trail_data_sheet'`, `PHOTOS_COLLECTION = 'photos'`, `AUTHORIZATIONS_COLLECTION =
  'authorizations'`, `MAINTENANCE_PLAN_COLLECTION = 'maintenance_plan'`, accanto a
  `ORIGINAL_GEOMETRY_COLLECTION` che esiste già.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('registra le sei collection dell istanza', function () {
    $application = TrailApplication::factory()->create();
    $names = collect($application->getRegisteredMediaCollections())->pluck('name');

    expect($names)->toContain('report', 'original_geometry', 'trail_data_sheet', 'photos', 'authorizations', 'maintenance_plan');
});

it('tiene un solo file nelle collection documentali', function () {
    $application = TrailApplication::factory()->create();
    $documentali = ['report', 'original_geometry', 'trail_data_sheet', 'authorizations', 'maintenance_plan'];

    foreach ($application->getRegisteredMediaCollections() as $collection) {
        expect($collection->singleFile)->toBe(in_array($collection->name, $documentali, true));
    }
});

it('riconosce una foto reinviata e non la duplica', function () {
    $application = TrailApplication::factory()->create();
    $file = UploadedFile::fake()->image('vetta.jpg');
    $hashes = UgcMediaHashService::make();
    $hash = $hashes->hashOfFile($file->getRealPath());

    $application->addMedia($file->getRealPath())->preservingOriginal()
        ->withCustomProperties([UgcMediaHashService::HASH_PROPERTY => $hash])
        ->toMediaCollection('photos');

    expect($hashes->findByContent($application->fresh(), $hash, 'photos'))->not->toBeNull();
});
```

- [ ] **Step 2: Lancia i test e verifica che falliscano**

Run: `composer test -- --filter=TrailApplicationMedia`
Atteso: FAIL, le collection non esistono.

- [ ] **Step 3: Registra le collection**

In `registerMediaCollections()`, dopo `parent::registerMediaCollections()` e la riga di
`original_geometry`, aggiungi le cinque collection. `singleFile()` su `report`,
`trail_data_sheet`, `authorizations`, `maintenance_plan`; **non** su `photos`, che ne contiene fino
a cinque. Il docblock dice perché: in un protocollo con retry, un reinvio deve sostituire il file e
non affiancarlo, mentre la galleria si difende deduplicando sul contenuto con
`UgcMediaHashService`.

- [ ] **Step 4: Lancia i test e verifica che passino**

Run: `composer test -- --filter=TrailApplicationMedia`
Atteso: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/TrailRegistry/Models/TrailApplication.php tests/Feature/TrailRegistry/TrailApplicationMediaTest.php
git commit -m "feat(oc:8960): sei collection media per gli allegati dell'istanza"
```

---

### Task 3: `api_data` e l'elenco degli obbligatori

**Files:**
- Modify: `src/TrailRegistry/Models/TrailApplication.php`
- Test: `tests/Feature/TrailRegistry/TrailApplicationApiDataTest.php` (nuovo)

**Interfaces:**
- Produces: `TrailApplication::API_DATA_KEY = 'api_data'`;
  `TrailApplication::requiredApiFields(): array` — i 27 identifier dichiarati obbligatori;
  `TrailApplication::requiredAttachments(): array` — le collection obbligatorie;
  `TrailApplication::missingForConfirmation(): array` — cosa manca, in forma strutturata, con le
  chiavi `fields` e `attachments`;
  `TrailApplication::writeApiData(array $payload): void` — scrive il payload per intero.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('scrive il payload intero, anche le chiavi che non conosciamo', function () {
    $application = TrailApplication::factory()->create(['properties' => []]);

    $application->writeApiData(['from' => 'Baunei', 'campo_che_non_conosciamo' => 'valore']);

    $properties = $application->fresh()->properties;
    expect($properties['api_data']['from'])->toBe('Baunei')
        ->and($properties['api_data']['campo_che_non_conosciamo'])->toBe('valore');
});

it('scrive api_data anche quando properties e un array vuoto', function () {
    $application = TrailApplication::factory()->create(['properties' => []]);

    $application->writeApiData(['from' => 'Baunei']);

    expect($application->fresh()->properties)->toHaveKey('api_data');
});

it('non tocca manual_data ne dem_data', function () {
    $application = TrailApplication::factory()->create([
        'properties' => ['manual_data' => ['distance' => 3.8], 'dem_data' => ['ascent' => 475]],
    ]);

    $application->writeApiData(['distance' => 99.9]);

    $properties = $application->fresh()->properties;
    expect($properties['manual_data']['distance'])->toBe(3.8)
        ->and($properties['dem_data']['ascent'])->toBe(475);
});

it('elenca cosa manca per la conferma', function () {
    $application = TrailApplication::factory()->create();
    $application->writeApiData(['from' => 'Baunei']);

    $mancanti = $application->missingForConfirmation();

    expect($mancanti['fields'])->toContain('to', 'distance', 'soggetto_gestore')
        ->and($mancanti['attachments'])->toContain('report', 'maintenance_plan')
        ->and($mancanti['attachments'])->not->toContain('authorizations');
});
```

- [ ] **Step 2: Lancia i test e verifica che falliscano**

Run: `composer test -- --filter=TrailApplicationApiData`
Atteso: FAIL, i metodi non esistono.

- [ ] **Step 3: Implementa i quattro metodi**

`requiredApiFields()` restituisce i 27 identifier della tabella «I 27 dati dichiarati» dell'overview
che la colonna Validazione segna obbligatori — gli altri restano fuori. I cinque condizionati a una
select (`soggetto_gestore_other`, `soggetto_rilevatore_other`, `soggetto_rilevatore_email`,
`soggetto_manutentore_other`, `soggetto_manutentore_cf`) non stanno in questo elenco: la loro
obbligatorietà dipende dal valore di un altro campo e la governa la validazione dell'endpoint, che
è fuori scope.

`requiredAttachments()` restituisce le cinque collection obbligatorie: `report`,
`original_geometry`, `trail_data_sheet`, `photos`, `maintenance_plan`. `authorizations` è
facoltativa.

`missingForConfirmation()` confronta `api_data` con `requiredApiFields()` e i media presenti con
`requiredAttachments()`, e restituisce `['fields' => [...], 'attachments' => [...]]`.

`writeApiData()` scrive con SQL, non con Eloquent, partendo da
`CASE WHEN jsonb_typeof(properties) = 'object' THEN properties ELSE '{}'::jsonb END` e usando
`jsonb_set` sulla chiave `{api_data}`: la factory crea `properties = []` e su un array `||`
concatena invece di unire, facendo sparire la chiave senza errore (oc:8571).

- [ ] **Step 4: Lancia i test e verifica che passino**

Run: `composer test -- --filter=TrailApplicationApiData`
Atteso: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/TrailRegistry/Models/TrailApplication.php tests/Feature/TrailRegistry/TrailApplicationApiDataTest.php
git commit -m "feat(oc:8960): api_data, elenco degli obbligatori e scrittura jsonb sicura"
```

---

### Task 4: I dati dichiarati nel dettaglio Nova

**Files:**
- Modify: `src/TrailRegistry/Nova/TrailApplication.php` (`fields()`)
- Test: `tests/Feature/TrailRegistry/TrailApplicationApiDataFieldsTest.php` (nuovo)

**Interfaces:**
- Consumes: `TrailApplicationModel::API_DATA_KEY` dal Task 3.
- Produces: un Tab «Dati dichiarati» nel dettaglio della Resource.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('mostra i dati dichiarati solo nel dettaglio', function () {
    $resource = new TrailApplication(TrailApplicationModel::factory()->create());
    $attributi = collect($resource->fields(app(NovaRequest::class)))
        ->flatMap(fn ($f) => method_exists($f, 'data') ? collect($f->data)->pluck('attribute') : [$f->attribute])
        ->filter()->values();

    expect($attributi)->toContain('properties->api_data->from');
});

it('non mette i dati dichiarati nel form di modifica', function () {
    $resource = new TrailApplication(TrailApplicationModel::factory()->create());
    $attributi = collect($resource->fieldsForUpdate(app(NovaRequest::class)))->pluck('attribute');

    expect($attributi->filter(fn ($a) => str_contains((string) $a, 'api_data')))->toBeEmpty();
});

it('apre il dettaglio di un istanza senza api_data', function () {
    $application = TrailApplicationModel::factory()->create(['properties' => []]);
    $resource = new TrailApplication($application);

    expect(fn () => $resource->fields(app(NovaRequest::class)))->not->toThrow(Throwable::class);
});
```

- [ ] **Step 2: Lancia i test e verifica che falliscano**

Run: `composer test -- --filter=TrailApplicationApiDataFields`
Atteso: FAIL, nessun campo su `api_data`.

- [ ] **Step 3: Aggiungi i Field al Tab**

In `fields()`, accanto al Tab «DEM» esistente, un Tab «Dati dichiarati» con un Field per ciascuno
dei 27 identifier, tutti su `properties->api_data->…` e tutti `onlyOnDetail()` — fuori dai form per
costruzione, non per un attributo di sola lettura. Le etichette sono quelle della colonna
«Denominazione nel format» dell'overview; i quattro soggetti usano le label di
`EcTrackRuoloFilter`: Gestore, Rilevatore, Manutentore, Custode.

`fieldsForUpdate()` non si tocca: continua a filtrare per prefisso `properties->manual_data->`.

- [ ] **Step 4: Aggiungi le traduzioni delle etichette**

Le stringhe nuove passate a `__()` vanno in tutte e cinque le lingue di `resources/lang/`.

- [ ] **Step 5: Lancia i test e verifica che passino**

Run: `composer test -- --filter=TrailApplicationApiDataFields`
Atteso: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/TrailRegistry/Nova/TrailApplication.php resources/lang/ tests/Feature/TrailRegistry/TrailApplicationApiDataFieldsTest.php
git commit -m "feat(oc:8960): tab dei dati dichiarati nel dettaglio dell'istanza"
```

---

### Task 5: Lo stato tradotto in elenco, dettaglio e filtro

**Files:**
- Modify: `src/TrailRegistry/Nova/TrailApplication.php:120`
- Modify: `src/TrailRegistry/Nova/Filters/TrailApplicationStatusFilter.php`
- Test: `tests/Feature/TrailRegistry/TrailApplicationStatusLabelTest.php` (nuovo)

**Interfaces:**
- Consumes: `TrailApplicationStatus::label()` dal Task 1.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('mostra lo stato tradotto, non il valore grezzo', function () {
    $application = TrailApplicationModel::factory()->create(['status' => TrailApplicationStatus::UnderCompilation]);
    $resource = new TrailApplication($application);
    $campo = collect($resource->fields(app(NovaRequest::class)))
        ->first(fn ($f) => $f->name === __('Review status'));

    expect($campo->resolveForDisplay($application) ?? $campo->value)->not->toBe('under_compilation');
});

it('elenca gli stati tradotti nel filtro', function () {
    $options = (new TrailApplicationStatusFilter)->options(app(NovaRequest::class));

    expect(array_keys($options))->not->toContain('under_compilation')
        ->and(array_values($options))->toContain('under_compilation');
});
```

- [ ] **Step 2: Lancia i test e verifica che falliscano**

Run: `composer test -- --filter=TrailApplicationStatusLabel`
Atteso: FAIL, entrambi mostrano il valore grezzo.

- [ ] **Step 3: Usa `label()` nei due punti**

Alla riga 120 della Resource, `fn () => $this->status->value` diventa `fn () => $this->status->label()`.
In `TrailApplicationStatusFilter::options()`, la `mapWithKeys` diventa
`[$status->label() => $status->value]`: la chiave è ciò che l'operatore legge, il valore è ciò su
cui si filtra.

- [ ] **Step 4: Lancia i test e verifica che passino**

Run: `composer test -- --filter=TrailApplicationStatusLabel`
Atteso: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/TrailRegistry/Nova/ tests/Feature/TrailRegistry/TrailApplicationStatusLabelTest.php
git commit -m "feat(oc:8960): stato tradotto nell'elenco, nel dettaglio e nel filtro"
```

---

### Task 6: L'approvazione non porta fuori niente di nuovo

**Files:**
- Modify: `src/TrailRegistry/Nova/Actions/ApproveTrailApplication.php` (`createTrack()` riga 132,
  `copyMedia()` righe 204-215)
- Test: `tests/Feature/TrailRegistry/ApproveTrailApplicationTest.php` (esiste)
- Test: `tests/Feature/TrailRegistry/TrailRegistryAccessHttpTest.php` (esiste)

**Interfaces:**
- Consumes: `API_DATA_KEY` dal Task 3, le collection dal Task 2, lo stato dal Task 1.

- [ ] **Step 1: Scrivi i test che falliscono**

In `ApproveTrailApplicationTest.php`:

```php
it('non copia api_data sul sentiero', function () {
    $application = istanza();
    $application->writeApiData(['soggetto_rilevatore_email' => 'mario@example.org']);

    (new ApproveTrailApplication)->handle(new ActionFields(collect(), collect()), collect([$application->fresh()]));

    $track = EcTrack::query()->latest('id')->first();
    expect($track->properties)->not->toHaveKey('api_data');
});

it('copia sul sentiero solo la traccia originale', function () {
    $application = istanza();
    $application->addMedia(UploadedFile::fake()->create('relazione.pdf')->getRealPath())
        ->preservingOriginal()->toMediaCollection('report');

    (new ApproveTrailApplication)->handle(new ActionFields(collect(), collect()), collect([$application->fresh()]));

    $track = EcTrack::query()->latest('id')->first();
    expect($track->getMedia('report'))->toBeEmpty();
});
```

In `TrailRegistryAccessHttpTest.php`, usando gli helper `utenteHttp()` e `istanzaHttp()` già
presenti:

```php
it('non lascia modificare un istanza in compilazione', function () {
    $istanza = istanzaHttp(TrailApplicationStatus::UnderCompilation);

    actingAs(utenteHttp('Administrator'))
        ->putJson("/nova-api/trail-applications/{$istanza->id}", ['name' => 'altro'])
        ->assertForbidden();
});

it('non lascia approvare ne respingere un istanza in compilazione', function () {
    $istanza = istanzaHttp(TrailApplicationStatus::UnderCompilation);

    foreach ([ApproveTrailApplication::class, RejectTrailApplication::class] as $action) {
        (new $action)->handle(new ActionFields(collect(), collect()), collect([$istanza]));
    }

    expect($istanza->fresh()->status)->toBe(TrailApplicationStatus::UnderCompilation);
});

it('apre l elenco e il dettaglio di un istanza in compilazione', function () {
    $istanza = istanzaHttp(TrailApplicationStatus::UnderCompilation);

    actingAs(utenteHttp('Administrator'))
        ->getJson("/nova-api/trail-applications/{$istanza->id}")
        ->assertOk();
});
```

- [ ] **Step 2: Lancia i test e verifica quali falliscono**

Run: `composer test -- --filter="ApproveTrailApplication|TrailRegistryAccessHttp"`
Atteso: falliscono i due su `api_data` e sugli allegati. I tre sulle autorizzazioni **devono
passare già**: le whitelist negano `under_compilation` per costruzione. Se uno di essi fallisce,
fermati e segnalalo: significa che una whitelist non è dove la spec dice.

- [ ] **Step 3: Escludi `api_data` dalla copia**

In `createTrack()`, dopo `$properties = $application->properties ?? []`, togli la chiave
`api_data` prima di scriverla sul nuovo `EcTrack`.

- [ ] **Step 4: Restringi `copyMedia()` alla traccia**

`copyMedia()` itera oggi su tutti i media. Deve copiare solo quelli della collection
`ORIGINAL_GEOMETRY_COLLECTION`: relazione, tabella dati, autorizzazioni, piano di manutenzione e
foto sono documenti dell'istruttoria e restano sull'istanza. Nel docblock, il motivo: `Media::copy()`
duplica il file sul disco, quindi una copia fatta per errore non si annulla scollegandola.

- [ ] **Step 5: Lancia i test e verifica che passino**

Run: `composer test -- --filter="ApproveTrailApplication|TrailRegistryAccessHttp"`
Atteso: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/TrailRegistry/Nova/Actions/ApproveTrailApplication.php tests/Feature/TrailRegistry/
git commit -m "feat(oc:8960): l'approvazione non porta api_data ne gli allegati sul sentiero"
```

---

### Task 7: Verifica finale

- [ ] **Step 1: Lancia l'intera suite del dominio**

Run: `composer test -- --filter=TrailRegistry`
Atteso: PASS, nessun test preesistente rotto.

- [ ] **Step 2: PHPStan**

Run: `composer analyse`
Atteso: nessun errore nuovo sui file toccati. Gli errori preesistenti su altri file non bloccano.

- [ ] **Step 3: Formattazione dei soli file toccati**

Run: `vendor/bin/pint src/TrailRegistry/ tests/Feature/TrailRegistry/ tests/Unit/TrailRegistry/`

Mai `composer format` senza scope: riformatta l'intero repo e inquina il diff della feature.

- [ ] **Step 4: Guarda il diff e consegna al dev**

Run: `git diff --stat` e `git diff`
I commit e la PR li fa il dev.
