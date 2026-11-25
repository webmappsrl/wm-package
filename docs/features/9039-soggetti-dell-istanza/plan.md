> Ticket: oc:9039
> Stato: seguito senza divergenze sui task; dopo la review il lavoro si è allargato a [R8](overview.md#r8), ai tematismi, ai limiti di lunghezza e allo schema OpenAPI, che il piano non prevedeva — le voci sono in notes.md

# Soggetti dell'istanza come liste di persone — piano (wm-package)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** sostituire i tredici campi delle categorie e dei comuni con quattro liste di soggetti,
una per ruolo, ciascuna di oggetti `{name, email, subject}`.

**Architecture:** tutto passa dallo schema. `ApplicationSchema::fields()` dichiara i quattro campi
di tipo `json`, `itemRules()` dichiara le regole annidate `campo.*.chiave`, e il resto del dominio
— decodifica del payload, completezza prima della conferma, tab «API data» di Nova, elenco dei
campi obbligatori — li legge da lì senza modifiche. Nessuna Rule object: `required_if` con wildcard
risolve l'indice fratello, verificato sul validator reale.

**Tech Stack:** Laravel 12, PHP >8.1, Pest, PHPStan livello 5.

**Spec:** [overview.md](overview.md) — requisiti [R1](overview.md#r1)…[R7](overview.md#r7).
La parte dello shard ha un piano suo:
[forestas/docs/features/9039-soggetti-dell-istanza/plan.md](../../../../docs/features/9039-soggetti-dell-istanza/plan.md).

## Global Constraints

- **Repo: `wm-package`.** Ogni file di questo piano sta sotto
  `/Users/bongiu/Documents/geobox2/forestas/wm-package`.
- **Identificatori in inglese**, commenti e documentazione in italiano.
- **PHP minimo `>8.1`:** mai `const` dentro un trait.
- **Nessun commit durante l'esecuzione.** I blocchi `git commit` dei task sono istruzioni
  testuali: il primo commit lo fa il dev alla chiusura.
- **I test del package girano sul database `wm_package`**, non su quello dello shard
  (`wm-package/phpunit.xml.dist`). Prima di lanciare la suite verifica che quel database esista;
  se l'isolamento non è verificabile, fermati e chiedi.
- **Comando dei test:**
  `docker exec -i php-forestas bash -c "cd /var/www/html/wm-package && vendor/bin/pest --filter='<nome>'"`
- **I nomi sono decisi e non si cambiano:** `managing_subjects`, `surveying_subjects`,
  `maintaining_subjects`, `keeping_subjects`; chiavi `name`, `email`, `subject`; valori di
  `subject` `organization` e `person`.

## Review Focus

Gli input che la spec implica e che nessun task esercita di suo. Ciascuno ha il suo test, nel task
che possiede il codice:

1. **Lista di stringhe al posto di oggetti** (`["Comune di Oliena"]`) — la forma che ha oggi il
   campo 54, cioè la migrazione sbagliata più probabile. Deve dare `422`. → Task 3.
2. **Oggetto singolo al posto della lista** — deve dare `422`. → Task 3.
3. **`keeping_subjects` assente del tutto** — deve dare `201`, non `422`. → Task 3.
4. **Lista presente ma vuota per un ruolo obbligatorio** — `422` alla creazione e istanza
   incompleta alla conferma. → Task 2 e Task 3.
5. **`email` assente su un `organization`** — deve dare `422` citando ruolo e posizione; su una
   `person` deve passare. → Task 3.

---

### Task 1: Lo schema

**Files:**
- Modify: `src/TrailRegistry/Schema/ApplicationSchema.php` — `fields()` (righe 97-123),
  `itemRules()` (135-143), `version()` (50)
- Modify: `resources/lang/it.json` (righe 648-660), `resources/lang/en.json`
- Test: `tests/Unit/TrailRegistry/ApplicationSchemaTest.php`

**Interfaces:**
- Produces: i quattro campi dello schema, su cui si appoggiano tutti i task successivi.
  `ApplicationSchema::version()` restituisce `'3'`. `requiredFieldNames()` contiene
  `managing_subjects`, `surveying_subjects`, `maintaining_subjects` e **non** `keeping_subjects`.

Realizza [R1](overview.md#r1), [R2](overview.md#r2), [R3](overview.md#r3),
[R4](overview.md#r4).

- [ ] **Step 1: Scrivi i test che falliscono** in `ApplicationSchemaTest.php`

```php
it('dichiara le quattro liste di soggetti e non più le select di categoria', function () {
    $names = array_column(app(ApplicationSchema::class)->toArray(), 'name');

    expect($names)->toContain('managing_subjects', 'surveying_subjects', 'maintaining_subjects', 'keeping_subjects')
        ->and($names)->not->toContain(
            'proposed_managing_municipalities', 'municipality_email', 'municipality_email_alt',
            'soggetto_gestore', 'soggetto_gestore_other',
            'soggetto_rilevatore', 'soggetto_rilevatore_other', 'soggetto_rilevatore_email',
            'soggetto_manutentore', 'soggetto_manutentore_other', 'soggetto_manutentore_cf',
            'soggetto_custode_presente', 'soggetto_custode',
        );
});

it('chiede almeno un soggetto per i tre ruoli obbligatori e nessuno per il custode', function () {
    $required = app(ApplicationSchema::class)->requiredFieldNames();

    expect($required)->toContain('managing_subjects', 'surveying_subjects', 'maintaining_subjects')
        ->and($required)->not->toContain('keeping_subjects');
});

it('condiziona la e-mail del soggetto alla sua natura', function () {
    $rules = app(ApplicationSchema::class)->rules();

    expect($rules['managing_subjects.*.email'])
        ->toContain('nullable', 'email', 'required_if:managing_subjects.*.subject,organization')
        ->and($rules['managing_subjects.*.subject'])->toContain('required', 'in:organization,person');
});

it('alza la versione dello schema a 3', function () {
    expect(app(ApplicationSchema::class)->version())->toBe('3');
});
```

- [ ] **Step 2: Lancia i test e verifica che falliscano**

Comando: `… vendor/bin/pest --filter='ApplicationSchemaTest'`
Atteso: FAIL — i campi nuovi non esistono.

- [ ] **Step 3: Dichiara i quattro campi in `fields()`**

Al posto dei tredici campi vecchi, nell'ordine in cui stanno oggi (dopo `themes`, prima di
`last_survey_date`), quattro `ApplicationField` di tipo `json`:

```php
new ApplicationField('managing_subjects', 'json', __('Managing subjects'), null, true, ['required', 'array', 'min:1'], null),    // 47
new ApplicationField('surveying_subjects', 'json', __('Surveying subjects'), null, true, ['required', 'array', 'min:1'], null),  // 49
new ApplicationField('maintaining_subjects', 'json', __('Maintaining subjects'), null, true, ['required', 'array', 'min:1'], null), // 53
new ApplicationField('keeping_subjects', 'json', __('Keeping subjects'), null, false, ['nullable', 'array'], null),              // 58
```

Il commento con il numero del campo del modulo resta: è quello che [R7](overview.md#r7) chiede di
tenere anche in documentazione.

- [ ] **Step 4: Dichiara le regole annidate in `itemRules()`**

Per ciascuno dei quattro campi, tre voci. `activities.*` resta dov'è e non si tocca; le altre tre
voci di oggi spariscono con i loro campi. Per `managing_subjects`:

```php
'managing_subjects.*'         => ['array'],
'managing_subjects.*.name'    => ['required', 'string'],
'managing_subjects.*.email'   => ['nullable', 'required_if:managing_subjects.*.subject,organization', 'email'],
'managing_subjects.*.subject' => ['required', 'in:organization,person'],
```

`'<campo>.*' => ['array']` è ciò che rifiuta una lista di stringhe con un messaggio leggibile
invece che con tre errori su chiavi inesistenti.

- [ ] **Step 5: Alza `version()` a `'3'`**

- [ ] **Step 6: Aggiorna le `label`** in `resources/lang/it.json` e `en.json`

Via le otto voci dei campi spariti (`Manager`, `Other manager`, `Surveyor`, `Other surveyor`,
`Surveyor email`, `Maintainer`, `Other maintainer`, `Other maintainer tax code`, `Keeper exists`,
`Keeper`, `Proposed managing municipalities`, `Municipality email`,
`Alternative municipality email`), dentro le quattro nuove:

```
"Managing subjects": "Soggetti gestori",
"Surveying subjects": "Soggetti rilevatori",
"Maintaining subjects": "Soggetti manutentori",
"Keeping subjects": "Soggetti custodi",
```

`de.json`, `es.json` e `fr.json` non hanno queste chiavi: non vanno toccati.

- [ ] **Step 7: Lancia i test e verifica che passino**

- [ ] **Step 8: Commit** *(istruzione testuale, non eseguire)*

```bash
git add src/TrailRegistry/Schema/ApplicationSchema.php resources/lang/it.json resources/lang/en.json tests/Unit/TrailRegistry/ApplicationSchemaTest.php
git commit -m "feat(oc:9039): quattro liste di soggetti nello schema dell'istanza"
```

---

### Task 2: Completezza prima della conferma, modello e factory

**Files:**
- Modify: `src/TrailRegistry/Models/TrailApplication.php` — docblock di `requiredApiFields()`
  (righe 237-254)
- Modify: `src/TrailRegistry/Database/Factories/TrailApplicationFactory.php` — `complete()`
  (riga 41)
- Test: `tests/Feature/TrailRegistry/TrailApplicationApiDataTest.php`

**Interfaces:**
- Consumes: i campi dichiarati nel Task 1.
- Produces: `TrailApplicationFactory::complete()` scrive un `api_data` **valido secondo lo
  schema**, con una lista di un soggetto per ciascuno dei tre ruoli obbligatori. Tutti i test che
  usano `complete()` si appoggiano a questo.

Realizza [R6](overview.md#r6).

- [ ] **Step 1: Scrivi i test che falliscono**

```php
it('considera incompleta un istanza a cui manca un ruolo obbligatorio', function () {
    $application = TrailApplication::factory()->create(['status' => TrailApplicationStatus::UnderCompilation]);
    $application->writeApiData(['managing_subjects' => [], 'surveying_subjects' => [['name' => 'X', 'email' => null, 'subject' => 'person']]]);

    expect($application->missingForConfirmation()['fields'])
        ->toContain('managing_subjects', 'maintaining_subjects')
        ->not->toContain('surveying_subjects', 'keeping_subjects');
});

it('la factory completa produce un payload che lo schema accetta', function () {
    $application = TrailApplication::factory()->complete()->create();
    $validator = Validator::make((array) $application->api_data, app(ApplicationSchema::class)->rules());

    expect($validator->errors()->keys())->not->toContain(
        'managing_subjects', 'managing_subjects.0.name', 'managing_subjects.0.subject',
    );
});
```

- [ ] **Step 2: Lancia i test e verifica che falliscano**

Atteso: il secondo FAIL — `complete()` scrive la stringa `'valore'` dove serve una lista.

- [ ] **Step 3: Correggi `complete()`**

`array_fill_keys(requiredApiFields(), 'valore')` non va più bene per i campi lista. Dai a
`complete()` un valore per campo che rispetti il tipo dichiarato dallo schema: per un campo `json`
che è una lista di soggetti, una lista con un soggetto valido
(`[['name' => 'Ente di prova', 'email' => 'ente@example.it', 'subject' => 'organization']]`); per
gli altri, il `'valore'` di oggi.

- [ ] **Step 4: Riscrivi il docblock di `requiredApiFields()`**

L'elenco dei campi condizionati citati per nome — `soggetto_gestore_other`,
`soggetto_rilevatore_other`, `soggetto_rilevatore_email`, `soggetto_manutentore_other`,
`soggetto_manutentore_cf`, `soggetto_custode` — non esiste più. Al suo posto: il custode è
facoltativo e resta fuori dagli obbligatori, e la forma degli oggetti dentro le liste la governano
le regole dello schema, non questo elenco — una lista non vuota con dentro oggetti malformati
supera `missingForConfirmation()`.

- [ ] **Step 5: Lancia i test e verifica che passino**

- [ ] **Step 6: Commit** *(istruzione testuale)*

```bash
git add src/TrailRegistry/Models/TrailApplication.php src/TrailRegistry/Database/Factories/TrailApplicationFactory.php tests/Feature/TrailRegistry/TrailApplicationApiDataTest.php
git commit -m "feat(oc:9039): completezza e factory sulle liste di soggetti"
```

---

### Task 3: La validazione dell'endpoint di creazione

**Files:**
- Test: `tests/Feature/TrailRegistry/StoreApplicationRequestTest.php` (31 occorrenze dei campi
  vecchi da riscrivere)

**Interfaces:**
- Consumes: le regole dichiarate nel Task 1.
- Produces: nessuna interfaccia — è il task che pinna il comportamento esterno dell'API.

Realizza [R1](overview.md#r1), [R2](overview.md#r2), [R3](overview.md#r3),
[R5](overview.md#r5), più tutti e cinque i punti di **Review Focus**.

- [ ] **Step 1: Riscrivi il payload valido del file**

Il file costruisce il proprio payload: sostituisci i tredici campi vecchi con le quattro liste.
Questo è il payload di riferimento di tutto il file.

- [ ] **Step 2: Scrivi i test dei casi limite**

```php
it('rifiuta una lista di stringhe al posto di oggetti', function () {
    // È la forma che aveva il campo 54: la migrazione sbagliata più probabile.
    $response = postApplication(['managing_subjects' => ['Comune di Oliena']]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['managing_subjects.0']);
});

it('rifiuta un oggetto singolo al posto della lista', function () {
    $response = postApplication(['managing_subjects' => ['name' => 'X', 'email' => 'x@y.it', 'subject' => 'organization']]);

    $response->assertUnprocessable();
});

it('accetta un istanza senza alcun custode', function () {
    $response = postApplication(exceptKeys: ['keeping_subjects']);

    $response->assertCreated();
});

it('rifiuta un ruolo obbligatorio con la lista vuota', function () {
    $response = postApplication(['managing_subjects' => []]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['managing_subjects']);
});

it('chiede la e-mail a un ente e non a una persona', function () {
    $response = postApplication(['managing_subjects' => [
        ['name' => 'Mario Rossi', 'email' => null, 'subject' => 'person'],
        ['name' => 'Comune di Oliena', 'email' => null, 'subject' => 'organization'],
    ]]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['managing_subjects.1.email'])
        ->assertJsonMissingValidationErrors(['managing_subjects.0.email']);
});

it('dice nell errore quale ruolo e quale posizione, contando da zero', function () {
    // Il comportamento viene dall'espansione del wildcard, non da attributes():
    // il test guarda il TESTO, perché una voce in validation.attributes lo romperebbe
    // senza cambiare la chiave.
    $response = postApplication(['surveying_subjects' => [
        ['name' => 'A', 'email' => 'a@b.it', 'subject' => 'organization'],
        ['name' => 'B', 'email' => 'non-una-email', 'subject' => 'organization'],
    ]]);

    expect($response->json('errors.surveying_subjects.1.email.0'))
        ->toContain('surveying_subjects.1.email');
});
```

- [ ] **Step 3: Lancia i test**

Comando: `… vendor/bin/pest --filter='StoreApplicationRequestTest'`
Atteso: PASS senza toccare codice di produzione — lo schema del Task 1 li copre già. Se uno
fallisce, il difetto è nelle regole del Task 1 e si corregge lì.

- [ ] **Step 4: Commit** *(istruzione testuale)*

```bash
git add tests/Feature/TrailRegistry/StoreApplicationRequestTest.php
git commit -m "feat(oc:9039): i test della validazione sulle liste di soggetti"
```

---

### Task 4: Gli altri test del package

**Files:**
- Test: `tests/Feature/TrailRegistry/StoreApplicationTest.php` (8 occorrenze)
- Test: `tests/Feature/TrailRegistry/TrailApplicationApiDataFieldsTest.php` (2)
- Test: `tests/Feature/TrailRegistry/ApproveTrailApplicationTest.php` (3)
- Test: `tests/Feature/TrailRegistry/ShowApplicationEndpointTest.php` (2)

**Interfaces:**
- Consumes: il payload valido del Task 3 e la factory del Task 2.

- [ ] **Step 1: Sostituisci i campi vecchi in ciascun file**

Ogni file costruisce il proprio payload, a volte come array e a volte come stringa JSON: tieni la
forma che il file già usa e cambia solo i campi. Dove un test verificava un campo sparito
(`municipality_email`, `soggetto_manutentore_cf`), scegli un campo che resta e che prova la stessa
cosa.

- [ ] **Step 2: Lancia la suite del dominio**

Comando: `… vendor/bin/pest --filter='TrailRegistry'`
Atteso: PASS, nessun riferimento ai tredici campi rimasto.

- [ ] **Step 3: Verifica che non resti nessuna occorrenza**

Comando: `grep -rn "soggetto_\|municipality_email\|proposed_managing_municipalities" src/ tests/ resources/lang/`
Atteso: nessun risultato.

- [ ] **Step 4: Commit** *(istruzione testuale)*

```bash
git add tests/Feature/TrailRegistry/
git commit -m "feat(oc:9039): i test del dominio sulle liste di soggetti"
```

---

### Task 5: La documentazione del package

**Files:**
- Modify: `src/Http/Controllers/Api/TrailRegistry/StoreApplicationController.php` — gli attributi
  `#[BodyParameter]` (righe 163-176) e l'esempio `curl` del docblock (118-132)

Realizza [R7](overview.md#r7).

- [ ] **Step 1: Sostituisci i tredici `#[BodyParameter]` con quattro**

Ciascuno apre con il numero del campo del modulo da cui viene, come fanno tutti gli altri, e dice
che la forma è cambiata. Per il gestore:

```php
#[BodyParameter('managing_subjects', description: 'Campo 47 del format — *Soggetto gestore*, nella forma nuova: **lista di soggetti**, almeno uno. Ogni soggetto ha `name` (denominazione), `email` e `subject`, che vale `organization` (ente) o `person` (persona fisica). La `email` è obbligatoria e deve essere valida per un `organization`; per una `person` può mancare o valere `null`. Se un soggetto è rifiutato, l\'errore indica ruolo e posizione nella lista, **contata da zero**: `managing_subjects.0.email`. Stringa JSON o forma nativa dei form.', example: [['name' => 'Comune di Oliena', 'email' => 'protocollo@comune.example.it', 'subject' => 'organization']])]
```

Gli altri tre sulla stessa falsariga: 49 rilevatore, 53 manutentore, 58 custode — quest'ultimo
dicendo che è **facoltativo**, cioè può essere una lista vuota o non essere passato affatto.

- [ ] **Step 2: Aggiorna l'esempio `curl` del docblock**

Via le righe `-F "soggetto_gestore=…"`, `-F "soggetto_rilevatore=…"`,
`-F "soggetto_manutentore=…"`, `-F "soggetto_custode_presente=false"`,
`-F 'proposed_managing_municipalities=…'`, `-F "municipality_email=…"`. Dentro le tre liste
obbligatorie come stringa JSON, sul modello della riga di `activities`.

- [ ] **Step 3: Verifica che la pagina si generi**

Comando: `docker exec -i php-forestas bash -c "cd /var/www/html/forestas && php artisan scramble:export --api=sus"` (o l'equivalente che il progetto usa)
Atteso: nessun errore.

- [ ] **Step 4: PHPStan**

Comando: `docker exec -i php-forestas bash -c "cd /var/www/html/wm-package && composer analyse"`
Atteso: nessun errore nuovo sui file toccati.

- [ ] **Step 5: Commit** *(istruzione testuale)*

```bash
git add src/Http/Controllers/Api/TrailRegistry/StoreApplicationController.php
git commit -m "feat(oc:9039): la documentazione dell'endpoint sulle liste di soggetti"
```
