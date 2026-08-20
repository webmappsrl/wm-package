> Ticket: oc:8349

# Supporto nativo ai campi Translatable nel componente Flexible — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **No auto commit/branch:** i comandi `git commit` elencati in ogni task sono istruzioni testuali per lo sviluppatore, non azioni da eseguire autonomamente durante l'esecuzione di questo piano. Non eseguire `git commit`/`git push`/creazione branch senza conferma esplicita del dev per ogni singolo commit — la fase di commit è gestita separatamente nel workflow `wm-plan`, dopo review del dev.

**Goal:** sostituire il meccanismo ad-hoc `HasFlexibleTranslatableFields::translatableFields()` (campo `KeyValue` multi-riga) con un nuovo Nova Field (`Wm\WmPackage\Nova\Fields\FlexibleTranslatable`, sottoclasse di `Kongulov\NovaTabTranslatable\NovaTabTranslatable`) che funziona correttamente dentro layout Flexible (`whitecube/nova-flexible-content`) e righe di `Repeater` Nova, in tutti e 4 i punti di consumo attuali, preservando esattamente il formato dati persistito.

**Architecture:** `NovaTabTranslatable` genera N campi (uno per locale) da un campo Nova "originale" passato in un array, ma le sue closure `resolveUsing`/`fillUsing` di default assumono un vero Eloquent Model con trait Spatie `HasTranslations` (`$model->translations[...]`, `$model->setTranslation(...)`). `FlexibleTranslatable` overrida `createTranslatedField()` per rimpiazzare quelle closure con logica agnostica al tipo di "modello" ricevuto — verificato che nei 4 punti di consumo il modello passato a `resolveUsing`/`fillUsing` è ora `Whitecube\NovaFlexibleContent\Layouts\Layout` (layout diretti su App), ora `Laravel\Nova\Support\Fluent` in fill / **array PHP grezzo** in resolve (righe di `Repeater` nativo Nova dentro `HorizontalScrollItemRepeatable`/`InfoBoxItemRepeatable`). Le letture usano `data_get()` (agnostico ad array/oggetto), le scritture usano accesso `->` (sempre valido nei contesti di fill reali, mai un array). Due modalità di storage: "simple" (un solo attributo JSON annidato per lingua, es. `title: {it:.., en:..}`, sostituisce il KeyValue) e "richText" (N attributi separati per lingua, es. `content_it`, `content_en`, con sanificazione HTMLPurifier condivisa, sostituisce il meccanismo Trix-per-locale hand-coded).

**Tech Stack:** Laravel 8.4 (Docker, `docker exec laravel-camminiditalia php artisan ...`), Laravel Nova 5, `kongulov/nova-tab-translatable` v2.1.7, `whitecube/nova-flexible-content` v2.0.1, `ezyang/htmlpurifier`, Pest (`docker exec laravel-camminiditalia php artisan test`).

## Global Constraints

- Repo coinvolto: solo `wm-package` (submodule). Nessun file nel repo principale camminiditalia.
- Tutti i test in `wm-package/tests/**` eseguiti da `docker exec laravel-camminiditalia php artisan test` devono usare `Tests\TestCase` (namespace del repo principale), **non** `Wm\WmPackage\Tests\TestCase` — quest'ultima non è in `autoload-dev` di camminiditalia.
- Locali disponibili: `it, en, fr, es, de` (da `wm-package/config/wm-tab-translatable.php`).
- Nessuna migration DB. Nessun cambiamento a `whitecube/nova-flexible-content` (vendor).
- PHPStan CI attivo (`phpstan.neon.dist` + `.github/workflows/phpstan.yml`) — il codice nuovo deve passare l'analisi statica.
- Formato dati persistito in `properties` JSON deve restare identico a quello attuale — nessuna migrazione dati, nessuna riscrittura dei record già esistenti in produzione.
- `Image`/`File` dentro Flexible sono esplicitamente out of scope — il nuovo field deve fallire in modo esplicito (eccezione) se usato con questi tipi di campo, non silenziosamente.

---

## Task 1: `FlexibleTranslatable` — modalità "simple" (gate di fattibilità)

**Files:**
- Create: `wm-package/src/Nova/Fields/FlexibleTranslatable.php`
- Test: `wm-package/tests/Feature/Nova/Fields/FlexibleTranslatableTest.php`

**Interfaces:**
- Produces: `Wm\WmPackage\Nova\Fields\FlexibleTranslatable extends Kongulov\NovaTabTranslatable\NovaTabTranslatable`, factory statici `FlexibleTranslatable::simple(string $label, array $fields, array $locales = []): static` e `FlexibleTranslatable::richText(string $label, array $fields, array $locales = [], string $allowedHtml = 'p,br,b,strong,i,em,u,ul,ol,li,h2,h3,h4,blockquote,a[href]'): static` (il secondo usato in Task 2). Proprietà pubbliche ereditate da `NovaTabTranslatable`: `$data` (array dei campi per-locale generati), ogni campo ha `->meta['locale']` e `->attribute`.

**Questo task è il gate del ciclo**: se emergono ostacoli imprevisti nello step 4 (i test non passano nonostante l'implementazione corretta secondo il design sottostante, per un comportamento non documentato del vendor), fermarsi, documentare il blocco in `docs/features/8349-translatable-field-flexible/notes.md` e tornare dal dev — non procedere a un fork del vendor autonomamente.

- [ ] **Step 1: Scrivere il test di round-trip attraverso un `Layout` reale (Whitecube), fallimento atteso**

```php
<?php

declare(strict_types=1);

use Laravel\Nova\Fields\Text;
use Laravel\Nova\Support\Fluent;
use Tests\TestCase;
use Whitecube\NovaFlexibleContent\Layouts\Layout;
use Wm\WmPackage\Nova\Fields\FlexibleTranslatable;

uses(TestCase::class);

function flexibleTranslatableRequest(string $attribute, ?string $value)
{
    return \Laravel\Nova\Http\Requests\NovaRequest::create('/', 'PUT', [$attribute => $value]);
}

it('round-trips a simple field through a real Whitecube Layout, matching the old KeyValue shape', function () {
    $locales = ['it', 'en', 'fr', 'es', 'de'];
    $field = FlexibleTranslatable::simple('Title', [Text::make('Title', 'title')], $locales);
    $layout = new Layout('Titolo', 'title', [$field]);

    $values = ['it' => 'Ciao', 'en' => 'Hello'];
    foreach ($field->data as $subField) {
        $locale = $subField->meta['locale'];
        $callback = $subField->fill(flexibleTranslatableRequest($subField->attribute, $values[$locale] ?? null), $layout);
        if (is_callable($callback)) {
            $callback();
        }
    }

    expect($layout->getAttributes()['title'])->toBe(['it' => 'Ciao', 'en' => 'Hello']);

    foreach ($field->data as $subField) {
        $subField->resolve($layout);
    }

    $resolvedByLocale = collect($field->data)->mapWithKeys(fn ($f) => [$f->meta['locale'] => $f->value]);

    expect($resolvedByLocale['it'])->toBe('Ciao');
    expect($resolvedByLocale['en'])->toBe('Hello');
    expect($resolvedByLocale['fr'])->toBe('');
});

it('round-trips a simple field through the Fluent(fill)+raw-array(resolve) shapes used inside a Repeater row', function () {
    $locales = ['it', 'en', 'fr', 'es', 'de'];
    $field = FlexibleTranslatable::simple('Title', [Text::make('Title', 'title')], $locales);

    $model = new Fluent;
    foreach ($field->data as $subField) {
        $locale = $subField->meta['locale'];
        $value = $locale === 'it' ? 'Storia' : null;
        $callback = $subField->fill(flexibleTranslatableRequest($subField->attribute, $value), $model);
        if (is_callable($callback)) {
            $callback();
        }
    }

    expect($model->title)->toBe(['it' => 'Storia']);

    $stored = ['title' => ['it' => 'Storia']];
    foreach ($field->data as $subField) {
        $subField->resolve($stored);
    }

    $resolvedByLocale = collect($field->data)->mapWithKeys(fn ($f) => [$f->meta['locale'] => $f->value]);

    expect($resolvedByLocale['it'])->toBe('Storia');
    expect($resolvedByLocale['en'])->toBe('');
});

it('decodes a legacy JSON-string stored value on resolve (backward compatibility with old KeyValue records)', function () {
    $locales = ['it', 'en'];
    $field = FlexibleTranslatable::simple('Title', [Text::make('Title', 'title')], $locales);

    // Records saved by the old HasFlexibleTranslatableFields/KeyValue mechanism may have
    // the raw value stored as a JSON string rather than a decoded array.
    $stored = ['title' => json_encode(['it' => 'Vecchio dato'])];
    foreach ($field->data as $subField) {
        $subField->resolve($stored);
    }

    $resolvedByLocale = collect($field->data)->mapWithKeys(fn ($f) => [$f->meta['locale'] => $f->value]);

    expect($resolvedByLocale['it'])->toBe('Vecchio dato');
});
```

- [ ] **Step 2: Eseguire il test, verificare che fallisca (classe non esiste)**

```bash
docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/Nova/Fields/FlexibleTranslatableTest.php
```

Atteso: `FAIL` — `Class "Wm\WmPackage\Nova\Fields\FlexibleTranslatable" not found`.

- [ ] **Step 3: Implementare `FlexibleTranslatable` (modalità simple, guard Image/File, senza richText — quella è Task 2)**

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Fields;

use HTMLPurifier;
use HTMLPurifier_Config;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Fields\Image;
use RuntimeException;

/**
 * Drop-in replacement for kongulov/nova-tab-translatable usable inside
 * whitecube/nova-flexible-content Layouts and Nova Repeater rows — neither
 * is a real Eloquent Model with Spatie HasTranslations, which is what
 * NovaTabTranslatable::createTranslatedField() assumes by default.
 *
 * Synced with kongulov/nova-tab-translatable v2.1.7 — re-check
 * createTranslatedField() on every vendor bump (composer.json pins ^2.1).
 *
 * Two storage modes, chosen via the named constructors:
 * - simple(): one JSON attribute nested by locale, e.g. `title: {it:.., en:..}`.
 * - richText(): N flat attributes, one per locale, e.g. `content_it`, `content_en`,
 *   with HTMLPurifier sanitization (single shared instance across locales).
 */
class FlexibleTranslatable extends NovaTabTranslatable
{
    protected bool $richText = false;

    protected ?HTMLPurifier $purifier = null;

    public function __construct(
        array $fields = [],
        array $locales = [],
        bool $richText = false,
        string $allowedHtml = 'p,br,b,strong,i,em,u,ul,ol,li,h2,h3,h4,blockquote,a[href]'
    ) {
        $this->richText = $richText;

        if ($richText) {
            $this->purifier = $this->makePurifier($allowedHtml);
        }

        parent::__construct($fields, $locales);
    }

    public static function simple(string $label, array $fields, array $locales = []): static
    {
        return (new static($fields, $locales, false))->setTitle($label);
    }

    public static function richText(
        string $label,
        array $fields,
        array $locales = [],
        string $allowedHtml = 'p,br,b,strong,i,em,u,ul,ol,li,h2,h3,h4,blockquote,a[href]'
    ): static {
        return (new static($fields, $locales, true, $allowedHtml))->setTitle($label);
    }

    protected function makePurifier(string $allowedHtml): HTMLPurifier
    {
        $cachePath = storage_path('framework/cache/htmlpurifier');

        if (! is_dir($cachePath)) {
            mkdir($cachePath, 0775, true);
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', $allowedHtml);
        $config->set('Cache.SerializerPath', $cachePath);

        return new HTMLPurifier($config);
    }

    protected function createTranslatedField(Field $originalField, string $locale): Field
    {
        if ($originalField instanceof Image || $originalField instanceof File) {
            throw new RuntimeException(
                static::class.' does not support Image/File fields inside Flexible layouts (out of scope, see docs/features/8349-translatable-field-flexible/overview.md).'
            );
        }

        $translatedField = clone $originalField;
        $originalAttribute = $translatedField->attribute;

        $translatedField->withMeta([
            'defaultValue' => $translatedField->defaultCallback,
            'locale' => $locale,
            'showOnIndex' => $translatedField->showOnIndex,
            'showOnDetail' => $translatedField->showOnDetail,
            'showOnCreation' => $translatedField->showOnCreation,
            'showOnUpdate' => $translatedField->showOnUpdate,
            'onlyOnDetail' => $translatedField->onlyOnDetail,
        ]);

        $translatedField->name = (count($this->locales) > 1)
            ? ($this->displayLocalizedNameUsingCallback)($translatedField, $locale)
            : $translatedField->name;

        $translatedField->panel = $this->panel;

        if ($this->richText) {
            $this->wireRichTextField($translatedField, $originalAttribute, $locale);
        } else {
            $this->wireSimpleField($translatedField, $originalAttribute, $locale);
        }

        return $translatedField;
    }

    protected function wireSimpleField(Field $translatedField, string $originalAttribute, string $locale): void
    {
        $translatedField->attribute = 'translations_'.$originalAttribute.'_'.$locale;

        $translatedField->resolveUsing(function ($value, $model) use ($originalAttribute, $locale) {
            $stored = $this->normalizeStoredValue(data_get($model, $originalAttribute));

            return $stored[$locale] ?? '';
        });

        $translatedField->fillUsing(function ($request, $model, $attribute, $requestAttribute) use ($originalAttribute, $locale) {
            $current = $this->normalizeStoredValue($model->{$originalAttribute} ?? null);
            $current[$locale] = $request->input($requestAttribute);

            $model->{$originalAttribute} = $current;
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeStoredValue(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }
}
```

(Il metodo `wireRichTextField()` viene aggiunto nel Task 2 — per ora, se richText fosse `true`, questo metodo non esiste ancora: `createTranslatedField()` chiamerebbe un metodo inesistente. Poiché in questo task nessun consumer usa `richText()`, questo è accettabile solo come stato intermedio: aggiungere comunque uno stub minimo che lancia `throw new \LogicException('not implemented yet')` per evitare un `Error: Call to undefined method` poco chiaro se qualcosa lo invoca per errore durante questo task.)

```php
    protected function wireRichTextField(Field $translatedField, string $originalAttribute, string $locale): void
    {
        throw new \LogicException('richText mode implemented in Task 2.');
    }
```

- [ ] **Step 4: Eseguire il test, verificare che passi**

```bash
docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/Nova/Fields/FlexibleTranslatableTest.php
```

Atteso: `PASS` (3 test).

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/Nova/Fields/FlexibleTranslatable.php wm-package/tests/Feature/Nova/Fields/FlexibleTranslatableTest.php
git commit -m "feat(oc:8349): add FlexibleTranslatable field, simple mode"
```

---

## Task 2: `FlexibleTranslatable` — modalità "richText" (HTMLPurifier + guard)

**Files:**
- Modify: `wm-package/src/Nova/Fields/FlexibleTranslatable.php` (implementare `wireRichTextField()`)
- Modify: `wm-package/tests/Feature/Nova/Fields/FlexibleTranslatableTest.php`

**Interfaces:**
- Consumes: `FlexibleTranslatable::richText()` (firma già definita in Task 1).
- Produces: comportamento completo di `richText()` — nessuna nuova interfaccia pubblica.

- [ ] **Step 1: Aggiungere i test per la modalità richText (fallimento atteso: `LogicException`)**

Aggiungere al file di test creato in Task 1:

```php
use Laravel\Nova\Fields\Trix;

it('sanitizes rich text per locale, matching the old Trix+HTMLPurifier mechanism', function () {
    $locales = ['it', 'en'];
    $field = FlexibleTranslatable::richText('Content', [Trix::make('Content', 'content')], $locales);

    $payloads = [
        'it' => '<p onclick="alert(1)">Testo <script>alert(2)</script><b>sicuro</b></p>',
        'en' => '<p>Safe text</p>',
    ];

    $model = new Fluent;
    foreach ($field->data as $subField) {
        $locale = $subField->meta['locale'];
        $callback = $subField->fill(flexibleTranslatableRequest($subField->attribute, $payloads[$locale]), $model);
        if (is_callable($callback)) {
            $callback();
        }
    }

    expect($model->content_it)->toContain('<b>sicuro</b>');
    expect($model->content_it)->not->toContain('onclick');
    expect($model->content_it)->not->toContain('<script>');
    expect($model->content_en)->toBe('<p>Safe text</p>');

    $stored = ['content_it' => $model->content_it, 'content_en' => $model->content_en];
    foreach ($field->data as $subField) {
        $subField->resolve($stored);
    }

    $resolvedByLocale = collect($field->data)->mapWithKeys(fn ($f) => [$f->meta['locale'] => $f->value]);
    expect($resolvedByLocale['it'])->toBe($model->content_it);
    expect($resolvedByLocale['en'])->toBe('<p>Safe text</p>');
});

it('leaves an empty rich text value empty instead of purifying an empty string', function () {
    $field = FlexibleTranslatable::richText('Content', [Trix::make('Content', 'content')], ['it']);

    $model = new Fluent;
    foreach ($field->data as $subField) {
        $callback = $subField->fill(flexibleTranslatableRequest($subField->attribute, ''), $model);
        if (is_callable($callback)) {
            $callback();
        }
    }

    expect($model->content_it)->toBe('');
});

it('rejects Image/File fields inside a Flexible-compatible translatable field', function () {
    expect(fn () => FlexibleTranslatable::simple('Icon', [\Laravel\Nova\Fields\Image::make('Icon', 'icon')], ['it']))
        ->toThrow(RuntimeException::class);
});
```

- [ ] **Step 2: Eseguire i test, verificare che i 3 nuovi falliscano**

```bash
docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/Nova/Fields/FlexibleTranslatableTest.php
```

Atteso: `FAIL` sui 3 nuovi test (`LogicException: richText mode implemented in Task 2.` per i primi due, il terzo dovrebbe già passare dato che il guard Image/File è già in `createTranslatedField()` dal Task 1 — se anche quello fallisce, verificare che il guard sia posizionato prima della chiamata a `wireRichTextField`/`wireSimpleField`, come già scritto in Task 1 Step 3).

- [ ] **Step 3: Implementare `wireRichTextField()`**

Sostituire lo stub `wireRichTextField()` scritto in Task 1 con:

```php
    protected function wireRichTextField(Field $translatedField, string $originalAttribute, string $locale): void
    {
        $flatAttribute = "{$originalAttribute}_{$locale}";
        $translatedField->attribute = $flatAttribute;

        $translatedField->resolveUsing(function ($value, $model) use ($flatAttribute) {
            return data_get($model, $flatAttribute, '');
        });

        $translatedField->fillUsing(function ($request, $model, $attribute, $requestAttribute) use ($flatAttribute) {
            $value = $request->input($requestAttribute);

            $model->{$flatAttribute} = (is_string($value) && $value !== '')
                ? $this->purifier->purify($value)
                : $value;
        });
    }
```

- [ ] **Step 4: Eseguire tutti i test del file, verificare che passino**

```bash
docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/Nova/Fields/FlexibleTranslatableTest.php
```

Atteso: `PASS` (6 test).

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/Nova/Fields/FlexibleTranslatable.php wm-package/tests/Feature/Nova/Fields/FlexibleTranslatableTest.php
git commit -m "feat(oc:8349): add FlexibleTranslatable richText mode with shared HTMLPurifier"
```

---

## Task 3: Migrare `InfoBoxItemRepeatable` (config_detail: title + content)

**Files:**
- Modify: `wm-package/src/Nova/Flexible/ConfigDetail/InfoBoxItemRepeatable.php`
- Modify: `wm-package/tests/Feature/Nova/InfoBoxItemRepeatableTest.php`

**Interfaces:**
- Consumes: `FlexibleTranslatable::simple()`, `FlexibleTranslatable::richText()` (Task 1+2).

- [ ] **Step 1: Aggiornare `InfoBoxItemRepeatableTest.php` alle nuove aspettative (fallimento atteso)**

Sostituire l'intero contenuto del file con:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Nova\Fields\FlexibleTranslatable;
use Wm\WmPackage\Nova\Flexible\ConfigDetail\InfoBoxItemRepeatable;

uses(TestCase::class, DatabaseTransactions::class);

it('exposes one title field and one content field, both FlexibleTranslatable', function () {
    $fields = InfoBoxItemRepeatable::make()->fields(NovaRequest::create('/'));

    expect($fields)->toHaveCount(2);
    expect($fields[0])->toBeInstanceOf(FlexibleTranslatable::class);
    expect($fields[1])->toBeInstanceOf(FlexibleTranslatable::class);
});
```

Nota: i test di round-trip e sanificazione HTMLPurifier per il comportamento del field in sé sono già coperti in modo più rigoroso da `FlexibleTranslatableTest.php` (Task 1/2), usando un `Fluent`/array reali invece di uno `stdClass` — non replicare qui quel pattern.

- [ ] **Step 2: Eseguire il test, verificare che fallisca**

```bash
docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/Nova/InfoBoxItemRepeatableTest.php
```

Atteso: `FAIL` (i field restituiti sono ancora `KeyValue`/`Trix`, non `FlexibleTranslatable`).

- [ ] **Step 3: Migrare `InfoBoxItemRepeatable::fields()`**

Sostituire il contenuto del file:

```php
<?php

namespace Wm\WmPackage\Nova\Flexible\ConfigDetail;

use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Repeater\Repeatable;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Trix;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Fields\FlexibleTranslatable;

class InfoBoxItemRepeatable extends Repeatable
{
    public static function key(): string
    {
        return 'info-box-item';
    }

    /**
     * @return array<int, Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            FlexibleTranslatable::simple(__('Title'), [Text::make(__('Title'), 'title')]),
            FlexibleTranslatable::richText(__('Content'), [Trix::make(__('Content'), 'content')]),
        ];
    }
}
```

- [ ] **Step 4: Eseguire il test di `InfoBoxItemRepeatable`, poi il test di integrazione end-to-end esistente**

```bash
docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/Nova/InfoBoxItemRepeatableTest.php wm-package/tests/Feature/Nova/LayerConfigDetailInfoBoxTest.php
```

Atteso: `PASS` su entrambi i file. `LayerConfigDetailInfoBoxTest.php` esercita l'intera pipeline reale (Layer → Flexible → Repeater → `ConfigDetailResolver`) e deve continuare a passare **senza modifiche** — è la prova che il formato persistito (`title: {it:..}`, `content: {it:..}` dopo il passaggio in `ConfigDetailResolver::buildInfoElement()`) non è cambiato.

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/Nova/Flexible/ConfigDetail/InfoBoxItemRepeatable.php wm-package/tests/Feature/Nova/InfoBoxItemRepeatableTest.php
git commit -m "refactor(oc:8349): migrate InfoBoxItemRepeatable to FlexibleTranslatable"
```

---

## Task 4: Migrare `HorizontalScrollItemRepeatable` (config_home: title a livello di riga)

**Files:**
- Modify: `wm-package/src/Nova/Flexible/ConfigHome/HorizontalScrollItemRepeatable.php`

**Interfaces:**
- Consumes: `FlexibleTranslatable::simple()` (Task 1).

- [ ] **Step 1: Aggiornare il metodo `fields()`**

In `wm-package/src/Nova/Flexible/ConfigHome/HorizontalScrollItemRepeatable.php`, sostituire:

```php
use Wm\WmPackage\Nova\Traits\HasFlexibleTranslatableFields;
```

con:

```php
use Wm\WmPackage\Nova\Fields\FlexibleTranslatable;
```

Rimuovere `use HasFlexibleTranslatableFields;` (riga della classe, non l'import) e sostituire nel metodo `fields()`:

```php
        foreach ($this->translatableFields(__('Title'), 'title') as $field) {
            $fields[] = $field->nullable()
                ->help(__('Overrides the default taxonomy label for this item in config JSON; leave empty to use the taxonomy name.'));
        }
```

con:

```php
        $fields[] = FlexibleTranslatable::simple(__('Title'), [Text::make(__('Title'), 'title')])
            ->nullable()
            ->help(__('Overrides the default taxonomy label for this item in config JSON; leave empty to use the taxonomy name.'));
```

Il resto della classe (costruttore, `key()`, `defaultTaxonomyOptionsForSelect()`, `taxonomyLabel()`) resta invariato.

- [ ] **Step 2: Verificare che non esista già un test dedicato a rompersi, poi eseguire i test di integrazione config_home esistenti**

```bash
grep -rl "HorizontalScrollItemRepeatable" wm-package/tests/
docker exec laravel-camminiditalia php artisan test --filter=ConfigHome
```

Se il filtro non trova nulla o il grep non restituisce file, eseguire l'intera suite Nova del package come rete di sicurezza:

```bash
docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/Nova/
```

Atteso: `PASS`, nessuna regressione.

- [ ] **Step 3: Commit**

```bash
git add wm-package/src/Nova/Flexible/ConfigHome/HorizontalScrollItemRepeatable.php
git commit -m "refactor(oc:8349): migrate HorizontalScrollItemRepeatable to FlexibleTranslatable"
```

---

## Task 5: Migrare `App::config_home_title_layout()` e `App::overlays_title_layout()`

**Files:**
- Modify: `wm-package/src/Nova/App.php`

**Interfaces:**
- Consumes: `FlexibleTranslatable::simple()` (Task 1).

- [ ] **Step 1: Sostituire le due chiamate a `translatableFields()`**

In `wm-package/src/Nova/App.php`:

```php
    protected function overlays_title_layout(): array
    {
        return $this->translatableFields('Label', 'label');
    }
```

diventa:

```php
    protected function overlays_title_layout(): array
    {
        return [FlexibleTranslatable::simple('Label', [Text::make('Label', 'label')])];
    }
```

```php
    protected function config_home_title_layout(): array
    {
        return $this->translatableFields('Title', 'title', required: true);
    }
```

diventa:

```php
    protected function config_home_title_layout(): array
    {
        return [FlexibleTranslatable::simple('Title', [Text::make('Title', 'title')])];
    }
```

Nota: il flag `required: true` viene rimosso — nella vecchia implementazione, `HasFlexibleTranslatableFields::translatableFields()` applicava `->rules('required')` al singolo campo `KeyValue` aggregato, una validazione che con `disableAddingRows()`/`disableDeletingRows()` e default sempre popolati (`array_fill_keys($locales, '')`) non impediva mai concretamente il salvataggio di un titolo vuoto — non è un comportamento funzionale da preservare. Se in fase di verifica manuale (Task 7) emerge che questo `required` aveva un effetto osservabile in UI non individuato qui, documentarlo in `notes.md` prima di procedere.

Aggiungere l'import in testa al file (verificare se `Text` è già importato altrove in `App.php` prima di duplicare l'import):

```php
use Wm\WmPackage\Nova\Fields\FlexibleTranslatable;
```

Rimuovere `use Wm\WmPackage\Nova\Traits\HasFlexibleTranslatableFields;` e la riga `use HasFlexibleTranslatableFields;` dentro la classe **solo se** nessun altro metodo di `App.php` la usa ancora — verificarlo con:

```bash
grep -n "translatableFields\|decodeTranslatableValue" wm-package/src/Nova/App.php
```

Atteso: nessuna occorrenza rimanente dopo le due sostituzioni sopra.

- [ ] **Step 2: Eseguire i test Nova esistenti su App**

```bash
docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/Nova/ --filter=App
```

Se il filtro non produce match significativi, eseguire l'intera suite Nova del package:

```bash
docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/Nova/
```

Atteso: `PASS`, nessuna regressione (in particolare `ConfigHomeResolver`/`ConfigOverlaysResolver`, che leggono `title`/`label` da `$layout->getAttributes()` — devono continuare a trovare lo stesso formato).

- [ ] **Step 3: Commit**

```bash
git add wm-package/src/Nova/App.php
git commit -m "refactor(oc:8349): migrate App config_home/overlays title layouts to FlexibleTranslatable"
```

---

## Task 6: Rimuovere `translatableFields()` da `HasFlexibleTranslatableFields`

**Files:**
- Modify: `wm-package/src/Nova/Traits/HasFlexibleTranslatableFields.php`

**Interfaces:**
- Consumes: nessuno (verifica di assenza di riferimenti residui).

- [ ] **Step 1: Verificare che nessun consumer chiami ancora `translatableFields()`**

```bash
grep -rn "->translatableFields(\|\$this->translatableFields(" wm-package/src/ app/
```

Atteso: **nessun risultato**. Se emerge un risultato, quel consumer non è stato migrato in un task precedente — tornare al task corrispondente prima di procedere.

- [ ] **Step 2: Rimuovere il metodo `translatableFields()` dal trait, mantenere `decodeTranslatableValue()`**

`wm-package/src/Nova/Traits/HasFlexibleTranslatableFields.php` diventa:

```php
<?php

namespace Wm\WmPackage\Nova\Traits;

/**
 * Decodes translated values already persisted in Flexible layout attributes.
 *
 * Historically also produced an ad-hoc KeyValue-based translatable field
 * (translatableFields()) — superseded by Wm\WmPackage\Nova\Fields\FlexibleTranslatable
 * (oc:8349). decodeTranslatableValue() survives because it is read-only and
 * already handles both the legacy JSON-string format and the plain-array
 * format the new field produces.
 */
trait HasFlexibleTranslatableFields
{
    protected function decodeTranslatableValue(mixed $val): array
    {
        if (is_string($val)) {
            $val = json_decode($val, true);
        }

        if (! is_array($val)) {
            return [];
        }

        return array_filter($val, static fn ($v) => $v !== null && $v !== '');
    }
}
```

- [ ] **Step 3: Verificare che i 3 resolver che usano ancora il trait continuino a funzionare**

```bash
grep -rln "use HasFlexibleTranslatableFields" wm-package/src/
```

Atteso: solo `ConfigHomeResolver.php`, `ConfigOverlaysResolver.php`, `ConfigDetailResolver.php` (i 3 resolver, non più `App.php`/`HorizontalScrollItemRepeatable.php`/`InfoBoxItemRepeatable.php`, già puliti nei task precedenti).

```bash
docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/Nova/
```

Atteso: `PASS`, nessuna regressione.

- [ ] **Step 4: Commit**

```bash
git add wm-package/src/Nova/Traits/HasFlexibleTranslatableFields.php
git commit -m "refactor(oc:8349): remove ad-hoc translatableFields(), keep decodeTranslatableValue()"
```

---

## Task 7: Verifica manuale sui record esistenti + PHPStan

**Files:** nessuno (task di verifica, non di scrittura codice).

- [ ] **Step 1: Eseguire l'intera suite di test del repo, non solo i file toccati**

```bash
docker exec laravel-camminiditalia php artisan test
```

Atteso: `PASS` su tutta la suite (nessuna regressione trasversale).

- [ ] **Step 2: Eseguire PHPStan**

```bash
docker exec laravel-camminiditalia vendor/bin/phpstan analyse --error-format=table
```

Atteso: nessun nuovo errore introdotto dai file toccati in questo piano (`FlexibleTranslatable.php`, `InfoBoxItemRepeatable.php`, `HorizontalScrollItemRepeatable.php`, `App.php`, `HasFlexibleTranslatableFields.php`). Errori preesistenti su altri file non sono responsabilità di questo ciclo (vedi `review-gate: phpstan-check` nel workflow `wm-plan`).

- [ ] **Step 3: Verifica manuale sui Box Informativi e Horizontal Scroll già configurati nel db locale**

```bash
docker exec laravel-camminiditalia php artisan tinker --execute="
\$layers = \Wm\WmPackage\Models\Layer::query()->whereNotNull('properties->config_detail')->limit(5)->get(['id','properties']);
foreach (\$layers as \$layer) {
    echo 'Layer '.\$layer->id.': '.json_encode(\$layer->properties['config_detail'] ?? []).PHP_EOL;
}
\$apps = \Wm\WmPackage\Models\App::query()->whereNotNull('properties->config_home')->limit(5)->get(['id','properties']);
foreach (\$apps as \$app) {
    echo 'App '.\$app->id.': '.json_encode(\$app->properties['config_home'] ?? []).PHP_EOL;
}
"
```

Confrontare l'output con quanto osservato **prima** di questo ciclo (se possibile, eseguire lo stesso comando su un checkout del branch precedente per un diff testuale) — verificare che:
- I Box Informativi esistenti mostrino ancora `title`/`content` popolati nelle stesse lingue di prima.
- Gli Horizontal Scroll esistenti mostrino ancora `title` popolato nelle stesse lingue di prima.

Poi aprire Nova in locale (`http://localhost:8000/nova` o porta configurata in `.env`), navigare su un Layer con Box Informativi esistenti e su App → config_home con Horizontal Scroll esistenti, verificare che l'editor mostri correttamente le traduzioni già salvate e che un salvataggio (anche senza modifiche) non alteri il formato osservato al punto precedente.

- [ ] **Step 4: Documentare l'esito in notes.md**

Aggiungere a `docs/features/8349-translatable-field-flexible/notes.md` (crearlo se non esiste) l'esito della verifica manuale (record controllati, esito, eventuali anomalie) e qualsiasi deviazione dal piano emersa durante l'implementazione.

---

## Self-Review

**Spec coverage** (rispetto ai Requisiti di `overview.md`):
- Spike di validazione con `Layout` reale → Task 1, Step 1 (primo test).
- Field completo, due modalità, HTMLPurifier riusato → Task 1 + Task 2.
- `whitecube/nova-flexible-content` non toccato → nessun task modifica `vendor/whitecube/*`.
- Sostituzione in tutti i 4 punti di consumo → Task 3 (InfoBoxItemRepeatable), Task 4 (HorizontalScrollItemRepeatable), Task 5 (App, 2 metodi).
- Formato dati identico → verificato in Task 1 Step 1 (test su `Layout`/Fluent/array) e in Task 3 Step 4 (`LayerConfigDetailInfoBoxTest.php` invariato che continua a passare).
- Test di regressione con traduzioni parziali → Task 1 Step 1 (secondo e terzo test, locale `en`/`fr` mai compilata).
- `HasFlexibleTranslatableFields` rimosso (non deprecato) → Task 6, con `decodeTranslatableValue()` preservato per i 3 resolver.
- Verifica manuale sui record esistenti → Task 7 Step 3.
- Gate di stop se infeasibile → nota esplicita in Task 1.

**Placeholder scan:** nessun "TBD"/"implementare dopo" nei task sopra — ogni step ha codice completo o comando eseguibile con output atteso esplicito.

**Type consistency:** `FlexibleTranslatable::simple()`/`richText()` hanno la stessa firma ovunque richiamati (Task 3, 4, 5); `$field->data`, `$field->meta['locale']`, `$field->attribute` usati con lo stesso significato in tutti i test.
