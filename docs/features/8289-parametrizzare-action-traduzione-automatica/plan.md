> Ticket: oc:8289

# Piano di implementazione — Rendere parametrica l'action di traduzione automatica

Basato su `docs/features/8289-parametrizzare-action-traduzione-automatica/overview.md` (stesso repo). Scritto senza `superpowers:writing-plans` (non installata in questa sessione — `/plugin install superpowers@wm-marketplace` per averla nei prossimi ticket); struttura e livello di granularità equivalenti.

**Nota su `our-code-style`**: anche questa skill (`wm-skills:our-code-style`) non risulta ancora pubblicata nel plugin `wm-skills` in questa sessione — applicate le convenzioni osservabili nel codice esistente del package (PSR-12, `declare(strict_types=1)` assente negli altri file del package quindi omesso per coerenza, PHPDoc solo dove il nome non è già autoesplicativo).

Nessun commit o branch automatico: i comandi git indicati sono testo per l'utente, non azioni eseguite da Claude durante l'esecuzione (vedi `execution: implementation` in `wm-plan`).

## Branch

```
feature/oc-8289-rendere-parametrica-action-traduzione-automatica
```
Da creare in **entrambi** i repo coinvolti (`osm2cai2` principale + submodule `wm-package`), prima di modificare qualunque file (hard-gate `wm-plan`).

---

## Step 1 — `TranslateModelJob`: estrarre le regole hardcoded in una costante per-campo

**File:** `wm-package/src/Jobs/TranslateModelJob.php`

Prima di toccare la logica, isolare le due regole oggi annegate nell'unico `PROMPT` in due voci indipendenti, così lo step 5 (prompt dinamico) le può assemblare per campo:

```php
protected const FIELD_RULES = [
    'name' => <<<'RULE'
    Keep proper nouns (people, local place names, mountains, villages) in their original Italian form
    unless they have a widely recognized official equivalent (e.g. "Monte Bianco" -> "Mont Blanc" in French).
    If the value is a code, abbreviation, or alphanumeric identifier (e.g. "SI-C G09-B"), return it UNCHANGED.
    RULE,
    'description' => <<<'RULE'
    Translate freely, preserving the meaning and tone of the original text.
    RULE,
];

protected const DEFAULT_FIELD_RULE = <<<'RULE'
Translate freely, preserving the meaning and tone of the original text.
RULE;
```

`DEFAULT_FIELD_RULE` è identica alla regola di `description` — è il fallback per qualunque campo extra senza regola propria (incluso `not_accessible_message`, decisione confermata in overview).

Non toccare ancora `PROMPT`/`callOpenAI()` in questo step — solo introdurre le costanti.

**Test:** nessuno specifico per questo step (costanti pure, verificate indirettamente dagli step successivi).

---

## Step 2 — `TranslateModelJob`: costruttore con `$additionalFieldRules`

**File:** `wm-package/src/Jobs/TranslateModelJob.php`

```php
public function __construct(
    protected Model $model,
    array $locales = ['en', 'de', 'fr', 'es'],
    protected array $additionalFieldRules = []
) {
    $this->locales = $locales;
}
```

`$additionalFieldRules` è il dizionario `campo => regola` per i campi extra (es. `['not_accessible_message' => TranslateModelJob::DEFAULT_FIELD_RULE]`), passato dall'Action (step 8). Default `[]` → comportamento identico a oggi per qualunque dispatch che non lo passa.

Aggiungere un metodo helper privato per l'elenco campi effettivo di questa istanza:

```php
protected function fieldRules(): array
{
    return array_merge(self::FIELD_RULES, $this->additionalFieldRules);
}
```

Da qui in avanti, ogni metodo del job che oggi itera `['description', 'name']` deve iterare `array_keys($this->fieldRules())`.

---

## Step 3 — `TranslateModelJob`: generalizzare `buildItalianTexts()`

**File:** `wm-package/src/Jobs/TranslateModelJob.php`

Oggi ha due blocchi `if` distinti e non simmetrici per `description` e `name` (righe 110-137 attuali). Riscrivere come loop su `array_keys($this->fieldRules())`, con **un solo ramo speciale per `name`** (Spatie `getTranslation`), tutti gli altri campi (inclusi `description` e ogni campo extra) trattati nello stesso modo — path `properties->{campo}`:

```php
protected function buildItalianTexts(array $properties): array
{
    $texts = [];

    foreach (array_keys($this->fieldRules()) as $field) {
        if ($field === 'name') {
            $italianName = $this->readPropertiesLocale($properties, 'name', 'it');
            if (empty($italianName) && in_array('name', $this->model->translatable ?? [])) {
                $italianName = $this->model->getTranslation('name', 'it', false) ?: null;
            }
            if (! empty($italianName)) {
                $texts['name'] = $italianName;
            }
            continue;
        }

        $value = $this->readPropertiesLocale($properties, $field, 'it');
        if (! empty($value)) {
            $texts[$field] = $value;
        }
    }

    return $texts;
}

/**
 * Legge properties->{$field}->{$locale}, normalizzando il caso in cui il valore
 * sia ancora una stringa semplice (non ancora migrato al formato {locale: testo}).
 */
protected function readPropertiesLocale(array $properties, string $field, string $locale): ?string
{
    $value = $properties[$field] ?? null;
    if (is_string($value)) {
        $value = ['it' => $value];
    }

    return is_array($value) ? ($value[$locale] ?? null) : null;
}
```

**Non è un mirror 1:1 del vecchio metodo**: `readPropertiesLocale()` è una nuova funzione helper (necessaria per non duplicare la normalizzazione stringa→array in 4 punti diversi, come nel codice attuale). Riusarla anche negli step 4 e 6.

---

## Step 4 — `TranslateModelJob`: generalizzare `getMissingLocales()`

**File:** `wm-package/src/Jobs/TranslateModelJob.php`

Stessa logica di oggi (righe 142-178), ma iterando `$this->locales` × `array_keys($italianTexts)` in modo dati-driven invece di due blocchi `if ($field === 'description')` / `if ($field === 'name')`:

```php
protected function getMissingLocales(array $properties, array $italianTexts): array
{
    $missing = [];

    foreach ($this->locales as $locale) {
        foreach (array_keys($italianTexts) as $field) {
            if ($field === 'name') {
                $existingInProps = $this->readPropertiesLocale($properties, 'name', $locale);
                $existingInSpatie = in_array('name', $this->model->translatable ?? [])
                    ? ($this->model->getTranslation('name', $locale, false) ?: null)
                    : null;

                if (empty($existingInProps) || empty($existingInSpatie)) {
                    $missing[] = $locale;
                    break;
                }

                continue;
            }

            if (empty($this->readPropertiesLocale($properties, $field, $locale))) {
                $missing[] = $locale;
                break;
            }
        }
    }

    return array_values(array_unique($missing));
}
```

---

## Step 5 — `TranslateModelJob`: generalizzare `getFieldsMissingForLocale()` e `applyTranslations()`

**File:** `wm-package/src/Jobs/TranslateModelJob.php`

`getFieldsMissingForLocale()` (righe 183-209 attuali) diventa:

```php
protected function getFieldsMissingForLocale(array $properties, array $italianTexts, string $locale): array
{
    $fields = [];

    foreach ($italianTexts as $field => $italianValue) {
        if ($field === 'name') {
            $existingInProps = $this->readPropertiesLocale($properties, 'name', $locale);
            if (empty($existingInProps)) {
                $fields['name'] = $italianValue;
            }
            continue;
        }

        if (empty($this->readPropertiesLocale($properties, $field, $locale))) {
            $fields[$field] = $italianValue;
        }
    }

    return $fields;
}
```

`applyTranslations()` (righe 214-235 attuali) diventa:

```php
protected function applyTranslations(array $translations, string $locale, array &$properties): void
{
    foreach ($translations as $field => $translatedValue) {
        if ($this->looksLikeRefusal($translatedValue)) {
            continue;
        }

        $current = is_array($properties[$field] ?? null)
            ? $properties[$field]
            : ['it' => $properties[$field] ?? null];
        $current[$locale] = $translatedValue;
        $properties[$field] = $current;

        if ($field === 'name' && in_array('name', $this->model->translatable ?? [])) {
            $this->model->setTranslation('name', $locale, $translatedValue);
        }
    }
}
```

---

## Step 6 — `TranslateModelJob`: prompt dinamico per campo

**File:** `wm-package/src/Jobs/TranslateModelJob.php`

Sostituire la `PROMPT` const statica (interpolata solo con la lingua) con un metodo che assembla le regole solo per i campi effettivamente presenti in quella chiamata:

```php
protected function buildPrompt(array $fields, string $targetLanguage): string
{
    $fieldRules = $this->fieldRules();

    $rulesText = collect($fields)
        ->keys()
        ->map(fn ($field) => sprintf(
            "Rules for \"%s\":\n%s",
            $field,
            $fieldRules[$field] ?? self::DEFAULT_FIELD_RULE
        ))
        ->implode("\n\n");

    return <<<PROMPT
    You are a professional translator specializing in outdoor and hiking content.
    You will receive a JSON object where each key is a field name and the value is the Italian
    source text to translate into {$targetLanguage}.

    {$rulesText}

    Return ONLY a valid JSON object with the same keys and the translated values.
    No explanation, no extra keys.
    PROMPT;
}
```

In `callOpenAI()`, sostituire `sprintf(self::PROMPT, $targetLanguage)` con `$this->buildPrompt($fields, $targetLanguage)` (il metodo riceve già `$fields` come parametro).

---

## Step 7 — `TranslateModelAction`: costruttore con Resource FQCN + validazione + `confirmText`

**File:** `wm-package/src/Nova/Actions/TranslateModelAction.php`

```php
protected string $resourceClass;
protected array $additionalFieldRules;

public function __construct(
    string $resourceClass,
    array $additionalFieldRules = [],
    array $targetLocales = ['en', 'de', 'fr', 'es']
) {
    foreach (array_keys($additionalFieldRules) as $key) {
        if (in_array($key, ['name', 'description'], true)) {
            throw new \InvalidArgumentException(
                "additionalFieldRules non può contenere '{$key}': è già gestito come campo hardcoded."
            );
        }
    }

    $this->resourceClass = $resourceClass;
    $this->additionalFieldRules = $additionalFieldRules;
    $this->targetLocales = $targetLocales;

    $fieldNames = array_merge(['name', 'description'], array_keys($additionalFieldRules));
    $this->confirmText = __('Missing translations will be updated for the following fields: :fields', [
        'fields' => implode(', ', $fieldNames),
    ]);
}

public function name(): string
{
    return __('Translate :label Contents', [
        'label' => $this->resourceClass::singularLabel(),
    ]);
}
```

`$this->confirmText` è la property pubblica già dichiarata in `Laravel\Nova\Actions\Action` (default `'Are you sure you want to run this action?'`) — l'assegnazione nel costruttore la sovrascrive per questa istanza, senza toccare il default condiviso da altre action.

---

## Step 8 — `TranslateModelAction`: generalizzare `getMissingLocales()` e `handle()`

**File:** `wm-package/src/Nova/Actions/TranslateModelAction.php`

```php
protected function getMissingLocales(array $properties): array
{
    $missing = [];
    $fieldNames = array_merge(['name', 'description'], array_keys($this->additionalFieldRules));

    foreach ($fieldNames as $field) {
        $value = $properties[$field] ?? null;
        if (empty($value)) {
            continue;
        }
        if (is_string($value)) {
            $value = ['it' => $value];
        }
        if (! is_array($value) || empty($value['it'] ?? null)) {
            continue;
        }
        foreach ($this->targetLocales as $locale) {
            if (empty($value[$locale] ?? null)) {
                $missing[] = $locale;
            }
        }
    }

    return array_values(array_unique($missing));
}
```

In `handle()`, il dispatch passa il dizionario al job:

```php
TranslateModelJob::dispatch($model, $missingLocales, $this->additionalFieldRules);
```

`fields()` resta `return [];` — nessuna checkbox (deciso in overview).

---

## Step 9 — Aggiornare le 4 Nova Resource che istanziano l'action

**File:** `wm-package/src/Nova/EcTrack.php`
```php
new TranslateModelAction(EcTrack::class),
```

**File:** `wm-package/src/Nova/EcPoi.php`
```php
new TranslateModelAction(EcPoi::class),
```

**File:** `app/Nova/SiHikingRoute.php`
```php
new TranslateModelAction(SiHikingRoute::class, [
    'not_accessible_message' => TranslateModelJob::DEFAULT_FIELD_RULE,
]),
```

**File:** `app/Nova/SiMTBRoute.php`
```php
new TranslateModelAction(SiMTBRoute::class, [
    'not_accessible_message' => TranslateModelJob::DEFAULT_FIELD_RULE,
]),
```

Verificare l'import di `TranslateModelJob` in cima ai due file `app/Nova/*.php` (serve solo per referenziare la costante `DEFAULT_FIELD_RULE`, non per chiamare il job direttamente).

---

## Step 10 — Chiavi di traduzione it/en mancanti

**File:** `wm-package/resources/lang/it.json`, `wm-package/resources/lang/en.json`

Aggiungere le chiavi per le stringhe introdotte/modificate dall'action (verificare prima con grep se "Translate :label Contents" o le altre stringhe esistono già, per non duplicare):

```json
"Translate :label Contents": "Traduci contenuti di :label",
"Missing translations will be updated for the following fields: :fields": "Verranno aggiornate le traduzioni mancanti per i seguenti campi: :fields",
"No fields to translate (already translated or missing Italian source).": "...",
":dispatched translation jobs dispatched, :skipped models skipped.": "..."
```

(le ultime due righe: verificare se già presenti — sono le stringhe esistenti di `Action::message()`, non nuove, ma il debito preesistente le include comunque in questo giro).

---

## Step 11 — Test: `TranslateModelActionTest` (Feature)

**File:** `wm-package/tests/Feature/Nova/Actions/TranslateModelActionTest.php` (nuovo)

Casi da coprire:
- Costruttore senza `$additionalFieldRules` → `name()` ritorna "Translate {Label} Contents" con solo `name`/`description` nel `confirmText`
- Costruttore con `['not_accessible_message' => '...']` → `confirmText` include anche `not_accessible_message`
- Costruttore con chiave `name` o `description` in `$additionalFieldRules` → lancia `InvalidArgumentException`
- `handle()` su un modello con tutte le lingue già complete per tutti i campi abilitati → nessun job dispatchato, messaggio "skipped"
- `handle()` su un modello con `not_accessible_message` mancante in una lingua (altri campi completi) → `TranslateModelJob::dispatch` chiamato con il dizionario `['not_accessible_message' => ...]` e la lingua mancante corretta (usare `Bus::fake()` + `Bus::assertDispatched` con closure che verifica gli argomenti)

---

## Step 12 — Test: `TranslateModelJobTest` (Unit)

**File:** `wm-package/tests/Unit/Jobs/TranslateModelJobTest.php` (nuovo)

Pattern da `wm-package/tests/Unit/AnalyticsServiceTest.php` (`Http::fake()`, no chiamate reali):

- `handle()` con solo `name`/`description` mancanti (nessun `$additionalFieldRules`) → stesso comportamento di oggi (regressione)
- `handle()` con `not_accessible_message` passato in `$additionalFieldRules` e mancante in una lingua → `Http::fake()` intercetta la chiamata OpenAI, assert che il body della request contenga **solo** i campi effettivamente mancanti per quella lingua (non tutti i campi abilitati)
- Verificare che il prompt costruito (`buildPrompt()`, testabile se resa `protected` con `Reflection` o estraendo il body della request fake) contenga la regola generica per `not_accessible_message` e la regola specifica per `name`
- `applyTranslations()`: un campo extra (`not_accessible_message`) viene scritto in `properties->not_accessible_message->{locale}`, mai via Spatie `setTranslation`
- Risposta OpenAI con un valore che matcha `looksLikeRefusal()` → il campo non viene scritto, `saveQuietly()` chiamato solo se almeno un altro campo/lingua è stato aggiornato

---

## Step 13 — Verifica finale

```bash
docker exec -it php81-osm2cai2 php artisan test --filter=TranslateModel
./vendor/bin/pint --ansi wm-package/src/Nova/Actions/TranslateModelAction.php wm-package/src/Jobs/TranslateModelJob.php
```

Verificare manualmente in Nova (ambiente locale): il pulsante mostra "Translate Route Contents" su `SiHikingRoute`, "Translate EC Poi Contents" su `EcPoi`; il popup di conferma elenca i campi corretti per ciascuna risorsa.

---

## Commit suggeriti (testuali — nessun commit automatico)

```
refactor(oc:8289): extract per-field translation rules as constants in TranslateModelJob
refactor(oc:8289): make TranslateModelJob field list and prompt data-driven
feat(oc:8289): accept resource class and additional field rules in TranslateModelAction
feat(oc:8289): enable not_accessible_message translation on SiHikingRoute/SiMTBRoute
fix(oc:8289): add missing it/en translation keys for TranslateModelAction strings
test(oc:8289): add TranslateModelAction and TranslateModelJob test coverage
```
