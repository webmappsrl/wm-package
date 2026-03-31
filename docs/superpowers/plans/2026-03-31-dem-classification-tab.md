# DEM Classification Tab — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migliorare il tab DEM di EcTrack mostrando in detail una tabella DEM | OSM | MANUAL | CURRENT VALUE per ogni campo principale, con indicatore della sorgente vincente.

**Architecture:** Trait `HasDemClassification` in wm-package con logica pura (`classifyField`) e rendering HTML (`generateFieldTable`). `AbstractGeometryResource::getDemTabFields()` aggiornato per usare il trait in detail; edit mode invariato. I 9 campi principali ottengono la tabella, i campi bike/hiking restano Text semplici.

**Tech Stack:** PHP 8.4, Laravel Nova 5, Pest (test), wm-package

---

## File Map

**Nuovi file:**
- `wm-package/src/Nova/Traits/HasDemClassification.php` — trait con `classifyField()` e `generateFieldTable()`
- `wm-package/tests/Unit/Nova/Traits/HasDemClassificationTest.php` — 8 test unitari

**File modificati:**
- `wm-package/src/Nova/AbstractGeometryResource.php` — usa trait, aggiorna `getDemTabFields()`

---

## Task 1: Trait `HasDemClassification` — logica pura

**Files:**
- Create: `wm-package/src/Nova/Traits/HasDemClassification.php`
- Test: `wm-package/tests/Unit/Nova/Traits/HasDemClassificationTest.php`

- [ ] **Step 1: Crea il file di test**

```php
<?php

namespace Tests\Unit\Nova\Traits;

use Wm\WmPackage\Nova\Traits\HasDemClassification;
use Wm\WmPackage\Tests\TestCase;

class HasDemClassificationTest extends TestCase
{
    use HasDemClassification;

    private function makeModel(
        ?array $demData,
        ?array $osmData,
        ?array $manualData,
        ?string $osmid = null
    ): object {
        return new class($demData, $osmData, $manualData, $osmid) {
            public function __construct(
                public readonly ?array $dem,
                public readonly ?array $osm,
                public readonly ?array $manual,
                public readonly ?string $osmid,
            ) {}

            public array $properties {
                get => [
                    'dem_data'    => $this->dem,
                    'osm_data'    => $this->osm,
                    'manual_data' => $this->manual,
                ];
            }
        };
    }

    /** 1. currentValue null → EMPTY */
    public function test_empty_when_all_sources_null(): void
    {
        $model = $this->makeModel([], [], [], null);
        $result = $this->classifyField($model, 'ascent');
        $this->assertSame('EMPTY', $result['indicator']);
        $this->assertNull($result['currentValue']);
    }

    /** 2. manual valorizzato → MANUAL */
    public function test_manual_wins(): void
    {
        $model = $this->makeModel(
            ['ascent' => 500],
            ['ascent' => 400],
            ['ascent' => 600],
            null
        );
        $result = $this->classifyField($model, 'ascent');
        $this->assertSame('MANUAL', $result['indicator']);
        $this->assertEquals(600, $result['currentValue']);
    }

    /** 3. manual stringa vuota, osmid valorizzato, osm valorizzato → OSM */
    public function test_osm_wins_when_manual_empty_string(): void
    {
        $model = $this->makeModel(
            ['ascent' => 500],
            ['ascent' => 400],
            ['ascent' => ''],
            'relation/123'
        );
        $result = $this->classifyField($model, 'ascent');
        $this->assertSame('OSM', $result['indicator']);
        $this->assertEquals(400, $result['currentValue']);
    }

    /** 4. osmid null con osm_data valorizzato → non OSM, usa DEM */
    public function test_osm_not_used_when_osmid_null(): void
    {
        $model = $this->makeModel(
            ['ascent' => 500],
            ['ascent' => 400],
            ['ascent' => ''],
            null
        );
        $result = $this->classifyField($model, 'ascent');
        $this->assertSame('DEM', $result['indicator']);
        $this->assertEquals(500, $result['currentValue']);
    }

    /** 5. solo dem_data valorizzato → DEM */
    public function test_dem_fallback(): void
    {
        $model = $this->makeModel(
            ['ascent' => 500],
            [],
            [],
            null
        );
        $result = $this->classifyField($model, 'ascent');
        $this->assertSame('DEM', $result['indicator']);
        $this->assertEquals(500, $result['currentValue']);
    }

    /** 6. manual stringa vuota, osmid null, dem valorizzato → DEM */
    public function test_dem_when_manual_empty_and_no_osmid(): void
    {
        $model = $this->makeModel(
            ['ascent' => 300],
            ['ascent' => 400],
            ['ascent' => ''],
            null
        );
        $result = $this->classifyField($model, 'ascent');
        $this->assertSame('DEM', $result['indicator']);
        $this->assertEquals(300, $result['currentValue']);
    }

    /** 7. properties null/malformato → nessun crash, EMPTY */
    public function test_no_crash_with_null_properties(): void
    {
        $model = $this->makeModel(null, null, null, null);
        $result = $this->classifyField($model, 'ascent');
        $this->assertSame('EMPTY', $result['indicator']);
        $this->assertNull($result['currentValue']);
    }

    /** 8. confronto loose == tra stringa e numero */
    public function test_loose_comparison_string_and_int(): void
    {
        $model = $this->makeModel(
            ['ascent' => 10],
            [],
            ['ascent' => ''],
            null
        );
        $result = $this->classifyField($model, 'ascent');
        // dem_data ha 10, manual è vuoto → DEM con valore 10
        $this->assertSame('DEM', $result['indicator']);
        $this->assertEquals(10, $result['currentValue']);
    }
}
```

Path: `wm-package/tests/Unit/Nova/Traits/HasDemClassificationTest.php`

- [ ] **Step 2: Esegui i test per verificare che falliscano**

```bash
cd /path/to/forestas
vendor/bin/pest wm-package/tests/Unit/Nova/Traits/HasDemClassificationTest.php
```

Output atteso: FAIL con "Trait not found" o "method not found"

- [ ] **Step 3: Crea il trait `HasDemClassification`**

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Traits;

trait HasDemClassification
{
    /**
     * Classifica il valore corrente di un campo in base alle sorgenti DEM/OSM/MANUAL.
     *
     * @return array{indicator: string, demValue: mixed, osmValue: mixed, manualValue: mixed, currentValue: mixed}
     */
    public function classifyField(object $model, string $field): array
    {
        $demData    = $this->safeArray($model->properties['dem_data'] ?? null);
        $osmData    = $this->safeArray($model->properties['osm_data'] ?? null);
        $manualData = $this->safeArray($model->properties['manual_data'] ?? null);

        $demValue    = $demData[$field] ?? null;
        $osmValue    = $osmData[$field] ?? null;
        $manualValue = $manualData[$field] ?? null;
        $osmid       = $model->osmid ?? null;

        $manualIsBlank = $manualValue === null || $manualValue === '';

        if (! $manualIsBlank) {
            $indicator    = 'MANUAL';
            $currentValue = $manualValue;
        } elseif ($osmid !== null && $osmValue !== null) {
            $indicator    = 'OSM';
            $currentValue = $osmValue;
        } elseif ($demValue !== null) {
            $indicator    = 'DEM';
            $currentValue = $demValue;
        } else {
            $indicator    = 'EMPTY';
            $currentValue = null;
        }

        return compact('indicator', 'demValue', 'osmValue', 'manualValue', 'currentValue');
    }

    /**
     * Genera la tabella HTML per il detail view di Nova.
     * La colonna OSM appare solo se $model->osmid non è null.
     */
    public function generateFieldTable(object $model, string $field): string
    {
        $data        = $this->classifyField($model, $field);
        $indicator   = $data['indicator'];
        $currentValue = $data['currentValue'];
        $showOsm     = ($model->osmid ?? null) !== null;

        $th = 'style="border:1px solid #ddd;padding:4px;text-align:center;white-space:nowrap;"';
        $td = 'style="border:1px solid #ddd;padding:4px;text-align:center;white-space:nowrap;"';

        $html  = '<table style="border-collapse:collapse;width:auto;min-width:400px;">';
        $html .= '<tr>';
        $html .= "<th {$th}>DEM</th>";
        if ($showOsm) {
            $html .= "<th {$th}>OSM</th>";
        }
        $html .= "<th {$th}>MANUAL</th>";
        $html .= "<th {$th}>CURRENT VALUE ({$indicator})</th>";
        $html .= '</tr><tr>';
        $html .= "<td {$td}>".(string) ($data['demValue'] ?? '').'</td>';
        if ($showOsm) {
            $html .= "<td {$td}>".(string) ($data['osmValue'] ?? '').'</td>';
        }
        $html .= "<td {$td}>".(string) ($data['manualValue'] ?? '').'</td>';
        $html .= "<td {$td}>".(string) ($currentValue ?? '').'</td>';
        $html .= '</tr></table>';

        return $html;
    }

    /**
     * Converte in modo sicuro un valore in array.
     * Se è una stringa JSON, la decodifica. Se è già un array, lo ritorna.
     * Se è null o malformato, ritorna [].
     *
     * @return array<string, mixed>
     */
    private function safeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
```

Path: `wm-package/src/Nova/Traits/HasDemClassification.php`

- [ ] **Step 4: Esegui i test per verificare che passino**

```bash
vendor/bin/pest wm-package/tests/Unit/Nova/Traits/HasDemClassificationTest.php
```

Output atteso: 8 test PASS

---

## Task 2: Aggiorna `getDemTabFields()` in `AbstractGeometryResource`

**Files:**
- Modify: `wm-package/src/Nova/AbstractGeometryResource.php`

- [ ] **Step 1: Aggiungi il trait e l'import**

In `AbstractGeometryResource.php`, aggiungi tra gli use esistenti:

```php
use Wm\WmPackage\Nova\Traits\HasDemClassification;
```

E nella class declaration, aggiungi il trait (dopo `abstract class AbstractGeometryResource extends Resource`):

```php
abstract class AbstractGeometryResource extends Resource
{
    use HasDemClassification;
```

- [ ] **Step 2: Sostituisci `getDemTabFields()`**

Sostituisci il metodo esistente con:

```php
public function getDemTabFields(): array
{
    $mainFields = [
        'ascent'            => __('Ascent'),
        'descent'           => __('Descent'),
        'distance'          => __('Distance'),
        'ele_max'           => __('Maximum Elevation'),
        'ele_min'           => __('Minimum Elevation'),
        'ele_from'          => __('Starting Point Elevation'),
        'ele_to'            => __('Ending Point Elevation'),
        'duration_forward'  => __('Duration Forward'),
        'duration_backward' => __('Duration Backward'),
    ];

    $fields = [
        Boolean::make(__('Round Trip'), 'properties->dem_data->round_trip'),
    ];

    foreach ($mainFields as $fieldKey => $label) {
        $fields[] = Text::make($label, 'properties->dem_data->'.$fieldKey)
            ->onlyOnDetail()
            ->resolveUsing(function ($value, $model) use ($fieldKey) {
                return $this->generateFieldTable($model, $fieldKey);
            })
            ->asHtml();

        $fields[] = Text::make($label, 'properties->manual_data->'.$fieldKey)
            ->onlyOnForms();
    }

    $fields[] = Text::make(__('Duration Forward (bike)'), 'properties->dem_data->duration_forward_bike');
    $fields[] = Text::make(__('Duration Backward (bike)'), 'properties->dem_data->duration_backward_bike');
    $fields[] = Text::make(__('Duration Forward (hiking)'), 'properties->dem_data->duration_forward_hiking');
    $fields[] = Text::make(__('Duration Backward (hiking)'), 'properties->dem_data->duration_backward_hiking');

    return $fields;
}
```

- [ ] **Step 3: Verifica PHP syntax**

```bash
docker exec php-forestas bash -c "cd /var/www/html/forestas && php artisan optimize 2>&1 | tail -3"
```

Output atteso: nessun errore, views compiled

- [ ] **Step 4: Test manuale**

Apri Nova → EC → Tracce → detail view di un track. Il tab DEM deve mostrare:
- `Round Trip`: checkbox normale
- I 9 campi principali: tabella DEM | (OSM se osmid presente) | MANUAL | CURRENT VALUE (indicator)
- I 4 campi bike/hiking: testo semplice

In edit mode i 9 campi principali scrivono su `properties->manual_data->*`.

---

## Note implementative

- Il metodo `classifyField` riceve `object $model` (non tipizzato su EcTrack) per rimanere riusabile da qualsiasi resource futura.
- `safeArray()` è privato al trait — gestisce JSON malformato senza crash.
- La colonna OSM nella tabella appare solo se `$model->osmid !== null`.
- Stringa vuota in `manual_data` viene ignorata e si scende alla sorgente successiva.
- Nessun commit autonomo: il codice va revisionato dall'utente prima del commit.
