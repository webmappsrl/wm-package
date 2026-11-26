> Ticket: oc:9061
> Stato: approvato il 2026-11-26

# Soggetti privati: il canale rifiuta i dati personali — piano (package)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** il canale rifiuta nome ed e-mail di un soggetto dichiarato persona fisica, e continua a
pretenderli da un ente.

**Architecture:** otto righe di regola in `ApplicationSchema::itemRules()` — due campi per quattro
ruoli — rese simmetriche: `nullable` + `required_if:…organization` + `prohibited_if:…person`. La
stessa fonte serve il canale della patch e la validazione alla conferma, quindi il messaggio del
rifiuto va messo dove entrambi lo vedono.

**Tech Stack:** Laravel 12, Pest, PHP 8.4 nel container `php-forestas`.

**Spec:** [overview.md](overview.md)

## Global Constraints

- Identificatori in inglese; commenti, docblock e messaggi di commit in italiano.
- Nessun commit durante l'esecuzione: il primo lo fa il dev.
- I test del package girano dentro il container, da `/var/www/html/forestas/wm-package`. I test di
  questo piano sono tutti `tests/Unit` e `tests/Feature` del package, che puntano a
  `DB_DATABASE=wm_package` da `phpunit.xml.dist`: prima di lanciarli verifica che quel file non
  sia stato scavalcato da un `phpunit.xml` locale.
- `version()` non si alza: questo canale non è in uso e la versione ha smesso di seguire i ticket
  ([R6](overview.md#r6)).

## Review Focus

- `{"name": null, "subject": "person"}` — deve passare: è la forma che la pagina presenta come
  equivalente all'omissione. Senza `nullable` fallirebbe su `string`. Test nel Task 1.
- `{"name": "", "subject": "person"}` — passa: `prohibited_if` considera vuota la stringa vuota,
  e una stringa vuota non è un dato personale. Test nel Task 1, perché è il modo in cui un form
  con campo obbligatorio aggira il problema.
- `{"subject": "person"}` su **tutti e quattro** i ruoli, non solo sul gestore: oggi nessun test
  fallirebbe se ne dimenticassi uno. Test nel Task 1.
- Un soggetto senza `subject`: `required_if` non scatta e il nome diventa facoltativo, quindi
  l'unico errore è su `subject`. È corretto, ma va fissato da un test perché non sia scambiato
  per un buco. Task 1.
- Il rifiuto alla conferma, che passa da `TrailRegistryService::invalidValues()` e non dalla
  Request: senza un messaggio condiviso uscirebbe la traduzione di default. Task 2.

---

### Task 1: Le otto regole dei soggetti

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-le-otto-regole-dei-soggetti)

Realizza [R1](overview.md#r1), [R2](overview.md#r2), [R3](overview.md#r3).

**Files:**
- Modify: `src/TrailRegistry/Schema/ApplicationSchema.php:166-181`
- Test: `tests/Unit/TrailRegistry/ApplicationSchemaTest.php`

**Interfaces:**
- Consumes: niente.
- Produces: `ApplicationSchema::rules()` con `*.name` e `*.email` condizionati a `*.subject` sui
  quattro ruoli. I task successivi assumono che la chiave di errore sia
  `<ruolo>.<indice>.<campo>`.

- [ ] **Step 1: Riscrivi il test che fissa la regola vecchia**

In `ApplicationSchemaTest.php`, il test `condiziona la e-mail del soggetto alla sua natura`
asserisce oggi `expect($rules['managing_subjects.*.name'])->toContain('required', 'string')`.
Sostituiscilo con un test che copre i quattro ruoli e i due campi:

```php
it('vieta i dati personali del soggetto privato e li pretende dall ente', function () {
    $rules = applicationSchema()->rules();

    foreach (['managing_subjects', 'surveying_subjects', 'maintaining_subjects', 'keeping_subjects'] as $role) {
        expect($rules["{$role}.*.name"])->toContain(
            'nullable', 'string', 'max:255',
            "required_if:{$role}.*.subject,organization",
            "prohibited_if:{$role}.*.subject,person",
        )->and($rules["{$role}.*.email"])->toContain(
            'nullable', 'email',
            "required_if:{$role}.*.subject,organization",
            "prohibited_if:{$role}.*.subject,person",
        )->and($rules["{$role}.*.subject"])->toContain('required', 'in:organization,person')
          ->and($rules["{$role}.*"])->toContain('array');
    }
});
```

- [ ] **Step 2: Lancia il test e verifica che fallisca**

Run: `docker exec php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter='vieta i dati personali'"`
Expected: FAIL — `prohibited_if` non è fra le regole.

- [ ] **Step 3: Cambia le otto righe in `itemRules()`**

Per ciascuno dei quattro ruoli, nome ed e-mail diventano simmetrici:

```php
'managing_subjects.*.name' => ['nullable', 'required_if:managing_subjects.*.subject,organization', 'prohibited_if:managing_subjects.*.subject,person', 'string', 'max:255'],
'managing_subjects.*.email' => ['nullable', 'required_if:managing_subjects.*.subject,organization', 'prohibited_if:managing_subjects.*.subject,person', 'email'],
```

`nullable` sul nome è la riga nuova rispetto a oggi, e serve perché `string` non venga applicata a
`null`. Aggiorna il commento sopra il blocco: oggi spiega la regola dell'e-mail, ora vale per
entrambi i campi e dice perché il divieto, non solo la facoltà.

- [ ] **Step 4: Lancia il test e verifica che passi**

Run: `docker exec php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter='vieta i dati personali'"`
Expected: PASS

- [ ] **Step 5: Scrivi i test sul comportamento, non sulle regole**

In `tests/Feature/TrailRegistry/PatchApplicationRequestTest.php` (dove stanno già i test dei
soggetti), aggiungi i casi che le regole da sole non dimostrano. Uno per ruolo nel primo, perché
è il caso che oggi nessun test coprirebbe:

```php
it('accetta un privato senza nome ne e-mail su tutti e quattro i ruoli', ...);   // {"subject":"person"} → nessun errore sul ruolo
it('accetta un privato con nome null', ...);                                     // {"name":null,"subject":"person"} → nessun errore
it('accetta un privato con nome stringa vuota', ...);                            // {"name":"","subject":"person"} → nessun errore
it('rifiuta il nome di un privato, segnaposto compreso', ...);                   // {"name":"campo oscurato per motivi di privacy","subject":"person"} → errore su surveying_subjects.0.name
it('rifiuta la e-mail di un privato', ...);                                      // {"email":"mario@example.it","subject":"person"} → errore su .0.email
it('pretende nome ed e-mail dall ente', ...);                                    // {"subject":"organization"} → errori su .0.name e .0.email
it('segnala solo subject quando il soggetto non dichiara la propria natura', ...); // {"name":"X"} → errore solo su .0.subject
```

- [ ] **Step 6: Lancia i test dei soggetti e verifica che passino**

Run: `docker exec php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest tests/Feature/TrailRegistry tests/Unit/TrailRegistry"`
Expected: PASS. I test che mandavano soggetti completi continuano a passare: un ente con nome ed
e-mail è ancora valido.

---

### Task 2: Il messaggio che il rifiuto porta con sé

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-il-messaggio-che-il-rifiuto-porta-con-sé)

Realizza [R4](overview.md#r4).

**Files:**
- Modify: `src/TrailRegistry/Schema/ApplicationSchema.php` (nuovo metodo `validationMessages()`)
- Modify: `src/Http/Requests/TrailRegistry/PatchApplicationRequest.php:263-266`
- Modify: `src/TrailRegistry/TrailRegistryService.php:1437`
- Modify: `resources/lang/it.json`
- Test: `tests/Feature/TrailRegistry/PatchApplicationRequestTest.php`, `tests/Feature/TrailRegistry/TrailRegistryServiceTest.php`

**Interfaces:**
- Consumes: le regole del Task 1.
- Produces: `ApplicationSchema::validationMessages(): array<string, string>` — i messaggi custom
  dello schema, oggi `in` e `prohibited_if`.

- [ ] **Step 1: Scrivi i due test del messaggio**

Il primo sul canale, il secondo alla conferma: sono due percorsi di validazione diversi e il
secondo non passa dalla Request.

```php
it('spiega perche il dato di un privato non e ammesso', ...);
// patch con {"name":"Mario Rossi","subject":"person"} → il messaggio su surveying_subjects.0.name
// contiene «non si trasmettono al Catasto» e nomina il riferimento della pratica

it('spiega perche il dato di un privato non e ammesso anche alla conferma', ...);
// api_data salvato con un privato nominato → ApplicationIncompleteException, e il messaggio
// dell'errore su surveying_subjects.0.name è lo stesso del canale
```

- [ ] **Step 2: Lancia i due test e verifica che falliscano**

Run: `docker exec php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter='non e ammesso'"`
Expected: FAIL — esce la traduzione di default di `prohibited_if`.

- [ ] **Step 3: Aggiungi `validationMessages()` allo schema e usalo nei due percorsi**

`ApplicationSchema::validationMessages(): array` restituisce i messaggi custom: la voce `in` che
oggi vive in `PatchApplicationRequest::messages()`, spostata qui, e la voce `prohibited_if`. Il
testo passa da `__()` e la voce va in `resources/lang/it.json` del package — non in `lang/`, che
viene ignorato, e non in `resources/lang-php/`, che è per i file PHP di traduzione.

`PatchApplicationRequest::messages()` restituisce `app(ApplicationSchema::class)->validationMessages()`.
In `TrailRegistryService::invalidValues()` la `Validator::make($apiData, $rules)` di riga 1437
riceve i messaggi come terzo argomento.

Il testo: «Il nome e la e-mail di un soggetto `person` non si trasmettono al Catasto: ometti il
campo e recupera il dato dal SUS con il riferimento della pratica.»

- [ ] **Step 4: Lancia i due test e verifica che passino**

Run: `docker exec php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter='non e ammesso'"`
Expected: PASS

- [ ] **Step 5: Lancia i test del dominio e verifica che il messaggio di `in` non sia cambiato**

Run: `docker exec php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest tests/Feature/TrailRegistry tests/Unit/TrailRegistry"`
Expected: PASS — in particolare i test che asseriscono il messaggio dei valori fuori elenco, che
ora lo prendono dallo schema invece che dalla Request.

---

### Task 3: La versione dello schema smette di seguire i ticket

Realizza [R6](overview.md#r6).

**Files:**
- Modify: `src/TrailRegistry/Schema/ApplicationSchema.php:93-101`
- Test: `tests/Unit/TrailRegistry/ApplicationSchemaTest.php:192`

**Interfaces:**
- Consumes: niente.
- Produces: `version()` che restituisce `'1'`.

- [ ] **Step 1: Riscrivi il test che fissa la versione**

`alza la versione dello schema a 3` diventa:

```php
it('tiene ferma la versione dello schema finche il canale e in costruzione', function () {
    expect(applicationSchema()->version())->toBe('1');
});
```

- [ ] **Step 2: Lancia il test e verifica che fallisca**

Run: `docker exec php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest --filter='versione dello schema'"`
Expected: FAIL — restituisce `'3'`.

- [ ] **Step 3: Porta `version()` a `'1'` e riscrivi il docblock**

Il docblock dice oggi che si alza «quando cambia cio' che la consegna deve contenere o come si
valida». Va riscritto: si alza quando il contratto è in uso e serve distinguere un payload
accettato con regole diverse da uno nuovo — non a ogni cambiamento delle regole. Finché il canale
si costruisce resta ferma.

- [ ] **Step 4: Lancia i test dello schema e verifica che passino**

Run: `docker exec php-forestas bash -c "cd /var/www/html/forestas/wm-package && vendor/bin/pest tests/Unit/TrailRegistry/ApplicationSchemaTest.php"`
Expected: PASS

---

### Task 4: Le descrizioni dei campi del canale

Realizza [R5](overview.md#r5).

**Files:**
- Modify: `src/Http/Controllers/Api/TrailRegistry/PatchApplicationController.php:172-176`

**Interfaces:**
- Consumes: le regole del Task 1.
- Produces: gli `#[BodyParameter]` dei quattro ruoli, letti dallo shard per generare la pagina.

- [ ] **Step 1: Riscrivi la frase condizionata nei quattro attributi**

Ogni descrizione contiene oggi: «La `email` è obbligatoria e deve essere un indirizzo valido
quando `subject` è `organization`; quando è `person` può mancare o valere `null`». Diventa una
frase su entrambi i campi: per un `organization` nome ed e-mail sono obbligatori; per un `person`
non si mandano, e se arrivano valorizzati la risposta è `422`, segnaposto testuale compreso.

Negli `example:` dei quattro attributi, il soggetto `person` di `surveying_subjects` perde il
nome: `[['subject' => 'person']]`.

- [ ] **Step 2: Verifica che la pagina dello shard si generi e dica la cosa giusta**

Run: `docker exec php-forestas bash -c "cd /var/www/html/forestas && vendor/bin/pest tests/Feature/Sus/SusApiDocumentationTest.php"`
Expected: i test che leggono gli attributi passano. `pubblica due esempi identici, e che il canale
accetterebbe` **fallisce ancora**: dipende dalla prosa e dal modulo di prova, che sono il piano
dello shard. È il legame fra i due repo, e si chiude lì.
