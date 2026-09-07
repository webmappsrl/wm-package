> Ticket: oc:8367

# Theme colori app — configurazione da backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Override Webmapp:** in questo progetto i commit sono vietati durante l'esecuzione automatica. Non eseguire `git commit`/`git add`/`git push` per nessun motivo — gli step "Commit" di questo piano sono istruzioni testuali per l'utente, non azioni da eseguire autonomamente. Il commit reale avviene solo dopo il review-gate del workflow `wm-plan`.

**Goal:** Correggere il mismatch snake_case/camelCase tra Nova (`properties->theme->*`) e il contratto `ITHEME` del frontend, estendere `theme_tab()` con i ruoli colore mancanti, e correggere il bug collaterale del fallback colore in `config_section_map()`.

**Architecture:** Nessuna migrazione dati. Lo storage resta `properties->theme->*` (JSON, snake_case) su `Wm\WmPackage\Models\App`; solo il layer di output (`AppConfigService::config_section_theme()`) viene riscritto per produrre le chiavi camelCase esatte attese da `ITHEME` (wm-core), con accesso sempre defensivo (`??`) per non propagare eccezioni nel salvataggio sincrono di `AppObserver::saved()`.

**Tech Stack:** Laravel Nova (PHP), Pest (Orchestra Testbench, `Wm\WmPackage\Tests\TestCase`), nessun frontend toccato.

**Spec:** `docs/features/8367-theme-colori-app-configurazione-da-backend/overview.md`

## Global Constraints

- Nessuna modifica ai dati già salvati sotto `properties->theme->*` — solo il metodo di output cambia.
- Ogni accesso a `properties->theme->*` in `AppConfigService` deve usare `??`/`isset()` — mai un accesso diretto che possa lanciare eccezione (il salvataggio dell'App in Nova chiama `config()` sincronamente; un'eccezione qui blocca il salvataggio di *tutta* l'App, non solo i colori).
- Regex di validazione color picker: `^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$`, `nullable`.
- Nessun Panel di raggruppamento in Nova — i campi restano flat nel tab "Theme".
- Nessuna modifica a `wm-core`/`webapp-app` (repo separati, fuori scope).
- Namespace container Docker per la verifica manuale: `php-camminiditalia` (non `laravel-camminiditalia` come riportato in CLAUDE.md — nome disallineato, verificato in questa sessione).

---

## Task 1: Estendere `theme_tab()` in Nova con i nuovi color picker + validazione + label i18n

**Files:**
- Modify: `src/Nova/App.php:428-441` (metodo `theme_tab()`)
- Modify: `resources/lang/it.json`
- Modify: `resources/lang/en.json`

**Interfaces:**
- Consumes: nessuna dipendenza da altri task.
- Produces: attributo Nova `properties->theme->primary_color` (color picker) e `properties->theme->{font_family_header,font_family_content}` (già esistenti, invariati) — questi 3 nomi di sottochiave sono quelli che il Task 2 legge in `config_section_theme()`. (`secondary_color`/`tertiary_color`/`default_feature_color`, mostrati nei blocchi di codice sotto come parte della cronologia, sono stati rimossi in decisioni successive — vedi note "Aggiornato post-round-3"/"post-round-4" più sotto e `notes.md`.)

> **Aggiornato post-commit (round 2 review + verifica frontend)**: `success_color`/`warning_color`/`danger_color` erano nel piano originale ma sono stati rimossi nello stesso ciclo — 0/0/4 consumatori CSS reali nel frontend (vedi `notes.md`, sezione "Decisione post-commit").
>
> **Aggiornato post-round-3**: `default_feature_color` (campo pre-esistente a oc:8367, non introdotto da questo task) è stato rimosso a sua volta, stesso motivo — 0 consumatori reali confermati da `map-core` (usa una costante hardcoded, ignora `THEME.defaultFeatureColor`). Vedi `notes.md`, sezione "Decisione post-round-3".
>
> **Aggiornato post-round-4**: la sessione frontend ha corretto (per la seconda volta) i propri conteggi di consumatori CSS reali — risultato finale: `tertiary` 0 usi reali (dato precedente, 3, era sbagliato), `secondary` solo 2 (bordo di focus su login/registrazione). Rimossi entrambi su richiesta esplicita del dev. Vedi `notes.md`, sezione "Decisione post-round-4". Il codice sotto riflette la versione finale: **un solo color picker** (`primary_color`) + 2 campi font, con l'helper `themeColorField()` (estratto in un secondo momento per cleanup, non nella stesura originale del piano, ora con una singola chiamata).

- [ ] **Step 1: Sostituire `theme_tab()` con la versione estesa**

Sostituire l'intero corpo del metodo in `src/Nova/App.php` (righe 428-441):

```php
    /**
     * Elenco duplicato di proposito in AppConfigService::THEME_KEY_MAP (chiave sorgente
     * properties->theme->* -> chiave camelCase in config.json) — mantenere sincronizzati:
     * un nuovo campo qui senza il corrispondente in THEME_KEY_MAP non produce mai errore,
     * semplicemente non raggiunge mai il frontend.
     */
    protected function theme_tab(): array
    {
        return [
            Text::make(__('Font Family Header'), 'properties->theme->font_family_header')
                ->hideFromIndex()
                ->help(__('Font family used for headings in the app theme')),
            Text::make(__('Font Family Content'), 'properties->theme->font_family_content')
                ->hideFromIndex()
                ->help(__('Font family used for body content in the app theme')),
            $this->themeColorField(__('Primary color'), 'primary_color', __('Primary color for the app theme (e.g. buttons, links)')),
        ];
    }

    private function themeColorField(string $label, string $attribute, string $help): Color
    {
        return Color::make($label, "properties->theme->{$attribute}")
            ->rules('nullable', 'regex:/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/')
            ->hideFromIndex()
            ->help($help);
    }
```

Nota: la regex va applicata anche al color picker già esistente (`primary_color`) per coerenza — prima non aveva alcuna validazione di formato.

> **Aggiornato post-round-4**: `secondary`/`tertiary` (e le relative 4 voci di traduzione degli step 2/3 sotto) sono stati rimossi. `primary_color` non aveva mai avuto una traduzione in `it.json`/`en.json` (gap pre-esistente, chiuso in round 4 — vedi `notes.md`): le uniche voci rimaste in scope per questo task sono "Primary color"/"Primary color for the app theme...".

- [ ] **Step 2: Aggiungere le nuove label a `resources/lang/en.json`**

Aggiungere queste coppie chiave/valore (identity mapping, come le altre voci in inglese del file — inserire in un punto qualsiasi dell'oggetto JSON, l'ordine non è significativo):

```json
    "Primary color": "Primary color",
    "Primary color for the app theme (e.g. buttons, links)": "Primary color for the app theme (e.g. buttons, links)",
```

- [ ] **Step 3: Aggiungere le traduzioni italiane a `resources/lang/it.json`**

```json
    "Primary color": "Colore primario",
    "Primary color for the app theme (e.g. buttons, links)": "Colore primario per il tema dell'app (es. pulsanti, link)",
```

- [ ] **Step 4: Verificare che il JSON resti valido**

Run: `php -r "json_decode(file_get_contents('resources/lang/it.json'), true) === null && exit(1); echo 'OK it.json';"` (eseguito dalla root di `wm-package`)
Run: `php -r "json_decode(file_get_contents('resources/lang/en.json'), true) === null && exit(1); echo 'OK en.json';"`
Expected: entrambi stampano `OK ...json` senza errori di parsing.

- [ ] **Step 5: Commit**

```bash
git add src/Nova/App.php resources/lang/it.json resources/lang/en.json
git commit -m "feat(oc:8367): add secondary/tertiary color pickers to theme tab"
```

---

## Task 2: Riscrivere `AppConfigService::config_section_theme()` con mapping camelCase defensivo (TDD)

**Files:**
- Create: `tests/Feature/AppConfigServiceThemeTest.php`
- Modify: `src/Services/Models/App/AppConfigService.php:656-665` (metodo `config_section_theme()`)

**Interfaces:**
- Consumes: nessuna dipendenza diretta da Task 1 (i test di questo task impostano `properties->theme->*` direttamente via factory, non passano da Nova).
- Produces: `config()['THEME']` con le chiavi camelCase `primary`, `fontFamilyHeader`, `fontFamilyContent` — Task 4 (verifica manuale) e la sezione "Bug trovati" di `notes.md` fanno riferimento a questi nomi esatti.

- [ ] **Step 1: Scrivere il test che fallisce — mapping esaustivo con tutte le 9 chiavi popolate**

Creare `tests/Feature/AppConfigServiceThemeTest.php`:

> **Aggiornato post-commit**: rimossi success/warning/danger dal caso di test esaustivo (vedi Task 1); aggiunto un sesto test per il guard `is_string()` (cleanup round 2 review, protegge da valori non scalari scritti in `properties->theme->*`); il namespace di `TestCase` corretto in `Wm\WmPackage\Tests\TestCase` (`Tests\TestCase`, usato nella stesura originale del piano, è un pattern noto rotto — vedi `wm-package/CLAUDE.md` oc:8183 — non risolvibile in questo ambiente).
>
> **Aggiornato post-round-3**: rimosso anche `default_feature_color` dal caso esaustivo e dal caso "dato reale camminiditalia" (ora asserisce solo `primary`); aggiunto un settimo test per la validazione di formato colore introdotta in round 3 (`config_section_theme()` ora esclude valori non hex sulle chiavi `*_color`).
>
> **Aggiornato post-round-4**: rimossi anche `secondary_color`/`tertiary_color` da tutti i casi di test (erano usati sia come chiavi "gemelle" di primary sia come i valori superstiti in alcune asserzioni — riscritti usando `font_family_header`/`font_family_content` per quel ruolo). Il test "excludes malformed color values" è stato spezzato in due (malformato escluso / 3-cifre valido incluso), dato che ora c'è un solo colore da testare. Codice sotto = versione finale, 8 test.

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppConfigService;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

it('config.json THEME contains all camelCase keys mapped from properties->theme snake_case', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => '#111111',
                'font_family_header' => 'Roboto Slab',
                'font_family_content' => 'Roboto',
            ],
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['THEME'])->toBe([
        'primary' => '#111111',
        'fontFamilyHeader' => 'Roboto Slab',
        'fontFamilyContent' => 'Roboto',
    ]);
});

it('config.json THEME excludes keys with empty or null values', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => '',
                'font_family_header' => 'Roboto Slab',
                'font_family_content' => null,
            ],
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['THEME'])->toBe([
        'fontFamilyHeader' => 'Roboto Slab',
    ]);
});

it('config.json THEME is an empty array when properties->theme is missing, without throwing', function () {
    $app = App::factory()->createQuietly([
        'properties' => [],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['THEME'])->toBe([]);
});

it('config.json THEME is an empty array when properties->theme is a malformed non-array value, without throwing', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => 'not-an-array',
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['THEME'])->toBe([]);
});

it('config.json THEME reproduces the real camminiditalia data shape (only primary color set, default_feature_color is orphaned data no longer mapped)', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => '#ef7821',
                'font_family_header' => null,
                'font_family_content' => null,
                'default_feature_color' => '#ef7821',
            ],
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['THEME'])->toBe([
        'primary' => '#ef7821',
    ]);
});

it('config.json THEME excludes non-string values instead of leaking them into the output', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => ['#fff'],
                'font_family_header' => 'Roboto Slab',
            ],
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['THEME'])->toBe([
        'fontFamilyHeader' => 'Roboto Slab',
    ]);
});

it('config.json THEME excludes a malformed primary color while keeping font strings untouched', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => 'banana',
                'font_family_header' => 'Roboto Slab',
            ],
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['THEME'])->toBe([
        'fontFamilyHeader' => 'Roboto Slab',
    ]);
});

it('config.json THEME includes a valid 3-digit hex primary color', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => '#abc',
            ],
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['THEME'])->toBe([
        'primary' => '#abc',
    ]);
});
```

- [ ] **Step 2: Eseguire i test per verificare che falliscano**

Run: `docker exec php-camminiditalia php artisan test --filter=AppConfigServiceThemeTest` (dalla root di `camminiditalia`, il comando esegue i test del submodule `wm-package` montato nel container)

Se il comando sopra non individua i test del package (dipende da come `phpunit.xml`/`composer.json` di camminiditalia includono `wm-package/tests`), eseguire in alternativa dalla root di `wm-package`:

Run: `cd wm-package && vendor/bin/pest tests/Feature/AppConfigServiceThemeTest.php`

Expected: FAIL — `config_section_theme()` produce ancora le vecchie chiavi snake_case (`primary_color`, `font_family_header`, ecc.), non quelle camelCase attese dal test (`expect($config['THEME'])->toBe([...])` fallisce per mismatch di array).

> **Nota post-commit sull'esecuzione reale**: in questo ambiente `vendor/bin/pest` non è mai stato eseguibile (`composer install` bloccato da credenziali `laravel/nova` scadute, oc:7546) — il ciclo TDD write-fail/pass è stato sostituito da verifica equivalente via `php artisan tinker` + reflection su istanze `App` non persistite. Dettaglio completo in `notes.md`, sezioni "Deviazioni dal piano" e "Follow-up".

- [ ] **Step 3: Riscrivere `config_section_theme()`**

Sostituire il metodo in `src/Services/Models/App/AppConfigService.php` (righe 656-665):

```php
    /**
     * Mappa properties->theme->* (snake_case, Nova App::theme_tab()) -> chiave camelCase
     * attesa da ITHEME (wm-core) in config.json.THEME. Unica fonte di verità per questo
     * elenco di chiavi lato AppConfigService — Nova App::theme_tab() mantiene la propria
     * copia (label/help diversi per campo, non riducibile a questa sola mappa).
     */
    private const THEME_KEY_MAP = [
        'primary_color' => 'primary',
        'font_family_header' => 'fontFamilyHeader',
        'font_family_content' => 'fontFamilyContent',
    ];

    private function config_section_theme(): array
    {
        $theme = $this->app->properties['theme'] ?? [];
        if (! is_array($theme)) {
            $theme = [];
        }

        $data = [];
        $data['THEME'] = [];

        foreach (self::THEME_KEY_MAP as $sourceKey => $targetKey) {
            $value = $theme[$sourceKey] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }
            if (str_ends_with($sourceKey, '_color') && ! preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
                continue;
            }
            $data['THEME'][$targetKey] = $value;
        }

        return $data;
    }
```

> **Aggiornato post-commit (cleanup round 2 review)**: la stesura originale del piano usava 9 blocchi `if (! empty(...))` ripetuti invece della costante `THEME_KEY_MAP` iterata in loop — refactor per DRY, comportamento identico (verificato con gli stessi 6 scenari via reflection prima e dopo). Il guard è passato da `! empty()` a `is_string($value) && $value !== ''`, per escludere valori non scalari (es. un array scritto per errore in `properties->theme->*`) dall'output invece di lasciarli passare — vedi il sesto test sopra.
>
> **Aggiornato post-round-3**: rimossa `default_feature_color` da `THEME_KEY_MAP` (vedi Task 1). Aggiunta una seconda condizione di esclusione: per le chiavi che finiscono in `_color`, il valore deve rispettare `^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$` (stessa regex tollerata da Nova) — un round di review aveva inizialmente liquidato l'assenza di questo controllo come "solo imprecisione di documentazione", poi riclassificato come gap di codice reale e corretto. I 2 campi font non sono soggetti a questo controllo (`str_ends_with($sourceKey, '_color')` li esclude). Vedi settimo test sopra e `notes.md`, sezione "Review — round 3".
>
> **Aggiornato post-round-4**: rimosse anche `secondary_color`/`tertiary_color` da `THEME_KEY_MAP` (vedi Task 1) — solo `primary_color` resta come chiave `*_color`. La logica del metodo (guard `is_string`/vuoto, poi guard formato hex per le chiavi `_color`) è invariata, si applica semplicemente a un `THEME_KEY_MAP` più corto. Vedi `notes.md`, sezione "Decisione post-round-4".

Nota sulla robustezza: `$this->app->properties['theme'] ?? []` non lancia mai eccezioni anche se `properties` è `null` (comportamento nativo dell'operatore `??` su catene di array in PHP, verificato — stesso pattern già usato altrove nel file, es. riga 429 `$properties = $this->app->properties ?? [];`). Il controllo `is_array($theme)` aggiuntivo gestisce il caso in cui `properties->theme` esista ma sia un valore scalare malformato (es. una stringa), che altrimenti farebbe fallire l'accesso su tipo non-array.

- [ ] **Step 4: Eseguire i test per verificare che passino**

Run: `cd wm-package && vendor/bin/pest tests/Feature/AppConfigServiceThemeTest.php`
Expected: PASS — tutti i 6 test verdi.

- [ ] **Step 5: Eseguire l'intera suite Pest del package per verificare l'assenza di regressioni**

Run: `cd wm-package && vendor/bin/pest --filter=AppConfigService`
Expected: PASS — nessuna regressione sugli altri test `AppConfigService*Test.php` esistenti (`AppConfigServiceOverlaysTest`, `AppConfigServiceTranslationsTest`, `AppConfigServiceMinAppVersionTest`).

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/AppConfigServiceThemeTest.php src/Services/Models/App/AppConfigService.php
git commit -m "fix(oc:8367): produce config.json THEME with camelCase keys expected by ITHEME"
```

---

## Task 3: Correggere il fallback colore in `config_section_map()` (bug collaterale) + aggiornare il docblock di `StoryShareImageService`

> **Aggiornato post-commit (round 2 review)**: la stesura originale di questo task copriva solo `$primaryColor`, lasciando `$fc->fill_color`/`$fc->stroke_color` (campi Nova `FeatureCollection` di testo libero, zero validazione) sullo stesso `hexToRgba()` non guardato — stesso identico bug, corretto solo a metà. Aggravante: raggiungibile anche da un endpoint pubblico non autenticato (`/{app}/config.json`), non solo al salvataggio Nova. Corretto estraendo una funzione globale condivisa `sanitizeHexColor()` in `src/helpers.php` (usata anche da `StoryShareImageService::resolveAccentColor()`, che duplicava la stessa regex). Dettaglio completo in `notes.md`, sezione "Review — round 2".

**Files:**
- Create: `tests/Feature/AppConfigServiceMapFeatureCollectionColorTest.php`
- Modify: `src/helpers.php` (nuova funzione globale `sanitizeHexColor()`)
- Modify: `src/Services/Models/App/AppConfigService.php:500` (dentro `config_section_map()`)
- Modify: `src/Services/Models/StoryShare/StoryShareImageService.php:106` (docblock + `resolveAccentColor()`)

**Interfaces:**
- Consumes: nessuna dipendenza diretta da Task 1/2 (il bug e il fix riguardano `properties->theme->primary_color`, stesso storage ma un consumer indipendente).
- Produces: `sanitizeHexColor($value, $fallback): string` (funzione globale, `src/helpers.php`) — usata da `config_section_map()` e da `StoryShareImageService::resolveAccentColor()`.

- [ ] **Step 1: Scrivere i test che falliscono**

Creare `tests/Feature/AppConfigServiceMapFeatureCollectionColorTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Services\Models\App\AppConfigService;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

it('feature_collection overlay box without its own colors falls back to the app real primary color', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => '#abcdef',
            ],
        ],
    ]);

    $fc = FeatureCollection::factory()->createQuietly([
        'app_id' => $app->id,
        'enabled' => true,
        'fill_color' => null,
        'stroke_color' => null,
    ]);

    $app->forceFill([
        'config_overlays' => [
            'OVERLAYS' => [
                [
                    'box_type' => 'feature_collection',
                    'feature_collection' => $fc->id,
                ],
            ],
        ],
    ])->save();

    $config = (new AppConfigService($app))->config();
    $overlay = $config['MAP']['controls']['overlays'][0];

    expect($overlay['fillColor'])->toBe(hexToRgba('#abcdef'));
    expect($overlay['strokeColor'])->toBe(hexToRgba('#abcdef'));
});

it('feature_collection overlay box does not throw when the theme primary color is a non-6-digit hex value', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => '#de1',
            ],
        ],
    ]);

    $fc = FeatureCollection::factory()->createQuietly([
        'app_id' => $app->id,
        'enabled' => true,
        'fill_color' => null,
        'stroke_color' => null,
    ]);

    $app->forceFill([
        'config_overlays' => [
            'OVERLAYS' => [
                [
                    'box_type' => 'feature_collection',
                    'feature_collection' => $fc->id,
                ],
            ],
        ],
    ])->save();

    $config = (new AppConfigService($app))->config();
    $overlay = $config['MAP']['controls']['overlays'][0];

    expect($overlay['fillColor'])->toBe(hexToRgba('#000000'));
    expect($overlay['strokeColor'])->toBe(hexToRgba('#000000'));
});

it('feature_collection overlay box does not throw when its own fill/stroke color is a malformed hex value', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => '#abcdef',
            ],
        ],
    ]);

    $fc = FeatureCollection::factory()->createQuietly([
        'app_id' => $app->id,
        'enabled' => true,
        'fill_color' => '#fff',
        'stroke_color' => 'red',
    ]);

    $app->forceFill([
        'config_overlays' => [
            'OVERLAYS' => [
                [
                    'box_type' => 'feature_collection',
                    'feature_collection' => $fc->id,
                ],
            ],
        ],
    ])->save();

    $config = (new AppConfigService($app))->config();
    $overlay = $config['MAP']['controls']['overlays'][0];

    expect($overlay['fillColor'])->toBe(hexToRgba('#abcdef'));
    expect($overlay['strokeColor'])->toBe(hexToRgba('#abcdef'));
});

it('feature_collection overlay box uses its own valid fill/stroke color instead of the app primary color', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => '#abcdef',
            ],
        ],
    ]);

    $fc = FeatureCollection::factory()->createQuietly([
        'app_id' => $app->id,
        'enabled' => true,
        'fill_color' => '#123456',
        'stroke_color' => '#654321',
    ]);

    $app->forceFill([
        'config_overlays' => [
            'OVERLAYS' => [
                [
                    'box_type' => 'feature_collection',
                    'feature_collection' => $fc->id,
                ],
            ],
        ],
    ])->save();

    $config = (new AppConfigService($app))->config();
    $overlay = $config['MAP']['controls']['overlays'][0];

    expect($overlay['fillColor'])->toBe(hexToRgba('#123456'));
    expect($overlay['strokeColor'])->toBe(hexToRgba('#654321'));
});
```

- [ ] **Step 2: Eseguire i test per verificare che falliscano**

Run: `cd wm-package && vendor/bin/pest tests/Feature/AppConfigServiceMapFeatureCollectionColorTest.php`
Expected: FAIL — `fillColor`/`strokeColor` prodotti da `hexToRgba('#000000')` (il default hardcoded), non da `hexToRgba('#abcdef')`, perché il codice attuale legge la colonna DB morta `$this->app->primary_color` (sempre vuota/non impostata da Nova) invece di `properties->theme->primary_color`; sui test 2-4 (aggiunti in round 2) `hexToRgba()` lancia eccezione non guardata su `#de1`/`#fff`/`red`.

> **Nota post-commit sull'esecuzione reale**: come per il Task 2, `vendor/bin/pest` non è mai stato eseguibile in questo ambiente — verificato via `php artisan tinker` con logica isolata riproducente esattamente `sanitizeHexColor()` contro l'implementazione reale di `hexToRgba()` (letta riga per riga). Dettaglio in `notes.md`.

- [ ] **Step 3: Aggiungere `sanitizeHexColor()` a `src/helpers.php`**

```php
if (! function_exists('sanitizeHexColor')) {
    /**
     * Return $value if it's an exact 6-digit hex color (e.g. "#ff0000"), otherwise $fallback.
     * Use before passing a color to hexToRgba(), which throws on any string containing "#"
     * that isn't exactly 6 or 8 hex digits long (e.g. free-text Nova fields, 3-digit CSS
     * shorthand, or any other unvalidated source).
     *
     * @param  mixed  $value
     */
    function sanitizeHexColor($value, string $fallback): string
    {
        if (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            return $value;
        }

        return $fallback;
    }
}
```

- [ ] **Step 4: Correggere `config_section_map()`**

In `src/Services/Models/App/AppConfigService.php`, sostituire (riga ~471, prima del loop `foreach ($overlayItems as $item)`):

```php
                    $primaryColor = $this->app->primary_color ?? '#000000';
```

con (fuori dal loop, calcolato una sola volta):

```php
            $primaryColor = sanitizeHexColor($this->app->properties['theme']['primary_color'] ?? null, '#000000');
```

e sostituire (dentro il loop, righe ~502-503):

```php
                    $array['fillColor'] = $fc->fill_color ? hexToRgba($fc->fill_color) : hexToRgba($primaryColor);
                    $array['strokeColor'] = $fc->stroke_color ? hexToRgba($fc->stroke_color) : hexToRgba($primaryColor);
```

con:

```php
                    $array['fillColor'] = hexToRgba(sanitizeHexColor($fc->fill_color, $primaryColor));
                    $array['strokeColor'] = hexToRgba(sanitizeHexColor($fc->stroke_color, $primaryColor));
```

- [ ] **Step 5: Aggiornare `StoryShareImageService::resolveAccentColor()`**

In `src/Services/Models/StoryShare/StoryShareImageService.php`, sostituire il metodo e il suo docblock:

```php
    /**
     * Per-app accent color for the map card border + stats value text/gradient — reads the
     * SAME primary color the app's own UI theme uses (Nova `properties->theme->primary_color`,
     * a native Color field also exposed to the frontend as `config.json` -> `THEME.primary`,
     * see AppConfigService::config_section_theme()), so this feature automatically matches
     * whatever brand color each tenant has already configured instead of hardcoding one app's
     * color into shared package code. Falls back to white when unset/malformed (via the shared
     * sanitizeHexColor() helper, src/helpers.php — same validation used by
     * AppConfigService::config_section_map()'s overlay color fallback), which reads fine
     * against both the dark FALLBACK_BACKGROUND_COLOR and most uploaded share_frame designs.
     */
    private function resolveAccentColor(App $app): string
    {
        return sanitizeHexColor($app->properties['theme']['primary_color'] ?? null, StoryImageLayout::DEFAULT_ACCENT_COLOR);
    }
```

- [ ] **Step 6: Eseguire i test per verificare che passino**

Run: `cd wm-package && vendor/bin/pest tests/Feature/AppConfigServiceMapFeatureCollectionColorTest.php`
Expected: PASS — tutti e 4 i test verdi.

- [ ] **Step 7: Eseguire la suite completa del package per verificare l'assenza di regressioni**

Run: `cd wm-package && vendor/bin/pest`
Expected: PASS — nessuna regressione sull'intera suite (incluso `AppConfigServiceOverlaysTest.php`, che testa altri rami dello stesso metodo `config_section_map()`, e qualsiasi test che eserciti `StoryShareImageService`).

- [ ] **Step 8: Commit**

```bash
git add tests/Feature/AppConfigServiceMapFeatureCollectionColorTest.php src/helpers.php src/Services/Models/App/AppConfigService.php src/Services/Models/StoryShare/StoryShareImageService.php
git commit -m "fix(oc:8367): guard hexToRgba() on all overlay colors, dedupe hex validation"
```

---

## Task 4: Verifica manuale su camminiditalia + `notes.md`

**Files:**
- Create: `docs/features/8367-theme-colori-app-configurazione-da-backend/notes.md`

**Interfaces:**
- Consumes: risultato di Task 1-3 (nessuna interfaccia di codice, solo verifica e documentazione).
- Produces: nessuna — task di chiusura del ciclo.

- [ ] **Step 1: Verificare via tinker che `config()['THEME']` rifletta i valori reali salvati su camminiditalia**

Run: `docker exec php-camminiditalia php artisan tinker --execute="\$app = \Wm\WmPackage\Models\App::first(); echo json_encode((new \Wm\WmPackage\Services\Models\App\AppConfigService(\$app))->config()['THEME']);"`

Expected output al momento di questo step (dato reale osservato in sessione, `primary_color`/`default_feature_color` = `#ef7821`, font family entrambi null): `{"primary":"#ef7821","defaultFeatureColor":"#ef7821"}` — chiavi camelCase, nessuna chiave con valore null.

> **Aggiornato post-round-3**: dopo la rimozione di `default_feature_color` da `THEME_KEY_MAP` (vedi Task 1/2), la chiave `defaultFeatureColor` non compare più nell'output di questo comando, qualunque sia lo stato reale dell'App al momento in cui lo si esegue (il valore resta come dato orfano in `properties->theme->*`, mai rimosso dal DB, semplicemente non più letto). Non fare affidamento sull'esatto output riportato sopra: verificato in sessione che l'App reale di camminiditalia ha nel frattempo anche `secondary`/`tertiary` impostati da fonte esterna a questo ciclo — l'unica garanzia stabile è l'assenza di `defaultFeatureColor`.

- [ ] **Step 2: Verificare via tinker che salvare un nuovo colore da Nova (simulato) si propaghi correttamente**

Run:
```
docker exec php-camminiditalia php artisan tinker --execute="
\$app = \Wm\WmPackage\Models\App::first();
\$props = \$app->properties;
\$props['theme']['secondary_color'] = '#00ff00';
\$app->properties = \$props;
\$app->save();
echo json_encode((new \Wm\WmPackage\Services\Models\App\AppConfigService(\$app))->config()['THEME']);
"
```

Expected: l'output include `"secondary":"#00ff00"` insieme alle chiavi già presenti — verifica che il campo Nova appena aggiunto (Task 1) e il mapping (Task 2) siano coerenti end-to-end lato backend.

- [ ] **Step 3: Ripristinare lo stato originale (rimuovere `secondary_color` di test)**

Run:
```
docker exec php-camminiditalia php artisan tinker --execute="
\$app = \Wm\WmPackage\Models\App::first();
\$props = \$app->properties;
unset(\$props['theme']['secondary_color']);
\$app->properties = \$props;
\$app->save();
echo 'ripristinato';
"
```

Expected: `ripristinato` — nessun dato di test residuo sull'App reale.

- [ ] **Step 4: Scrivere `notes.md`**

> **Superato dagli eventi — vedi il file reale**: questo step prevedeva di creare `notes.md` con un contenuto iniziale minimale (deviazioni, bug trovati, decisioni, follow-up). Il file `docs/features/8367-theme-colori-app-configurazione-da-backend/notes.md` è stato effettivamente creato con quel contenuto e poi **ampliato più volte** nel corso dello stesso ciclo (round 2 di review, fix del bloccante `hexToRgba()`, decisione di rimozione di `success`/`warning`/`danger`) — riportare qui una copia statica del template originale lo renderebbe immediatamente disallineato. Il file reale è la fonte di verità, non questa sezione del piano.

- [ ] **Step 5: Commit**

```bash
git add docs/features/8367-theme-colori-app-configurazione-da-backend/notes.md
git commit -m "docs(oc:8367): add operational notes and follow-ups for theme colors fix"
```
