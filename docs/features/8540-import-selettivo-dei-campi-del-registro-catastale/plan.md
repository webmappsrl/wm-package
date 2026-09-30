> Ticket: oc:8540

# Chiave `via` del sentiero — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** aggiungere a `EcTrack.properties` la chiave `via` (meta intermedia), nel DTO e nel pannello «Proprietà» di Nova.

**Architecture:** un parametro in più nel DTO `EcTrackPropertiesData`, in fondo al costruttore; una voce in più nello schema `config/wm-ec-track-schema.php`, che `PropertiesPanel` legge da sé.

**Tech Stack:** PHP 8.4, Laravel 12, Nova 5, Pest.

**Spec:** `docs/features/8540-import-selettivo-dei-campi-del-registro-catastale/overview.md`

## Global Constraints

- **Nessun `git commit`, `git add`, `git push` né branch creati in autonomia**: i commit sotto sono istruzioni per il dev.
- Commenti e documentazione in italiano, termini tecnici in inglese.
- La suite del package usa il database `wm_package` (`phpunit.xml.dist`), non il DB di Forestas.
- `via` va come **ultimo** parametro del costruttore: uno shard che passa gli argomenti per posizione non deve rompersi.
- Etichette: `it` «meta intermedia», `en` «Via».

## Review Focus

- `toArray()` con `via` nulla non deve emettere la chiave: un import che non la conosce non deve cancellare quella già salvata (l'`array_merge` degli shard conserva le chiavi assenti).
- Uno shard che estende il DTO chiamando `parent::__construct()` con argomenti nominati non deve cambiare comportamento (Forestas: `app/Dto/Import/TrackPropertiesData.php`).
- La voce `via` deve stare fra `from` e `to`, non in coda allo schema.

---

### Task 1: `via` nel DTO e nello schema

**Files:**
- Modify: `src/Dto/EcTrackPropertiesData.php:30-41`
- Modify: `config/wm-ec-track-schema.php:40` (dopo la voce `from`)
- Create: `tests/Unit/Dto/EcTrackPropertiesDataTest.php`
- Create: `tests/Unit/Config/EcTrackSchemaTest.php`

**Interfaces:**
- Produces: `EcTrackPropertiesData::__construct(..., ?string $ref = null, ?string $via = null)`; chiave `properties.via` (stringa non traducibile).

- [ ] **Step 1: Scrivi i test che falliscono**

`tests/Unit/Dto/EcTrackPropertiesDataTest.php`:

```php
<?php

declare(strict_types=1);

use Wm\WmPackage\Dto\EcTrackPropertiesData;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

it('omette via quando è nulla, così un import non cancella quella salvata', function () {
    expect((new EcTrackPropertiesData(from: 'A', to: 'B'))->toArray())
        ->toBe(['from' => 'A', 'to' => 'B']);
});

it('emette via quando è valorizzata', function () {
    expect((new EcTrackPropertiesData(from: 'A', to: 'B', via: 'Iscacari'))->toArray())
        ->toMatchArray(['from' => 'A', 'via' => 'Iscacari', 'to' => 'B']);
});

it('via è l\'ultimo parametro: gli argomenti posizionali esistenti non si spostano', function () {
    $dto = new EcTrackPropertiesData(null, null, null, null, null, 'A', 'B', 'REF');

    expect($dto->from)->toBe('A')->and($dto->to)->toBe('B')->and($dto->ref)->toBe('REF')->and($dto->via)->toBeNull();
});
```

`tests/Unit/Config/EcTrackSchemaTest.php`:

```php
<?php

declare(strict_types=1);

use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

it('lo schema ha via fra from e to, testo non traducibile', function () {
    $names = array_column(config('wm-ec-track-schema.properties.fields'), 'name');
    $via = collect(config('wm-ec-track-schema.properties.fields'))->firstWhere('name', 'via');

    expect(array_search('via', $names, true))->toBe(array_search('from', $names, true) + 1)
        ->and(array_search('to', $names, true))->toBe(array_search('via', $names, true) + 1)
        ->and($via['type'])->toBe('text')
        ->and($via['translatable'])->toBeFalse()
        ->and($via['label'])->toBe(['it' => 'meta intermedia', 'en' => 'Via']);
});
```

- [ ] **Step 2: Verifica che falliscano**

Prima verifica l'isolamento: il DB `wm_package` esiste e `phpunit.xml.dist` punta lì.
Run: `docker exec php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Unit/Dto/EcTrackPropertiesDataTest.php tests/Unit/Config/EcTrackSchemaTest.php"`
Expected: FAIL (`Unknown named parameter $via`, e `via` assente dallo schema).

- [ ] **Step 3: Implementa**

In `src/Dto/EcTrackPropertiesData.php`, dopo `public ?string $ref = null,`:

```php
        public ?string $ref = null,
        /** Meta intermedia del percorso, come il tag OSM `via` delle relazioni route (oc:8540). In fondo: gli shard che passano argomenti per posizione non si spostano. */
        public ?string $via = null,
```

In `config/wm-ec-track-schema.php`, fra la voce `from` e la voce `to`:

```php
            [
                'name' => 'via',
                'type' => 'text',
                'required' => false,
                'translatable' => false,
                'label' => [
                    'it' => 'meta intermedia',
                    'en' => 'Via',
                ],
            ],
```

- [ ] **Step 4: Verifica che passino**

Run: lo stesso comando dello Step 2.
Expected: PASS, 4 test.

- [ ] **Step 5: Commit (istruzione per il dev)**

```bash
git add src/Dto/EcTrackPropertiesData.php config/wm-ec-track-schema.php tests/Unit/Dto/EcTrackPropertiesDataTest.php tests/Unit/Config/EcTrackSchemaTest.php
git commit -m "feat(oc:8540): chiave via (meta intermedia) nelle properties del sentiero"
```

### Task 2: documentazione del package

**Files:**
- Modify: `docs/resources/EcTrack.md` se elenca le chiavi di `properties` (altrimenti nessuna modifica): aggiungere `via` accanto a `from`/`to`.

- [ ] **Step 1:** `grep -n "from" docs/resources/EcTrack.md`; se c'è l'elenco delle chiavi, aggiungere la riga `via` — «meta intermedia del percorso, stringa libera non traducibile (oc:8540)».
