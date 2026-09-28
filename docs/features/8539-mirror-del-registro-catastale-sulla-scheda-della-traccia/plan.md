> Ticket: oc:8539

# Dominio Catasto Sentieri estendibile — piano di implementazione (wm-package)

> **Per chi esegue:** sub-skill richiesta `superpowers:subagent-driven-development` (consigliata) o
> `superpowers:executing-plans`. Gli step usano le checkbox (`- [ ]`).
>
> **Nessun commit, `git add`, push o branch automatico.** Le righe «Commit» sono istruzioni per il
> dev, che committa da sé.

**Goal:** rendere estendibile dagli shard l'intero dominio Catasto — modelli, service, Resource Nova
e tipi di anomalia — senza cambiare il comportamento per chi non configura nulla.

**Architecture:** un punto unico (`TrailRegistryClasses`) risolve da config la classe di ogni
modello del dominio; le Resource Nova non sono più registrate dal package ma dallo shard, come
`EcTrack`; i tipi di anomalia passano da un registro che unisce l'enum del catasto e i tipi
dichiarati dallo shard; le anomalie hanno una provenienza e il normalize riscrive solo le sue.

**Tech Stack:** PHP ≥ 8.1, Laravel 10–13, Nova 5, PostgreSQL + PostGIS, Pest.

**Spec:** [overview.md](overview.md) di questa cartella, e per il contesto d'uso
`forestas/docs/features/8539-mirror-del-registro-catastale-sulla-scheda-della-traccia/overview.md`.

## Global Constraints

- PHP minimo `>8.1`: **mai `const` dentro un trait**.
- Geometrie PostGIS solo via SQL puro, mai risalvando modelli geometrici con Eloquent.
- Traduzioni del package in `resources/lang/*.json`.
- `composer format` solo sui file toccati (`vendor/bin/pint <file>`), mai sull'intero repo.
- Migration nuove in **uno stub nuovo** sotto `database/migrations/trail_registry/`; lo stub
  `create` esistente non si tocca.
- Test dentro il container: `docker exec php-forestas bash -c "cd wm-package && vendor/bin/pest --filter=<nome>"`.
  La suite del package usa il DB `wm_package` (`phpunit.xml.dist`): verificare che non esista un
  `phpunit.xml` locale senza righe `DB_*` prima di lanciarla.
- Documentazione, commenti e messaggi di commit in italiano.
- Senza configurazione dello shard il comportamento resta identico a oggi.

## Review Focus

1. **Tipo di anomalia sconosciuto in DB** (scritto da uno shard poi rimosso): index e detail della
   Resource Anomalie devono aprirsi comunque, con dettaglio generico — test nel Task 3.
2. **Anomalia senza traccia** (`ec_track_id` null): titolo, dettaglio e mappa non devono dare errore
   né stampare `#` vuoti — test nel Task 5.
3. **Classe configurata inesistente o che non estende quella del package:** errore chiaro all'avvio,
   non ritorno silenzioso alla classe del package — test nel Task 1.
4. **Normalize con anomalie di altra provenienza presenti:** non le cancella, e la verifica
   «anomalia ⇒ nessun codice» vale solo per il catasto — test nel Task 4.
5. **Dominio spento con sottoclassi dello shard presenti in `app/Nova`:** nessuna voce di menu,
   nessun accesso per URL, nessun risultato di ricerca — test nel Task 2.

---

### Task 1: Classi del dominio risolte da config

**Files:**
- Create: `src/TrailRegistry/TrailRegistryClasses.php`
- Modify: `config/wm-package.php` (sezione `features.trail_registry`)
- Modify: `src/TrailRegistry/Models/TrailRegistryCode.php:117,132`,
  `src/TrailRegistry/Models/TrailApplication.php:175,184`,
  `src/TrailRegistry/Models/TrailRegistryCodeEvent.php:48`
- Modify: `src/TrailRegistry/TrailRegistryService.php:125,169,194,240,536,619,804,818,845,883`
- Modify: `src/TrailRegistry/Commands/TrailRegistryNormalizeCommand.php:687`
- Modify: `src/TrailRegistry/Jobs/UpdateTrailApplicationDemJob.php:65`
- Modify: `src/TrailRegistry/Nova/Filters/TrailCodeProvinceFilter.php:27`, `TrailCodeAreaFilter.php:27`,
  `TrailCodeSectorFilter.php:27`, `TrailApplicationSourceFilter.php:26`
- Modify: `src/TrailRegistry/Nova/Actions/ReplaceTrailCodeNumber.php:171`
- Modify: `src/WmPackageServiceProvider.php` (verifica in `packageBooted()`)
- Test: `tests/Feature/TrailRegistry/TrailRegistryClassesTest.php`

**Interfaces:**
- Produces: `TrailRegistryClasses::code(): string`, `::event(): string`, `::application(): string`,
  `::anomaly(): string` (FQCN del modello da usare); `TrailRegistryClasses::assertValid(): void`.

- [ ] **Step 1: test che falliscono**

```php
<?php

use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

class ShardTrailRegistryCode extends TrailRegistryCode {}

it('senza configurazione usa i modelli del package', function () {
    expect(TrailRegistryClasses::code())->toBe(TrailRegistryCode::class);
});

it('usa il modello dichiarato dallo shard, anche nelle relazioni', function () {
    config(['wm-package.features.trail_registry.models.code' => ShardTrailRegistryCode::class]);

    expect(TrailRegistryClasses::code())->toBe(ShardTrailRegistryCode::class);
    expect((new \Wm\WmPackage\TrailRegistry\Models\TrailApplication)->codes()->getRelated())
        ->toBeInstanceOf(ShardTrailRegistryCode::class);
});

it('rifiuta una classe che non esiste', function () {
    config(['wm-package.features.trail_registry.models.code' => 'App\\Models\\Refuso']);

    TrailRegistryClasses::assertValid();
})->throws(InvalidArgumentException::class, 'models.code');

it('rifiuta una classe che non estende quella del package', function () {
    config(['wm-package.features.trail_registry.models.code' => \Wm\WmPackage\Models\EcTrack::class]);

    TrailRegistryClasses::assertValid();
})->throws(InvalidArgumentException::class, 'deve estendere');
```

- [ ] **Step 2: eseguirli e vederli fallire** — `--filter=TrailRegistryClassesTest`, atteso
  «Class TrailRegistryClasses not found».

- [ ] **Step 3: implementazione**

`config/wm-package.php`, dentro `features.trail_registry`:

```php
// Classi dei modelli del dominio. Uno shard le sostituisce con una propria
// sottoclasse impostando la chiave (es. in AppServiceProvider::register()):
// relazioni, service, comandi e Resource leggono da qui. Vedi
// docs/howto/attivare-catasto-sentieri.md.
'models' => [
    'code' => \Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode::class,
    'event' => \Wm\WmPackage\TrailRegistry\Models\TrailRegistryCodeEvent::class,
    'application' => \Wm\WmPackage\TrailRegistry\Models\TrailApplication::class,
    'anomaly' => \Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly::class,
],
```

`src/TrailRegistry/TrailRegistryClasses.php`:

```php
<?php

namespace Wm\WmPackage\TrailRegistry;

use InvalidArgumentException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCodeEvent;

/**
 * Il punto unico da cui il dominio sa quale classe usare per ogni modello.
 *
 * I modelli si richiamano fra loro (un'istanza ha dei codici, un codice ha
 * degli eventi): senza questo punto una sottoclasse dello shard verrebbe
 * scavalcata da ogni relazione scritta con il nome della classe del package.
 */
class TrailRegistryClasses
{
    private const DEFAULTS = [
        'code' => TrailRegistryCode::class,
        'event' => TrailRegistryCodeEvent::class,
        'application' => TrailApplication::class,
        'anomaly' => TrailRegistryAnomaly::class,
    ];

    public static function code(): string
    {
        return self::resolve('code');
    }

    public static function event(): string
    {
        return self::resolve('event');
    }

    public static function application(): string
    {
        return self::resolve('application');
    }

    public static function anomaly(): string
    {
        return self::resolve('anomaly');
    }

    /**
     * Fallisce subito su una classe sbagliata: tornare in silenzio a quella
     * del package e' esattamente il difetto che questo punto deve togliere.
     */
    public static function assertValid(): void
    {
        foreach (self::DEFAULTS as $key => $base) {
            $class = self::resolve($key);

            if (! class_exists($class)) {
                throw new InvalidArgumentException(
                    "wm-package.features.trail_registry.models.{$key}: la classe {$class} non esiste."
                );
            }

            if ($class !== $base && ! is_subclass_of($class, $base)) {
                throw new InvalidArgumentException(
                    "wm-package.features.trail_registry.models.{$key}: {$class} deve estendere {$base}."
                );
            }
        }
    }

    private static function resolve(string $key): string
    {
        return (string) config("wm-package.features.trail_registry.models.{$key}", self::DEFAULTS[$key]);
    }
}
```

Relazioni (esempio per `TrailApplication::codes()`, uguale per le altre):

```php
public function codes(): HasMany
{
    return $this->hasMany(TrailRegistryClasses::code(), 'trail_application_id');
}
```

Nel service, nel comando, nel job, nei filtri e nell'action ogni `TrailRegistryCode::query()` /
`::create()` / `TrailApplication::find()` / `TrailRegistryCodeEvent::create()` /
`TrailRegistryAnomaly::query()` diventa `TrailRegistryClasses::code()::query()` e analoghi. Il
modello del codice ha le relazioni verso istanza ed eventi: stessa sostituzione.

In `WmPackageServiceProvider::packageBooted()`, dentro il ramo del dominio acceso:

```php
if (FeaturesService::isEnabled('trail_registry')) {
    TrailRegistryClasses::assertValid();
}
```

- [ ] **Step 4: eseguire i test** — `--filter=TrailRegistry`: tutta la cartella del dominio deve
  restare verde (le relazioni cambiate sono usate da quasi tutti i test esistenti).

- [ ] **Step 5: verificare che nessun riferimento diretto sia rimasto**

```bash
grep -rn "TrailRegistryCode::\(query\|create\|find\)\|TrailApplication::\(query\|find\)\|TrailRegistryCodeEvent::create\|TrailRegistryAnomaly::query" src
```

Atteso: nessun risultato.

- [ ] **Step 6: commit (a cura del dev)** — `refactor(oc:8539): modelli del Catasto risolti da config`

---

### Task 2: Resource Nova registrate dallo shard, nascoste a dominio spento

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-resource-nova-registrate-dallo-shard)

**Files:**
- Modify: `config/wm-package.php` — togliere `nova_resources` da `features.trail_registry`
- Modify: `src/WmPackageServiceProvider.php:262-265` (resta il supporto generico a
  `nova_resources` per altri domini) e `:388-395` (menu)
- Create: `src/TrailRegistry/Nova/HidesWhenTrailRegistryDisabled.php` (trait, nessuna `const`)
- Modify: `src/TrailRegistry/Nova/TrailRegistryCode.php`, `TrailApplication.php`,
  `TrailRegistryAnomaly.php` — `use` del trait, `$model` da `TrailRegistryClasses`,
  `BelongsTo` verso le Resource del dominio tramite `resourceForModel()`
- Modify: `tests/Feature/TrailRegistry/TrailRegistryDomainRegistrationTest.php`
- Test: `tests/Feature/TrailRegistry/TrailRegistryShardResourcesTest.php`

**Interfaces:**
- Consumes: `TrailRegistryClasses::*()` (Task 1).
- Produces: trait `HidesWhenTrailRegistryDisabled`; le tre Resource del package restano estendibili
  e non vengono registrate da nessuno se lo shard non le estende.

- [ ] **Step 1: test che falliscono**

```php
<?php

use Illuminate\Http\Request;
use Laravel\Nova\Nova;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryCode;

class ShardCodeResource extends TrailRegistryCode {}

it('il package non registra piu le resource del catasto', function () {
    expect(config('wm-package.features.trail_registry.nova_resources'))->toBeNull();
    expect(collect(Nova::$resources))->not->toContain(TrailRegistryCode::class);
});

it('a dominio acceso la sottoclasse dello shard e quella che Nova usa', function () {
    config(['wm-package.features.trail_registry.enabled' => true]);
    Nova::resources([ShardCodeResource::class]);

    expect(Nova::resourceForKey('trail-registry-codes'))->toBe(ShardCodeResource::class);
    expect(ShardCodeResource::availableForNavigation(Request::create('/')))->toBeTrue();
});

it('a dominio spento la sottoclasse non si vede e non si apre', function () {
    config(['wm-package.features.trail_registry.enabled' => false]);
    $request = Request::create('/');

    expect(ShardCodeResource::availableForNavigation($request))->toBeFalse();
    expect(ShardCodeResource::authorizedToViewAny($request))->toBeFalse();
});
```

Nel file esistente `TrailRegistryDomainRegistrationTest.php` il test
«dichiara le resource e il comando del dominio» diventa «dichiara il comando del dominio»: resta la
sola aspettativa su `commands`.

- [ ] **Step 2: eseguirli e vederli fallire.**

- [ ] **Step 3: implementazione**

Trait:

```php
<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Illuminate\Http\Request;
use Wm\WmPackage\Services\FeaturesService;

/**
 * Le Resource del Catasto le registra lo shard, come EcTrack: a dominio
 * spento resterebbero comunque in app/Nova. Il controllo sta qui, nella
 * classe base, perche' lo shard non debba ricordarsene.
 */
trait HidesWhenTrailRegistryDisabled
{
    public static function availableForNavigation(Request $request): bool
    {
        return FeaturesService::isEnabled('trail_registry');
    }

    public static function authorizedToViewAny(Request $request): bool
    {
        return FeaturesService::isEnabled('trail_registry') && parent::authorizedToViewAny($request);
    }

    public function authorizedToView(Request $request): bool
    {
        return FeaturesService::isEnabled('trail_registry') && parent::authorizedToView($request);
    }
}
```

`$model` nelle Resource: Nova legge `static::$model` come proprietà statica, quindi va assegnata
prima dell'uso. Si usa il metodo `newModel()` di Nova:

```php
public static $model = \Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode::class;

public static function newModel()
{
    $class = TrailRegistryClasses::code();

    return new $class;
}
```

`BelongsTo` verso l'istanza in `TrailRegistryCode.php:169`, con il trait
`ResolvesCanonicalResources` già presente:

```php
$applicationResource = static::resourceForModel(TrailRegistryClasses::application(), TrailApplication::class);
BelongsTo::make(__('Istanza'), 'application', $applicationResource)->nullable(),
```

Menu (`WmPackageServiceProvider::trailRegistryMenuItems()`): ogni voce usa la Resource che Nova ha
registrato per il modello, e si omette se lo shard non l'ha registrata:

```php
protected function trailRegistryMenuItems(): array
{
    $items = [
        [TrailRegistryClasses::application(), __('Istanze')],
        [TrailRegistryClasses::code(), __('Registro dei codici')],
        [TrailRegistryClasses::anomaly(), __('Anomalie')],
    ];

    return collect($items)
        ->map(fn (array $item) => [Nova::resourceForModel($item[0]), $item[1]])
        ->filter(fn (array $item) => $item[0] !== null)
        ->map(fn (array $item) => MenuItem::resource($item[0])->name($item[1]))
        ->values()
        ->all();
}
```

- [ ] **Step 4: eseguire i test** — `--filter=TrailRegistry`.

- [ ] **Step 5: commit (a cura del dev)** — `feat(oc:8539): Resource del Catasto registrate dallo shard`

---

### Task 3: Registro dei tipi di anomalia

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-registro-dei-tipi-di-anomalia)

**Files:**
- Create: `src/TrailRegistry/Anomalies/AnomalyTypeDefinition.php` (interfaccia)
- Create: `src/TrailRegistry/Anomalies/TrailRegistryAnomalyTypes.php` (registro)
- Create: `src/TrailRegistry/Anomalies/AnomalyTypeCast.php` (cast Eloquent)
- Modify: `config/wm-package.php` — chiave `features.trail_registry.anomaly_types` (vuota)
- Modify: `src/TrailRegistry/Models/TrailRegistryAnomaly.php:56` (cast)
- Modify: `src/TrailRegistry/Nova/AnomalyDetailRenderer.php:41-60` (ramo per i tipi dello shard e
  per quelli sconosciuti)
- Modify: `src/TrailRegistry/Nova/Filters/TrailAnomalyTypeFilter.php:27-28`
- Modify: `src/TrailRegistry/Nova/TrailRegistryAnomaly.php:40-46` (titolo)
- Test: `tests/Feature/TrailRegistry/TrailRegistryAnomalyTypesTest.php`

**Interfaces:**
- Produces:
  - `interface AnomalyTypeDefinition { public function label(): string; public function detailRows(TrailRegistryAnomaly $anomaly): array; }`
    — `detailRows()` restituisce `list<array{0: string, 1: string}>` (etichetta, HTML già
    sottoposto a escape con `e()`).
  - `TrailRegistryAnomalyTypes::definition(string $type): ?AnomalyTypeDefinition`,
    `::label(string $type): string`, `::values(): list<string>`, `::isKnown(string $type): bool`.
  - Il cast restituisce `TrailRegistryAnomalyType` per i tipi del catasto e `string` per gli altri:
    i confronti `=== TrailRegistryAnomalyType::X` esistenti restano validi.

- [ ] **Step 1: test che falliscono**

```php
<?php

use Wm\WmPackage\TrailRegistry\Anomalies\AnomalyTypeDefinition;
use Wm\WmPackage\TrailRegistry\Anomalies\TrailRegistryAnomalyTypes;
use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Nova\AnomalyDetailRenderer;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryAnomaly as AnomalyResource;

beforeEach(fn () => runTrailRegistryStubs());

class ShardAnomalyType implements AnomalyTypeDefinition
{
    public function label(): string { return 'Riga del registro senza traccia'; }

    public function detailRows(TrailRegistryAnomaly $anomaly): array
    {
        return [['Valore', e($anomaly->context['raw'] ?? '')]];
    }
}

it('un tipo del catasto resta un enum', function () {
    $anomaly = new TrailRegistryAnomaly(['type' => 'codice_gia_assegnato']);

    expect($anomaly->type)->toBe(TrailRegistryAnomalyType::CodiceGiaAssegnato);
});

it('un tipo dello shard si legge e si rende con la sua definizione', function () {
    config(['wm-package.features.trail_registry.anomaly_types' => ['registro_link_orfano' => ShardAnomalyType::class]]);
    $anomaly = new TrailRegistryAnomaly(['type' => 'registro_link_orfano', 'context' => ['raw' => '<b>x</b>']]);

    expect($anomaly->type)->toBe('registro_link_orfano');
    expect(AnomalyDetailRenderer::render($anomaly))->toContain('&lt;b&gt;x&lt;/b&gt;');
    expect(TrailRegistryAnomalyTypes::values())->toContain('registro_link_orfano', 'codice_gia_assegnato');
});

it('un tipo sconosciuto non manda in errore titolo e dettaglio', function () {
    $anomaly = new TrailRegistryAnomaly(['type' => 'tipo_rimosso', 'ec_track_id' => 7]);

    expect((new AnomalyResource($anomaly))->title())->toBe('tipo_rimosso · #7');
    expect(AnomalyDetailRenderer::render($anomaly))->toBeString();
});
```

- [ ] **Step 2: eseguirli e vederli fallire.**

- [ ] **Step 3: implementazione**

Cast:

```php
<?php

namespace Wm\WmPackage\TrailRegistry\Anomalies;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;

/**
 * I tipi del catasto restano l'enum, cosi' ogni confronto esistente continua
 * a funzionare; tutti gli altri — dichiarati da uno shard, o rimasti in
 * tabella dopo che lo shard li ha tolti — restano stringhe. Un cast enum
 * puro solleverebbe ValueError al caricamento e bloccherebbe l'intera lista.
 */
class AnomalyTypeCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): TrailRegistryAnomalyType|string|null
    {
        if ($value === null) {
            return null;
        }

        return TrailRegistryAnomalyType::tryFrom($value) ?? (string) $value;
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        return $value instanceof TrailRegistryAnomalyType ? $value->value : $value;
    }
}
```

Registro:

```php
<?php

namespace Wm\WmPackage\TrailRegistry\Anomalies;

use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;

class TrailRegistryAnomalyTypes
{
    public static function definition(string $type): ?AnomalyTypeDefinition
    {
        $class = self::shardTypes()[$type] ?? null;

        return $class !== null ? app($class) : null;
    }

    public static function label(string $type): string
    {
        return self::definition($type)?->label() ?? $type;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_values(array_unique([
            ...TrailRegistryAnomalyType::values(),
            ...array_keys(self::shardTypes()),
        ]));
    }

    public static function isKnown(string $type): bool
    {
        return in_array($type, self::values(), true);
    }

    /** @return array<string, class-string<AnomalyTypeDefinition>> */
    private static function shardTypes(): array
    {
        return (array) config('wm-package.features.trail_registry.anomaly_types', []);
    }
}
```

`AnomalyDetailRenderer::render()`: prima del `match`, se `$anomaly->type` è una stringa, si usa la
definizione dello shard, oppure una riga generica (tipo + `context` in JSON con `e()`) se il tipo è
sconosciuto; il `match` sull'enum resta com'è per i tipi del catasto.

Titolo in `Nova/TrailRegistryAnomaly.php`:

```php
public function title(): string
{
    $type = $this->resource->type;
    $label = $type instanceof TrailRegistryAnomalyType ? $type->value : ($type ?: __('anomalia'));

    return trim($label.' · #'.$this->resource->ec_track_id);
}
```

Filtro: `collect(TrailRegistryAnomalyTypes::values())->mapWithKeys(fn (string $type) => [TrailRegistryAnomalyTypes::label($type) => $type])`.

- [ ] **Step 4: eseguire i test** — `--filter=TrailRegistry`, compreso
  `TrailRegistryAnomalyResourceTest` e `MapLegendRendererTest` esistenti.

- [ ] **Step 5: commit (a cura del dev)** — `feat(oc:8539): tipi di anomalia del Catasto estendibili`

---

### Task 4: Provenienza delle anomalie e normalize limitato al catasto

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-provenienza-delle-anomalie-e-normalize-limitato-al-catasto)

**Files:**
- Modify: `database/migrations/trail_registry/zz_2026_09_10_000001_create_trail_registry_anomalies_table.php.stub`
- Modify: `src/TrailRegistry/Models/TrailRegistryAnomaly.php` (`source` in `$fillable`, costante
  `SOURCE_CATASTO = 'catasto'` sulla classe, scope `fromSource()`)
- Modify: `src/TrailRegistry/Commands/TrailRegistryNormalizeCommand.php:682-696`
- Test: `tests/Feature/TrailRegistry/TrailRegistryAnomalySourceTest.php`

**Interfaces:**
- Produces: colonna `trail_registry_anomalies.source` (`string(32)`, not null, senza default dopo la
  migration); `ec_track_id` nullable; nessun `CHECK` sul tipo;
  `TrailRegistryAnomaly::SOURCE_CATASTO`; `scopeFromSource(Builder $q, string $source)`.

- [ ] **Step 1: test che falliscono**

```php
<?php

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;

beforeEach(fn () => runTrailRegistryStubs());

it('accetta anomalie senza traccia e di tipo dello shard', function () {
    DB::table('trail_registry_anomalies')->insert([
        'ec_track_id' => null, 'type' => 'registro_link_orfano',
        'source' => 'registro', 'context' => '{}', 'created_at' => now(),
    ]);

    expect(TrailRegistryAnomaly::fromSource('registro')->count())->toBe(1);
});

it('la provenienza non ha default: chi scrive deve dichiararla', function () {
    DB::table('trail_registry_anomalies')->insert([
        'ec_track_id' => null, 'type' => 'x', 'context' => '{}', 'created_at' => now(),
    ]);
})->throws(\Illuminate\Database\QueryException::class);

it('il normalize non tocca le anomalie di altra provenienza', function () {
    DB::table('trail_registry_anomalies')->insert([
        'ec_track_id' => null, 'type' => 'registro_link_orfano',
        'source' => 'registro', 'context' => '{}', 'created_at' => now(),
    ]);

    $this->artisan('wm-package:trail-registry-normalize', ['--force' => true])->assertSuccessful();

    expect(TrailRegistryAnomaly::fromSource('registro')->count())->toBe(1);
});
```

- [ ] **Step 2: eseguirli e vederli fallire.**

- [ ] **Step 3: implementazione**

Stub nuovo (non modificare lo stub `create`):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('trail_registry_anomalies', 'source')) {
            return;
        }

        // Il default serve solo a battezzare le righe gia' presenti, che sono
        // tutte del catasto; poi si toglie, perche' chi scrive un'anomalia
        // dichiari da dove viene invece di finire «catasto» per distrazione
        // — e farsi cancellare dal normalize successivo.
        DB::statement("ALTER TABLE trail_registry_anomalies ADD COLUMN source varchar(32) NOT NULL DEFAULT 'catasto'");
        DB::statement('ALTER TABLE trail_registry_anomalies ALTER COLUMN source DROP DEFAULT');
        DB::statement('CREATE INDEX trail_registry_anomalies_source_index ON trail_registry_anomalies (source)');

        DB::statement('ALTER TABLE trail_registry_anomalies ALTER COLUMN ec_track_id DROP NOT NULL');

        // I tipi ora li dichiara anche lo shard: la garanzia passa al registro
        // dei tipi (TrailRegistryAnomalyTypes), non piu' al vincolo.
        DB::statement('ALTER TABLE trail_registry_anomalies DROP CONSTRAINT IF EXISTS trail_registry_anomalies_type_check');
    }

    public function down(): void
    {
        DB::table('trail_registry_anomalies')->where('source', '!=', 'catasto')->delete();

        DB::statement('ALTER TABLE trail_registry_anomalies ALTER COLUMN ec_track_id SET NOT NULL');
        DB::statement("
            ALTER TABLE trail_registry_anomalies
            ADD CONSTRAINT trail_registry_anomalies_type_check
            CHECK (type IN (
                'codice_gia_assegnato', 'settore_discordante', 'geometria_duplicata',
                'codice_illeggibile', 'fuori_da_ogni_settore'
            ))
        ");
        DB::statement('DROP INDEX IF EXISTS trail_registry_anomalies_source_index');
        DB::statement('ALTER TABLE trail_registry_anomalies DROP COLUMN source');
    }
};
```

Normalize:

```php
DB::transaction(function () use ($anomalies, $now) {
    TrailRegistryClasses::anomaly()::query()
        ->fromSource(TrailRegistryAnomaly::SOURCE_CATASTO)
        ->delete();

    foreach (array_chunk($anomalies, 500) as $chunk) {
        DB::table('trail_registry_anomalies')->insert(array_map(
            fn (array $row) => [...$row, 'source' => TrailRegistryAnomaly::SOURCE_CATASTO, 'created_at' => $now],
            $chunk,
        ));
    }
});
```

Verificare che `runTrailRegistryStubs()` (in `tests/Pest.php`) esegua tutti gli stub del dominio,
compreso quello nuovo; se elenca i file a mano, aggiungerlo.

- [ ] **Step 4: eseguire i test** — `--filter=TrailRegistry`, e
  `php artisan wm-package:publish-missing-migrations --dry-run --with=trail_registry` da forestas:
  deve elencare lo stub nuovo.

- [ ] **Step 5: commit (a cura del dev)** — `feat(oc:8539): provenienza delle anomalie del Catasto`

---

### Task 5: Interfaccia delle Anomalie sovrascrivibile e anomalie senza traccia

**Files:**
- Modify: `src/TrailRegistry/Nova/TrailRegistryAnomaly.php:40-46,81-95,112-172,180`
- Modify: `src/TrailRegistry/Models/TrailRegistryAnomaly.php` (mappa con `ec_track_id` null)
- Test: `tests/Feature/TrailRegistry/TrailRegistryAnomalyResourceTest.php` (casi aggiunti)

**Interfaces:**
- Produces, sovrascrivibili dallo shard:
  - `protected function noticeBody(): string` (esiste già, resta `protected`);
  - `protected function titleFor(TrailRegistryAnomaly $anomaly): string`, chiamato da `title()`;
  - `protected function subjectField(): \Laravel\Nova\Fields\Field` — la colonna «Sentiero»; il
    package la rende `BelongsTo` alla traccia, lo shard può sostituirla.

- [ ] **Step 1: test che falliscono**

```php
it('un anomalia senza traccia ha un titolo senza cancelletto vuoto', function () {
    $anomaly = new TrailRegistryAnomaly(['type' => 'codice_gia_assegnato', 'ec_track_id' => null]);

    expect((new AnomalyResource($anomaly))->title())->toBe('codice_gia_assegnato');
});

it('la mappa di un anomalia senza traccia e una collezione vuota', function () {
    $anomaly = new TrailRegistryAnomaly(['type' => 'registro_link_orfano', 'ec_track_id' => null]);

    expect($anomaly->getFeatureCollectionMap()['features'])->toBe([]);
});
```

- [ ] **Step 2: eseguirli e vederli fallire.**

- [ ] **Step 3: implementazione** — `title()` delega a `titleFor()`, che omette ` · #…` quando
  `ec_track_id` è null; il campo «Sentiero» in `fields()` passa da `subjectField()`; la mappa
  restituisce `['type' => 'FeatureCollection', 'features' => []]` se non c'è traccia; l'ordinamento
  di default diventa `orderBy('source')->orderBy('type')->orderByRaw('ec_track_id NULLS LAST')`.

- [ ] **Step 4: eseguire i test** — `--filter=TrailRegistry`.

- [ ] **Step 5: commit (a cura del dev)** — `feat(oc:8539): Resource Anomalie estendibile dallo shard`

---

### Task 6: Procedura di attivazione e documentazione

**Files:**
- Create: `docs/howto/attivare-catasto-sentieri.md`
- Modify: `docs/resources/TrailRegistry.md` (sezioni «Anomalie», «Configurazione», «Interfaccia
  Nova»; nuova sezione «Estendere il dominio»)
- Modify: `docs/resources/OptionalDomains.md:108-137` e `docs/knowledge/domini-opzionali.md:15-20`
  (il Catasto non registra più le proprie Resource)
- Modify: `CLAUDE.md` del package, sezione «Procedure»

- [ ] **Step 1: scrivere la procedura**, con queste sezioni in quest'ordine, ciascuna con il
  riferimento concreto di forestas:
  1. Interruttore `WM_TRAIL_REGISTRY_ENABLED` e i posti dove va allineato (`.env`, `.env-deploy`,
     `.env-example`, `phpunit.xml`, `.env.testing-example`, `.env` del server).
  2. Migration: `php artisan wm-package:publish-missing-migrations --with=trail_registry`, e il gate
     in CI con lo stesso `--with`.
  3. Configurazione per shard: `legacy_code_property`, `source_url_property`, `source_label`,
     `sector_source`, `name_code_pattern`, `nova_uri_keys`, con i valori di forestas.
  4. Sottoclassi Nova obbligatorie in `app/Nova`: `TrailRegistryCode`, `TrailApplication`,
     `TrailRegistryAnomaly` (anche vuote), e sezione di menu «Catasto» dichiarata nel `mainMenu`.
  5. Estendere: modelli (`models.*`), service (binding nel container), tipi di anomalia
     (`anomaly_types` + `AnomalyTypeDefinition`, provenienza propria obbligatoria).
  6. Normalize: quando lanciarlo rispetto all'import dello shard (su forestas, nella callback del
     batch).
  7. Verifiche finali: le voci del menu, la join «anomalia ⇒ nessun codice» a zero per
     `source = 'catasto'`, i test del dominio.

- [ ] **Step 2: ripercorrere la procedura su forestas** e annotare accanto a ogni passaggio il file
  di forestas che lo realizza; un passaggio senza riscontro è un errore della procedura.

- [ ] **Step 3: aggiornare le tre doc** e aggiungere in `CLAUDE.md`, sezione «Procedure»:
  `Per attivare il Catasto Sentieri su uno shard: [docs/howto/attivare-catasto-sentieri.md](docs/howto/attivare-catasto-sentieri.md).`

- [ ] **Step 4: commit (a cura del dev)** — `docs(oc:8539): procedura di attivazione del Catasto`

---

## Verifica finale

- [ ] `docker exec php-forestas bash -c "cd wm-package && vendor/bin/pest --filter=TrailRegistry"` verde.
- [ ] `docker exec php-forestas bash -c "cd wm-package && composer analyse"` senza errori nuovi.
- [ ] `vendor/bin/pint` sui soli file toccati.
- [ ] PR del package verso `develop`, da mergiare **prima** della PR di forestas.
