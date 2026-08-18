> Ticket: oc:8159

# Tracciamento bacino di utenza per cammino — Piano implementativo

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aggiungere a `LayerAnalyticsCard` una metrica "Utenti sul cammino" (conteggio persone che hanno effettivamente percorso il layer, calcolato via matching GPS↔traccia in PostGIS) e mostrare le posizioni GPS live (ultimi 60 minuti) sulla mappa del layer in Nova.

**Architecture:** Nuovo metodo `AnalyticsService::getUserMovedStats()` interroga PostHog (evento `userMoved`) via HogQL, pre-aggrega i punti per bucket orario, li porta in bulk in Postgres e conta le persone il cui punto GPS cade entro una soglia di distanza (`ST_DWithin`) da almeno una EcTrack del layer. Stesso pattern di cache/TTL già usato da `AnalyticsService` per le altre metriche. La mappa del layer (`Layer::getFeatureCollectionMap()`) aggiunge le posizioni live come Point feature con stile dedicato, usando le property `pointFillColor`/`pointStrokeColor`/`pointRadius`/`pointStrokeWidth` già supportate dal componente Vue `FeatureCollectionMap.vue` — nessuna modifica al rendering dei marker. La legenda testuale è invece un elemento nuovo, non esistente oggi nel campo: richiede una piccola modifica a `DetailField.vue`.

**Tech Stack:** Laravel 11 (wm-package), PHP 8.2+, PostgreSQL/PostGIS, HogQL (PostHog), Vue 3 (Nova custom Card/Field), Pest/PHPUnit (Orchestra Testbench).

## Global Constraints

- Feature interamente in `wm-package` — nessun file del repo principale `camminiditalia`
- `COUNT(DISTINCT person_id)`, non `distinct_id` — coerenza con `AnalyticsService::queryUniqueUsers()` già in uso nella stessa card
- Nuova costante `LAYER_USER_PRESENCE_DISTANCE_METERS` (default 50) — **non** riusare `UGC_LAYER_SEARCH_DISTANCE_METERS`
- Timeout HTTP dedicato (5s) per la query PostHog di questo metodo, distinto dal default 10s di `runQuery()`
- Su fallimento/timeout: il metodo ritorna `null` (mai un'eccezione che fa fallire l'intera risposta di `AnalyticsController::layer()`) — la card mostra "N/D" solo su questa metrica
- Cap esplicito sul numero di punti processati (`MAX_USER_PRESENCE_POINTS = 5000`), con `Log::warning()` se raggiunto
- Cache: 15min/1h/6h per range (30/90/365gg, stesso `TTL_MAP` esistente) per il KPI; 90s dedicati per i punti live sulla mappa
- Nessuna migration DB richiesta (nessuna nuova tabella/colonna)
- Stringhe UI hardcoded in italiano, coerenti con lo stile esistente della card (nessuna i18n)
- PHPStan (CI attivo su questo progetto) deve passare senza nuovi errori sul codice introdotto

---

## Task 0: Verifica schema reale dell'evento `userMoved` in PostHog

Questo task è un prerequisito bloccante per i Task 2-3: il codice esistente di `AnalyticsService` accede solo a property PostHog "flat" (`properties.layer_id`, `properties.$lib`, `properties.track_id`) — nessun precedente di accesso a property JSON annidate come `properties.user_location.latitude`. Anche il filtro `shard_name` richiesto dal ticket non ha un precedente verificato su questo evento specifico (il codice mobile che emette `userMoved`, in `wm-core`, non è ispezionabile da questo repo).

**Files:** nessuno (task di verifica manuale, non produce codice)

- [x] **Step 1: Verifica via API HogQL (eseguita, non serve accesso alla UI PostHog)**

Interrogata direttamente l'API `/api/projects/{id}/query` con le credenziali già in `.env` (stesso meccanismo di `AnalyticsService::runQuery()`) per ispezionare eventi `userMoved` reali. **Risultato — due correzioni rispetto alle assunzioni iniziali:**

1. `user_location` esiste con `latitude`/`longitude` **flat** (confermato, dot-notation `properties.user_location.latitude` funziona in HogQL)
2. **`shard_name` NON è una property piatta** — è un oggetto `{"_value": "camminiditalia"}` (stesso pattern di `app_id`/`app_version`/`app_platform`/`app_build`, verosimilmente super-property registrate una tantum via `initAndRegister()` lato mobile). Il filtro corretto è `properties.shard_name._value = 'camminiditalia'`, non `properties.shard_name = 'camminiditalia'` come assunto nel ticket originale.
3. **Bonus scoperto testando la query di aggregazione**: la funzione `toFloat64OrNull()` non esiste in HogQL (errore `Unsupported function call`) — la funzione corretta è `toFloatOrDefault(valore, default)`, che richiede un default dello stesso tipo del cast (`0.0`, non `null`).

Query di verifica eseguita con successo (risultati reali, bucket per `person_id`/ora su 30gg):
```sql
SELECT person_id,
       avg(toFloatOrDefault(properties.user_location.latitude, 0.0)) AS lat,
       avg(toFloatOrDefault(properties.user_location.longitude, 0.0)) AS lng
FROM events
WHERE event = 'userMoved'
  AND properties.shard_name._value = 'camminiditalia'
  AND properties.user_location IS NOT NULL
  AND timestamp >= now() - INTERVAL 30 DAY
GROUP BY person_id, toStartOfHour(timestamp)
```

I Task 2 e 7 sotto sono già stati scritti con questi nomi/funzioni corretti (non con le assunzioni originali del ticket).

- [x] **Step 2: Documentare l'esito in `notes.md`** — verifica riuscita, nessuna deviazione residua da segnalare come rischio: la correzione è stata applicata direttamente nel piano prima dell'implementazione, non durante l'esecuzione.

---

## Task 1: Nuova costante di configurazione per la distanza di presenza

**Files:**
- Modify: `wm-package/config/wm-package.php`
- Test: nessuno (valore di configurazione, verificato indirettamente dai test del Task 3)

**Interfaces:**
- Produces: `config('wm-package.layer_user_presence_distance_meters')` — intero, usato dal Task 3

- [ ] **Step 1: Aggiungere la chiave di configurazione**

In `wm-package/config/wm-package.php`, subito dopo la riga `'shard_name' => env('SHARD_NAME', env('APP_NAME')),` (riga 6):

```php
'layer_user_presence_distance_meters' => env('LAYER_USER_PRESENCE_DISTANCE_METERS', 50),
```

- [ ] **Step 2: Verificare che la chiave sia leggibile**

Non serve un test dedicato: la lettura di questa chiave viene verificata indirettamente dai test del Task 3 (`AnalyticsServiceUserPresenceTest`), che impostano `config(['wm-package.layer_user_presence_distance_meters' => ...])` per controllare il comportamento della soglia.

- [ ] **Step 3: Commit**

```bash
git add wm-package/config/wm-package.php
git commit -m "feat(oc:8159): add dedicated distance config for user presence matching"
```

---

## Task 2: `AnalyticsService` — query HogQL bucketizzata per i punti GPS

**Files:**
- Modify: `wm-package/src/Services/PostHog/AnalyticsService.php`
- Test: `wm-package/tests/Unit/AnalyticsServiceUserPresenceTest.php` (nuovo file — separato da `AnalyticsServiceTest.php` per isolare i test specifici di questa feature, che usano un pattern di mocking diverso: `Http::fake` per HogQL + inserimento diretto di `EcTrack` con geometria reale)

**Interfaces:**
- Consumes: `AnalyticsService::runQuery(string $sql, bool $strict = false, int $timeoutSeconds = 10): array` — esistente, va estesa con il terzo parametro (Step 1 sotto)
- Consumes: `AnalyticsService::whereClause(string $range): string` — esistente, riusato as-is
- Produces: `AnalyticsService::queryUserMovedBucketedPoints(string $range): array` — privato, ritorna `list<array{person_id: string, lat: float, lng: float}>`, usato dal Task 4

- [ ] **Step 1: Estendere `runQuery()` con un timeout opzionale**

In `wm-package/src/Services/PostHog/AnalyticsService.php`, modificare la firma esistente di `runQuery()` (riga 617) per accettare un timeout opzionale, di default invariato:

```php
/** @return list<list<mixed>> */
private function runQuery(string $sql, bool $strict = false, int $timeoutSeconds = 10): array
{
    $url = "{$this->host}/api/projects/{$this->projectId}/query";

    $response = Http::withToken($this->apiKey)
        ->timeout($timeoutSeconds)
        ->post($url, [
            'query' => [
                'kind' => 'HogQLQuery',
                'query' => $sql,
            ],
        ]);

    if (! $response->successful()) {
        Log::error('PostHog query failed', [
            'status' => $response->status(),
            'body' => $response->body(),
            'sql' => $sql,
        ]);

        if ($strict) {
            throw new AnalyticsQueryException("PostHog query failed with status {$response->status()}");
        }

        return [];
    }

    return $response->json('results', []);
}
```

Questo è l'unico cambiamento a `runQuery()` — tutte le chiamate esistenti (10 chiamate nel file) continuano a compilare senza modifiche perché il terzo parametro ha un default.

- [ ] **Step 2: Scrivere il test per `queryUserMovedBucketedPoints()` (fallirà — metodo non esiste)**

Creare `wm-package/tests/Unit/AnalyticsServiceUserPresenceTest.php`:

```php
<?php

namespace Wm\WmPackage\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use Wm\WmPackage\Services\PostHog\AnalyticsService;

class AnalyticsServiceUserPresenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.posthog.host' => 'https://posthog.example.com',
            'services.posthog.project_id' => '1',
            'services.posthog.personal_api_key' => 'phx_test',
            'wm-package.shard_name' => 'camminiditalia',
            'wm-package.layer_user_presence_distance_meters' => 50,
        ]);
    }

    public function test_bucketed_points_are_normalized(): void
    {
        Http::fake([
            '*' => Http::response([
                'results' => [
                    ['person-1', 43.7, 10.4],
                    ['person-3', 43.71, 10.41],
                ],
            ]),
        ]);

        $service = new AnalyticsService;
        $points = $this->callPrivateMethod($service, 'queryUserMovedBucketedPoints', ['last_30_days']);

        $this->assertCount(2, $points);
        $this->assertSame('person-1', $points[0]['person_id']);
        $this->assertSame(43.7, $points[0]['lat']);
        $this->assertSame(10.4, $points[0]['lng']);
    }

    public function test_logs_warning_when_safety_cap_is_reached(): void
    {
        Log::spy();

        $cap = 5000;
        $rows = [];
        for ($i = 0; $i < $cap; $i++) {
            $rows[] = ["person-{$i}", 43.7, 10.4];
        }

        Http::fake(['*' => Http::response(['results' => $rows])]);

        $service = new AnalyticsService;
        $this->callPrivateMethod($service, 'queryUserMovedBucketedPoints', ['last_365_days']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'safety cap'))
            ->once();
    }

    protected function callPrivateMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }
}
```

- [ ] **Step 3: Eseguire il test per verificare che fallisca**

Run: `docker exec laravel-camminiditalia bash -c "cd wm-package && vendor/bin/phpunit tests/Unit/AnalyticsServiceUserPresenceTest.php"`
Expected: FAIL — `Call to undefined method Wm\WmPackage\Services\PostHog\AnalyticsService::queryUserMovedBucketedPoints()` (via `ReflectionMethod`)

- [ ] **Step 4: Implementare `queryUserMovedBucketedPoints()`**

In `AnalyticsService.php`, aggiungere una costante privata e il metodo (posizionarlo vicino a `queryTotalSearches()`, nella sezione "Core generico"):

```php
private const MAX_USER_PRESENCE_POINTS = 5000;

private const USER_PRESENCE_TIMEOUT_SECONDS = 5;

/** @return list<array{person_id: string, lat: float, lng: float}> */
private function queryUserMovedBucketedPoints(string $range): array
{
    $whereClause = $this->whereClause($range);
    $shardName = (string) config('wm-package.shard_name');
    $limit = self::MAX_USER_PRESENCE_POINTS;

    $sql = <<<SQL
SELECT
    person_id,
    avg(toFloatOrDefault(properties.user_location.latitude, 0.0)) AS lat,
    avg(toFloatOrDefault(properties.user_location.longitude, 0.0)) AS lng
FROM events
WHERE event = 'userMoved'
  AND properties.shard_name._value = '{$shardName}'
  AND properties.user_location IS NOT NULL
  AND {$whereClause}
GROUP BY person_id, toStartOfHour(timestamp)
LIMIT {$limit}
SQL;

    $rows = $this->runQuery($sql, true, self::USER_PRESENCE_TIMEOUT_SECONDS);

    if (count($rows) >= $limit) {
        Log::warning('queryUserMovedBucketedPoints() hit the safety cap — presence count may be underestimated', [
            'range' => $range,
            'limit' => $limit,
        ]);
    }

    // Nessun filtro null qui: la clausola `properties.user_location IS NOT NULL` sopra esclude
    // già a livello SQL le righe senza posizione — toFloatOrDefault() con default 0.0 non
    // restituisce mai null (verificato via query reale in Task 0), quindi un controllo PHP
    // ridondante sarebbe morto e ingannevole.
    return array_map(fn ($row) => [
        'person_id' => (string) $row[0],
        'lat' => (float) $row[1],
        'lng' => (float) $row[2],
    ], $rows);
}
```

- [ ] **Step 5: Eseguire i test per verificare che passino**

Run: `docker exec laravel-camminiditalia bash -c "cd wm-package && vendor/bin/phpunit tests/Unit/AnalyticsServiceUserPresenceTest.php"`
Expected: PASS (2 test)

- [ ] **Step 6: Commit**

```bash
git add wm-package/src/Services/PostHog/AnalyticsService.php wm-package/tests/Unit/AnalyticsServiceUserPresenceTest.php
git commit -m "feat(oc:8159): add bucketed userMoved points query to AnalyticsService"
```

---

## Task 3: `AnalyticsService` — match bulk PostGIS contro le EcTrack del layer

Questo task richiede PostGIS reale (nessun supporto SQLite) — i test vanno scritti come Feature test con `Wm\WmPackage\Tests\TestCase` (non `Orchestra\Testbench\TestCase` come nel Task 2), che si connette al DB Postgres reale del package (`wm_package`), stesso pattern già usato da `LayerServiceUpdateLayersPropertyGuardTest.php`.

**Files:**
- Modify: `wm-package/src/Services/PostHog/AnalyticsService.php`
- Test: `wm-package/tests/Feature/AnalyticsServiceUserPresenceGeoTest.php` (nuovo)

**Interfaces:**
- Consumes: `queryUserMovedBucketedPoints()` (Task 2) — non chiamato direttamente da questo metodo, sono orchestrati insieme nel Task 4
- Produces: `AnalyticsService::countPersonsNearLayerTracks(Layer $layer, array $points): int` — privato, `$points` nello stesso formato prodotto da `queryUserMovedBucketedPoints()`, usato dal Task 4

- [ ] **Step 1: Scrivere il test (fallirà — metodo non esiste)**

Creare `wm-package/tests/Feature/AnalyticsServiceUserPresenceGeoTest.php`:

```php
<?php

namespace Wm\WmPackage\Tests\Feature;

use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\PostHog\AnalyticsService;
use Wm\WmPackage\Tests\TestCase;

class AnalyticsServiceUserPresenceGeoTest extends TestCase
{
    public function test_counts_only_points_within_distance_of_layer_tracks(): void
    {
        config(['wm-package.layer_user_presence_distance_meters' => 50]);

        // Traccia rettilinea nota: da (10.400, 43.700) a (10.410, 43.700)
        $track = EcTrack::factory()->create([
            'geometry' => \DB::raw("ST_GeomFromText('LINESTRING(10.400 43.700, 10.410 43.700)', 4326)"),
        ]);

        $layer = Layer::factory()->create();
        $layer->ecTracks()->attach($track->id);

        $points = [
            // ~5m dalla traccia -> dentro soglia 50m
            ['person_id' => 'near-1', 'lat' => 43.70004, 'lng' => 10.405],
            // ~5km dalla traccia -> fuori soglia
            ['person_id' => 'far-1', 'lat' => 43.75, 'lng' => 10.405],
            // stesso utente near-1 rilevato due volte (bucket diversi) -> contato una sola volta
            ['person_id' => 'near-1', 'lat' => 43.70003, 'lng' => 10.406],
        ];

        $service = new AnalyticsService;
        $count = $this->callPrivateMethod($service, 'countPersonsNearLayerTracks', [$layer, $points]);

        $this->assertSame(1, $count);
    }

    public function test_returns_zero_when_layer_has_no_tracks(): void
    {
        // LayerFactory legge App::first()->id senza fallback: creare l'App esplicitamente
        // (EcTrackFactory lo farebbe implicitamente, ma qui non creiamo alcuna EcTrack di proposito).
        \Wm\WmPackage\Models\App::factory()->create();
        $layer = Layer::factory()->create();

        $service = new AnalyticsService;
        $count = $this->callPrivateMethod($service, 'countPersonsNearLayerTracks', [$layer, [
            ['person_id' => 'x', 'lat' => 43.7, 'lng' => 10.4],
        ]]);

        $this->assertSame(0, $count);
    }

    public function test_returns_zero_when_points_list_is_empty(): void
    {
        $track = EcTrack::factory()->create();
        $layer = Layer::factory()->create();
        $layer->ecTracks()->attach($track->id);

        $service = new AnalyticsService;
        $count = $this->callPrivateMethod($service, 'countPersonsNearLayerTracks', [$layer, []]);

        $this->assertSame(0, $count);
    }
}
```

- [ ] **Step 2: Eseguire il test per verificare che fallisca**

Run: `docker exec laravel-camminiditalia bash -c "cd wm-package && vendor/bin/phpunit tests/Feature/AnalyticsServiceUserPresenceGeoTest.php"`
Expected: FAIL — `Call to undefined method ...::countPersonsNearLayerTracks()`

- [ ] **Step 3: Implementare `countPersonsNearLayerTracks()`**

In `AnalyticsService.php`, aggiungere (vicino a `queryUserMovedBucketedPoints()`):

```php
/** @param list<array{person_id: string, lat: float, lng: float}> $points */
private function countPersonsNearLayerTracks(Layer $layer, array $points): int
{
    if (empty($points)) {
        return 0;
    }

    $trackTable = config('wm-package.ec_track_table', 'ec_tracks');
    $trackIds = $layer->ecTracks()->pluck("{$trackTable}.id")->toArray();

    if (empty($trackIds)) {
        return 0;
    }

    $valuesSql = implode(', ', array_fill(0, count($points), '(?, ?::float8, ?::float8)'));
    $trackPlaceholders = implode(', ', array_fill(0, count($trackIds), '?'));
    $distanceMeters = (int) config('wm-package.layer_user_presence_distance_meters', 50);

    $sql = <<<SQL
SELECT count(DISTINCT v.person_id) AS total
FROM (VALUES {$valuesSql}) AS v(person_id, lat, lng)
WHERE EXISTS (
    SELECT 1 FROM {$trackTable}
    WHERE id IN ({$trackPlaceholders})
      AND geometry IS NOT NULL
      AND ST_DWithin(
          geometry::geography,
          ST_SetSRID(ST_MakePoint(v.lng, v.lat), 4326)::geography,
          ?
      )
)
SQL;

    $bindings = [];
    foreach ($points as $point) {
        $bindings[] = $point['person_id'];
        $bindings[] = $point['lat'];
        $bindings[] = $point['lng'];
    }
    $bindings = array_merge($bindings, $trackIds, [$distanceMeters]);

    /** @var object{total: int}|null $row */
    $row = DB::selectOne($sql, $bindings);

    return (int) ($row->total ?? 0);
}
```

Assicurarsi che `use Illuminate\Support\Facades\DB;` sia già importato in cima al file (verificare — se assente, aggiungerlo).

- [ ] **Step 4: Eseguire i test per verificare che passino**

Run: `docker exec laravel-camminiditalia bash -c "cd wm-package && vendor/bin/phpunit tests/Feature/AnalyticsServiceUserPresenceGeoTest.php"`
Expected: PASS (3 test)

- [ ] **Step 5: Verificare il query plan con `EXPLAIN` (manuale, non automatizzabile in un test)**

Con `php artisan tinker` dentro il container, su un layer reale con più tracce, eseguire la stessa query con `EXPLAIN ANALYZE` prepending alla SQL sopra e verificare che il piano usi un indice spaziale (`GIST`) su `geometry` per la subquery `EXISTS`, non un sequential scan su `ec_tracks`. Se manca un indice GIST su `ec_tracks.geometry`, annotarlo in `notes.md` come follow-up (non aggiungere una migration in questo ciclo se l'indice esiste già altrove — verificare prima con `\d ec_tracks` in psql).

- [ ] **Step 6: Commit**

```bash
git add wm-package/src/Services/PostHog/AnalyticsService.php wm-package/tests/Feature/AnalyticsServiceUserPresenceGeoTest.php
git commit -m "feat(oc:8159): add bulk PostGIS matching for user presence count"
```

---

## Task 4: `AnalyticsService` — orchestrazione, cache, fallback "N/D"

**Files:**
- Modify: `wm-package/src/Services/PostHog/AnalyticsService.php`
- Test: `wm-package/tests/Unit/AnalyticsServiceUserPresenceTest.php` (stesso file del Task 2, nuovi metodi di test)

**Interfaces:**
- Consumes: `queryUserMovedBucketedPoints(string $range): array` (Task 2), `countPersonsNearLayerTracks(Layer $layer, array $points): int` (Task 3), `rememberWithLock(string $cacheKey, string $range, \Closure $callback): mixed` (esistente)
- Produces: `AnalyticsService::getUserMovedStats(Layer $layer, string $range = 'last_30_days'): ?int` — **pubblico**, usato dal Task 5 (`AnalyticsController`). Ritorna `null` su fallimento/timeout, altrimenti un intero `>= 0`.

- [ ] **Step 1: Scrivere i test (falliranno — metodo non esiste)**

Aggiungere a `wm-package/tests/Unit/AnalyticsServiceUserPresenceTest.php`:

```php
    public function test_get_user_moved_stats_returns_null_on_http_failure(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $layer = new \Wm\WmPackage\Models\Layer;
        $layer->id = 1;

        $service = new AnalyticsService;
        $result = $service->getUserMovedStats($layer, 'last_30_days');

        $this->assertNull($result);
    }

    public function test_get_user_moved_stats_returns_zero_when_no_points_found(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $layer = new \Wm\WmPackage\Models\Layer;
        $layer->id = 1;

        $service = new AnalyticsService;
        $result = $service->getUserMovedStats($layer, 'last_30_days');

        $this->assertSame(0, $result);
    }

    public function test_get_user_moved_stats_uses_cache_on_second_call(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $layer = new \Wm\WmPackage\Models\Layer;
        $layer->id = 42;

        $service = new AnalyticsService;
        $service->getUserMovedStats($layer, 'last_30_days');
        $service->getUserMovedStats($layer, 'last_30_days');

        Http::assertSentCount(1);
    }
```

> Nota: `countPersonsNearLayerTracks()` richiede PostGIS reale (Task 3) — questi test usano `results => []` (nessun punto), per cui `countPersonsNearLayerTracks()` non viene mai chiamato (il metodo orchestratore deve fare short-circuit su punti vuoti, vedi Step 3) e i test possono girare su SQLite/Testbench senza toccare PostGIS.

- [ ] **Step 2: Eseguire i test per verificare che falliscano**

Run: `docker exec laravel-camminiditalia bash -c "cd wm-package && vendor/bin/phpunit tests/Unit/AnalyticsServiceUserPresenceTest.php"`
Expected: FAIL — `Call to undefined method ...::getUserMovedStats()`

- [ ] **Step 3: Implementare `getUserMovedStats()`**

In `AnalyticsService.php`, aggiungere nella sezione "Metodi pubblici per modello" (vicino a `getLayerTrackDownloads()`):

```php
public function getUserMovedStats(Layer $layer, string $range = 'last_30_days'): ?int
{
    $cacheKey = "posthog:userMoved:{$layer->id}:presence:{$range}";

    try {
        return $this->rememberWithLock($cacheKey, $range, function () use ($layer, $range) {
            $points = $this->queryUserMovedBucketedPoints($range);

            if (empty($points)) {
                return 0;
            }

            return $this->countPersonsNearLayerTracks($layer, $points);
        });
    } catch (AnalyticsQueryException|\Illuminate\Contracts\Cache\LockTimeoutException $e) {
        Log::error('getUserMovedStats() failed', [
            'layer_id' => $layer->id,
            'range' => $range,
            'error' => $e->getMessage(),
        ]);

        return null;
    }
}
```

- [ ] **Step 4: Eseguire i test per verificare che passino**

Run: `docker exec laravel-camminiditalia bash -c "cd wm-package && vendor/bin/phpunit tests/Unit/AnalyticsServiceUserPresenceTest.php"`
Expected: PASS (5 test totali nel file)

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/Services/PostHog/AnalyticsService.php wm-package/tests/Unit/AnalyticsServiceUserPresenceTest.php
git commit -m "feat(oc:8159): add getUserMovedStats orchestration with cache and null fallback"
```

---

## Task 5: `AnalyticsController` — esporre la metrica nella risposta layer()

**Files:**
- Modify: `wm-package/src/Http/Controllers/Nova/AnalyticsController.php`
- Test: `wm-package/tests/Feature/AnalyticsControllerLayerAuthorizationTest.php` (estendere il file esistente con un nuovo test — leggerlo prima per capire il pattern di setup già usato)

**Interfaces:**
- Consumes: `AnalyticsService::getUserMovedStats(Layer $layer, string $range): ?int` (Task 4)
- Produces: chiave `user_presence` (int|null) nella risposta JSON di `GET /nova-vendor/layer-analytics/{layer}` — consumata dal Task 6 (Vue)

- [ ] **Step 1: Leggere il test esistente per il pattern di setup**

Aprire `wm-package/tests/Feature/AnalyticsControllerLayerAuthorizationTest.php` e annotare come vengono creati Layer/User/route per i test — replicare lo stesso setup nel nuovo test.

- [ ] **Step 2: Scrivere il test (fallirà — chiave assente nella risposta)**

Aggiungere a `AnalyticsControllerLayerAuthorizationTest.php` (o in un nuovo file `AnalyticsControllerUserPresenceTest.php` se il file esistente è dedicato esclusivamente all'autorizzazione — verificare il contenuto al passo precedente e scegliere di conseguenza):

```php
public function test_layer_endpoint_includes_user_presence_key(): void
{
    Http::fake(['*' => Http::response(['results' => []])]);

    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    // LayerFactory legge App::first()->id senza fallback: creare l'App esplicitamente.
    \Wm\WmPackage\Models\App::factory()->create();
    $layer = Layer::factory()->create();

    $response = $this->actingAs($admin, 'web')
        ->getJson("/nova-vendor/layer-analytics/{$layer->id}");

    $response->assertOk();
    $response->assertJsonStructure(['user_presence']);
}
```

- [ ] **Step 3: Eseguire il test per verificare che fallisca**

Run: `docker exec laravel-camminiditalia bash -c "cd wm-package && vendor/bin/phpunit --filter test_layer_endpoint_includes_user_presence_key"`
Expected: FAIL — chiave `user_presence` assente dalla risposta JSON

- [ ] **Step 4: Aggiungere la chiamata nel controller**

In `AnalyticsController.php`, metodo `layer()` (righe 17-37):

```php
public function layer(Request $request, Layer $layer): JsonResponse
{
    abort_unless(
        $request->user()?->hasRole('Administrator') || $layer->user_id === $request->user()?->id,
        403
    );

    $service = app(AnalyticsService::class);
    $range = $this->resolveRange($request);

    try {
        $usage = $service->getLayerUsage($layer->id, $range);
        $trackDownloads = $service->getLayerTrackDownloads($layer, $range);
    } catch (LockTimeoutException $e) {
        return response()->json(['error' => 'analytics_query_failed'], 502);
    }

    $userPresence = $service->getUserMovedStats($layer, $range);

    return response()->json(array_merge($usage, [
        'track_downloads' => $trackDownloads,
        'user_presence' => $userPresence,
    ]));
}
```

Nota: `getUserMovedStats()` non va inserito nel blocco `try/catch` esistente — gestisce già internamente ogni fallimento e ritorna `null`, per design (vedi Task 4). Se fosse dentro il `try`, un'eventuale futura modifica che lo fa lanciare un'eccezione romperebbe silenziosamente questo isolamento.

- [ ] **Step 5: Eseguire il test per verificare che passi**

Run: `docker exec laravel-camminiditalia bash -c "cd wm-package && vendor/bin/phpunit --filter test_layer_endpoint_includes_user_presence_key"`
Expected: PASS

- [ ] **Step 6: Eseguire l'intera suite Feature di Analytics per verificare nessuna regressione**

Run: `docker exec laravel-camminiditalia bash -c "cd wm-package && vendor/bin/phpunit tests/Feature/AnalyticsController*Test.php"`
Expected: PASS (tutti i test, inclusi quelli preesistenti)

- [ ] **Step 7: Commit**

```bash
git add wm-package/src/Http/Controllers/Nova/AnalyticsController.php wm-package/tests/Feature/AnalyticsControllerLayerAuthorizationTest.php
git commit -m "feat(oc:8159): expose user_presence in layer analytics endpoint"
```

---

## Task 6: `LayerAnalyticsCard` — nuova metrica KPI in Vue

**Files:**
- Modify: `wm-package/src/Nova/Cards/LayerAnalytics/resources/js/components/LayerAnalyticsCard.vue`

**Interfaces:**
- Consumes: `data.user_presence` (int|null, dal Task 5) e `card.mode` (`'layer'`|`'global'`, esistente)

Non ci sono test automatizzati per questo componente Vue (nessuna suite di test frontend esiste oggi per le card Nova di questo package — coerente con l'assenza di test per gli altri elementi della stessa card). La verifica è manuale (Step 3).

- [ ] **Step 1: Aggiungere il quarto box KPI**

In `LayerAnalyticsCard.vue`, nella sezione "KPI row" (righe 43-56), aggiungere un quarto box **solo quando `card.mode !== 'global'`** (il conteggio è per-layer, non ha senso in modalità aggregata su tutti i layer — l'endpoint `global()` non espone `user_presence`):

```html
      <div style="display:flex; gap:16px; margin-bottom:24px;">
        <div style="flex:1; background:#f9fafb; border-radius:8px; padding:16px; text-align:center;">
          <p style="font-size:2rem; font-weight:700; color:#10b981; margin:0;">{{ data.total }}</p>
          <p style="font-size:0.75rem; color:#6b7280; margin:4px 0 0;">Aperture totali</p>
        </div>
        <div style="flex:1; background:#f9fafb; border-radius:8px; padding:16px; text-align:center;">
          <p style="font-size:2rem; font-weight:700; color:#10b981; margin:0;">{{ data.unique_users }}</p>
          <p style="font-size:0.75rem; color:#6b7280; margin:4px 0 0;">Utenti unici</p>
        </div>
        <div style="flex:1; background:#f9fafb; border-radius:8px; padding:16px; text-align:center;">
          <p style="font-size:2rem; font-weight:700; color:#10b981; margin:0;">{{ avgPerDay }}</p>
          <p style="font-size:0.75rem; color:#6b7280; margin:4px 0 0;">Media/giorno</p>
        </div>
        <div v-if="card.mode !== 'global'" style="flex:1; background:#f9fafb; border-radius:8px; padding:16px; text-align:center;">
          <p style="font-size:2rem; font-weight:700; margin:0;" :style="{ color: userPresenceDisplay === 'N/D' ? '#9ca3af' : '#16a34a' }">{{ userPresenceDisplay }}</p>
          <p style="font-size:0.75rem; color:#6b7280; margin:4px 0 0;">Utenti sul cammino</p>
        </div>
      </div>
```

- [ ] **Step 2: Aggiungere il computed `userPresenceDisplay`**

Nella sezione `computed` (dopo `avgPerDay()`, riga 370):

```javascript
    userPresenceDisplay() {
      const value = this.data?.user_presence
      return value === null || value === undefined ? 'N/D' : value
    },
```

- [ ] **Step 3: Verifica manuale**

Avviare l'ambiente di sviluppo (`composer run dev` dentro il container), aprire in Nova il detail di un Layer con analytics abilitato. Verificare:
1. Il quarto box "Utenti sul cammino" appare accanto agli altri tre, solo in modalità layer (non nella card globale sull'index Layer)
2. Se l'endpoint risponde con `user_presence: null` (es. forzando temporaneamente un errore HTTP lato PostHog in `.env`), il box mostra "N/D" in grigio invece di un numero verde

- [ ] **Step 4: Commit**

```bash
git add wm-package/src/Nova/Cards/LayerAnalytics/resources/js/components/LayerAnalyticsCard.vue
git commit -m "feat(oc:8159): add Utenti sul cammino KPI to LayerAnalyticsCard"
```

---

## Task 7: `Layer::getFeatureCollectionMap()` — punti GPS live con cache breve

**Files:**
- Modify: `wm-package/src/Models/Layer.php`
- Test: `wm-package/tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php` (nuovo)

**Interfaces:**
- Consumes: `AnalyticsService` (nuovo metodo da aggiungere in questo task, vedi Step 1) — non riusa `getUserMovedStats()` del Task 4 (quello conta persone nel range 30/90/365gg contro le tracce; qui serve invece l'elenco delle posizioni recenti degli ultimi 60 minuti, un dato diverso)
- Produces: Point feature aggiuntive nel GeoJSON ritornato da `getFeatureCollectionMap()`, con `properties.pointFillColor`/`pointStrokeColor`/`pointRadius`/`pointStrokeWidth`/`tooltip` — consumate da `FeatureCollectionMap.vue` (già esistente, nessuna modifica al rendering)

- [ ] **Step 1: Aggiungere in `AnalyticsService` il metodo per le posizioni recenti**

Questo è un metodo diverso da `queryUserMovedBucketedPoints()` (Task 2): non aggrega per bucket, prende solo gli ultimi 60 minuti, e ritorna anche `person_id` per il tooltip (nessun nome/dato identificativo, solo un contatore). Aggiungere in `AnalyticsService.php`:

```php
/** @return list<array{lat: float, lng: float}> */
public function getRecentUserPositions(Layer $layer, int $minutesWindow = 60): array
{
    $cacheKey = "posthog:userMoved:{$layer->id}:recent_positions";

    try {
        return Cache::remember($cacheKey, now()->addSeconds(90), function () use ($minutesWindow) {
            $shardName = (string) config('wm-package.shard_name');

            $sql = <<<SQL
SELECT
    avg(toFloatOrDefault(properties.user_location.latitude, 0.0)) AS lat,
    avg(toFloatOrDefault(properties.user_location.longitude, 0.0)) AS lng
FROM events
WHERE event = 'userMoved'
  AND properties.shard_name._value = '{$shardName}'
  AND properties.user_location IS NOT NULL
  AND timestamp >= now() - INTERVAL {$minutesWindow} MINUTE
GROUP BY person_id
LIMIT 500
SQL;

            $rows = $this->runQuery($sql, true, self::USER_PRESENCE_TIMEOUT_SECONDS);

            return array_map(fn ($row) => ['lat' => (float) $row[0], 'lng' => (float) $row[1]], $rows);
        });
    } catch (AnalyticsQueryException $e) {
        Log::error('getRecentUserPositions() failed', ['layer_id' => $layer->id, 'error' => $e->getMessage()]);

        return [];
    }
}
```

> Nota: qui il match geografico contro le tracce del layer **non viene ripetuto** — i punti mostrati sulla mappa sono tutte le posizioni recenti a livello di shard, non filtrate per layer, per due motivi pratici: (a) il volume in una finestra di 60 minuti è già intrinsecamente piccolo (cap 500, non 5000), (b) `getFeatureCollectionMap()` è già una chiamata potenzialmente pesante (traccia + POI + taxonomy_wheres) e ripetere qui il join PostGIS bulk del Task 3 raddoppierebbe il costo per un guadagno marginale nella finestra di soli 60 minuti. **Questo è un compromesso esplicito**: alcuni punti mostrati sulla mappa di un layer potrebbero essere in realtà lontani dal cammino (nessun filtro di prossimità sui punti live). Annotare questa scelta in `notes.md` come decisione presa in fase di implementazione, non prevista esplicitamente nell'overview — se il dev la considera inaccettabile in review, il fix è applicare lo stesso filtro `ST_DWithin` del Task 3 anche qui, al costo di un'altra query bulk per ogni apertura di pagina Layer (mitigata dalla stessa cache 90s).

- [ ] **Step 2: Scrivere il test per l'integrazione in `getFeatureCollectionMap()`**

Creare `wm-package/tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php`:

```php
<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Tests\TestCase;

class LayerFeatureCollectionMapUserPresenceTest extends TestCase
{
    public function test_recent_positions_are_added_as_point_features_with_distinct_style(): void
    {
        Http::fake([
            '*' => Http::response(['results' => [[43.7, 10.4], [43.71, 10.41]]]),
        ]);

        // LayerFactory legge App::first()->id senza fallback: creare l'App esplicitamente.
        \Wm\WmPackage\Models\App::factory()->create();
        $layer = Layer::factory()->create();
        $geojson = $layer->getFeatureCollectionMap();

        $userPositionFeatures = array_filter(
            $geojson['features'],
            fn ($f) => ($f['properties']['tooltip'] ?? null) === 'Posizione utente (ultimi 60 minuti)'
        );

        $this->assertCount(2, $userPositionFeatures);
        foreach ($userPositionFeatures as $feature) {
            $this->assertSame('Point', $feature['geometry']['type']);
            $this->assertStringContainsString('34, 197, 94', $feature['properties']['pointFillColor']);
        }
    }

    public function test_no_point_features_added_when_no_recent_positions(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        // LayerFactory legge App::first()->id senza fallback: creare l'App esplicitamente.
        \Wm\WmPackage\Models\App::factory()->create();
        $layer = Layer::factory()->create();
        $geojson = $layer->getFeatureCollectionMap();

        $userPositionFeatures = array_filter(
            $geojson['features'],
            fn ($f) => ($f['properties']['tooltip'] ?? null) === 'Posizione utente (ultimi 60 minuti)'
        );

        $this->assertCount(0, $userPositionFeatures);
    }
}
```

- [ ] **Step 3: Eseguire il test per verificare che fallisca**

Run: `docker exec laravel-camminiditalia bash -c "cd wm-package && vendor/bin/phpunit tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php"`
Expected: FAIL — nessuna feature con quel tooltip viene prodotta

- [ ] **Step 4: Aggiungere le Point feature in `getFeatureCollectionMap()`**

In `Layer.php`, subito prima del `return` finale (righe 492-496):

```php
        $recentPositions = app(\Wm\WmPackage\Services\PostHog\AnalyticsService::class)->getRecentUserPositions($this);
        foreach ($recentPositions as $position) {
            $this->addFeaturesForMap([[
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$position['lng'], $position['lat']],
                ],
                'properties' => [
                    'tooltip' => 'Posizione utente (ultimi 60 minuti)',
                    'pointFillColor' => 'rgba(34, 197, 94, 0.9)',
                    'pointStrokeColor' => 'rgba(255, 255, 255, 1)',
                    'pointStrokeWidth' => 3,
                    'pointRadius' => 8,
                ],
            ]]);
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $this->getAdditionalFeaturesForMap(),
        ];
    }
}
```

`pointRadius: 8` (contro il default 6 usato implicitamente dagli EcPoi, che non impostano questa property) e `pointStrokeWidth: 3` (contro il default 2) rendono il marker visibilmente più grande e con un anello bianco più spesso — distinzione di forma oltre al colore, per l'accessibilità (nessuna modifica al componente Vue: tutte queste property sono già supportate da `getFeatureStyle()` in `FeatureCollectionMap.vue`, righe 224-228).

- [ ] **Step 5: Eseguire i test per verificare che passino**

Run: `docker exec laravel-camminiditalia bash -c "cd wm-package && vendor/bin/phpunit tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php"`
Expected: PASS (2 test)

- [ ] **Step 6: Eseguire la suite Feature di Layer per verificare nessuna regressione su `getFeatureCollectionMap()`**

Run: `docker exec laravel-camminiditalia bash -c "cd wm-package && vendor/bin/phpunit --filter FeatureCollectionMap"`
Expected: PASS (tutti i test esistenti + i 2 nuovi)

- [ ] **Step 7: Commit**

```bash
git add wm-package/src/Services/PostHog/AnalyticsService.php wm-package/src/Models/Layer.php wm-package/tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php
git commit -m "feat(oc:8159): add live user position points to layer map GeoJSON"
```

---

## Task 8: Legenda testuale sulla mappa (unica modifica frontend con rebuild dist)

A differenza della colorazione dei marker (Task 7, già supportata via property GeoJSON senza toccare il Vue), **nessun meccanismo di legenda esiste oggi** in `FeatureCollectionMap.vue`/`DetailField.vue` — verificato che il termine "legend" non compare in nessun file sorgente del campo. Questo è un elemento nuovo, richiede una piccola modifica al componente e il rebuild del dist.

**Files:**
- Modify: `wm-package/src/Nova/Fields/FeatureCollectionMap/resources/js/components/DetailField.vue`
- Rebuild: `wm-package/src/Nova/Fields/FeatureCollectionMap/dist/`

**Interfaces:**
- Consumes: nessuna nuova prop dal backend — la legenda è statica (sempre gli stessi 4 tipi: traccia, POI, area, posizione utente), non derivata dai dati del GeoJSON

- [ ] **Step 1: Aggiungere la legenda nel template**

In `DetailField.vue`, dentro `<template #value>`, subito dopo il componente `<FeatureCollectionMap ... />` (dopo la riga 12, prima del `<component v-if="field.popupComponent" ...>` alla riga 15):

```html
            <div style="display:flex; gap:16px; align-items:center; margin-top:8px; font-size:0.75rem; color:#6b7280; flex-wrap:wrap;">
                <span style="display:flex; align-items:center; gap:6px;">
                    <span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:rgba(34, 197, 94, 0.9); border:2px solid rgba(255,255,255,1); box-shadow:0 0 0 1px rgba(0,0,0,0.15);"></span>
                    Posizione utente (ultimi 60 minuti)
                </span>
            </div>
```

> Nota: la legenda mostra solo la voce "Posizione utente" — le voci per traccia/POI/taxonomy_where non esistono già oggi (nessuna legenda pregressa), aggiungerle tutte è fuori scope per questo ticket (l'overview richiede la legenda solo per il nuovo elemento). Se in review il dev preferisce una legenda completa con tutti i tipi di feature, va trattato come richiesta aggiuntiva, non come parte di questo task.

- [ ] **Step 2: Verifica manuale**

Avviare l'ambiente (`composer run dev`), aprire il detail di un Layer in Nova. Verificare che la legenda appaia sotto la mappa, sempre visibile (non condizionata alla presenza di punti live — mostrarla comunque evita un "salto" di layout quando i punti appaiono/scompaiono tra un poll e l'altro).

- [ ] **Step 3: Rebuild del dist**

```bash
docker exec laravel-camminiditalia bash -c "cd wm-package/src/Nova/Fields/FeatureCollectionMap && npm run prod"
```

- [ ] **Step 4: Verificare il diff del dist prima di committare**

```bash
git diff --stat wm-package/src/Nova/Fields/FeatureCollectionMap/dist/
```

Controllare che il diff contenga solo le modifiche attese (l'aggiunta della legenda) — nessuna prop/dipendenza spuria introdotta dal processo di build (vedi precedenti oc:8093, oc:7546 in `wm-package/CLAUDE.md`). Se il diff contiene modifiche non spiegabili dalla sola aggiunta della legenda (es. hash di versione Vue diversi, blocchi di codice non correlati), NON committare: investigare la causa prima di procedere (probabile causa: versione di `npm`/dipendenze diversa da quella usata per l'ultima build committata).

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/Nova/Fields/FeatureCollectionMap/resources/js/components/DetailField.vue wm-package/src/Nova/Fields/FeatureCollectionMap/dist/
git commit -m "feat(oc:8159): add legend for live user position marker on layer map"
```

---

## Task 9: Verifica finale — PHPStan e suite completa

**Files:** nessuno (task di verifica, nessuna modifica di codice prevista salvo fix di errori emersi)

- [ ] **Step 1: Eseguire PHPStan**

```bash
docker exec laravel-camminiditalia vendor/bin/phpstan analyse --error-format=table
```

Expected: nessun nuovo errore sui file toccati da questo piano (`AnalyticsService.php`, `AnalyticsController.php`, `Layer.php`). Se emergono errori, fixarli seguendo le convenzioni già usate nel resto del file (es. tipi di ritorno espliciti, `@param`/`@return` PHPDoc dove PHPStan non riesce a inferire).

- [ ] **Step 2: Eseguire l'intera suite di test del package toccata da questo piano**

```bash
docker exec laravel-camminiditalia bash -c "cd wm-package && vendor/bin/phpunit tests/Unit/AnalyticsServiceUserPresenceTest.php tests/Feature/AnalyticsServiceUserPresenceGeoTest.php tests/Feature/AnalyticsControllerLayerAuthorizationTest.php tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php"
```

Expected: PASS su tutti i file.

- [ ] **Step 3: Verifica manuale end-to-end**

In Nova, apertura del detail di un Layer con analytics abilitato: confermare che (a) il quarto box KPI mostra un numero (o "N/D" se PostHog non risponde), (b) la mappa mostra eventuali punti verdi con la legenda sotto, (c) nessun errore in console browser.

- [ ] **Step 4: Nessun commit in questo task** — è solo verifica; eventuali fix vanno committati con il proprio messaggio descrittivo (`fix(oc:8159): ...`).
