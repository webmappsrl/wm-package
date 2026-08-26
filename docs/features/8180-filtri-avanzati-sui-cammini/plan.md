> Ticket: oc:8180

# Filtri avanzati sui cammini — Piano wm-package

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **⚠️ NESSUN commit e NESSUN branch automatico.** I comandi `git` presenti sono istruzioni testuali per il developer: non eseguirli. La fase di commit è gestita dal developer dopo la review del diff.

**Goal:** Aggiungere a `wm-package` due enum backed traducibili (`OsmWalkingNetwork`, `Season`) riusabili da qualunque shard Webmapp, senza toccare nient'altro nel package.

**Architecture:** Due enum PHP backed su string, con `label()` tradotta via `__()` e `toArray()` statico pronto per `Select::options()` — stesso stile dell'unico enum esistente (`ExportFormat`). Le chiavi di traduzione vanno in `resources/lang/it.json` e `resources/lang/en.json` (path reali del package: `lang/` non è caricato).

**Tech Stack:** PHP 8.1+ backed enum, traduzioni JSON Laravel.

**Spec:** `wm-package/docs/features/8180-filtri-avanzati-sui-cammini/overview.md`

## Global Constraints

- PHP minimo supportato dal package: `>8.1` — **mai** dichiarare `const` dentro un trait (supportate solo da 8.2). Vincolo già documentato in `wm-package/CLAUDE.md`.
- Valori dei case in **minuscolo**, identici al tag OSM reale (`network=lwn|rwn|nwn|iwn`): un valore maiuscolo richiederebbe un mapping quando in futuro si leggerà il tag automaticamente.
- Traduzioni **solo it/en** (decisione esplicita dell'overview). Le altre lingue del progetto (fr/es/de) mostreranno la chiave grezza.
- I test di questi enum vanno nel **repo principale** (`camminiditalia/tests/Unit/`), non in `wm-package/tests/`: `Wm\WmPackage\Tests\TestCase` non è in `autoload-dev` di camminiditalia e i test del package non possono referenziare `Tests\TestCase` del repo principale (precedente: oc:8140).
- **Nessuna modifica** a `GeometryComputationService`, `UpdateModelWithGeometryTaxonomyWhere`, o a qualunque altro file del package oltre a quelli elencati nei task.

---

### Task 1: Enum `OsmWalkingNetwork`

**Files:**
- Create: `src/Enums/OsmWalkingNetwork.php`
- Modify: `resources/lang/it.json`, `resources/lang/en.json`
- Test: nel repo principale — `camminiditalia/tests/Unit/Enums/OsmWalkingNetworkTest.php`

**Interfaces:**
- Consumes: nulla
- Produces: `Wm\WmPackage\Enums\OsmWalkingNetwork` con case `LWN|RWN|NWN|IWN` (valori `'lwn'|'rwn'|'nwn'|'iwn'`), metodi `label(): string` e `static toArray(): array` (mappa `value => label`)

- [ ] **Step 1: Scrivere il test che falisce**

In `camminiditalia/tests/Unit/Enums/OsmWalkingNetworkTest.php`:

```php
<?php

namespace Tests\Unit\Enums;

use Tests\TestCase;
use Wm\WmPackage\Enums\OsmWalkingNetwork;

class OsmWalkingNetworkTest extends TestCase
{
    public function test_case_values_match_osm_tag_vocabulary(): void
    {
        $this->assertSame('lwn', OsmWalkingNetwork::LWN->value);
        $this->assertSame('rwn', OsmWalkingNetwork::RWN->value);
        $this->assertSame('nwn', OsmWalkingNetwork::NWN->value);
        $this->assertSame('iwn', OsmWalkingNetwork::IWN->value);
    }

    public function test_to_array_returns_value_label_map_for_select_options(): void
    {
        $options = OsmWalkingNetwork::toArray();

        $this->assertSame(['lwn', 'rwn', 'nwn', 'iwn'], array_keys($options));
        foreach ($options as $value => $label) {
            $this->assertIsString($label);
            $this->assertNotSame('', $label, "Label vuota per il valore {$value}");
        }
    }

    public function test_try_from_returns_null_on_unknown_legacy_value(): void
    {
        $this->assertNull(OsmWalkingNetwork::tryFrom('lwn_legacy'));
    }
}
```

- [ ] **Step 2: Eseguire il test per verificare che falisca**

Run: `docker exec laravel-camminiditalia php artisan test tests/Unit/Enums/OsmWalkingNetworkTest.php`
Expected: FAIL con `Class "Wm\WmPackage\Enums\OsmWalkingNetwork" not found`

- [ ] **Step 3: Creare l'enum**

In `wm-package/src/Enums/OsmWalkingNetwork.php`:

```php
<?php

namespace Wm\WmPackage\Enums;

/**
 * Scala della rete escursionistica a cui un percorso appartiene.
 *
 * I valori replicano il vocabolario del tag OpenStreetMap `network=*`
 * per le reti pedonali (`lwn`/`rwn`/`nwn`/`iwn` = local/regional/national/
 * international walking network). Il nome della classe espone
 * esplicitamente la provenienza OSM per non essere confuso con la rete
 * dati in contesto mobile app; la semantica di prodotto ("portata" del
 * percorso) è veicolata dalle label tradotte restituite da label().
 *
 * Il valore è scelto manualmente dall'utente: nessuna lettura automatica
 * del tag OSM è implementata.
 */
enum OsmWalkingNetwork: string
{
    case LWN = 'lwn';
    case RWN = 'rwn';
    case NWN = 'nwn';
    case IWN = 'iwn';

    public function label(): string
    {
        return match ($this) {
            self::LWN => __('Local walking network'),
            self::RWN => __('Regional walking network'),
            self::NWN => __('National walking network'),
            self::IWN => __('International walking network'),
        };
    }

    /**
     * @return array<string, string> value => label, pronto per Select::options()
     */
    public static function toArray(): array
    {
        return array_reduce(self::cases(), function (array $carry, OsmWalkingNetwork $network) {
            $carry[$network->value] = $network->label();

            return $carry;
        }, []);
    }
}
```

- [ ] **Step 4: Aggiungere le traduzioni**

In `wm-package/resources/lang/it.json`, aggiungere (mantenendo l'ordinamento del file se presente):

```json
"Local walking network": "Rete locale",
"Regional walking network": "Rete regionale",
"National walking network": "Rete nazionale",
"International walking network": "Rete internazionale"
```

In `wm-package/resources/lang/en.json`:

```json
"Local walking network": "Local",
"Regional walking network": "Regional",
"National walking network": "National",
"International walking network": "International"
```

- [ ] **Step 5: Eseguire il test per verificare che passi**

Run: `docker exec laravel-camminiditalia php artisan test tests/Unit/Enums/OsmWalkingNetworkTest.php`
Expected: PASS (3 test)

- [ ] **Step 6: Verificare che le chiavi siano effettivamente caricate**

Run:
```bash
docker exec laravel-camminiditalia php artisan tinker --execute="app()->setLocale('it'); echo \Wm\WmPackage\Enums\OsmWalkingNetwork::IWN->label();"
```
Expected: stampa `Rete internazionale` (non la chiave grezza `International walking network`). Se stampa la chiave, le traduzioni sono nel file sbagliato: devono stare in `resources/lang/`, non in `lang/`.

- [ ] **Step 7: Formattare**

Run: `docker exec laravel-camminiditalia composer format`
Poi controllare `git -C /Users/bongiu/Documents/camminiditalia/wm-package status` e ripristinare eventuali file riformattati fuori scope: `git -C /Users/bongiu/Documents/camminiditalia/wm-package checkout -- <file>`

- [ ] **Step 8: Commit** *(istruzione per il developer — non eseguire)*

```bash
# nel submodule
git add src/Enums/OsmWalkingNetwork.php resources/lang/it.json resources/lang/en.json
git commit -m "feat(oc:8180): add OsmWalkingNetwork enum with translatable labels"
# nel repo principale
git add tests/Unit/Enums/OsmWalkingNetworkTest.php
git commit -m "test(oc:8180): cover OsmWalkingNetwork enum contract"
```

---

### Task 2: Enum `Season`

**Files:**
- Create: `src/Enums/Season.php`
- Modify: `resources/lang/it.json`, `resources/lang/en.json`
- Test: nel repo principale — `camminiditalia/tests/Unit/Enums/SeasonTest.php`

**Interfaces:**
- Consumes: nulla
- Produces: `Wm\WmPackage\Enums\Season` con case `SPRING|SUMMER|AUTUMN|WINTER` (valori `'spring'|'summer'|'autumn'|'winter'`), metodi `label(): string` e `static toArray(): array`

- [ ] **Step 1: Scrivere il test che falisce**

In `camminiditalia/tests/Unit/Enums/SeasonTest.php`:

```php
<?php

namespace Tests\Unit\Enums;

use Tests\TestCase;
use Wm\WmPackage\Enums\Season;

class SeasonTest extends TestCase
{
    public function test_case_values_are_stable_lowercase_identifiers(): void
    {
        $this->assertSame('spring', Season::SPRING->value);
        $this->assertSame('summer', Season::SUMMER->value);
        $this->assertSame('autumn', Season::AUTUMN->value);
        $this->assertSame('winter', Season::WINTER->value);
    }

    public function test_to_array_returns_value_label_map_for_select_options(): void
    {
        $options = Season::toArray();

        $this->assertSame(['spring', 'summer', 'autumn', 'winter'], array_keys($options));
        foreach ($options as $value => $label) {
            $this->assertIsString($label);
            $this->assertNotSame('', $label, "Label vuota per il valore {$value}");
        }
    }

    public function test_try_from_returns_null_on_unknown_legacy_value(): void
    {
        $this->assertNull(Season::tryFrom('primavera'));
    }
}
```

- [ ] **Step 2: Eseguire il test per verificare che falisca**

Run: `docker exec laravel-camminiditalia php artisan test tests/Unit/Enums/SeasonTest.php`
Expected: FAIL con `Class "Wm\WmPackage\Enums\Season" not found`

- [ ] **Step 3: Creare l'enum**

In `wm-package/src/Enums/Season.php`:

```php
<?php

namespace Wm\WmPackage\Enums;

/**
 * Stagione in cui un percorso è preferibilmente percorribile.
 *
 * Vocabolario chiuso a quattro valori, scelto manualmente dall'utente
 * (nessun calcolo automatico). I valori sono identificatori stabili in
 * inglese minuscolo: la resa all'utente passa sempre da label().
 */
enum Season: string
{
    case SPRING = 'spring';
    case SUMMER = 'summer';
    case AUTUMN = 'autumn';
    case WINTER = 'winter';

    public function label(): string
    {
        return match ($this) {
            self::SPRING => __('Spring'),
            self::SUMMER => __('Summer'),
            self::AUTUMN => __('Autumn'),
            self::WINTER => __('Winter'),
        };
    }

    /**
     * @return array<string, string> value => label, pronto per Multiselect::options()
     */
    public static function toArray(): array
    {
        return array_reduce(self::cases(), function (array $carry, Season $season) {
            $carry[$season->value] = $season->label();

            return $carry;
        }, []);
    }
}
```

- [ ] **Step 4: Aggiungere le traduzioni**

In `wm-package/resources/lang/it.json`:

```json
"Spring": "Primavera",
"Summer": "Estate",
"Autumn": "Autunno",
"Winter": "Inverno"
```

In `wm-package/resources/lang/en.json`:

```json
"Spring": "Spring",
"Summer": "Summer",
"Autumn": "Autumn",
"Winter": "Winter"
```

- [ ] **Step 5: Eseguire il test per verificare che passi**

Run: `docker exec laravel-camminiditalia php artisan test tests/Unit/Enums/SeasonTest.php`
Expected: PASS (3 test)

- [ ] **Step 6: Verificare il caricamento delle traduzioni**

Run:
```bash
docker exec laravel-camminiditalia php artisan tinker --execute="app()->setLocale('it'); echo \Wm\WmPackage\Enums\Season::AUTUMN->label();"
```
Expected: stampa `Autunno`

- [ ] **Step 7: Formattare**

Run: `docker exec laravel-camminiditalia composer format`
Controllare il `git status` del submodule e ripristinare i file fuori scope.

- [ ] **Step 8: Commit** *(istruzione per il developer — non eseguire)*

```bash
# nel submodule
git add src/Enums/Season.php resources/lang/it.json resources/lang/en.json
git commit -m "feat(oc:8180): add Season enum with translatable labels"
# nel repo principale
git add tests/Unit/Enums/SeasonTest.php
git commit -m "test(oc:8180): cover Season enum contract"
```
