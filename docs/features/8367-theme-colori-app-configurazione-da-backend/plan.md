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
- Produces: attributi Nova `properties->theme->{primary_color,secondary_color,tertiary_color,success_color,warning_color,danger_color,default_feature_color}` (color picker) e `properties->theme->{font_family_header,font_family_content}` (già esistenti, invariati) — questi 9 nomi di sottochiave sono quelli che il Task 2 legge in `config_section_theme()`.

- [ ] **Step 1: Sostituire `theme_tab()` con la versione estesa**

Sostituire l'intero corpo del metodo in `src/Nova/App.php` (righe 428-441):

```php
    protected function theme_tab(): array
    {
        $hexColorRule = 'regex:/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/';

        return [
            Text::make(__('Font Family Header'), 'properties->theme->font_family_header')
                ->hideFromIndex()
                ->help(__('Font family used for headings in the app theme')),
            Text::make(__('Font Family Content'), 'properties->theme->font_family_content')
                ->hideFromIndex()
                ->help(__('Font family used for body content in the app theme')),
            Color::make(__('Primary color'), 'properties->theme->primary_color')
                ->rules('nullable', $hexColorRule)
                ->hideFromIndex()
                ->help(__('Primary color for the app theme (e.g. buttons, links)')),
            Color::make(__('Secondary color'), 'properties->theme->secondary_color')
                ->rules('nullable', $hexColorRule)
                ->hideFromIndex()
                ->help(__('Secondary color for the app theme')),
            Color::make(__('Tertiary color'), 'properties->theme->tertiary_color')
                ->rules('nullable', $hexColorRule)
                ->hideFromIndex()
                ->help(__('Tertiary color for the app theme')),
            Color::make(__('Success color'), 'properties->theme->success_color')
                ->rules('nullable', $hexColorRule)
                ->hideFromIndex()
                ->help(__('Color used for success states in the app theme')),
            Color::make(__('Warning color'), 'properties->theme->warning_color')
                ->rules('nullable', $hexColorRule)
                ->hideFromIndex()
                ->help(__('Color used for warning states in the app theme')),
            Color::make(__('Danger color'), 'properties->theme->danger_color')
                ->rules('nullable', $hexColorRule)
                ->hideFromIndex()
                ->help(__('Color used for danger/error states in the app theme')),
            Color::make(__('Default feature color'), 'properties->theme->default_feature_color')
                ->rules('nullable', $hexColorRule)
                ->hideFromIndex()
                ->help(__('Default color used for map features when no specific style is set')),
        ];
    }
```

Nota: `->rules()` va applicato anche ai 2 color picker già esistenti (`primary_color`, `default_feature_color`) per coerenza — prima non avevano alcuna validazione di formato.

- [ ] **Step 2: Aggiungere le nuove label a `resources/lang/en.json`**

Aggiungere queste coppie chiave/valore (identity mapping, come le altre voci in inglese del file — inserire in un punto qualsiasi dell'oggetto JSON, l'ordine non è significativo):

```json
    "Secondary color": "Secondary color",
    "Secondary color for the app theme": "Secondary color for the app theme",
    "Tertiary color": "Tertiary color",
    "Tertiary color for the app theme": "Tertiary color for the app theme",
    "Success color": "Success color",
    "Color used for success states in the app theme": "Color used for success states in the app theme",
    "Warning color": "Warning color",
    "Color used for warning states in the app theme": "Color used for warning states in the app theme",
    "Danger color": "Danger color",
    "Color used for danger/error states in the app theme": "Color used for danger/error states in the app theme",
```

- [ ] **Step 3: Aggiungere le traduzioni italiane a `resources/lang/it.json`**

```json
    "Secondary color": "Colore secondario",
    "Secondary color for the app theme": "Colore secondario per il tema dell'app",
    "Tertiary color": "Colore terziario",
    "Tertiary color for the app theme": "Colore terziario per il tema dell'app",
    "Success color": "Colore successo",
    "Color used for success states in the app theme": "Colore usato per gli stati di successo nel tema dell'app",
    "Warning color": "Colore avviso",
    "Color used for warning states in the app theme": "Colore usato per gli stati di avviso nel tema dell'app",
    "Danger color": "Colore pericolo",
    "Color used for danger/error states in the app theme": "Colore usato per gli stati di pericolo/errore nel tema dell'app",
```

- [ ] **Step 4: Verificare che il JSON resti valido**

Run: `php -r "json_decode(file_get_contents('resources/lang/it.json'), true) === null && exit(1); echo 'OK it.json';"` (eseguito dalla root di `wm-package`)
Run: `php -r "json_decode(file_get_contents('resources/lang/en.json'), true) === null && exit(1); echo 'OK en.json';"`
Expected: entrambi stampano `OK ...json` senza errori di parsing.

- [ ] **Step 5: Commit**

```bash
git add src/Nova/App.php resources/lang/it.json resources/lang/en.json
git commit -m "feat(oc:8367): add secondary/tertiary/success/warning/danger color pickers to theme tab"
```

---

## Task 2: Riscrivere `AppConfigService::config_section_theme()` con mapping camelCase defensivo (TDD)

**Files:**
- Create: `tests/Feature/AppConfigServiceThemeTest.php`
- Modify: `src/Services/Models/App/AppConfigService.php:656-665` (metodo `config_section_theme()`)

**Interfaces:**
- Consumes: nessuna dipendenza diretta da Task 1 (i test di questo task impostano `properties->theme->*` direttamente via factory, non passano da Nova).
- Produces: `config()['THEME']` con le chiavi camelCase `primary`, `secondary`, `tertiary`, `success`, `warning`, `danger`, `defaultFeatureColor`, `fontFamilyHeader`, `fontFamilyContent` — Task 4 (verifica manuale) e la sezione "Bug trovati" di `notes.md` fanno riferimento a questi nomi esatti.

- [ ] **Step 1: Scrivere il test che fallisce — mapping esaustivo con tutte le 9 chiavi popolate**

Creare `tests/Feature/AppConfigServiceThemeTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppConfigService;

uses(TestCase::class, DatabaseTransactions::class);

it('config.json THEME contains all camelCase keys mapped from properties->theme snake_case', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => '#111111',
                'secondary_color' => '#222222',
                'tertiary_color' => '#333333',
                'success_color' => '#444444',
                'warning_color' => '#555555',
                'danger_color' => '#666666',
                'default_feature_color' => '#777777',
                'font_family_header' => 'Roboto Slab',
                'font_family_content' => 'Roboto',
            ],
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['THEME'])->toBe([
        'primary' => '#111111',
        'secondary' => '#222222',
        'tertiary' => '#333333',
        'success' => '#444444',
        'warning' => '#555555',
        'danger' => '#666666',
        'defaultFeatureColor' => '#777777',
        'fontFamilyHeader' => 'Roboto Slab',
        'fontFamilyContent' => 'Roboto',
    ]);
});

it('config.json THEME excludes keys with empty or null values', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => '#111111',
                'secondary_color' => '',
                'tertiary_color' => null,
                'font_family_header' => 'Roboto Slab',
            ],
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['THEME'])->toBe([
        'primary' => '#111111',
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

it('config.json THEME reproduces the real camminiditalia data shape (only primary and default feature color set)', function () {
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
        'defaultFeatureColor' => '#ef7821',
    ]);
});
```

- [ ] **Step 2: Eseguire i test per verificare che falliscano**

Run: `docker exec php-camminiditalia php artisan test --filter=AppConfigServiceThemeTest` (dalla root di `camminiditalia`, il comando esegue i test del submodule `wm-package` montato nel container)

Se il comando sopra non individua i test del package (dipende da come `phpunit.xml`/`composer.json` di camminiditalia includono `wm-package/tests`), eseguire in alternativa dalla root di `wm-package`:

Run: `cd wm-package && vendor/bin/pest tests/Feature/AppConfigServiceThemeTest.php`

Expected: FAIL — `config_section_theme()` produce ancora le vecchie chiavi snake_case (`primary_color`, `font_family_header`, ecc.), non quelle camelCase attese dal test (`expect($config['THEME'])->toBe([...])` fallisce per mismatch di array).

- [ ] **Step 3: Riscrivere `config_section_theme()`**

Sostituire il metodo in `src/Services/Models/App/AppConfigService.php` (righe 656-665):

```php
    private function config_section_theme(): array
    {
        $theme = $this->app->properties['theme'] ?? [];
        if (! is_array($theme)) {
            $theme = [];
        }

        $data['THEME'] = [];

        if (! empty($theme['primary_color'])) {
            $data['THEME']['primary'] = $theme['primary_color'];
        }
        if (! empty($theme['secondary_color'])) {
            $data['THEME']['secondary'] = $theme['secondary_color'];
        }
        if (! empty($theme['tertiary_color'])) {
            $data['THEME']['tertiary'] = $theme['tertiary_color'];
        }
        if (! empty($theme['success_color'])) {
            $data['THEME']['success'] = $theme['success_color'];
        }
        if (! empty($theme['warning_color'])) {
            $data['THEME']['warning'] = $theme['warning_color'];
        }
        if (! empty($theme['danger_color'])) {
            $data['THEME']['danger'] = $theme['danger_color'];
        }
        if (! empty($theme['default_feature_color'])) {
            $data['THEME']['defaultFeatureColor'] = $theme['default_feature_color'];
        }
        if (! empty($theme['font_family_header'])) {
            $data['THEME']['fontFamilyHeader'] = $theme['font_family_header'];
        }
        if (! empty($theme['font_family_content'])) {
            $data['THEME']['fontFamilyContent'] = $theme['font_family_content'];
        }

        return $data;
    }
```

Nota sulla robustezza: `$this->app->properties['theme'] ?? []` non lancia mai eccezioni anche se `properties` è `null` (comportamento nativo dell'operatore `??` su catene di array in PHP, verificato — stesso pattern già usato altrove nel file, es. riga 429 `$properties = $this->app->properties ?? [];`). Il controllo `is_array($theme)` aggiuntivo gestisce il caso in cui `properties->theme` esista ma sia un valore scalare malformato (es. una stringa), che altrimenti farebbe fallire `! empty($theme['primary_color'])` con un errore di accesso su tipo non-array.

- [ ] **Step 4: Eseguire i test per verificare che passino**

Run: `cd wm-package && vendor/bin/pest tests/Feature/AppConfigServiceThemeTest.php`
Expected: PASS — tutti i 5 test verdi.

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

**Files:**
- Create: `tests/Feature/AppConfigServiceMapFeatureCollectionColorTest.php`
- Modify: `src/Services/Models/App/AppConfigService.php:500` (dentro `config_section_map()`)
- Modify: `src/Services/Models/StoryShare/StoryShareImageService.php:106` (docblock di `resolveAccentColor()`)

**Interfaces:**
- Consumes: nessuna dipendenza diretta da Task 1/2 (il bug e il fix riguardano `properties->theme->primary_color`, stesso storage ma un consumer indipendente).
- Produces: nessuna nuova interfaccia pubblica — corregge solo il valore di fallback usato internamente da `config_section_map()`.

- [ ] **Step 1: Scrivere il test che fallisce**

Creare `tests/Feature/AppConfigServiceMapFeatureCollectionColorTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Services\Models\App\AppConfigService;

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
```

- [ ] **Step 2: Eseguire il test per verificare che fallisca**

Run: `cd wm-package && vendor/bin/pest tests/Feature/AppConfigServiceMapFeatureCollectionColorTest.php`
Expected: FAIL — `fillColor`/`strokeColor` prodotti da `hexToRgba('#000000')` (il default hardcoded), non da `hexToRgba('#abcdef')`, perché il codice attuale legge la colonna DB morta `$this->app->primary_color` (sempre vuota/non impostata da Nova) invece di `properties->theme->primary_color`.

- [ ] **Step 3: Correggere `config_section_map()`**

In `src/Services/Models/App/AppConfigService.php`, riga 500, sostituire:

```php
                    $primaryColor = $this->app->primary_color ?? '#000000';
```

con:

```php
                    $primaryColor = $this->app->properties['theme']['primary_color'] ?? '#000000';
```

- [ ] **Step 4: Aggiornare il docblock di `StoryShareImageService::resolveAccentColor()`**

In `src/Services/Models/StoryShare/StoryShareImageService.php`, riga 106, sostituire:

```php
     * a native Color field also exposed to the frontend as `config.json` -> `THEME.primary_color`,
```

con:

```php
     * a native Color field also exposed to the frontend as `config.json` -> `THEME.primary`,
```

- [ ] **Step 5: Eseguire il test per verificare che passi**

Run: `cd wm-package && vendor/bin/pest tests/Feature/AppConfigServiceMapFeatureCollectionColorTest.php`
Expected: PASS.

- [ ] **Step 6: Eseguire la suite completa del package per verificare l'assenza di regressioni**

Run: `cd wm-package && vendor/bin/pest`
Expected: PASS — nessuna regressione sull'intera suite (incluso `AppConfigServiceOverlaysTest.php`, che testa altri rami dello stesso metodo `config_section_map()`).

- [ ] **Step 7: Commit**

```bash
git add tests/Feature/AppConfigServiceMapFeatureCollectionColorTest.php src/Services/Models/App/AppConfigService.php src/Services/Models/StoryShare/StoryShareImageService.php
git commit -m "fix(oc:8367): read real theme primary color instead of dead app column in map overlay fallback"
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

Expected output (dato reale osservato in questa sessione, `primary_color`/`default_feature_color` = `#ef7821`, font family entrambi null): `{"primary":"#ef7821","defaultFeatureColor":"#ef7821"}` — chiavi camelCase, nessuna chiave con valore null.

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

Creare `docs/features/8367-theme-colori-app-configurazione-da-backend/notes.md`:

```markdown
> Ticket: oc:8367

# Notes — Theme colori app — configurazione da backend

## Deviazioni dal piano

Nessuna deviazione rispetto al piano approvato.

## Bug trovati

- **`config_section_map()` (`AppConfigService.php:500`)**: il fallback colore per i box "feature_collection" della home leggeva `$this->app->primary_color`, una colonna DB reale della tabella `apps` (definita in `create_apps_table.php.stub`, default `#de1b0d`) mai scritta da Nova — che scrive invece su `properties->theme->primary_color` (JSON). La colonna era quindi sempre al valore di default della migration, indipendentemente dal colore primario reale impostato dall'admin. Trovato durante la Fase: write-plan di questo ciclo (non nel ticket originale), corretto nello stesso ciclo su richiesta esplicita del dev — stessa causa radice del bug principale (storage reale disconnesso dal JSON `theme`).
- Le colonne DB morte (`font_family_header`, `font_family_content`, `default_feature_color`, `primary_color` sulla tabella `apps`) non sono state rimosse — restano orfane ma inerti. Nessun altro consumer trovato oltre a quello corretto in questo ciclo (verificato con grep su tutto `wm-package`).

## Decisioni

- Nessuna migrazione dati: la traduzione snake_case → camelCase avviene solo nel layer di output (`config_section_theme()`), i dati già salvati sotto `properties->theme->*` restano intatti.
- Regex di validazione color picker (`^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$`) accetta hex a 3 cifre solo per tolleranza verso valori scritti via API/tinker — il campo Nova `Color` (`<input type="color">` nativo) non produce mai un valore a 3 cifre dal picker stesso.
- Ruoli `dark`/`medium`/`light`, `select` e le 7 chiavi font-size di `ITHEME` restano fuori scope — nessun campo Nova aggiunto, restano ai default del frontend.

## Follow-up

- **Nota operativa per il deploy**: qualsiasi App che ha già `primary_color`/`default_feature_color` impostati in Nova (mai avuto effetto finora) cambierà colore visivamente al primo save dopo il deploy di questo fix — comportamento corretto e voluto, ma da comunicare ai clienti con colori già configurati, non da lasciare scoprire da soli. Stesso discorso per il fallback colore dei box "feature_collection" della home (Task 3).
- Nessun comando di backfill/resync batch di `config.json` per le app esistenti — un semplice re-save dell'App in Nova rigenera `config.json` con lo schema corretto. Basso volume (una sola App per istanza), non giustifica un command dedicato in questo ciclo.
- Staleness cache/CDN sulla pipeline `writeAppConfigOnAws()` (Nova save → S3 → CDN/fetch client) non investigata in questo ciclo — comportamento preesistente condiviso da tutte le sezioni di `config()`, non introdotto da questa feature.
- Nessuna verifica visiva end-to-end in `wm-core`/`webapp-app` in questa sessione (repo separati, non buildati/eseguiti qui) — verifica demandata al dev su un ambiente con il frontend in esecuzione.
```

- [ ] **Step 5: Commit**

```bash
git add docs/features/8367-theme-colori-app-configurazione-da-backend/notes.md
git commit -m "docs(oc:8367): add operational notes and follow-ups for theme colors fix"
```
