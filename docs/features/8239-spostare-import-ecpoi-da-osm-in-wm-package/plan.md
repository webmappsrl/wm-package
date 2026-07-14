> Ticket: oc:8239

# Import EcPoi da OSM in wm-package — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Spostare la logica di import EcPoi da OpenStreetMap (Nova Action, servizi, DTO, comando CLI, controller/route/view, config, traduzioni, test) da Maphub a wm-package, includendo due fix di bug (`visibleAppsFor()` regressione permessi, `findExistingEcPoiByOsmid()` isolamento multi-app) individuati in Fase: challenge.

**Architecture:** Lift-and-shift 1:1 dei file da `App\*` (Maphub) a `Wm\WmPackage\*`, seguendo i pattern già consolidati nel package (Spatie Package Tools per config/comandi/route, `Wm\WmPackage\Tests\TestCase` per i test, Nova Action standalone). Nessuna nuova astrazione introdotta.

**Tech Stack:** Laravel 12, PHP 8.4, Nova 5, Pest (via `Wm\WmPackage\Tests\TestCase` / Orchestra Testbench), PostgreSQL+PostGIS.

## Global Constraints

- Namespace: tutte le classi spostate da `App\*` a `Wm\WmPackage\*` (es. `App\Services\Osm\OsmPoiImporter` → `Wm\WmPackage\Services\Osm\OsmPoiImporter`).
- Comando CLI rinominato: `wm-package:import-ec-pois-from-osm` (era `maphub:import-ec-pois-from-osm`); classe rinominata `WmImportEcPoiFromOsmCommand` per coerenza con le altre classi comando del package (`WmImportFromGeohubCommand`, `WmGeneratePBFCommand`, ecc. — tutte prefissate `Wm*Command`).
- Config rinominato: `osm-import.php` → `wm-osm-import.php`; env var rinominate `OSM_IMPORT_REQUEST_DELAY_MS` → `WM_OSM_IMPORT_REQUEST_DELAY_MS`, `OSM_IMPORT_MAX_IDS_PER_RUN` → `WM_OSM_IMPORT_MAX_IDS_PER_RUN`.
- Traduzioni: SOLO `resources/lang/{en,it}.json` (il package non ha fr/es/de per queste chiavi — decisione presa in Fase: reverse-interaction, non riaprire).
- View: il package pubblica le view senza sottocartelle e le referenzia con il namespace `wm-package::` (verificato in `RestoreDbController`, `RankingController`) — la view va in `resources/views/osm-import-report.blade.php`, referenziata come `view('wm-package::osm-import-report', [...])`.
- Test: namespace `Wm\WmPackage\Tests\TestCase`, in `wm-package/tests/Unit/` o `wm-package/tests/Feature/` a seconda che serva il DB.
- Fix 1 (regressione permessi): `visibleAppsFor()` deve usare `$user->hasRole('Administrator') || RolesAndPermissionsService::allowsUser($user)` — **non** sostituire con il solo `allowsUser()`.
- Fix 2 (isolamento multi-app): `findExistingEcPoiByOsmid()` deve filtrare esplicitamente per `app_id`.
- Nessun commit va eseguito da chi esegue questo piano in modalità autonoma senza revisione: ogni step "Commit" è testo per lo sviluppatore umano, che decide quando eseguirlo dopo review.
- Comandi test: dal container `php-maphub` (`docker exec -it php-maphub bash`), poi dentro `wm-package/`: `../vendor/bin/phpunit <path>` oppure, se il package ha il proprio `vendor/bin/pest` via Testbench, usare quello — verificare con `cat wm-package/composer.json | grep pestphp` prima del primo run (fatto in Task 1, Step 2).

---

### Task 1: Config `wm-osm-import.php` + registrazione

**Files:**
- Create: `wm-package/config/wm-osm-import.php`
- Modify: `wm-package/src/WmPackageServiceProvider.php` (aggiungere `'wm-osm-import'` all'array `hasConfigFile([...])` in `configurePackage()`)
- Test: `wm-package/tests/Unit/WmOsmImportConfigTest.php`

**Interfaces:**
- Produces: `config('wm-osm-import.request_delay_ms')` (int, default 350), `config('wm-osm-import.max_ids_per_run')` (int, default 500) — consumati da `OsmPoiImporter` in Task 5.

- [ ] **Step 1: Verificare il runner di test del package**

Run: `cd wm-package && cat composer.json | grep -A2 '"scripts"'`
Expected: presenza di uno script `test` (tipicamente `vendor/bin/testbench package:test` o `vendor/bin/phpunit`). Annotare il comando esatto da usare per tutti gli step successivi di questo piano (sostituire `<TEST_CMD>` nei comandi seguenti).

- [ ] **Step 2: Scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

it('exposes wm-osm-import config with defaults', function () {
    expect(config('wm-osm-import.request_delay_ms'))->toBe(350)
        ->and(config('wm-osm-import.max_ids_per_run'))->toBe(500);
});

it('reads request_delay_ms and max_ids_per_run from env', function () {
    config(['wm-osm-import.request_delay_ms' => 0, 'wm-osm-import.max_ids_per_run' => 10]);

    expect(config('wm-osm-import.request_delay_ms'))->toBe(0)
        ->and(config('wm-osm-import.max_ids_per_run'))->toBe(10);
});
```

Salva in `wm-package/tests/Unit/WmOsmImportConfigTest.php`.

- [ ] **Step 3: Eseguire il test e verificare che fallisca**

Run: `<TEST_CMD> tests/Unit/WmOsmImportConfigTest.php`
Expected: FAIL — `config('wm-osm-import.request_delay_ms')` restituisce `null` (file config inesistente).

- [ ] **Step 4: Creare il file di config**

```php
<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Delay between HTTP requests to the OSM API and the next one (POI import)
    |--------------------------------------------------------------------------
    |
    | Reduces burst traffic to api.openstreetmap.org. Value in milliseconds.
    | Set to 0 to disable (testing only or very low volumes).
    |
    */
    'request_delay_ms' => max(0, (int) env('WM_OSM_IMPORT_REQUEST_DELAY_MS', 350)),

    /*
    |--------------------------------------------------------------------------
    | Maximum number of OSM IDs processed per import run
    |--------------------------------------------------------------------------
    |
    | If the list exceeds this value, extra IDs are ignored and the report
    | shows how many were omitted. 0 means no limit.
    |
    */
    'max_ids_per_run' => max(0, (int) env('WM_OSM_IMPORT_MAX_IDS_PER_RUN', 500)),

];
```

Salva in `wm-package/config/wm-osm-import.php`.

- [ ] **Step 5: Registrare il config nel Service Provider**

In `wm-package/src/WmPackageServiceProvider.php`, dentro `configurePackage()`, l'array `hasConfigFile([...])` (circa riga 170-188) diventa:

```php
            ->hasConfigFile([
                'wm-package',
                'wm-filesystems',
                'wm-backup',
                'wm-media-library',
                'wm-geohub-import',
                'wm-excel-ec-import',
                'wm-elasticsearch',
                'wm-minio',
                'wm-horizon',
                'wm-form-schema',
                'wm-tab-translatable',
                'wm-layer-schema',
                'wm-ec-track-schema',
                'wm-ec-poi-schema',
                'wm-ec-from-ugc-schema',
                'wm-app-languages',
                'wm-logging',
                'wm-osm-import',
            ])
```

- [ ] **Step 6: Eseguire il test e verificare che passi**

Run: `<TEST_CMD> tests/Unit/WmOsmImportConfigTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add wm-package/config/wm-osm-import.php wm-package/src/WmPackageServiceProvider.php wm-package/tests/Unit/WmOsmImportConfigTest.php
git commit -m "feat(oc:8239): add wm-osm-import config to wm-package"
```

---

### Task 2: `ImportReport`

**Files:**
- Create: `wm-package/src/Services/Osm/ImportReport.php`
- Test: `wm-package/tests/Unit/Services/Osm/ImportReportTest.php`

**Interfaces:**
- Produces: `ImportReport` — `__construct(bool $dryRun)`, `addOutcome(array $outcome)`, `addFailure(int $osmid, string $error, string $category = 'other')`, `setTruncatedBeyondLimit(int $count)`, `outcomes(): array`, `failures(): array`, `failuresByCategory(): array`, `createdCount(): int`, `updatedCount(): int`, `failuresCount(): int`, `newTaxonomiesCount(): int`, `truncatedBeyondLimit(): int`, `const CATEGORY_LABELS`. Consumato da `OsmPoiImporter` (Task 5) e `OsmImportReportPresenter` (Task 6).

- [ ] **Step 1: Scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

use Wm\WmPackage\Services\Osm\ImportReport;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

it('aggregates outcomes and failures', function () {
    $report = new ImportReport(false);
    $report->addOutcome([
        'action' => 'created',
        'osmid' => 1,
        'ec_poi_id' => 10,
        'taxonomy_identifier' => 'amenity-bench',
        'taxonomy_created' => false,
    ]);
    $report->addFailure(2, 'skipped', 'no_tags');
    $report->setTruncatedBeyondLimit(3);

    expect($report->createdCount())->toBe(1)
        ->and($report->failuresCount())->toBe(1)
        ->and($report->truncatedBeyondLimit())->toBe(3)
        ->and($report->failuresByCategory())->toHaveKey('no_tags');
});
```

Salva in `wm-package/tests/Unit/Services/Osm/ImportReportTest.php`.

- [ ] **Step 2: Eseguire il test e verificare che fallisca**

Run: `<TEST_CMD> tests/Unit/Services/Osm/ImportReportTest.php`
Expected: FAIL con `Class "Wm\WmPackage\Services\Osm\ImportReport" not found`.

- [ ] **Step 3: Creare la classe**

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Services\Osm;

/**
 * Aggregates outcomes of a single {@see OsmPoiImporter::importNodes()} run.
 *
 * Not a readonly DTO: it is filled incrementally during iteration; state is
 * owned by the importer (mutations only through public methods).
 */
class ImportReport
{
    /** @var list<array{action: string, osmid: int, ec_poi_id: ?int, taxonomy_identifier: ?string, taxonomy_created: bool}> */
    private array $outcomes = [];

    /** @var list<array{osmid: int, error: string, category: string}> */
    private array $failures = [];

    /**
     * Human-readable error category labels (English strings passed to {@see __()}).
     * Translations live in the package `resources/lang/{en,it}.json` files.
     *
     * @var array<string, string>
     */
    public const CATEGORY_LABELS = [
        'not_found_or_invalid_osm' => 'Node not found or invalid OSM response',
        'no_tags' => 'OSM node has no tags (nothing useful to import)',
        'geometry' => 'Invalid OSM geometry',
        'storage' => 'S3/MinIO storage (wmfe disk) not configured or error after save',
        'other' => 'Other error',
    ];

    /** Count of OSM IDs not processed because {@see config('wm-osm-import.max_ids_per_run')} was exceeded. */
    private int $truncatedBeyondLimit = 0;

    public function __construct(public readonly bool $dryRun) {}

    public function setTruncatedBeyondLimit(int $count): void
    {
        $this->truncatedBeyondLimit = max(0, $count);
    }

    public function truncatedBeyondLimit(): int
    {
        return $this->truncatedBeyondLimit;
    }

    /**
     * @param  array{action: string, osmid: int, ec_poi_id: ?int, taxonomy_identifier: ?string, taxonomy_created: bool}  $outcome
     */
    public function addOutcome(array $outcome): void
    {
        $this->outcomes[] = $outcome;
    }

    public function addFailure(int $osmid, string $error, string $category = 'other'): void
    {
        $this->failures[] = [
            'osmid' => $osmid,
            'error' => $error,
            'category' => $category,
        ];
    }

    /** @return list<array{action: string, osmid: int, ec_poi_id: ?int, taxonomy_identifier: ?string, taxonomy_created: bool}> */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    /** @return list<array{osmid: int, error: string, category: string}> */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * Failure counts grouped by category (e.g. ['no_tags' => 12, 'not_found_or_invalid_osm' => 9]).
     *
     * @return array<string, int>
     */
    public function failuresByCategory(): array
    {
        $out = [];
        foreach ($this->failures as $f) {
            $out[$f['category']] = ($out[$f['category']] ?? 0) + 1;
        }

        return $out;
    }

    public function createdCount(): int
    {
        return count(array_filter($this->outcomes, static fn ($o) => $o['action'] === 'created'));
    }

    public function updatedCount(): int
    {
        return count(array_filter($this->outcomes, static fn ($o) => $o['action'] === 'updated'));
    }

    public function failuresCount(): int
    {
        return count($this->failures);
    }

    public function newTaxonomiesCount(): int
    {
        return count(array_filter($this->outcomes, static fn ($o) => $o['taxonomy_created'] === true));
    }
}
```

Salva in `wm-package/src/Services/Osm/ImportReport.php`.

- [ ] **Step 4: Eseguire il test e verificare che passi**

Run: `<TEST_CMD> tests/Unit/Services/Osm/ImportReportTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/Services/Osm/ImportReport.php wm-package/tests/Unit/Services/Osm/ImportReportTest.php
git commit -m "feat(oc:8239): move ImportReport to wm-package"
```

---

### Task 3: DTO `OsmEcPoiPropertiesData` + `OsmNodePoiData`

**Files:**
- Create: `wm-package/src/Dto/OsmEcPoiPropertiesData.php`
- Create: `wm-package/src/Dto/OsmNodePoiData.php`
- Test: `wm-package/tests/Unit/Dto/OsmNodePoiDataTest.php`

**Interfaces:**
- Consumes: `Wm\WmPackage\Dto\EcPoiPropertiesData` (già esistente nel package, nessuna modifica).
- Produces: `OsmEcPoiPropertiesData::fromOsmTags(array $tags, array $audit = []): self`, `->toArray(): array`. `OsmNodePoiData::fromOsmNode(int $osmid, array $properties, array $geometry): self`, `->toEcPoiAttributes(int $appId, ?int $userId = null): array`, `->toEcPoiProperties(): OsmEcPoiPropertiesData`, `->primaryName(): string`, `->poiTypeCompositeIdentifier(): ?string`, `::normalizeIdentifier(string $value): string`, `const POI_TYPE_TAG_KEYS`. Consumati da `OsmTaxonomyPoiTypeResolver` (Task 4) e `OsmPoiImporter` (Task 5).

- [ ] **Step 1: Scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

use Wm\WmPackage\Dto\OsmEcPoiPropertiesData;
use Wm\WmPackage\Dto\OsmNodePoiData;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

it('prefers information=guidepost over tourism=information for taxonomy key', function () {
    $dto = OsmNodePoiData::fromOsmNode(
        73_057_667_56,
        [
            'name' => 'Passo',
            'tourism' => 'information',
            'information' => 'guidepost',
        ],
        ['type' => 'Point', 'coordinates' => [10.1, 44.2]],
    );

    expect($dto->poiTypeOsmKey)->toBe('information')
        ->and($dto->poiTypeOsmValue)->toBe('guidepost')
        ->and($dto->poiTypeCompositeIdentifier())->toBe('information-guidepost');
});

it('normalizes composite identifiers for taxonomy matching', function () {
    expect(OsmNodePoiData::normalizeIdentifier('Tourism-Viewpoint'))->toBe('tourism-viewpoint')
        ->and(OsmNodePoiData::normalizeIdentifier('post_office'))->toBe('post-office');
});

it('rejects non-point geometry', function () {
    expect(fn () => OsmNodePoiData::fromOsmNode(
        1,
        ['name' => 'X'],
        ['type' => 'LineString', 'coordinates' => [[0, 0], [1, 1]]],
    ))->toThrow(\InvalidArgumentException::class);
});

it('maps OSM tags to properties payload', function () {
    $data = OsmEcPoiPropertiesData::fromOsmTags(
        [
            'name' => 'Poste',
            'amenity' => 'post_office',
            'phone' => '+390000',
            'addr:city' => 'Test City',
            'addr:street' => 'Via Roma',
            'addr:housenumber' => '1',
            'addr:postcode' => '41000',
            'opening_hours' => '24/7',
            'description:it' => 'Desc IT',
            'ref' => 'REF-1',
        ],
        ['osmid' => 4_769_303_114, 'type' => 'node', 'source_updated_at' => '2020-01-01 00:00:00', 'tags' => []],
    );

    $arr = $data->toArray();

    expect($arr['contact_phone'])->toBe('+390000')
        ->and($arr['opening_hours'])->toBe('24/7')
        ->and($arr['addr_locality'])->toBe('Test City')
        ->and($arr['addr_housenumber'])->toBe('1')
        ->and($arr['addr_complete'])->toContain('Via Roma')
        ->and($arr['addr_complete'])->toContain('41000')
        ->and($arr['description']['it'])->toBe('Desc IT')
        ->and($arr['out_source_feature_id'])->toBe('REF-1');
});
```

Salva in `wm-package/tests/Unit/Dto/OsmNodePoiDataTest.php`.

- [ ] **Step 2: Eseguire il test e verificare che fallisca**

Run: `<TEST_CMD> tests/Unit/Dto/OsmNodePoiDataTest.php`
Expected: FAIL con `Class "Wm\WmPackage\Dto\OsmNodePoiData" not found`.

- [ ] **Step 3: Creare `OsmEcPoiPropertiesData`**

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Dto;

/**
 * Project-specific DTO extending {@see EcPoiPropertiesData} with properties fields
 * exposed by the Nova `EcPoi` resource but missing on the base DTO
 * (`opening_hours`, `addr_locality`, `addr_housenumber`) plus OSM provenance.
 *
 * Shape follows wm-package conventions (see EcTrackService / HasDemClassification):
 *  - `osmid` at the top level of `properties` (dedicated field, easy SQL access)
 *  - `osm_data` at the top level of `properties`: dict with `type`, `source_updated_at`, `tags`
 *
 * Built only via {@see self::fromOsmTags()}: caller passes normalized OSM tag strings and gets
 * a clean map of only non-null values.
 */
readonly class OsmEcPoiPropertiesData extends EcPoiPropertiesData
{
    /**
     * @param  array<string, string>|null  $description
     * @param  array<string, string>|null  $excerpt
     * @param  array<string, string>|null  $related_url  Label => URL map (e.g. ['website' => 'https://...']); passed to parent for standard serialization.
     * @param  array<string, mixed>|null  $osm_data  Audit block aligned with wm-package conventions.
     * @param  int|null  $osmid  Numeric OSM node id, stored top-level for SQL compatibility.
     */
    public function __construct(
        ?array $description = null,
        ?array $excerpt = null,
        ?string $out_source_feature_id = null,
        ?string $addr_complete = null,
        ?int $capacity = null,
        ?string $contact_phone = null,
        ?string $contact_email = null,
        ?array $related_url = null,
        public ?string $opening_hours = null,
        public ?string $addr_locality = null,
        public ?string $addr_housenumber = null,
        public ?array $osm_data = null,
        public ?int $osmid = null,
    ) {
        parent::__construct(
            description: $description,
            excerpt: $excerpt,
            out_source_feature_id: $out_source_feature_id,
            addr_complete: $addr_complete,
            capacity: $capacity,
            contact_phone: $contact_phone,
            contact_email: $contact_email,
            related_url: $related_url,
        );
    }

    /**
     * Factory from OSM tags (already normalized to strings).
     *
     * @param  array<string, string>  $tags
     * @param  array<string, mixed>  $audit  Must include `osmid` (int), `type` (string), `source_updated_at` (string|null), `tags` (array).
     */
    public static function fromOsmTags(array $tags, array $audit = []): self
    {
        $capacity = self::firstNonEmpty($tags, ['capacity']);
        $capacityInt = $capacity !== null && ctype_digit(trim($capacity)) ? (int) $capacity : null;

        $osmid = isset($audit['osmid']) && is_int($audit['osmid']) ? $audit['osmid'] : null;

        $osmData = $audit !== [] ? array_filter([
            'type' => $audit['type'] ?? null,
            'source_updated_at' => $audit['source_updated_at'] ?? null,
            'tags' => $audit['tags'] ?? null,
        ], static fn ($v) => $v !== null) : null;

        return new self(
            description: self::extractTranslated($tags, 'description'),
            excerpt: self::extractTranslated($tags, 'inscription'),
            out_source_feature_id: isset($tags['ref']) && $tags['ref'] !== '' ? $tags['ref'] : null,
            addr_complete: self::buildCompleteAddress($tags),
            capacity: $capacityInt,
            contact_phone: self::firstNonEmpty($tags, ['contact:phone', 'phone']),
            contact_email: self::firstNonEmpty($tags, ['contact:email', 'email']),
            related_url: self::extractRelatedUrls($tags),
            opening_hours: self::firstNonEmpty($tags, ['opening_hours']),
            addr_locality: self::firstNonEmpty($tags, ['addr:city']),
            addr_housenumber: self::firstNonEmpty($tags, ['addr:housenumber']),
            osm_data: $osmData !== [] ? $osmData : null,
            osmid: $osmid,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $extra = array_filter([
            'osmid' => $this->osmid,
            'opening_hours' => $this->opening_hours,
            'addr_locality' => $this->addr_locality,
            'addr_housenumber' => $this->addr_housenumber,
            'osm_data' => $this->osm_data,
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);

        return array_merge(parent::toArray(), $extra);
    }

    /**
     * @param  array<string, string>  $tags
     * @param  list<string>  $keys
     */
    private static function firstNonEmpty(array $tags, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($tags[$key]) && trim($tags[$key]) !== '') {
                return trim($tags[$key]);
            }
        }

        return null;
    }

    /**
     * Combines addr:street, addr:housenumber, addr:postcode, addr:city into one string
     * such as "Via Example 12, 41023 Lama Mocogno". Returns null when nothing meaningful is present.
     *
     * @param  array<string, string>  $tags
     */
    private static function buildCompleteAddress(array $tags): ?string
    {
        $street = self::firstNonEmpty($tags, ['addr:street']);
        $housenumber = self::firstNonEmpty($tags, ['addr:housenumber']);
        $postcode = self::firstNonEmpty($tags, ['addr:postcode']);
        $city = self::firstNonEmpty($tags, ['addr:city']);

        $streetPart = trim(($street ?? '').' '.($housenumber ?? ''));
        $cityPart = trim(($postcode ?? '').' '.($city ?? ''));

        $parts = array_values(array_filter([$streetPart, $cityPart], static fn ($p) => $p !== ''));
        if ($parts === []) {
            return null;
        }

        return implode(', ', $parts);
    }

    /**
     * Extracts external links as a label => URL map compatible with Nova KeyValue.
     *
     * @param  array<string, string>  $tags
     * @return array<string, string>|null
     */
    private static function extractRelatedUrls(array $tags): ?array
    {
        $out = [];

        foreach (['website', 'contact:website', 'url'] as $key) {
            if (isset($tags[$key]) && $tags[$key] !== '' && self::isHttpUrl($tags[$key])) {
                $out['website'] = $tags[$key];
                break;
            }
        }

        if (isset($tags['wikipedia']) && $tags['wikipedia'] !== '') {
            $out['wikipedia'] = self::wikipediaUrl($tags['wikipedia']);
        }
        if (isset($tags['wikidata']) && preg_match('/^Q\d+$/', $tags['wikidata']) === 1) {
            $out['wikidata'] = 'https://www.wikidata.org/wiki/'.$tags['wikidata'];
        }

        return $out === [] ? null : $out;
    }

    private static function isHttpUrl(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }

    /**
     * Turns "en:Title" or full URLs into a canonical Wikipedia URL.
     */
    private static function wikipediaUrl(string $value): string
    {
        if (self::isHttpUrl($value)) {
            return $value;
        }
        if (preg_match('/^([a-z]{2,3}):(.+)$/', $value, $m) === 1) {
            return "https://{$m[1]}.wikipedia.org/wiki/".rawurlencode(str_replace(' ', '_', $m[2]));
        }

        return 'https://en.wikipedia.org/wiki/'.rawurlencode(str_replace(' ', '_', $value));
    }

    /**
     * Extracts translations for `description` / `excerpt` with fallback to the base tag.
     *
     * @param  array<string, string>  $tags
     * @return array<string, string>|null
     */
    private static function extractTranslated(array $tags, string $base): ?array
    {
        $out = [];

        if (isset($tags["{$base}:it"]) && $tags["{$base}:it"] !== '') {
            $out['it'] = $tags["{$base}:it"];
        }
        if (isset($tags["{$base}:en"]) && $tags["{$base}:en"] !== '') {
            $out['en'] = $tags["{$base}:en"];
        }
        if (! isset($out['it']) && isset($tags[$base]) && $tags[$base] !== '') {
            $out['it'] = $tags[$base];
        }

        return $out === [] ? null : $out;
    }
}
```

Salva in `wm-package/src/Dto/OsmEcPoiPropertiesData.php`.

- [ ] **Step 4: Creare `OsmNodePoiData`**

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Dto;

use Wm\WmPackage\Http\Clients\OsmClient;

/**
 * DTO: normalized representation of an OSM node ready to map onto {@see \Wm\WmPackage\Models\EcPoi}.
 *
 * Same pattern as {@see EcPoiPropertiesData}: readonly immutable class with a factory that parses OSM data.
 *
 * OSM tags considered for the (key, value) pair used as "POI type" are listed in {@see self::POI_TYPE_TAG_KEYS},
 * in alphabetical order: the first key present on the node wins.
 */
readonly class OsmNodePoiData
{
    /**
     * OSM tag keys used to derive TaxonomyPoiType.
     * Alphabetical order: first present key (non-empty, not "no") wins.
     *
     * @var list<string>
     */
    public const POI_TYPE_TAG_KEYS = [
        'advertising',
        'aerialway',
        'aeroway',
        'amenity',
        'attraction',
        'barrier',
        'boundary',
        'building',
        'checkpoint',
        'club',
        'craft',
        'emergency',
        'entrance',
        'ford',
        'geological',
        'healthcare',
        'highway',
        'historic',
        'information',
        'landuse',
        'leisure',
        'man_made',
        'military',
        'mountain_pass',
        'natural',
        'office',
        'place',
        'power',
        'public_transport',
        'railway',
        'route',
        'shop',
        'sport',
        'telecom',
        'tourism',
        'traffic_calming',
        'traffic_sign',
        'water',
        'waterway',
    ];

    /**
     * @param  int  $osmid  Numeric OSM node ID (without the "node/" prefix).
     * @param  array<string, string>  $nameTranslations  Locale => name (e.g. ['it' => 'Belvedere', 'en' => 'Viewpoint']).
     * @param  float  $lat  WGS84 latitude.
     * @param  float  $lng  WGS84 longitude.
     * @param  string|null  $poiTypeOsmKey  OSM key chosen as classifier (e.g. "tourism"), null if none.
     * @param  string|null  $poiTypeOsmValue  OSM value chosen (e.g. "viewpoint"), null if none.
     * @param  array<string, string>  $rawTags  Full OSM tags (for audit/debug in properties).
     * @param  string|null  $sourceUpdatedAt  "_updated_at" timestamp from OsmClient.
     */
    public function __construct(
        public int $osmid,
        public array $nameTranslations,
        public float $lat,
        public float $lng,
        public ?string $poiTypeOsmKey,
        public ?string $poiTypeOsmValue,
        public array $rawTags = [],
        public ?string $sourceUpdatedAt = null,
    ) {}

    /**
     * Factory: builds the DTO from {@see OsmClient::getPropertiesAndGeometry()} for an OSM node.
     * Strictly requires a node (GeoJSON Point geometry); ways/relations would need a separate centroid strategy
     * and are out of scope for this importer.
     *
     * @param  array<string, mixed>  $properties  Tags plus technical keys ("_updated_at", …) from OsmClient.
     * @param  array<string, mixed>  $geometry  GeoJSON Point geometry (defensively validated).
     *
     * @throws \InvalidArgumentException When geometry is not a valid Point.
     */
    public static function fromOsmNode(int $osmid, array $properties, array $geometry): self
    {
        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;
        if ($type !== 'Point' || ! is_array($coordinates) || ! isset($coordinates[0], $coordinates[1])) {
            throw new \InvalidArgumentException("OSM node {$osmid}: geometry is not a valid Point.");
        }

        $lng = (float) $coordinates[0];
        $lat = (float) $coordinates[1];

        $tags = [];
        foreach ($properties as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue;
            }
            $tags[$key] = is_scalar($value) ? (string) $value : '';
        }

        [$poiKey, $poiValue] = self::pickPoiTypeFromTags($tags);

        return new self(
            osmid: $osmid,
            nameTranslations: self::extractNameTranslations($tags),
            lat: $lat,
            lng: $lng,
            poiTypeOsmKey: $poiKey,
            poiTypeOsmValue: $poiValue,
            rawTags: $tags,
            sourceUpdatedAt: isset($properties['_updated_at']) && is_string($properties['_updated_at'])
                ? $properties['_updated_at']
                : null,
        );
    }

    /**
     * Primary display name (defaults to Italian, then first available locale, then OSM node id).
     */
    public function primaryName(): string
    {
        if (isset($this->nameTranslations['it']) && $this->nameTranslations['it'] !== '') {
            return $this->nameTranslations['it'];
        }
        $firstKey = array_key_first($this->nameTranslations);

        return $firstKey !== null ? $this->nameTranslations[$firstKey] : "OSM node {$this->osmid}";
    }

    /**
     * Composite identifier candidate for TaxonomyPoiType as "key-value" (e.g. "tourism-viewpoint").
     * Returns null when the node has no classifying tag.
     */
    public function poiTypeCompositeIdentifier(): ?string
    {
        if ($this->poiTypeOsmKey === null || $this->poiTypeOsmValue === null) {
            return null;
        }

        return self::normalizeIdentifier("{$this->poiTypeOsmKey}-{$this->poiTypeOsmValue}");
    }

    /**
     * Normalizes an identifier for comparison/persistence: lowercase, trim,
     * spaces/underscores to hyphens, strip non-alphanumeric characters (except "-").
     */
    public static function normalizeIdentifier(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = preg_replace('/[\s_]+/', '-', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9\-]/', '', $value) ?? $value;

        return trim($value, '-');
    }

    /**
     * Attributes ready for {@see \Wm\WmPackage\Models\EcPoi::create()}.
     * `name` stays translatable: set via setTranslation on the caller side.
     *
     * The `properties` block is produced by {@see OsmEcPoiPropertiesData} from OSM tags:
     * maps standard keys (contact_email, contact_phone, opening_hours, addr_*, related_url, …)
     * and keeps raw tags under `properties.osm_data.tags` for audit/debug.
     *
     * @return array<string, mixed>
     */
    public function toEcPoiAttributes(int $appId, ?int $userId = null): array
    {
        $properties = $this->toEcPoiProperties()->toArray();

        $attrs = [
            'osmid' => $this->osmid,
            'app_id' => $appId,
            'geometry' => sprintf('POINT(%F %F)', $this->lng, $this->lat),
            'name' => $this->primaryName(),
            'properties' => $properties,
        ];

        if ($userId !== null) {
            $attrs['user_id'] = $userId;
        }

        return $attrs;
    }

    /**
     * Builds the properties DTO from OSM tags.
     * Resulting shape follows wm-package conventions:
     *  - `properties.osmid` → numeric top-level id (SQL-friendly / EcTrackService)
     *  - `properties.osm_data` → audit block with `type`, `source_updated_at`, `tags`
     */
    public function toEcPoiProperties(): OsmEcPoiPropertiesData
    {
        return OsmEcPoiPropertiesData::fromOsmTags(
            $this->rawTags,
            [
                'osmid' => $this->osmid,
                'type' => 'node',
                'source_updated_at' => $this->sourceUpdatedAt,
                'tags' => $this->rawTags,
            ],
        );
    }

    /**
     * Picks the (key, value) OSM pair used as classifier.
     *
     * @param  array<string, string>  $tags
     * @return array{0: ?string, 1: ?string}
     */
    private static function pickPoiTypeFromTags(array $tags): array
    {
        foreach (self::POI_TYPE_TAG_KEYS as $key) {
            if (isset($tags[$key]) && $tags[$key] !== '' && $tags[$key] !== 'no') {
                return [$key, $tags[$key]];
            }
        }

        return [null, null];
    }

    /**
     * Extracts name translations from OSM tags (`name`, `name:it`, `name:en`, …).
     * If OSM provides no `name*`, returns an empty array: taxonomy name fallback is the caller's job.
     *
     * @param  array<string, string>  $tags
     * @return array<string, string>
     */
    private static function extractNameTranslations(array $tags): array
    {
        $translations = [];

        if (isset($tags['name:it']) && $tags['name:it'] !== '') {
            $translations['it'] = $tags['name:it'];
        }
        if (isset($tags['name:en']) && $tags['name:en'] !== '') {
            $translations['en'] = $tags['name:en'];
        }

        if (! isset($translations['it']) && isset($tags['name']) && $tags['name'] !== '') {
            $translations['it'] = $tags['name'];
        }

        return $translations;
    }
}
```

Salva in `wm-package/src/Dto/OsmNodePoiData.php`.

- [ ] **Step 5: Eseguire il test e verificare che passi**

Run: `<TEST_CMD> tests/Unit/Dto/OsmNodePoiDataTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add wm-package/src/Dto/OsmEcPoiPropertiesData.php wm-package/src/Dto/OsmNodePoiData.php wm-package/tests/Unit/Dto/OsmNodePoiDataTest.php
git commit -m "feat(oc:8239): move OSM DTOs to wm-package"
```

---

### Task 4: `OsmTaxonomyPoiTypeResolver`

**Files:**
- Create: `wm-package/src/Services/Osm/OsmTaxonomyPoiTypeResolver.php`
- Test: `wm-package/tests/Feature/Services/Osm/OsmTaxonomyPoiTypeResolverTest.php` (Feature, non Unit: scrive su `TaxonomyPoiType` reale, serve il DB)

**Interfaces:**
- Consumes: `Wm\WmPackage\Dto\OsmNodePoiData` (Task 3), `Wm\WmPackage\Models\TaxonomyPoiType` (esistente).
- Produces: `OsmTaxonomyPoiTypeResolver::resolve(OsmNodePoiData $data, bool $dryRun = false): array{taxonomy: TaxonomyPoiType, created: bool}`. Consumato da `OsmPoiImporter` (Task 5).

- [ ] **Step 1: Scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

use Wm\WmPackage\Dto\OsmNodePoiData;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Services\Osm\OsmTaxonomyPoiTypeResolver;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

it('creates a new TaxonomyPoiType from an unknown OSM tag pair', function () {
    $dto = OsmNodePoiData::fromOsmNode(
        1,
        ['name' => 'Test', 'amenity' => 'drinking_water'],
        ['type' => 'Point', 'coordinates' => [10.0, 44.0]],
    );

    $resolver = new OsmTaxonomyPoiTypeResolver;
    $result = $resolver->resolve($dto);

    expect($result['created'])->toBeTrue()
        ->and($result['taxonomy'])->toBeInstanceOf(TaxonomyPoiType::class)
        ->and($result['taxonomy']->identifier)->toBe('amenity-drinking-water');

    expect(TaxonomyPoiType::query()->where('identifier', 'amenity-drinking-water')->exists())->toBeTrue();
});

it('reuses an existing TaxonomyPoiType by identifier without creating a duplicate', function () {
    TaxonomyPoiType::query()->create(['identifier' => 'amenity-bench']);

    $dto = OsmNodePoiData::fromOsmNode(
        2,
        ['name' => 'Test', 'amenity' => 'bench'],
        ['type' => 'Point', 'coordinates' => [10.0, 44.0]],
    );

    $resolver = new OsmTaxonomyPoiTypeResolver;
    $result = $resolver->resolve($dto);

    expect($result['created'])->toBeFalse()
        ->and(TaxonomyPoiType::query()->where('identifier', 'amenity-bench')->count())->toBe(1);
});

it('falls back to the generic poi identifier when no classifying tag is present', function () {
    $dto = OsmNodePoiData::fromOsmNode(
        3,
        ['name' => 'Unclassified'],
        ['type' => 'Point', 'coordinates' => [10.0, 44.0]],
    );

    $resolver = new OsmTaxonomyPoiTypeResolver;
    $result = $resolver->resolve($dto);

    expect($result['taxonomy']->identifier)->toBe('poi');
});
```

Salva in `wm-package/tests/Feature/Services/Osm/OsmTaxonomyPoiTypeResolverTest.php`.

- [ ] **Step 2: Eseguire il test e verificare che fallisca**

Run: `<TEST_CMD> tests/Feature/Services/Osm/OsmTaxonomyPoiTypeResolverTest.php`
Expected: FAIL con `Class "Wm\WmPackage\Services\Osm\OsmTaxonomyPoiTypeResolver" not found`.

- [ ] **Step 3: Creare la classe**

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Services\Osm;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wm\WmPackage\Dto\OsmNodePoiData;
use Wm\WmPackage\Models\TaxonomyPoiType;

/**
 * Resolves a {@see TaxonomyPoiType} from the (OSM key, OSM value) pair derived from {@see OsmNodePoiData}.
 *
 * Strategy (in order):
 *  0. No qualifying tag among known keys → fallback to {@see TaxonomyPoiType} with identifier `poi`
 *     (existing row, or created on first real import if missing).
 *  1. Exact match on `identifier` using normalized OSM value (e.g. "viewpoint").
 *  2. Exact match on composite identifier "key-value" (e.g. "tourism-viewpoint").
 *  3. Case-insensitive match with trim (covers legacy identifiers with different casing).
 *  4. Create a new TaxonomyPoiType with identifier "key-value" and titlecased {it,en} names.
 *
 * All queries use the indexed/unique `identifier` column (see `create_taxonomy_poi_types_table` migration).
 */
class OsmTaxonomyPoiTypeResolver
{
    /**
     * In-memory cache to avoid repeated queries during a single import batch.
     *
     * @var array<string, TaxonomyPoiType|null>
     */
    private array $cache = [];

    /**
     * Returns an existing TaxonomyPoiType or creates a new one.
     * In dry-run mode, returns the existing instance or a non-persisted instance so the caller
     * can see which identifier would be created.
     *
     * @return array{taxonomy: TaxonomyPoiType, created: bool}
     */
    public function resolve(OsmNodePoiData $data, bool $dryRun = false): array
    {
        if ($data->poiTypeOsmKey === null || $data->poiTypeOsmValue === null) {
            $fallback = $this->fallbackGenericPoi($dryRun);

            return [
                'taxonomy' => $fallback['taxonomy'],
                'created' => $fallback['created'],
            ];
        }

        $valueIdentifier = OsmNodePoiData::normalizeIdentifier($data->poiTypeOsmValue);
        $compositeIdentifier = $data->poiTypeCompositeIdentifier() ?? $valueIdentifier;

        if ($existing = $this->findByIdentifier($valueIdentifier)) {
            return ['taxonomy' => $existing, 'created' => false];
        }

        if ($existing = $this->findByIdentifier($compositeIdentifier)) {
            return ['taxonomy' => $existing, 'created' => false];
        }

        if ($dryRun) {
            return [
                'taxonomy' => $this->buildUnsaved($compositeIdentifier, $data->poiTypeOsmValue, $data->nameTranslations),
                'created' => true,
            ];
        }

        return [
            'taxonomy' => $this->createTaxonomy($compositeIdentifier, $data->poiTypeOsmValue, $data->nameTranslations),
            'created' => true,
        ];
    }

    /**
     * Lookup by identifier with legacy support: exact match first, then case-insensitive trim.
     */
    private function findByIdentifier(string $identifier): ?TaxonomyPoiType
    {
        if ($identifier === '') {
            return null;
        }
        if (array_key_exists($identifier, $this->cache)) {
            return $this->cache[$identifier];
        }

        $match = TaxonomyPoiType::query()
            ->whereRaw('LOWER(BTRIM(identifier)) = ?', [$identifier])
            ->orderBy('id')
            ->first();

        return $this->cache[$identifier] = $match;
    }

    private function createTaxonomy(string $identifier, string $osmValue, array $nameTranslations): TaxonomyPoiType
    {
        $taxonomy = new TaxonomyPoiType;
        $this->applyNewTaxonomyAttributes($taxonomy, $identifier, $osmValue, $nameTranslations);
        $taxonomy->save();

        $this->cache[$identifier] = $taxonomy;

        return $taxonomy;
    }

    private function buildUnsaved(string $identifier, string $osmValue, array $nameTranslations): TaxonomyPoiType
    {
        $taxonomy = new TaxonomyPoiType;
        $this->applyNewTaxonomyAttributes($taxonomy, $identifier, $osmValue, $nameTranslations);

        return $taxonomy;
    }

    /**
     * @param  array<string, string>  $nameTranslations
     */
    private function applyNewTaxonomyAttributes(
        TaxonomyPoiType $taxonomy,
        string $identifier,
        string $osmValue,
        array $nameTranslations,
    ): void {
        $humanName = Str::title(str_replace(['_', '-'], ' ', $osmValue));

        $taxonomy->identifier = $identifier;

        $taxonomy->setTranslation('name', 'it', $nameTranslations['it'] ?? $humanName);
        if (isset($nameTranslations['en']) && $nameTranslations['en'] !== '') {
            $taxonomy->setTranslation('name', 'en', $nameTranslations['en']);
        } else {
            $taxonomy->setTranslation('name', 'en', $humanName);
        }
    }

    /**
     * When the OSM node has no qualifying classifying tag, always use {@see TaxonomyPoiType} with identifier `poi`.
     * If missing: created on real import; in dry-run returns a non-persisted instance with created=true.
     *
     * @return array{taxonomy: TaxonomyPoiType, created: bool}
     */
    private function fallbackGenericPoi(bool $dryRun): array
    {
        if ($existing = $this->findByIdentifier('poi')) {
            return ['taxonomy' => $existing, 'created' => false];
        }

        $taxonomy = new TaxonomyPoiType;
        $taxonomy->identifier = 'poi';
        $taxonomy->setTranslation('name', 'it', 'POI');
        $taxonomy->setTranslation('name', 'en', 'POI');

        if ($dryRun) {
            return ['taxonomy' => $taxonomy, 'created' => true];
        }

        try {
            DB::transaction(function () use ($taxonomy) {
                $taxonomy->save();
            });
            $this->cache['poi'] = $taxonomy;

            return ['taxonomy' => $taxonomy, 'created' => true];
        } catch (\Throwable) {
            // Race: another process created `poi` in the meantime.
            $existing = TaxonomyPoiType::query()->where('identifier', 'poi')->firstOrFail();
            $this->cache['poi'] = $existing;

            return ['taxonomy' => $existing, 'created' => false];
        }
    }
}
```

Salva in `wm-package/src/Services/Osm/OsmTaxonomyPoiTypeResolver.php`.

- [ ] **Step 4: Eseguire il test e verificare che passi**

Run: `<TEST_CMD> tests/Feature/Services/Osm/OsmTaxonomyPoiTypeResolverTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/Services/Osm/OsmTaxonomyPoiTypeResolver.php wm-package/tests/Feature/Services/Osm/OsmTaxonomyPoiTypeResolverTest.php
git commit -m "feat(oc:8239): move OsmTaxonomyPoiTypeResolver to wm-package"
```

---

### Task 5: `OsmPoiImporter` con fix isolamento multi-app

**Files:**
- Create: `wm-package/src/Services/Osm/OsmPoiImporter.php`
- Test: `wm-package/tests/Unit/OsmPoiImportTest.php` (porting dei test dry-run con mock)
- Test: `wm-package/tests/Feature/Services/Osm/OsmPoiImporterMultiAppIsolationTest.php` (nuovo — fix isolamento)

**Interfaces:**
- Consumes: `Wm\WmPackage\Http\Clients\OsmClient` (esistente), `Wm\WmPackage\Services\Osm\OsmTaxonomyPoiTypeResolver` (Task 4), `Wm\WmPackage\Dto\OsmNodePoiData` (Task 3), `Wm\WmPackage\Services\Osm\ImportReport` (Task 2), `config('wm-osm-import.*')` (Task 1), `Wm\WmPackage\Models\EcPoi` (esistente).
- Produces: `OsmPoiImporter::importNodes(array $osmIds, int $appId, ?int $userId = null, bool $dryRun = false, bool $global = true): ImportReport`. Consumato dalla Nova Action (Task 7) e dal comando CLI (Task 8).
- **Fix isolamento multi-app**: `findExistingEcPoiByOsmid(int $osmid, int $appId): ?EcPoi` — firma cambiata rispetto all'originale (aggiunto `$appId`), filtro esplicito `where('app_id', $appId)` su entrambe le query.

- [ ] **Step 1: Scrivere il test di isolamento multi-app che fallisce**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\Osm\OsmPoiImporter;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

it('does not let two different apps overwrite each other on the same osmid', function () {
    $appA = App::factory()->create();
    $appB = App::factory()->create();

    Http::fake([
        'api.openstreetmap.org/api/0.6/node/555000111.json' => Http::response([
            'elements' => [[
                'type' => 'node',
                'id' => 555_000_111,
                'lat' => 44.0,
                'lon' => 10.0,
                'timestamp' => '2024-01-01T00:00:00Z',
                'tags' => ['name' => 'Shared OSM node', 'amenity' => 'bench'],
            ]],
        ], 200),
    ]);

    $importer = app(OsmPoiImporter::class);

    $importer->importNodes([555_000_111], (int) $appA->id, null, false, true);
    $importer->importNodes([555_000_111], (int) $appB->id, null, false, true);

    $poiA = EcPoi::query()->where('app_id', $appA->id)->where('osmid', 555_000_111)->first();
    $poiB = EcPoi::query()->where('app_id', $appB->id)->where('osmid', 555_000_111)->first();

    expect($poiA)->not->toBeNull()
        ->and($poiB)->not->toBeNull()
        ->and($poiA->id)->not->toBe($poiB->id);
});
```

Salva in `wm-package/tests/Feature/Services/Osm/OsmPoiImporterMultiAppIsolationTest.php`.

- [ ] **Step 2: Eseguire il test e verificare che fallisca**

Run: `<TEST_CMD> tests/Feature/Services/Osm/OsmPoiImporterMultiAppIsolationTest.php`
Expected: FAIL con `Class "Wm\WmPackage\Services\Osm\OsmPoiImporter" not found`.

- [ ] **Step 3: Creare la classe con il fix applicato**

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Services\Osm;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Wm\WmPackage\Dto\OsmNodePoiData;
use Wm\WmPackage\Exceptions\OsmClientException;
use Wm\WmPackage\Exceptions\OsmClientExceptionNoTags;
use Wm\WmPackage\Http\Clients\OsmClient;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\TaxonomyPoiType;

/**
 * High-level service: given a set of OSM node IDs, fetches data from OpenStreetMap
 * (via {@see OsmClient}), normalizes it into {@see OsmNodePoiData}, and creates/updates
 * {@see EcPoi} records with the correct {@see TaxonomyPoiType}.
 * Long-form text (description, excerpt from inscription) stays in the {@see EcPoi::$properties}
 * JSON because most consumer DB schemas have no dedicated columns for those keys.
 *
 * TODO: Resolve OSM image tags (`image`, `wikimedia_commons`, …) into Spatie Media Library on
 * {@see EcPoi} (`default` collection: first file = feature image, following = gallery per
 * {@see \Wm\WmPackage\Http\Resources\EcPoiResource}), e.g. Wikimedia Commons API + `addMediaFromUrl`.
 *
 * Dry-run mode: no persistence; returns the expected outcome for interactive validation (Nova/CLI).
 */
class OsmPoiImporter
{
    public function __construct(
        private readonly OsmClient $osmClient,
        private readonly OsmTaxonomyPoiTypeResolver $taxonomyResolver,
    ) {}

    /**
     * Imports a list of OSM node IDs.
     *
     * @param  list<int|string>  $osmIds  Numeric OSM node IDs (cast to int).
     * @param  int  $appId  Destination app ID (required on `ec_pois` schema; also used to scope
     *                       existing-POI lookup so different apps never overwrite each other's data).
     * @param  int|null  $userId  Owning user ID (optional).
     * @param  bool  $dryRun  When true, nothing is persisted.
     * @param  bool  $global  When `ec_pois.global` exists: value to set (true = included in {@see \Wm\WmPackage\Models\App::getAllPoisGeojson()} / pois.geojson).
     */
    public function importNodes(array $osmIds, int $appId, ?int $userId = null, bool $dryRun = false, bool $global = true): ImportReport
    {
        $report = new ImportReport($dryRun);
        $hasGlobalColumn = Schema::hasColumn((new EcPoi)->getTable(), 'global');

        $ids = $this->normalizeIds($osmIds);
        $maxPerRun = (int) config('wm-osm-import.max_ids_per_run', 0);
        if ($maxPerRun > 0 && count($ids) > $maxPerRun) {
            $report->setTruncatedBeyondLimit(count($ids) - $maxPerRun);
            $ids = array_slice($ids, 0, $maxPerRun);
        }

        $delayMs = max(0, (int) config('wm-osm-import.request_delay_ms', 0));
        $total = count($ids);

        foreach ($ids as $index => $osmid) {
            try {
                $report->addOutcome($this->importSingleNode($osmid, $appId, $userId, $dryRun, $global, $hasGlobalColumn));
            } catch (\Throwable $e) {
                [$category, $message] = $this->classifyFailure($osmid, $e);
                Log::warning('OsmPoiImporter: failure on node '.$osmid, [
                    'category' => $category,
                    'message' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
                $report->addFailure($osmid, $message, $category);
            }

            if ($delayMs > 0 && $index < $total - 1) {
                usleep($delayMs * 1000);
            }
        }

        return $report;
    }

    /**
     * Maps an exception (including TypeError from non-JSON OSM responses) to a
     * (category, human-readable message) pair for the report.
     *
     * @return array{0: string, 1: string}
     */
    private function classifyFailure(int $osmid, \Throwable $e): array
    {
        if ($e instanceof OsmClientExceptionNoTags) {
            return ['no_tags', "node/{$osmid}: no tags on OpenStreetMap; nothing to import."];
        }
        $message = $e->getMessage();
        if ($this->looksLikeStorageMisconfiguration($message)) {
            return [
                'storage',
                "node/{$osmid}: after save, the observer tried to update pois.geojson on S3/MinIO (wmfe disk) but configuration is incomplete. Set at least AWS_DEFAULT_REGION (e.g. us-east-1 or eu-central-1), AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, and for local dev AWS_ENDPOINT for MinIO. See .env-example.",
            ];
        }
        if ($e instanceof \InvalidArgumentException) {
            if (str_contains($message, 'Point') || str_contains($message, 'geometry')) {
                return ['geometry', "node/{$osmid}: invalid OSM geometry."];
            }

            return ['other', "node/{$osmid}: {$message}"];
        }
        if ($e instanceof OsmClientException) {
            return ['not_found_or_invalid_osm', "node/{$osmid}: ".$e->getMessage()];
        }
        if ($e instanceof \TypeError) {
            // Typical: api.openstreetmap.org returns a non-JSON body (404/410 or intermittent errors).
            return ['not_found_or_invalid_osm', "node/{$osmid}: not found on OpenStreetMap or invalid response."];
        }

        return ['other', "node/{$osmid}: ".$message];
    }

    /**
     * After a real import, EcPoiObserver runs jobs/side effects using the `wmfe` (S3) disk.
     * Without region/credentials the AWS SDK throws InvalidArgumentException — do not confuse with OSM geometry.
     */
    private function looksLikeStorageMisconfiguration(string $message): bool
    {
        $lower = mb_strtolower($message);

        return (str_contains($lower, 'region') && str_contains($lower, 's3'))
            || str_contains($lower, 'wmfe')
            || str_contains($lower, 'failed to get disk')
            || str_contains($lower, 'filesystemmanager')
            || (str_contains($lower, 'aws') && str_contains($lower, 'configuration'));
    }

    /**
     * @return array{action: string, osmid: int, ec_poi_id: ?int, taxonomy_identifier: ?string, taxonomy_created: bool}
     */
    private function importSingleNode(int $osmid, int $appId, ?int $userId, bool $dryRun, bool $global, bool $hasGlobalColumn): array
    {
        [$properties, $geometry] = $this->fetchOsmNode($osmid);
        $dto = OsmNodePoiData::fromOsmNode($osmid, $properties, $geometry);

        $resolution = $this->taxonomyResolver->resolve($dto, $dryRun);
        $taxonomy = $resolution['taxonomy'];

        $existing = $this->findExistingEcPoiByOsmid($osmid, $appId);
        $action = $existing ? 'updated' : 'created';

        if ($dryRun) {
            return [
                'action' => $action,
                'osmid' => $osmid,
                'ec_poi_id' => $existing?->id,
                'taxonomy_identifier' => $taxonomy->identifier,
                'taxonomy_created' => $resolution['created'],
            ];
        }

        $ecPoi = DB::transaction(function () use ($dto, $existing, $appId, $userId, $taxonomy, $global, $hasGlobalColumn) {
            $attrs = $dto->toEcPoiAttributes($appId, $userId);
            unset($attrs['name']);

            // Only pass fillable attributes (exclude osmid, name, properties).
            $fillable = array_diff_key($attrs, ['osmid' => true, 'name' => true, 'properties' => true]);

            if ($existing) {
                $existing->fill($fillable);
                $existing->properties = $this->mergeImportedEcPoiProperties(
                    $existing->properties ?? [],
                    $attrs['properties'],
                );
                $existing->setAttribute('osmid', $dto->osmid);
                $this->applyNameTranslations($existing, $dto, $taxonomy);
                if ($hasGlobalColumn) {
                    $existing->global = $global;
                }
                $existing->save();
                $poi = $existing;
            } else {
                $poi = new EcPoi;
                $poi->fill($fillable);
                $poi->properties = $attrs['properties'];
                $poi->setAttribute('osmid', $dto->osmid);
                if ($userId !== null) {
                    $poi->setAttribute('user_id', $userId);
                }
                if ($hasGlobalColumn) {
                    $poi->global = $global;
                }
                $this->applyNameTranslations($poi, $dto, $taxonomy);
                $poi->save();
            }

            $poi->taxonomyPoiTypes()->syncWithoutDetaching([$taxonomy->id]);

            return $poi;
        });

        // TODO: Sync POI media from OSM (`image`, `wikimedia_commons`, multi-value refs): Commons API,
        // then attach to EcPoi `default` collection (featured + gallery order).

        return [
            'action' => $action,
            'osmid' => $osmid,
            'ec_poi_id' => $ecPoi->id,
            'taxonomy_identifier' => $taxonomy->identifier,
            'taxonomy_created' => $resolution['created'],
        ];
    }

    /**
     * Finds a POI already imported from the same OSM node **within the same app**: first
     * `properties->osmid` (JSON, useful when the `osmid` column is unset), then `ec_pois.osmid`.
     *
     * Scoped by `app_id` so two different apps sharing the same database never resolve to each
     * other's POI for the same OSM node (fix applied when this service was centralized in
     * wm-package — see Fase: challenge, oc:8239).
     */
    protected function findExistingEcPoiByOsmid(int $osmid, int $appId): ?EcPoi
    {
        $byProperties = EcPoi::query()
            ->where('app_id', $appId)
            ->where(function ($query) use ($osmid) {
                $query->where('properties->osmid', $osmid)
                    ->orWhere('properties->osmid', (string) $osmid);
            })
            ->first();

        if ($byProperties !== null) {
            return $byProperties;
        }

        return EcPoi::query()->where('app_id', $appId)->where('osmid', $osmid)->first();
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     *
     * @throws OsmClientException When the OSM endpoint does not return valid JSON or the node does not exist.
     */
    private function fetchOsmNode(int $osmid): array
    {
        try {
            $payload = $this->osmClient->getPropertiesAndGeometry("node/{$osmid}");
        } catch (OsmClientException $e) {
            // Already described by the client (e.g. OsmClientExceptionNoTags).
            throw $e;
        } catch (\TypeError $e) {
            // OsmClient throws TypeError when Http::get(...)->json() returns null
            // (typically HTTP 404/410 with a non-JSON body for missing OSM IDs).
            throw new OsmClientException('not found on OpenStreetMap or invalid response.');
        }

        if (! isset($payload[0], $payload[1]) || ! is_array($payload[0]) || ! is_array($payload[1])) {
            throw new OsmClientException('unexpected OSM response.');
        }

        return [$payload[0], $payload[1]];
    }

    /**
     * Sets name translations on the model.
     *
     * Strategy, in order:
     *  1. OSM tags (`name`, `name:it`, `name:en`, …) collected in {@see OsmNodePoiData::$nameTranslations}.
     *  2. If OSM provides no `name*`, use translated names from the matched/created {@see TaxonomyPoiType}
     *     (e.g. "Viewpoint") so the POI inherits a category-consistent name instead of a titlecased OSM label.
     *  3. Final fallback: {@see OsmNodePoiData::primaryName()} (e.g. "OSM node 123") to avoid an empty name.
     */
    private function applyNameTranslations(EcPoi $poi, OsmNodePoiData $dto, TaxonomyPoiType $taxonomy): void
    {
        if ($dto->nameTranslations !== []) {
            foreach ($dto->nameTranslations as $locale => $value) {
                if ($value === '') {
                    continue;
                }
                $poi->setTranslation('name', $locale, $value);
            }

            return;
        }

        $taxonomyNames = array_filter(
            $taxonomy->getTranslations('name'),
            static fn ($value) => is_string($value) && $value !== '',
        );

        if ($taxonomyNames !== []) {
            foreach ($taxonomyNames as $locale => $value) {
                $poi->setTranslation('name', (string) $locale, $value);
            }

            return;
        }

        $poi->setTranslation('name', 'it', $dto->primaryName());
    }

    /**
     * @param  list<int|string>  $osmIds
     * @return list<int>
     */
    private function normalizeIds(array $osmIds): array
    {
        $ids = [];
        foreach ($osmIds as $value) {
            $trimmed = trim((string) $value);
            if ($trimmed === '' || ! ctype_digit($trimmed)) {
                continue;
            }
            $ids[] = (int) $trimmed;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Merges existing properties with OSM import properties and drops legacy keys that would
     * otherwise survive {@see array_merge} (e.g. `related_url_assoc` before DTO alignment, or the
     * `osm` block replaced by `osm_data` + top-level `osmid`).
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $import
     * @return array<string, mixed>
     */
    private function mergeImportedEcPoiProperties(array $existing, array $import): array
    {
        $merged = array_merge($existing, $import);
        unset($merged['related_url_assoc'], $merged['osm']);

        return $merged;
    }
}
```

Salva in `wm-package/src/Services/Osm/OsmPoiImporter.php`.

- [ ] **Step 4: Eseguire il test di isolamento e verificare che passi**

Run: `<TEST_CMD> tests/Feature/Services/Osm/OsmPoiImporterMultiAppIsolationTest.php`
Expected: PASS

- [ ] **Step 5: Portare i test dry-run esistenti (con mock)**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Exceptions\OsmClientExceptionNoTags;
use Wm\WmPackage\Http\Clients\OsmClient;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Services\Osm\OsmPoiImporter;
use Wm\WmPackage\Services\Osm\OsmTaxonomyPoiTypeResolver;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    \Mockery::close();
});

describe('OsmClient with Http::fake (no real OSM calls)', function () {
    it('parses a mocked node JSON response like production OSM API', function () {
        $payload = [
            'elements' => [
                [
                    'type' => 'node',
                    'id' => 99_900_001,
                    'lat' => 44.5,
                    'lon' => 10.25,
                    'timestamp' => '2024-06-01T10:00:00Z',
                    'tags' => [
                        'name' => 'Fake Bench',
                        'amenity' => 'bench',
                    ],
                ],
            ],
        ];

        Http::fake([
            'api.openstreetmap.org/api/0.6/node/99900001.json' => Http::response($payload, 200),
        ]);

        $client = new OsmClient;
        [$properties, $geometry] = $client->getPropertiesAndGeometry('node/99900001');

        expect($geometry['type'])->toBe('Point')
            ->and($geometry['coordinates'])->toBe([10.25, 44.5])
            ->and($properties['name'])->toBe('Fake Bench')
            ->and($properties['amenity'])->toBe('bench')
            ->and($properties)->toHaveKey('_updated_at');
    });
});

describe('OsmPoiImporter dry-run (mocked OSM + taxonomy, no DB writes)', function () {
    it('returns expected outcome without persisting', function () {
        $properties = [
            'name' => 'Test POI',
            'amenity' => 'bench',
            '_updated_at' => '2024-01-01 12:00:00',
        ];
        $geometry = ['type' => 'Point', 'coordinates' => [9.0, 45.0]];

        $osmClient = \Mockery::mock(OsmClient::class);
        $osmClient->shouldReceive('getPropertiesAndGeometry')
            ->once()
            ->with('node/1001')
            ->andReturn([$properties, $geometry]);

        $taxonomy = new TaxonomyPoiType;
        $taxonomy->forceFill([
            'id' => 42,
            'identifier' => 'amenity-bench',
        ]);

        $resolver = \Mockery::mock(OsmTaxonomyPoiTypeResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->andReturn(['taxonomy' => $taxonomy, 'created' => false]);

        $importer = new class ($osmClient, $resolver) extends OsmPoiImporter {
            protected function findExistingEcPoiByOsmid(int $osmid, int $appId): ?\Wm\WmPackage\Models\EcPoi
            {
                return null;
            }
        };

        $report = $importer->importNodes([1001], appId: 1, userId: null, dryRun: true, global: true);

        expect($report->dryRun)->toBeTrue()
            ->and($report->outcomes())->toHaveCount(1)
            ->and($report->outcomes()[0]['action'])->toBe('created')
            ->and($report->outcomes()[0]['osmid'])->toBe(1001)
            ->and($report->outcomes()[0]['ec_poi_id'])->toBeNull()
            ->and($report->outcomes()[0]['taxonomy_identifier'])->toBe('amenity-bench')
            ->and($report->failuresCount())->toBe(0);
    });

    it('records failures when OSM client throws', function () {
        $osmClient = \Mockery::mock(OsmClient::class);
        $osmClient->shouldReceive('getPropertiesAndGeometry')
            ->andThrow(new OsmClientExceptionNoTags('no tags', 1));

        $resolver = \Mockery::mock(OsmTaxonomyPoiTypeResolver::class);
        $resolver->shouldReceive('resolve')->never();

        $importer = new class ($osmClient, $resolver) extends OsmPoiImporter {
            protected function findExistingEcPoiByOsmid(int $osmid, int $appId): ?\Wm\WmPackage\Models\EcPoi
            {
                return null;
            }
        };

        $report = $importer->importNodes([2002], 1, null, true, true);

        expect($report->outcomes())->toBeEmpty()
            ->and($report->failuresCount())->toBe(1)
            ->and($report->failures()[0]['category'])->toBe('no_tags')
            ->and($report->failures()[0]['osmid'])->toBe(2002);
    });
});

describe('Simulated import pipeline (DTO only, no persistence)', function () {
    it('builds EcPoi attributes from mocked OSM node payload', function () {
        Http::fake([
            'api.openstreetmap.org/api/0.6/node/88800001.json' => Http::response([
                'elements' => [
                    [
                        'type' => 'node',
                        'id' => 88_800_001,
                        'lat' => 46.0,
                        'lon' => 11.0,
                        'timestamp' => '2023-05-05T08:00:00Z',
                        'tags' => [
                            'name' => 'Summit',
                            'natural' => 'peak',
                            'ele' => '2000',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = new OsmClient;
        [$props, $geom] = $client->getPropertiesAndGeometry('node/88800001');
        $dto = \Wm\WmPackage\Dto\OsmNodePoiData::fromOsmNode(88_800_001, $props, $geom);
        $attrs = $dto->toEcPoiAttributes(appId: 5, userId: null);

        expect($attrs['app_id'])->toBe(5)
            ->and($attrs['osmid'])->toBe(88_800_001)
            ->and($attrs['properties']['osmid'])->toBe(88_800_001)
            ->and($attrs['properties']['osm_data']['tags']['natural'])->toBe('peak')
            ->and($dto->poiTypeOsmKey)->toBe('natural')
            ->and($dto->poiTypeOsmValue)->toBe('peak');
    });
});
```

Salva in `wm-package/tests/Unit/OsmPoiImportTest.php`. Nota: la firma dell'override anonimo di `findExistingEcPoiByOsmid` include ora `int $appId` (era solo `int $osmid` nell'originale Maphub) per allinearsi al fix del Task 5.

- [ ] **Step 6: Eseguire tutti i test del task e verificare che passino**

Run: `<TEST_CMD> tests/Unit/OsmPoiImportTest.php tests/Feature/Services/Osm/OsmPoiImporterMultiAppIsolationTest.php`
Expected: PASS (tutti)

- [ ] **Step 7: Commit**

```bash
git add wm-package/src/Services/Osm/OsmPoiImporter.php wm-package/tests/Unit/OsmPoiImportTest.php wm-package/tests/Feature/Services/Osm/OsmPoiImporterMultiAppIsolationTest.php
git commit -m "fix(oc:8239): move OsmPoiImporter to wm-package, scope existing-POI lookup by app_id"
```

---

### Task 6: `OsmImportReportPresenter` + `OsmImportReportStore`

**Files:**
- Create: `wm-package/src/Services/Osm/OsmImportReportPresenter.php`
- Create: `wm-package/src/Services/Osm/OsmImportReportStore.php`
- Test: `wm-package/tests/Unit/Services/Osm/OsmImportReportPresenterTest.php`
- Test: `wm-package/tests/Unit/Services/Osm/OsmImportReportStoreTest.php`

**Interfaces:**
- Consumes: `ImportReport` (Task 2).
- Produces: `OsmImportReportPresenter::payload(ImportReport $report, int $requested): array`. `OsmImportReportStore::put(array $payload, int $userId): string`, `::get(string $token, int $userId): ?array`, `const TTL_MINUTES`. Consumati dalla Nova Action (Task 7) e dal Controller (Task 9).

- [ ] **Step 1: Scrivere i test che falliscono**

```php
<?php

declare(strict_types=1);

use Wm\WmPackage\Services\Osm\ImportReport;
use Wm\WmPackage\Services\Osm\OsmImportReportPresenter;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

it('builds a success payload when there are no failures', function () {
    $report = new ImportReport(false);
    $report->addOutcome(['action' => 'created', 'osmid' => 1, 'ec_poi_id' => 10, 'taxonomy_identifier' => 'amenity-bench', 'taxonomy_created' => true]);

    $payload = OsmImportReportPresenter::payload($report, 1);

    expect($payload['status'])->toBe('success')
        ->and($payload['created'])->toBe(1)
        ->and($payload['new_taxonomies'])->toBe(1);
});

it('builds a danger payload when every node fails', function () {
    $report = new ImportReport(false);
    $report->addFailure(1, 'node not found', 'not_found_or_invalid_osm');

    $payload = OsmImportReportPresenter::payload($report, 1);

    expect($payload['status'])->toBe('danger')
        ->and($payload['failure_samples'])->toHaveCount(1)
        ->and($payload['failure_samples'][0]['osm_url'])->toBe('https://www.openstreetmap.org/node/1');
});
```

Salva in `wm-package/tests/Unit/Services/Osm/OsmImportReportPresenterTest.php`.

```php
<?php

declare(strict_types=1);

use Wm\WmPackage\Services\Osm\OsmImportReportStore;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

it('stores and retrieves a report only for the owning user', function () {
    $token = OsmImportReportStore::put(['requested' => 1], 42);

    expect(OsmImportReportStore::get($token, 42))->toBe(['requested' => 1])
        ->and(OsmImportReportStore::get($token, 43))->toBeNull()
        ->and(OsmImportReportStore::get('non-existent-token', 42))->toBeNull();
});
```

Salva in `wm-package/tests/Unit/Services/Osm/OsmImportReportStoreTest.php`.

- [ ] **Step 2: Eseguire i test e verificare che falliscano**

Run: `<TEST_CMD> tests/Unit/Services/Osm/OsmImportReportPresenterTest.php tests/Unit/Services/Osm/OsmImportReportStoreTest.php`
Expected: FAIL — classi non trovate.

- [ ] **Step 3: Creare `OsmImportReportPresenter`**

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Services\Osm;

/**
 * Serializes {@see ImportReport} for the post-import HTML report view.
 *
 * @phpstan-type CategoryRow array{label: string, count: int}
 * @phpstan-type FailureRow array{node: string, osm_url: string, message: string, category: string}
 */
final class OsmImportReportPresenter
{
    private const FAILURE_SAMPLE_LIMIT = 40;

    /**
     * @return array{
     *     dry_run: bool,
     *     requested: int,
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     new_taxonomies: int,
     *     categories: list<CategoryRow>,
     *     failure_samples: list<FailureRow>,
     *     failure_more: int,
     *     status: 'success'|'warning'|'danger',
     *     truncated_beyond_limit: int,
     * }
     */
    public static function payload(ImportReport $report, int $requested): array
    {
        $categories = [];
        foreach ($report->failuresByCategory() as $category => $count) {
            $categories[] = [
                'label' => __(ImportReport::CATEGORY_LABELS[$category] ?? $category),
                'count' => $count,
            ];
        }

        $failures = $report->failures();
        $samples = array_slice($failures, 0, self::FAILURE_SAMPLE_LIMIT);
        $failureSamples = [];
        foreach ($samples as $f) {
            $osmid = (int) $f['osmid'];
            $failureSamples[] = [
                'node' => 'node/' . $osmid,
                'osm_url' => 'https://www.openstreetmap.org/node/' . $osmid,
                'message' => $f['error'],
                'category' => __(ImportReport::CATEGORY_LABELS[$f['category']] ?? $f['category']),
            ];
        }

        $processedOk = $report->createdCount() + $report->updatedCount();
        $truncated = $report->truncatedBeyondLimit();
        $status = self::resolveStatus($requested, $report->failuresCount(), $processedOk);

        return [
            'dry_run' => $report->dryRun,
            'requested' => $requested,
            'created' => $report->createdCount(),
            'updated' => $report->updatedCount(),
            'skipped' => $report->failuresCount(),
            'new_taxonomies' => $report->newTaxonomiesCount(),
            'categories' => $categories,
            'failure_samples' => $failureSamples,
            'failure_more' => max(0, $report->failuresCount() - count($samples)),
            'status' => $status,
            'truncated_beyond_limit' => $truncated,
        ];
    }

    private static function resolveStatus(int $requested, int $failures, int $processedOk): string
    {
        if ($requested === 0) {
            return 'warning';
        }
        if ($failures > 0 && $processedOk === 0) {
            return 'danger';
        }
        if ($failures > 0) {
            return 'warning';
        }

        return 'success';
    }
}
```

Salva in `wm-package/src/Services/Osm/OsmImportReportPresenter.php`.

- [ ] **Step 4: Creare `OsmImportReportStore`**

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Services\Osm;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Stores the OSM import report payload for the HTML summary page (one-time token in cache).
 */
final class OsmImportReportStore
{
    public const TTL_MINUTES = 15;

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function put(array $payload, int $userId): string
    {
        $token = Str::random(48);
        Cache::put(self::cacheKey($token), [
            'user_id' => $userId,
            'payload' => $payload,
        ], now()->addMinutes(self::TTL_MINUTES));

        return $token;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $token, int $userId): ?array
    {
        $row = Cache::get(self::cacheKey($token));
        if (! is_array($row) || ! isset($row['user_id'], $row['payload'])) {
            return null;
        }
        if ((int) $row['user_id'] !== $userId) {
            return null;
        }

        return is_array($row['payload']) ? $row['payload'] : null;
    }

    private static function cacheKey(string $token): string
    {
        return 'osm_import_report:' . $token;
    }
}
```

Salva in `wm-package/src/Services/Osm/OsmImportReportStore.php`.

- [ ] **Step 5: Eseguire i test e verificare che passino**

Run: `<TEST_CMD> tests/Unit/Services/Osm/OsmImportReportPresenterTest.php tests/Unit/Services/Osm/OsmImportReportStoreTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add wm-package/src/Services/Osm/OsmImportReportPresenter.php wm-package/src/Services/Osm/OsmImportReportStore.php wm-package/tests/Unit/Services/Osm/OsmImportReportPresenterTest.php wm-package/tests/Unit/Services/Osm/OsmImportReportStoreTest.php
git commit -m "feat(oc:8239): move OSM import report presenter and store to wm-package"
```

---

### Task 7: Nova Action `ImportEcPoiFromOsm` con fix permessi + registrazione default

**Files:**
- Create: `wm-package/src/Nova/Actions/ImportEcPoiFromOsm.php`
- Modify: `wm-package/src/Nova/EcPoi.php` (aggiungere l'action in `actions()`)
- Test: `wm-package/tests/Feature/Nova/Actions/ImportEcPoiFromOsmActionTest.php`

**Interfaces:**
- Consumes: `OsmPoiImporter` (Task 5), `OsmImportReportPresenter`/`OsmImportReportStore` (Task 6), `Wm\WmPackage\Models\App`, `Wm\WmPackage\Models\User`, `Wm\WmPackage\Services\RolesAndPermissionsService` (esistente).
- Produces: azione Nova `ImportEcPoiFromOsm`, registrata di default in `Wm\WmPackage\Nova\EcPoi::actions()`.
- **Fix permessi**: `visibleAppsFor()` usa `$user->hasRole('Administrator') || RolesAndPermissionsService::allowsUser($user)` (preserva entrambi i rami dell'originale, non solo l'email).

- [ ] **Step 1: Scrivere il test di regressione permessi che fallisce**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\Actions\ImportEcPoiFromOsm;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['wm-package.super_admin_emails' => ['team@webmapp.it']]);
    RolesAndPermissionsService::seedDatabase();
});

it('lets an Administrator not in the super-admin allowlist see every app', function () {
    $administrator = User::factory()->create(['email' => 'not-super-admin@example.com']);
    $administrator->assignRole('Administrator');

    App::factory()->count(3)->create();

    Auth::login($administrator);
    $request = NovaRequest::create('/');
    $request->setUserResolver(fn () => $administrator);

    $fields = (new ImportEcPoiFromOsm)->fields($request);
    /** @var Select $appSelect */
    $appSelect = collect($fields)->first(fn ($f) => $f instanceof Select);

    expect($appSelect->meta['options'])->toHaveCount(3);
});

it('lets the configured super-admin email see every app even without the Administrator role', function () {
    $superAdmin = User::factory()->create(['email' => 'team@webmapp.it']);

    App::factory()->count(2)->create();

    Auth::login($superAdmin);
    $request = NovaRequest::create('/');
    $request->setUserResolver(fn () => $superAdmin);

    $fields = (new ImportEcPoiFromOsm)->fields($request);
    $appSelect = collect($fields)->first(fn ($f) => $f instanceof Select);

    expect($appSelect->meta['options'])->toHaveCount(2);
});

it('restricts a regular user to only their own apps', function () {
    $user = User::factory()->create(['email' => 'regular@example.com']);
    $ownApp = App::factory()->create(['user_id' => $user->id]);
    App::factory()->create(); // other user's app

    Auth::login($user);
    $request = NovaRequest::create('/');
    $request->setUserResolver(fn () => $user);

    $fields = (new ImportEcPoiFromOsm)->fields($request);
    $appSelect = collect($fields)->first(fn ($f) => $f instanceof Select);

    expect($appSelect->meta['options'])->toHaveCount(1)
        ->and(array_key_first($appSelect->meta['options']))->toBe((string) $ownApp->id);
});
```

Salva in `wm-package/tests/Feature/Nova/Actions/ImportEcPoiFromOsmActionTest.php`.

- [ ] **Step 2: Eseguire il test e verificare che fallisca**

Run: `<TEST_CMD> tests/Feature/Nova/Actions/ImportEcPoiFromOsmActionTest.php`
Expected: FAIL con `Class "Wm\WmPackage\Nova\Actions\ImportEcPoiFromOsm" not found`.

- [ ] **Step 3: Creare la Nova Action con il fix applicato**

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Osm\OsmImportReportPresenter;
use Wm\WmPackage\Services\Osm\OsmImportReportStore;
use Wm\WmPackage\Services\Osm\OsmPoiImporter;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Imports {@see \Wm\WmPackage\Models\EcPoi} records from comma-separated OSM node IDs.
 *
 * UI: textarea + app select + global + dry-run. POI owner is not chosen manually; it comes from
 * {@see App::$user_id} on the selected app.
 *
 * After completion (including dry-run), response is a redirect to the internal report page
 * (same tab), without external toasts or popups.
 *
 * App visibility:
 *  - Administrator role, or email in {@see RolesAndPermissionsService::allowsUser()} allowlist → all apps
 *  - other users → only apps where they are `user_id` ({@see User::apps()})
 *
 * App select: first app (by name) is pre-selected; when the user sees only one app, the select is read-only.
 */
class ImportEcPoiFromOsm extends Action
{
    use InteractsWithQueue, Queueable;

    public $standalone = true;

    public function __construct()
    {
        // English strings: Nova applies `Nova::__()` on serialization (correct user locale).
        $this->confirmText = 'Data will be downloaded from openstreetmap.org for each OSM ID. Continue?';
        $this->confirmButtonText = 'Import';
    }

    public function name(): string
    {
        return __('Import POIs from OSM');
    }

    public function handle(ActionFields $fields, Collection $models): mixed
    {
        $rawOsmIds = (string) ($fields->get('osm_ids') ?? '');
        $osmIds = $this->parseOsmIds($rawOsmIds);

        if ($osmIds === []) {
            return Action::danger(__('No valid OSM IDs found. Enter numeric IDs separated by commas.'));
        }

        $app = $this->resolveApp($fields);
        if ($app === null) {
            return Action::danger(__('No app selected or available.'));
        }

        $userId = $app->user_id !== null ? (int) $app->user_id : null;
        $dryRun = (bool) $fields->get('dry_run');
        $global = (bool) $fields->get('global', true);

        /** @var OsmPoiImporter $importer */
        $importer = app(OsmPoiImporter::class);
        $report = $importer->importNodes($osmIds, (int) $app->id, $userId, $dryRun, $global);

        $payload = OsmImportReportPresenter::payload($report, count($osmIds));
        $token = OsmImportReportStore::put($payload, (int) Auth::id());

        return Action::redirect(route('osm.import.report', ['token' => $token]));
    }

    public function fields(NovaRequest $request): array
    {
        $fields = [
            Textarea::make(__('OSM node IDs (comma-separated)'), 'osm_ids')
                ->rows(4)
                ->help(__('Example: 12345, 67890, 11223. OSM nodes only (points).') . ' ' . __('If an OSM ID was already imported, its POI will be updated.'))
                ->rules('required', 'string', 'max:10000'),
        ];

        $apps = $this->visibleAppsFor($request->user())->orderBy('name')->get();

        $appSelect = Select::make(__('App'), 'app_id')
            ->options($apps->pluck('name', 'id')->toArray())
            ->rules('required')
            ->searchable()
            ->displayUsingLabels()
            ->help(__('The POI owner is automatically set to the user_id of the selected app.'));

        if ($apps->isNotEmpty()) {
            $appSelect->default($apps->first()->id);
        }

        if ($apps->count() === 1) {
            $appSelect->readonly();
        }

        $fields[] = $appSelect;

        $fields[] = Boolean::make(__('Include in app pois.geojson (EcPoi.global = true)'), 'global')
            ->default(true)
            ->help(__('When enabled, POIs are included in the app’s pois.geojson file (global filter in getAllPoisGeojson). When disabled, they are imported but excluded from that file until global is set to true.'));

        $fields[] = Boolean::make(__('Dry run (no writes)'), 'dry_run')
            ->default(false)
            ->help(__('When enabled, data is fetched and the outcome is shown without persisting any changes.'));

        return $fields;
    }

    /**
     * Apps visible to the current user:
     *  - Administrator role, or super-admin email allowlist → all apps;
     *  - others → only apps they own (`apps.user_id = user.id`).
     */
    private function visibleAppsFor(?User $user): Builder
    {
        $query = App::query();

        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('Administrator') || RolesAndPermissionsService::allowsUser($user)) {
            return $query;
        }

        return $query->where('user_id', $user->id);
    }

    /**
     * @return list<int>
     */
    private function parseOsmIds(string $input): array
    {
        $ids = [];
        foreach (preg_split('/[\s,;]+/', $input) ?: [] as $token) {
            $token = trim($token);
            if ($token === '' || ! ctype_digit($token)) {
                continue;
            }
            $ids[] = (int) $token;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Resolves the app from the selected `app_id`, or auto-selection when the user sees only one app.
     * Ensures the app is among visible apps (no bypass via tampered form data).
     */
    private function resolveApp(ActionFields $fields): ?App
    {
        $user = Auth::user();
        $visible = $this->visibleAppsFor($user instanceof User ? $user : null);

        $appIdFromField = $fields->get('app_id');
        if (! empty($appIdFromField)) {
            return $visible->where('id', (int) $appIdFromField)->first();
        }

        $apps = (clone $visible)->limit(2)->get();

        return $apps->count() === 1 ? $apps->first() : null;
    }
}
```

Salva in `wm-package/src/Nova/Actions/ImportEcPoiFromOsm.php`.

- [ ] **Step 4: Registrare l'action in `Wm\WmPackage\Nova\EcPoi::actions()`**

In `wm-package/src/Nova/EcPoi.php`, aggiungere l'`use` in cima al file:

```php
use Wm\WmPackage\Nova\Actions\ImportEcPoiFromOsm;
```

E modificare il metodo `actions()` (circa riga 89-97):

```php
    public function actions(NovaRequest $request): array
    {
        return [
            new ExecuteEcPoiDataChainAction,
            new DownloadEcPoiAction,
            (new UploadPoiFile)->standalone(),
            new TranslateModelAction,
            new ImportEcPoiFromOsm,
        ];
    }
```

- [ ] **Step 5: Eseguire il test e verificare che passi**

Run: `<TEST_CMD> tests/Feature/Nova/Actions/ImportEcPoiFromOsmActionTest.php`
Expected: PASS — in particolare il primo test (`Administrator` non super-admin vede tutte le app) conferma il fix.

- [ ] **Step 6: Commit**

```bash
git add wm-package/src/Nova/Actions/ImportEcPoiFromOsm.php wm-package/src/Nova/EcPoi.php wm-package/tests/Feature/Nova/Actions/ImportEcPoiFromOsmActionTest.php
git commit -m "fix(oc:8239): move ImportEcPoiFromOsm Nova action to wm-package, preserve Administrator visibility"
```

---

### Task 8: Comando CLI `WmImportEcPoiFromOsmCommand`

**Files:**
- Create: `wm-package/src/Commands/WmImportEcPoiFromOsmCommand.php`
- Modify: `wm-package/src/WmPackageServiceProvider.php` (aggiungere `use` + entry in `hasCommands([...])`)
- Test: `wm-package/tests/Feature/Commands/WmImportEcPoiFromOsmCommandTest.php`

**Interfaces:**
- Consumes: `OsmPoiImporter` (Task 5), `Wm\WmPackage\Models\App`.
- Produces: comando artisan `wm-package:import-ec-pois-from-osm`.

- [ ] **Step 1: Scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

it('imports a single OSM node via CLI with auto-picked app', function () {
    App::factory()->create();

    Http::fake([
        'api.openstreetmap.org/api/0.6/node/123456.json' => Http::response([
            'elements' => [[
                'type' => 'node',
                'id' => 123_456,
                'lat' => 44.0,
                'lon' => 10.0,
                'timestamp' => '2024-01-01T00:00:00Z',
                'tags' => ['name' => 'CLI Test POI', 'amenity' => 'bench'],
            ]],
        ], 200),
    ]);

    $this->artisan('wm-package:import-ec-pois-from-osm', ['osmids' => '123456'])
        ->assertExitCode(0);
});

it('fails with a clear message when no --app is given and multiple apps exist', function () {
    App::factory()->count(2)->create();

    $this->artisan('wm-package:import-ec-pois-from-osm', ['osmids' => '123456'])
        ->expectsOutput('Pass --app=ID: more than one app exists in the database.')
        ->assertExitCode(2); // Command::INVALID
});
```

Salva in `wm-package/tests/Feature/Commands/WmImportEcPoiFromOsmCommandTest.php`.

- [ ] **Step 2: Eseguire il test e verificare che fallisca**

Run: `<TEST_CMD> tests/Feature/Commands/WmImportEcPoiFromOsmCommandTest.php`
Expected: FAIL — comando non registrato (`Command "wm-package:import-ec-pois-from-osm" is not defined`).

- [ ] **Step 3: Creare la classe comando**

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Osm\ImportReport;
use Wm\WmPackage\Services\Osm\OsmPoiImporter;

/**
 * Imports POIs from OSM node IDs (CLI).
 *
 * Examples:
 *   php artisan wm-package:import-ec-pois-from-osm "12345,67890,11223" --app=1
 *   php artisan wm-package:import-ec-pois-from-osm 12345 --app=1 --dry-run
 *   php artisan wm-package:import-ec-pois-from-osm @osmids.txt --app=1
 *   php artisan wm-package:import-ec-pois-from-osm 12345 --app=2 --no-global
 *
 * POI owner user_id comes from {@see App::$user_id} on the selected app (same as the Nova action).
 */
class WmImportEcPoiFromOsmCommand extends Command
{
    protected $signature = 'wm-package:import-ec-pois-from-osm
        {osmids : Comma-separated OSM node IDs, or "@/path/file.txt" to read IDs from a file}
        {--app= : Destination app ID (auto-picked when only one app exists)}
        {--dry-run : Run without persisting; print what would happen}
        {--no-global : Set EcPoi.global=false (excluded from pois.geojson; default: global true)}';

    protected $description = 'Import EcPoi records from OpenStreetMap (nodes only), mapping OSM tags to TaxonomyPoiType.';

    public function handle(OsmPoiImporter $importer): int
    {
        $osmIds = $this->readOsmIds((string) $this->argument('osmids'));
        if ($osmIds === []) {
            $this->error('No valid OSM IDs. Enter numeric IDs separated by commas.');

            return self::INVALID;
        }

        $appOption = $this->option('app');
        $app = $this->resolveApp();
        if ($app === null) {
            if ($appOption !== null && $appOption !== '') {
                $this->error("No app found with ID {$appOption}.");

                return self::INVALID;
            }
            $this->error('Pass --app=ID: more than one app exists in the database.');

            return self::INVALID;
        }

        $appId = (int) $app->id;
        $userId = $app->user_id !== null ? (int) $app->user_id : null;
        $dryRun = (bool) $this->option('dry-run');
        $global = ! (bool) $this->option('no-global');

        $this->info(sprintf(
            '%sImporting %d OSM ID(s) into app %d%s (global=%s) ...',
            $dryRun ? '[DRY-RUN] ' : '',
            count($osmIds),
            $appId,
            $userId !== null ? " (user_id={$userId})" : '',
            $global ? 'true' : 'false',
        ));

        $report = $importer->importNodes($osmIds, $appId, $userId, $dryRun, $global);

        $this->renderReport($report);

        // SUCCESS when at least one POI was created/updated. Skipped IDs are bad input data,
        // not a failed operation — do not force a non-zero exit for that alone.
        $imported = $report->createdCount() + $report->updatedCount();

        return $imported > 0 || $report->failuresCount() === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<int>
     */
    private function readOsmIds(string $raw): array
    {
        if (str_starts_with($raw, '@')) {
            $path = substr($raw, 1);
            if (! is_readable($path)) {
                $this->error("File not readable: {$path}");

                return [];
            }
            $raw = (string) file_get_contents($path);
        }

        $ids = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $token) {
            $token = trim($token);
            if ($token === '' || ! ctype_digit($token)) {
                continue;
            }
            $ids[] = (int) $token;
        }

        return array_values(array_unique($ids));
    }

    private function resolveApp(): ?App
    {
        $option = $this->option('app');
        if ($option !== null && $option !== '') {
            return App::query()->find((int) $option);
        }

        $apps = App::query()->orderBy('id')->limit(2)->get();
        if ($apps->count() === 1) {
            return $apps->first();
        }

        return null;
    }

    private function renderReport(ImportReport $report): void
    {
        $rows = array_map(
            static fn ($o) => [
                $o['osmid'],
                $o['action'],
                $o['ec_poi_id'] ?? '-',
                $o['taxonomy_identifier'] ?? '-',
                $o['taxonomy_created'] ? 'yes' : 'no',
            ],
            $report->outcomes(),
        );

        if ($rows !== []) {
            $this->table(['OSMID', 'Action', 'EcPoi ID', 'Taxonomy', 'New Taxonomy?'], $rows);
        }

        if ($report->truncatedBeyondLimit() > 0) {
            $this->warn(sprintf(
                'Warning: %d OSM ID(s) were not processed (WM_OSM_IMPORT_MAX_IDS_PER_RUN limit). Run another import with the remaining IDs.',
                $report->truncatedBeyondLimit(),
            ));
        }

        if ($report->failuresCount() > 0) {
            $this->warn("Skipped ({$report->failuresCount()}):");
            foreach ($report->failures() as $failure) {
                $label = ImportReport::CATEGORY_LABELS[$failure['category']] ?? $failure['category'];
                $this->warn(" - node/{$failure['osmid']} [{$label}]: {$failure['error']}");
            }

            $byCategory = $report->failuresByCategory();
            if ($byCategory !== []) {
                $this->line('Skip summary by reason:');
                foreach ($byCategory as $category => $count) {
                    $label = ImportReport::CATEGORY_LABELS[$category] ?? $category;
                    $this->line(" - {$label}: {$count}");
                }
            }
        }

        $this->line(sprintf(
            '%sCreated: %d | Updated: %d | New taxonomies: %d | Skipped: %d',
            $report->dryRun ? '[DRY-RUN] ' : '',
            $report->createdCount(),
            $report->updatedCount(),
            $report->newTaxonomiesCount(),
            $report->failuresCount(),
        ));
    }
}
```

Salva in `wm-package/src/Commands/WmImportEcPoiFromOsmCommand.php`.

- [ ] **Step 4: Registrare il comando**

In `wm-package/src/WmPackageServiceProvider.php`, aggiungere l'import in cima:

```php
use Wm\WmPackage\Commands\WmImportEcPoiFromOsmCommand;
```

E aggiungere alla lista `hasCommands([...])` in `configurePackage()`:

```php
            ->hasCommands([
                WmPackageCommand::class,
                // WmBackupCommand::class,//See in the boot() method
                WmImportFromGeohubCommand::class,
                WmGeneratePBFCommand::class,
                WmDownloadDbBackupCommand::class,
                WmBuildAppPoisGeojsonCommand::class,
                WmSyncUgcTaxonomyWhereCommand::class,
                WmRestoreDbCommand::class,
                WmGenerateIconsCommand::class,
                WmPackagePublishMigrationCommand::class,
                WmPackagePublishMissingMigrationsCommand::class,
                WmImportEcPoiFromOsmCommand::class,
            ])
```

- [ ] **Step 5: Eseguire il test e verificare che passi**

Run: `<TEST_CMD> tests/Feature/Commands/WmImportEcPoiFromOsmCommandTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add wm-package/src/Commands/WmImportEcPoiFromOsmCommand.php wm-package/src/WmPackageServiceProvider.php wm-package/tests/Feature/Commands/WmImportEcPoiFromOsmCommandTest.php
git commit -m "feat(oc:8239): add wm-package:import-ec-pois-from-osm CLI command"
```

---

### Task 9: Controller + route + view

**Files:**
- Create: `wm-package/src/Http/Controllers/OsmImportReportController.php`
- Create: `wm-package/resources/views/osm-import-report.blade.php`
- Modify: `wm-package/routes/web.php` (aggiungere la route)
- Test: `wm-package/tests/Feature/OsmImportReportRouteTest.php`

**Interfaces:**
- Consumes: `OsmImportReportStore` (Task 6).
- Produces: route nominata `osm.import.report`, consumata dalla Nova Action (Task 7, già scritta con `route('osm.import.report', ...)`).

- [ ] **Step 1: Scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('denies the osm import report route to Guest', function () {
    $user = User::factory()->create();
    $user->assignRole('Guest');

    $response = $this->actingAs($user)->get('/nova-vendor/osm-import-reports/'.str_repeat('a', 16));

    $response->assertForbidden();
});

it('allows the osm import report route to Editor', function () {
    $user = User::factory()->create();
    $user->assignRole('Editor');

    $response = $this->actingAs($user)->get('/nova-vendor/osm-import-reports/'.str_repeat('a', 16));

    // access-nova middleware must not block (403); 404 is expected because the token is fake.
    expect($response->status())->not->toBe(403);
});
```

Salva in `wm-package/tests/Feature/OsmImportReportRouteTest.php`.

- [ ] **Step 2: Eseguire il test e verificare che fallisca**

Run: `<TEST_CMD> tests/Feature/OsmImportReportRouteTest.php`
Expected: FAIL — route `osm.import.report` inesistente (404 su path non registrato, non 403 atteso).

- [ ] **Step 3: Creare il controller**

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Laravel\Nova\Nova;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Wm\WmPackage\Services\Osm\OsmImportReportStore;

class OsmImportReportController extends Controller
{
    /**
     * OSM import summary page (same browser tab; no external popup).
     */
    public function show(Request $request, string $token): View
    {
        $user = $request->user();
        if ($user === null) {
            throw new NotFoundHttpException;
        }

        $payload = OsmImportReportStore::get($token, (int) $user->id);
        if ($payload === null) {
            throw new NotFoundHttpException;
        }

        return view('wm-package::osm-import-report', [
            'report' => $payload,
            'backUrl' => Nova::url('/resources/ec-pois'),
            'ttlMinutes' => OsmImportReportStore::TTL_MINUTES,
        ]);
    }
}
```

Salva in `wm-package/src/Http/Controllers/OsmImportReportController.php`. Verificare la classe base `Controller` esistente in `wm-package/src/Http/Controllers/Controller.php` (usata da `RestoreDbController` ecc.) e usare lo stesso `namespace`/`extends`.

- [ ] **Step 4: Creare la view**

Copiare integralmente il contenuto HTML/CSS/Blade da `resources/views/nova/osm-import-report.blade.php` (Maphub) in `wm-package/resources/views/osm-import-report.blade.php` — nessuna modifica al markup, solo il path cambia (flat, senza sottocartella `nova/`, coerente con `restore-db.blade.php`/`top-ten.blade.php` esistenti).

- [ ] **Step 5: Registrare la route**

In `wm-package/routes/web.php`, aggiungere in cima:

```php
use Wm\WmPackage\Http\Controllers\OsmImportReportController;
```

E in fondo al file:

```php
Route::middleware(['web', 'auth', 'can:access-nova'])
    ->get('/nova-vendor/osm-import-reports/{token}', [OsmImportReportController::class, 'show'])
    ->where('token', '[A-Za-z0-9\-]{16,64}')
    ->name('osm.import.report');
```

Il gruppo globale `Route::middleware('web')->group($packageDirPath.'routes/web.php')` in `WmPackageServiceProvider::boot()` applica già `web`; qui si aggiunge esplicitamente `auth` + `can:access-nova` sulla singola route, come nell'originale Maphub.

- [ ] **Step 6: Eseguire il test e verificare che passi**

Run: `<TEST_CMD> tests/Feature/OsmImportReportRouteTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add wm-package/src/Http/Controllers/OsmImportReportController.php wm-package/resources/views/osm-import-report.blade.php wm-package/routes/web.php wm-package/tests/Feature/OsmImportReportRouteTest.php
git commit -m "feat(oc:8239): move OSM import report controller, route and view to wm-package"
```

---

### Task 10: Traduzioni `en`/`it`

**Files:**
- Modify: `wm-package/resources/lang/it.json`
- Modify: `wm-package/resources/lang/en.json`

**Interfaces:**
- Nessuna (dati statici consumati da `__()` nelle classi già create nei Task 6-9).

- [ ] **Step 1: Aggiungere le chiavi a `it.json`**

Aggiungere (in ordine alfabetico, coerente con il resto del file) le seguenti coppie chiave-valore a `wm-package/resources/lang/it.json`:

```json
    "Data will be downloaded from openstreetmap.org for each OSM ID. Continue?": "Verranno scaricati i dati da openstreetmap.org per ogni OSMID. Continuare?",
    "Dry run (no writes)": "Dry run (nessuna scrittura)",
    "Example: 12345, 67890, 11223. OSM nodes only (points).": "Esempio: 12345, 67890, 11223. Solo node OSM (punti).",
    "If an OSM ID was already imported, its POI will be updated.": "Se un OSMID è già stato importato in precedenza, il relativo POI verrà aggiornato.",
    "Import POIs from OSM": "Importa POI da OSM",
    "Invalid OSM geometry": "Geometria OSM non valida",
    "Node not found or invalid OSM response": "Node non trovato o risposta OSM non valida",
    "No app selected or available.": "Nessuna app selezionata o disponibile.",
    "No valid OSM IDs found. Enter numeric IDs separated by commas.": "Nessun OSMID valido trovato. Inserire ID numerici separati da virgola.",
    "OSM node has no tags (nothing useful to import)": "Node OSM senza tag (nessun dato utile)",
    "OSM node IDs (comma-separated)": "OSMID dei node (separati da virgola)",
    "Other error": "Altro errore",
    "S3/MinIO storage (wmfe disk) not configured or error after save": "Storage S3/MinIO (disk wmfe) non configurato o errore dopo il salvataggio",
    "The POI owner is automatically set to the user_id of the selected app.": "Il proprietario dei POI viene impostato automaticamente sull'user_id dell'app selezionata.",
    "Include in app pois.geojson (EcPoi.global = true)": "Visibili nel pois.geojson (EcPoi.global = true)",
    "All requested nodes failed. See the table below for details.": "Tutti i node richiesti sono falliti. Vedi la tabella sotto per i dettagli.",
    "Go back": "Torna indietro",
    "Category": "Categoria",
    "Completed with some errors. Review skipped rows below.": "Completato con alcuni errori. Controlla le righe skippate qui sotto.",
    "Created": "Creati",
    "Dry run — no database changes will be saved.": "Dry run — nessuna modifica al database verrà salvata.",
    "Errors by category": "Errori per categoria",
    "Import completed successfully.": "Import completato con successo.",
    "Message": "Messaggio",
    "More failures (:count) are not shown in this table.": "Altri :count errori non sono mostrati in questa tabella.",
    "New taxonomies": "Nuove taxonomy",
    "OSM import report": "Report import OSM",
    "Reference": "Riferimento",
    "Requested OSM IDs": "OSMID richiesti",
    "Sample failures": "Esempi di errori",
    "Skipped": "Skippati / errori",
    "Summary": "Riepilogo",
    "This page expires after about :minutes minutes.": "Questa pagina scade dopo circa :minutes minuti.",
    "Updated": "Aggiornati",
    ":count OSM IDs were skipped because they exceeded the per-run limit (WM_OSM_IMPORT_MAX_IDS_PER_RUN). Import the remaining IDs in another run.": ":count OSMID non sono stati elaborati perché superano il limite per esecuzione (WM_OSM_IMPORT_MAX_IDS_PER_RUN). Importa i rimanenti in un'altra esecuzione.",
    "When enabled, POIs are included in the app’s pois.geojson file (global filter in getAllPoisGeojson). When disabled, they are imported but excluded from that file until global is set to true.": "Se abilitato, i POI sono inclusi nel file pois.geojson dell'app (filtro global in getAllPoisGeojson). Se disabilitato, vengono importati ma esclusi dal file finché global non viene impostato a true.",
    "When enabled, data is fetched and the outcome is shown without persisting any changes.": "Se abilitato, i dati vengono scaricati e il risultato mostrato senza salvare alcuna modifica."
```

**Nota:** non portare le chiavi legacy non più referenziate da nessun `__()` nel codice spostato: `"New taxonomies created: :tax."`, `"Requested :req OSM IDs. Created :created, updated :updated, skipped :fail."`, `"OSM IDs not imported (first): "`, `"POI owner (required)."` — sono residui di una versione precedente della action/CLI e non compaiono in nessuna delle classi create nei Task 2-9. Rimarranno solo se già usate altrove in Maphub (verificare con `grep -rn "New taxonomies created" app/ resources/` prima di rimuoverle in Task Maphub — se zero risultati, sono da rimuovere come cleanup nel piano Maphub).

Il valore aggiornato con `WM_OSM_IMPORT_MAX_IDS_PER_RUN` (non più `OSM_IMPORT_MAX_IDS_PER_RUN`) nell'ultima chiave riflette il rename dell'env var del Task 1 — la label OSM_IMPORT nella view (`resources/views/osm-import-report.blade.php`, Task 9) referenzia questa stessa stringa tradotta.

- [ ] **Step 2: Aggiungere le stesse chiavi a `en.json`** (valore = chiave, come da convenzione del file — verificare aprendo `wm-package/resources/lang/en.json` che tutte le voci esistenti abbiano value == key)

```json
    "Data will be downloaded from openstreetmap.org for each OSM ID. Continue?": "Data will be downloaded from openstreetmap.org for each OSM ID. Continue?",
    "Dry run (no writes)": "Dry run (no writes)",
    "Example: 12345, 67890, 11223. OSM nodes only (points).": "Example: 12345, 67890, 11223. OSM nodes only (points).",
    "If an OSM ID was already imported, its POI will be updated.": "If an OSM ID was already imported, its POI will be updated.",
    "Import POIs from OSM": "Import POIs from OSM",
    "Invalid OSM geometry": "Invalid OSM geometry",
    "Node not found or invalid OSM response": "Node not found or invalid OSM response",
    "No app selected or available.": "No app selected or available.",
    "No valid OSM IDs found. Enter numeric IDs separated by commas.": "No valid OSM IDs found. Enter numeric IDs separated by commas.",
    "OSM node has no tags (nothing useful to import)": "OSM node has no tags (nothing useful to import)",
    "OSM node IDs (comma-separated)": "OSM node IDs (comma-separated)",
    "Other error": "Other error",
    "S3/MinIO storage (wmfe disk) not configured or error after save": "S3/MinIO storage (wmfe disk) not configured or error after save",
    "The POI owner is automatically set to the user_id of the selected app.": "The POI owner is automatically set to the user_id of the selected app.",
    "Include in app pois.geojson (EcPoi.global = true)": "Include in app pois.geojson (EcPoi.global = true)",
    "All requested nodes failed. See the table below for details.": "All requested nodes failed. See the table below for details.",
    "Go back": "Go back",
    "Category": "Category",
    "Completed with some errors. Review skipped rows below.": "Completed with some errors. Review skipped rows below.",
    "Created": "Created",
    "Dry run — no database changes will be saved.": "Dry run — no database changes will be saved.",
    "Errors by category": "Errors by category",
    "Import completed successfully.": "Import completed successfully.",
    "Message": "Message",
    "More failures (:count) are not shown in this table.": "More failures (:count) are not shown in this table.",
    "New taxonomies": "New taxonomies",
    "OSM import report": "OSM import report",
    "Reference": "Reference",
    "Requested OSM IDs": "Requested OSM IDs",
    "Sample failures": "Sample failures",
    "Skipped": "Skipped",
    "Summary": "Summary",
    "This page expires after about :minutes minutes.": "This page expires after about :minutes minutes.",
    "Updated": "Updated",
    ":count OSM IDs were skipped because they exceeded the per-run limit (WM_OSM_IMPORT_MAX_IDS_PER_RUN). Import the remaining IDs in another run.": ":count OSM IDs were skipped because they exceeded the per-run limit (WM_OSM_IMPORT_MAX_IDS_PER_RUN). Import the remaining IDs in another run.",
    "When enabled, POIs are included in the app’s pois.geojson file (global filter in getAllPoisGeojson). When disabled, they are imported but excluded from that file until global is set to true.": "When enabled, POIs are included in the app’s pois.geojson file (global filter in getAllPoisGeojson). When disabled, they are imported but excluded from that file until global is set to true.",
    "When enabled, data is fetched and the outcome is shown without persisting any changes.": "When enabled, data is fetched and the outcome is shown without persisting any changes."
```

- [ ] **Step 3: Verificare manualmente il rendering**

Run: `<TEST_CMD> tests/Feature/Nova/Actions/ImportEcPoiFromOsmActionTest.php` (già verde dal Task 7, questo step è solo per confermare che l'aggiunta delle chiavi lang non abbia rotto il JSON — un JSON malformato farebbe fallire il boot dell'app in qualunque test)
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add wm-package/resources/lang/it.json wm-package/resources/lang/en.json
git commit -m "feat(oc:8239): add OSM import translations to wm-package (en, it)"
```

---

### Task 11: Verifica finale suite completa + aggiornamento CLAUDE.md

**Files:**
- Modify: `wm-package/CLAUDE.md`

- [ ] **Step 1: Eseguire l'intera suite del package**

Run: `cd wm-package && <TEST_CMD>`
Expected: PASS su tutta la suite (nessuna regressione sui test preesistenti del package).

- [ ] **Step 2: Verificare il dry-run del gate migration (se applicabile)**

Run: `docker exec -it php-maphub php artisan wm-package:publish-missing-migrations --dry-run`
Expected: exit 0 (questa feature non introduce migration, solo codice/config/route — verifica comunque che nulla sia stato rotto).

- [ ] **Step 3: Aggiornare `wm-package/CLAUDE.md`**

Aggiungere una riga alla tabella `## Feature disponibili` (in cima alla tabella esistente):

```markdown
| Import EcPoi da OSM (Nova Action, CLI, report) | oc:8239 | `src/Nova/Actions/ImportEcPoiFromOsm.php`, `src/Services/Osm/*`, `src/Dto/Osm*.php`, `src/Commands/WmImportEcPoiFromOsmCommand.php`, `src/Http/Controllers/OsmImportReportController.php`, `config/wm-osm-import.php` | Lift-and-shift da Maphub; action registrata di default in `EcPoi::actions()`; fix isolamento multi-app su `findExistingEcPoiByOsmid()`; `visibleAppsFor()` preserva `hasRole('Administrator')` oltre a `RolesAndPermissionsService::allowsUser()` |
```

Aggiungere un blocco a `## Decisioni architetturali` (in cima, sotto il titolo della sezione):

```markdown
### Import EcPoi da OSM (oc:8239)
- `visibleAppsFor()` in `ImportEcPoiFromOsm`: la condizione di visibilità app è `$user->hasRole('Administrator') || RolesAndPermissionsService::allowsUser($user)` — **non sostituire con il solo `allowsUser()`**, altrimenti gli Administrator non presenti in `WM_SUPER_ADMIN_EMAILS` perdono la visibilità su tutte le app (regressione individuata in Fase: challenge del ticket)
- `OsmPoiImporter::findExistingEcPoiByOsmid()` richiede `$appId` e filtra sempre per `app_id` — non rimuovere questo filtro: senza di esso, due App diverse sullo stesso DB con lo stesso `osmid` importato si sovrascriverebbero a vicenda
- Nessun `User-Agent` custom su `Wm\WmPackage\Http\Clients\OsmClient` — rischio noto di rate-limit condiviso tra tutti i consumer del package se più progetti eseguono import OSM in parallelo; non risolto in questo ciclo (fuori scope, vedi `docs/features/8239-.../overview.md`)
- Traduzioni OSM presenti solo in `resources/lang/{en,it}.json` — nessuna traduzione fr/es/de per questa feature (decisione esplicita, non un'omissione)
```

Mostra il diff di `wm-package/CLAUDE.md` prima di scriverlo (regola generale del workflow wm-plan).

- [ ] **Step 4: Commit**

```bash
git add wm-package/CLAUDE.md
git commit -m "docs(oc:8239): document OSM import migration in wm-package CLAUDE.md"
```

---

## Note per l'esecutore

- **Non procedere al piano Maphub** (`docs/features/8239-spostare-import-ecpoi-da-osm-in-wm-package/plan.md` nel repo principale) finché tutti i task di questo piano non sono completi e la Task 11 (suite verde) è confermata — vedi vincolo di sequenza in `overview.md`.
- Dopo il commit finale di questo piano, il repo wm-package deve essere pushato e mergiato (branch dedicato, vedi Fase: execution → branch del workflow wm-plan) **prima** di bumpare `composer.json` in Maphub.
