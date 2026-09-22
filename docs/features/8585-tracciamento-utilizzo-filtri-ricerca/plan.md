> Ticket: oc:8585

# Aggregazione e visualizzazione dell'uso dei filtri avanzati (route) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aggiungere ad `AnalyticsService` un nuovo metodo `getRouteFilterUsage()` che conta gli
usi dei 7 filtri del pannello "filtro avanzato" (route) camminiditalia, esporlo da
`AnalyticsController::global()`, e mostrarlo in `LayerAnalyticsCard.vue` come nuovo grafico a
barre impilate per piattaforma, subito dopo "Cammini più frequentati".

**Architecture:** L'evento PostHog `filterUsed`/`filter_type:'route'` esiste già lato frontend
(fuori scope). Il nuovo metodo recupera righe grezze (non aggregate in HogQL, per permettere un
dedup temporale che HogQL in questo ambiente non garantisce in modo affidabile — vedi
`Global Constraints`), le raggruppa in PHP con una finestra di 5s per `(session_id, filter_id)`, e
restituisce sempre le 7 righe note (anche a zero). Stesso pattern di cache/lock/filtro-shard delle
altre metriche della classe.

**Tech Stack:** Laravel 12 / PHP 8.4 (ambiente Docker `php-forestas`), Pest (`composer test`),
PHPStan (`composer analyse`), Vue 2 + Chart.js (card Nova, build già esistente, nessuna nuova
toolchain).

**Spec:** [docs/features/8585-tracciamento-utilizzo-filtri-ricerca/overview.md](overview.md)

## Global Constraints

- PHP minimo `>8.1`: mai `const` dentro un trait (non toccato in questo piano, nessun trait
  coinvolto).
- `composer format` (Pint) senza scope riformatta l'intero repo — se usato, controllare
  `git status` dopo e scartare ogni file fuori da questo lavoro.
- Nessuna migrazione DB, nessuna migrazione artisan, nessun breaking change di API pubblica.
- Documentazione, commenti e messaggi di commit in italiano; termini tecnici in inglese.
- Test PHP: `composer test` → `vendor/bin/pest`, dentro il container Docker `php-forestas`
  (`docker exec -it php-forestas bash`, poi da `wm-package/`).
- PHPStan: `composer analyse` deve passare senza nuovi errori (`has_phpstan_ci: true`).
- Non ci si fida di window function HogQL non già verificate in questo ambiente:
  `leadInFrame()` è nota per non essere affidabile qui (commento su `queryTopSearchQueries()` in
  `src/Services/PostHog/AnalyticsService.php`) — per questo il dedup a 5s è fatto in PHP, non in
  HogQL con `lag()`/`lagInFrame()`.
- Ogni query PostHog aggiunge il filtro shard via `whereClause()` (mai duplicarlo a mano).
- Commit scope: `feat(oc:8585): ...` per tutti i commit di questo piano. Nessun commit o branch
  va eseguito automaticamente: sono istruzioni testuali per chi esegue il piano.

## Review Focus

- **Formato reale del `timestamp` restituito da PostHog per un evento grezzo** (mai usato per
  intero in questo service finora, solo troncato a giorno via `toDate()` in
  `queryDailyBreakdown()`): se `Carbon::parse()` interpreta il formato o il fuso orario in modo
  diverso da quanto atteso, il dedup a 5s sballa senza errore visibile. Va verificato manualmente
  contro una risposta HogQL reale prima del rilascio (non solo contro i fixture di test).
- **Sessione con eventi su più `lib` diversi entro la stessa finestra di 5s** (utente che cambia
  rete/contesto a metà sessione): il dedup raggruppa per `(session_id, filter_id)` ignorando `lib`
  nel confronto temporale — un evento web e uno mobile ravvicinati collassano come un solo
  "utilizzo", e il breakdown per piattaforma nel risultato riflette solo la piattaforma
  dell'evento effettivamente tenuto, non di quello scartato.
- **Volume di righe grezze su range ampi** (`last_365_days`): a differenza delle altre query di
  questa classe (aggregate in HogQL), questa recupera righe non aggregate — un cap di sicurezza
  con `Log::warning` (pattern già usato per il ranking globale layer) mitiga ma non elimina il
  rischio di risposte enormi o query lente.
- **`filter_id` assente o vuoto in un evento malformato**: finirebbe loggato come "sconosciuto"
  con valore vuoto — comportamento accettabile ma non esplicitamente testato in questo piano.
- **Cache stantia dopo un fix**: fino allo scadere del TTL (fino a 21600s) la card mostra dati
  basati sulla versione precedente del codice — limite già noto e accettato per le altre metriche
  cache di questa classe, non specifico di questo lavoro.

---

## Task 1: `AnalyticsService::getRouteFilterUsage()` — query grezza, dedup a 5s, 7 righe fisse

**Files:**
- Modify: `src/Services/PostHog/AnalyticsService.php` (nuove costanti dopo riga 41, nuovi metodi
  dopo la chiusura di `getAllLayersUserPresence()` a riga 171, prima di
  `getLayerTrackDownloads()`)
- Test: `tests/Unit/AnalyticsServiceTest.php` (nuova sezione dopo il blocco "Ranking globale layer
  (getAllLayersUsage)", subito prima del commento `// Gestione errori HTTP` a riga 340)

**Interfaces:**
- Produces: `AnalyticsService::getRouteFilterUsage(string $range = 'last_30_days'): array` — ogni
  elemento: `['filter_id' => string, 'name' => string, 'total' => int, 'breakdown' => [['lib' =>
  string, 'total' => int], ...]]`, sempre 7 elementi (uno per `ROUTE_FILTER_LABELS`), ordinati per
  `total` decrescente.

- [ ] **Step 1: Scrivi i test che fissano la forma base del risultato (sempre 7 righe, anche a
      zero)**

Aggiungi in `tests/Unit/AnalyticsServiceTest.php`, dopo il blocco esistente
"Ranking globale layer (getAllLayersUsage)" (dopo la chiusura di
`test_get_all_layers_usage_truncates_to_20_after_filtering_orphans`, prima del commento
`// Gestione errori HTTP`):

```php
    // -------------------------------------------------------------------------
    // Uso dei filtri route (getRouteFilterUsage)
    // -------------------------------------------------------------------------

    public function test_get_route_filter_usage_always_returns_seven_rows(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $result = (new AnalyticsService)->getRouteFilterUsage('last_30_days');

        $this->assertCount(7, $result);
        $filterIds = array_column($result, 'filter_id');
        $this->assertEqualsCanonicalizing(
            ['distance', 'stageCount', 'shape', 'walkingNetwork', 'regions', 'themes', 'seasons'],
            $filterIds
        );
        foreach ($result as $row) {
            $this->assertSame(0, $row['total']);
            $this->assertSame([], $row['breakdown']);
        }
    }

    public function test_get_route_filter_usage_labels_match_italian_names(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $result = (new AnalyticsService)->getRouteFilterUsage('last_30_days');

        $byId = array_column($result, 'name', 'filter_id');
        $this->assertSame('Lunghezza', $byId['distance']);
        $this->assertSame('Tappe', $byId['stageCount']);
        $this->assertSame('Tipologia', $byId['shape']);
        $this->assertSame('Portata', $byId['walkingNetwork']);
        $this->assertSame('Regioni', $byId['regions']);
        $this->assertSame('Temi', $byId['themes']);
        $this->assertSame('Stagioni', $byId['seasons']);
    }

    public function test_get_route_filter_usage_counts_single_event(): void
    {
        Http::fake(['*' => Http::response(['results' => [
            ['distance', 's1', 'web', '2026-06-01 10:00:00'],
        ]])]);

        $result = (new AnalyticsService)->getRouteFilterUsage('last_30_days');

        $byId = array_column($result, null, 'filter_id');
        $this->assertSame(1, $byId['distance']['total']);
        $this->assertSame([['lib' => 'web', 'total' => 1]], $byId['distance']['breakdown']);
        $this->assertSame(0, $byId['themes']['total']);
    }

    public function test_get_route_filter_usage_orders_by_total_descending(): void
    {
        Http::fake(['*' => Http::response(['results' => [
            ['distance', 's1', 'web', '2026-06-01 10:00:00'],
            ['themes', 's1', 'web', '2026-06-01 10:00:20'],
            ['themes', 's2', 'web', '2026-06-01 10:00:00'],
        ]])]);

        $result = (new AnalyticsService)->getRouteFilterUsage('last_30_days');

        $this->assertSame('themes', $result[0]['filter_id']);
        $this->assertSame(2, $result[0]['total']);
        $this->assertSame('distance', $result[1]['filter_id']);
        $this->assertSame(1, $result[1]['total']);
    }
```

- [ ] **Step 2: Esegui i test e verifica che falliscano** (il metodo non esiste ancora)

Run: `docker exec -it php-forestas bash -lc "cd wm-package && vendor/bin/pest --filter=test_get_route_filter_usage"`
Expected: FAIL con `Call to undefined method Wm\WmPackage\Services\PostHog\AnalyticsService::getRouteFilterUsage()`

- [ ] **Step 3: Aggiungi le costanti**

In `src/Services/PostHog/AnalyticsService.php`, subito dopo la dichiarazione di
`MAX_RECENT_POSITIONS` (riga 38) e prima di `USER_PRESENCE_TIMEOUT_SECONDS`:

```php
    /** Etichette italiane dei 7 filtri "route" del pannello avanzato camminiditalia (oc:8414) — insieme chiuso, coerente con `RouteFilterState` in wm-types. Un filter_id fuori da questa lista è un segnale di drift col frontend, non un valore valido da mostrare come riga fissa. */
    private const ROUTE_FILTER_LABELS = [
        'distance' => 'Lunghezza',
        'stageCount' => 'Tappe',
        'shape' => 'Tipologia',
        'walkingNetwork' => 'Portata',
        'regions' => 'Regioni',
        'themes' => 'Temi',
        'seasons' => 'Stagioni',
    ];

    /** Finestra di raggruppamento per il dedup lato PHP di `filterUsed`/route: eventi consecutivi della stessa sessione/filtro con un gap <= a questo valore contano come un solo utilizzo (oc:8585). */
    private const ROUTE_FILTER_DEDUP_WINDOW_SECONDS = 5;

    /** Cap di sicurezza sulle righe grezze recuperate (non aggregate in HogQL, a differenza delle altre query di questa classe) — stesso principio del cap 1000 righe di queryAllLayersRanking(). */
    private const ROUTE_FILTER_MAX_ROWS = 5000;
```

E subito dopo `USER_PRESENCE_TIMEOUT_SECONDS = 5;` (riga 41):

```php

    /** Timeout dedicato per la query grezza di getRouteFilterUsage() — righe non aggregate, potenzialmente più pesante del default 10s; non deve rallentare l'intera global(). */
    private const ROUTE_FILTER_TIMEOUT_SECONDS = 5;
```

- [ ] **Step 4: Implementa `queryRouteFilterEvents()` e `getRouteFilterUsage()`**

In `src/Services/PostHog/AnalyticsService.php`, subito dopo la chiusura di
`getAllLayersUserPresence()` (riga 171) e prima di `public function getLayerTrackDownloads(...)`,
aggiungi:

```php
    /**
     * Conteggio degli usi dei 7 filtri del pannello "avanzato" (route) della search bar
     * camminiditalia, con breakdown per piattaforma. Le 7 righe sono sempre presenti (anche a
     * zero): a differenza delle classifiche aperte di questa classe, qui l'insieme è chiuso e
     * noto (vedi ROUTE_FILTER_LABELS) — un filtro mai usato è il dato più interessante per
     * l'obiettivo del ticket (capire quali filtri rimuovere), non un dato da nascondere.
     *
     * Conteggio grezzo degli eventi (non utenti unici), con dedup a 5s lato PHP: vedi
     * dedupeAndCountRouteFilterEvents() per il perché non è fatto in HogQL.
     */
    public function getRouteFilterUsage(string $range = 'last_30_days'): array
    {
        $cacheKey = "posthog:filterUsed:route:ranking:{$range}";

        return $this->rememberWithLock($cacheKey, $range, fn () => $this->buildRouteFilterUsage($range));
    }

    private function buildRouteFilterUsage(string $range): array
    {
        $rows = $this->queryRouteFilterEvents($range);
        $counts = $this->dedupeAndCountRouteFilterEvents($rows);

        $result = [];
        foreach (self::ROUTE_FILTER_LABELS as $filterId => $label) {
            $entry = $counts[$filterId] ?? ['total' => 0, 'breakdown' => []];
            $result[] = [
                'filter_id' => $filterId,
                'name' => $label,
                'total' => $entry['total'],
                'breakdown' => $entry['breakdown'],
            ];
        }

        usort($result, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $result;
    }

    /**
     * Righe grezze (non aggregate): il dedup a 5s per sessione richiede l'ordine cronologico dei
     * singoli eventi, che un GROUP BY in HogQL distruggerebbe. ORDER BY session_id, filter_id,
     * timestamp: fondamentale, dedupeAndCountRouteFilterEvents() assume questo ordine per
     * riconoscere le sequenze consecutive della stessa (sessione, filtro).
     */
    private function queryRouteFilterEvents(string $range): array
    {
        $whereClause = $this->whereClause($range);
        $libs = $this->libList();
        $limit = self::ROUTE_FILTER_MAX_ROWS;

        $sql = <<<SQL
SELECT
    properties.filter_id AS filter_id,
    properties.\$session_id AS session_id,
    properties.\$lib AS lib,
    toString(timestamp) AS ts
FROM events
WHERE event = 'filterUsed'
  AND properties.filter_type = 'route'
  AND properties.\$lib IN ({$libs})
  AND {$whereClause}
ORDER BY session_id, filter_id, timestamp
LIMIT {$limit}
SQL;

        $rows = $this->runQuery($sql, true, self::ROUTE_FILTER_TIMEOUT_SECONDS);

        if (count($rows) >= self::ROUTE_FILTER_MAX_ROWS) {
            Log::warning('queryRouteFilterEvents() hit the safety row cap — route filter usage may be incomplete', ['range' => $range]);
        }

        return array_map(fn ($row) => [
            'filter_id' => (string) $row[0],
            'session_id' => (string) $row[1],
            'lib' => (string) $row[2],
            'timestamp' => (string) $row[3],
        ], $rows);
    }

    /**
     * Raggruppa eventi consecutivi della stessa (session_id, filter_id) il cui gap dal
     * precedente è <= ROUTE_FILTER_DEDUP_WINDOW_SECONDS: contano come un solo utilizzo. Un gap
     * più ampio apre un nuovo conteggio. Fatto qui e non in HogQL perché leadInFrame() (la window
     * function più vicina a questo bisogno) non è affidabile in questo ambiente — vedi il
     * commento su queryTopSearchQueries().
     *
     * @param  array<int, array{filter_id: string, session_id: string, lib: string, timestamp: string}>  $rows  ordinate per (session_id, filter_id, timestamp) — vedi queryRouteFilterEvents()
     * @return array<string, array{total: int, breakdown: list<array{lib: string, total: int}>}>
     */
    private function dedupeAndCountRouteFilterEvents(array $rows): array
    {
        $counts = [];
        $lastTimestamps = [];

        foreach ($rows as $row) {
            $filterId = $row['filter_id'];
            $key = "{$row['session_id']}|{$filterId}";
            $timestamp = Carbon::parse($row['timestamp']);
            $previous = $lastTimestamps[$key] ?? null;

            $isNewUsage = $previous === null
                || $previous->diffInSeconds($timestamp) > self::ROUTE_FILTER_DEDUP_WINDOW_SECONDS;

            $lastTimestamps[$key] = $timestamp;

            if (! $isNewUsage) {
                continue;
            }

            if (! isset(self::ROUTE_FILTER_LABELS[$filterId])) {
                Log::warning('AnalyticsService: filter_id sconosciuto in evento filterUsed/route', ['filter_id' => $filterId]);
            }

            $counts[$filterId] ??= ['total' => 0, 'breakdown' => []];
            $counts[$filterId]['total']++;
            $counts[$filterId]['breakdown'][$row['lib']] = ($counts[$filterId]['breakdown'][$row['lib']] ?? 0) + 1;
        }

        foreach ($counts as $filterId => $entry) {
            $counts[$filterId]['breakdown'] = array_map(
                fn ($lib, $total) => ['lib' => $lib, 'total' => $total],
                array_keys($entry['breakdown']),
                array_values($entry['breakdown']),
            );
        }

        return $counts;
    }

```

- [ ] **Step 5: Esegui i test dello Step 1 e verifica che passino**

Run: `docker exec -it php-forestas bash -lc "cd wm-package && vendor/bin/pest --filter=test_get_route_filter_usage"`
Expected: PASS (4 test)

- [ ] **Step 6: Commit**

```bash
git add src/Services/PostHog/AnalyticsService.php tests/Unit/AnalyticsServiceTest.php
git commit -m "feat(oc:8585): aggiunge AnalyticsService::getRouteFilterUsage() con 7 righe fisse"
```

- [ ] **Step 7: Scrivi i test per il dedup a 5s (ravvicinati, distanti, caso limite)**

Aggiungi subito dopo i test dello Step 1, stesso file:

```php
    public function test_get_route_filter_usage_collapses_events_within_five_seconds(): void
    {
        Http::fake(['*' => Http::response(['results' => [
            ['distance', 's1', 'web', '2026-06-01 10:00:00'],
            ['distance', 's1', 'web', '2026-06-01 10:00:03'],
        ]])]);

        $result = (new AnalyticsService)->getRouteFilterUsage('last_30_days');

        $byId = array_column($result, null, 'filter_id');
        $this->assertSame(1, $byId['distance']['total']);
    }

    public function test_get_route_filter_usage_counts_events_beyond_five_seconds_separately(): void
    {
        Http::fake(['*' => Http::response(['results' => [
            ['distance', 's1', 'web', '2026-06-01 10:00:00'],
            ['distance', 's1', 'web', '2026-06-01 10:00:07'],
        ]])]);

        $result = (new AnalyticsService)->getRouteFilterUsage('last_30_days');

        $byId = array_column($result, null, 'filter_id');
        $this->assertSame(2, $byId['distance']['total']);
    }

    public function test_get_route_filter_usage_boundary_exactly_five_seconds_collapses(): void
    {
        // Esattamente 5s è considerato "lo stesso utilizzo" — il nuovo conteggio scatta solo
        // oltre i 5s, non da 5s in poi (vedi dedupeAndCountRouteFilterEvents: `> 5`, non `>= 5`).
        Http::fake(['*' => Http::response(['results' => [
            ['distance', 's1', 'web', '2026-06-01 10:00:00'],
            ['distance', 's1', 'web', '2026-06-01 10:00:05'],
        ]])]);

        $result = (new AnalyticsService)->getRouteFilterUsage('last_30_days');

        $byId = array_column($result, null, 'filter_id');
        $this->assertSame(1, $byId['distance']['total']);
    }

    public function test_get_route_filter_usage_does_not_collapse_across_different_sessions(): void
    {
        Http::fake(['*' => Http::response(['results' => [
            ['distance', 's1', 'web', '2026-06-01 10:00:00'],
            ['distance', 's2', 'web', '2026-06-01 10:00:01'],
        ]])]);

        $result = (new AnalyticsService)->getRouteFilterUsage('last_30_days');

        $byId = array_column($result, null, 'filter_id');
        $this->assertSame(2, $byId['distance']['total']);
    }

    public function test_get_route_filter_usage_does_not_collapse_across_different_filters_same_session(): void
    {
        Http::fake(['*' => Http::response(['results' => [
            ['distance', 's1', 'web', '2026-06-01 10:00:00'],
            ['themes', 's1', 'web', '2026-06-01 10:00:01'],
        ]])]);

        $result = (new AnalyticsService)->getRouteFilterUsage('last_30_days');

        $byId = array_column($result, null, 'filter_id');
        $this->assertSame(1, $byId['distance']['total']);
        $this->assertSame(1, $byId['themes']['total']);
    }

    public function test_get_route_filter_usage_breakdown_splits_by_platform(): void
    {
        Http::fake(['*' => Http::response(['results' => [
            ['distance', 's1', 'posthog-android', '2026-06-01 10:00:00'],
            ['distance', 's2', 'posthog-ios', '2026-06-01 10:00:00'],
            ['distance', 's3', 'web', '2026-06-01 10:00:00'],
        ]])]);

        $result = (new AnalyticsService)->getRouteFilterUsage('last_30_days');

        $byId = array_column($result, null, 'filter_id');
        $this->assertSame(3, $byId['distance']['total']);
        $breakdownByLib = array_column($byId['distance']['breakdown'], 'total', 'lib');
        $this->assertSame(1, $breakdownByLib['posthog-android']);
        $this->assertSame(1, $breakdownByLib['posthog-ios']);
        $this->assertSame(1, $breakdownByLib['web']);
    }
```

- [ ] **Step 8: Esegui i test e verifica che passino** (l'implementazione dello Step 4 li copre già)

Run: `docker exec -it php-forestas bash -lc "cd wm-package && vendor/bin/pest --filter=test_get_route_filter_usage"`
Expected: PASS (10 test totali)

- [ ] **Step 9: Commit**

```bash
git add tests/Unit/AnalyticsServiceTest.php
git commit -m "test(oc:8585): copre il dedup a 5s di getRouteFilterUsage (ravvicinati, distanti, boundary, sessioni/filtri diversi)"
```

- [ ] **Step 10: Scrivi il test per il filter_id sconosciuto**

```php
    public function test_get_route_filter_usage_logs_warning_on_unknown_filter_id(): void
    {
        Http::fake(['*' => Http::response(['results' => [
            ['unknownFilter', 's1', 'web', '2026-06-01 10:00:00'],
        ]])]);
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg, $context) => $msg === 'AnalyticsService: filter_id sconosciuto in evento filterUsed/route'
                && $context['filter_id'] === 'unknownFilter');

        $result = (new AnalyticsService)->getRouteFilterUsage('last_30_days');

        // Il filtro sconosciuto non compare come riga (solo le 7 note), ma viene comunque
        // segnalato — non silenziosamente ignorato.
        $this->assertCount(7, $result);
    }
```

- [ ] **Step 11: Esegui il test e verifica che passi**

Run: `docker exec -it php-forestas bash -lc "cd wm-package && vendor/bin/pest --filter=test_get_route_filter_usage_logs_warning_on_unknown_filter_id"`
Expected: PASS

- [ ] **Step 12: Commit**

```bash
git add tests/Unit/AnalyticsServiceTest.php
git commit -m "test(oc:8585): verifica il Log::warning su filter_id sconosciuto in getRouteFilterUsage"
```

- [ ] **Step 13: Scrivi i test su filtro shard, timeout, cache, propagazione errore**

```php
    public function test_get_route_filter_usage_sql_includes_shard_name_filter(): void
    {
        config(['wm-package.shard_name' => 'camminiditalia']);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getRouteFilterUsage();

        Http::assertSent(fn (Request $request) => str_contains(
            $request->data()['query']['query'],
            "properties.shard_name._value = 'camminiditalia'"
        ));
    }

    public function test_get_route_filter_usage_second_call_uses_cache_and_does_not_hit_http(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        $service = new AnalyticsService;
        $service->getRouteFilterUsage('last_30_days');
        $service->getRouteFilterUsage('last_30_days');

        Http::assertSentCount(1);
    }

    public function test_get_route_filter_usage_propagates_failure_when_query_fails(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response('Internal Server Error', 500)]);
        Log::shouldReceive('error')->atLeast()->once();

        $this->expectException(AnalyticsQueryException::class);

        (new AnalyticsService)->getRouteFilterUsage('last_30_days');
    }
```

- [ ] **Step 14: Esegui i test e verifica che passino**

Run: `docker exec -it php-forestas bash -lc "cd wm-package && vendor/bin/pest --filter=test_get_route_filter_usage"`
Expected: PASS (13 test totali per `getRouteFilterUsage`)

- [ ] **Step 15: Esegui l'intera suite e PHPStan prima di chiudere il task**

Run: `docker exec -it php-forestas bash -lc "cd wm-package && composer test && composer analyse"`
Expected: tutti i test passano, PHPStan senza nuovi errori

- [ ] **Step 16: Commit**

```bash
git add tests/Unit/AnalyticsServiceTest.php
git commit -m "test(oc:8585): copre filtro shard, cache e propagazione errore per getRouteFilterUsage"
```

---

## Task 2: Wiring in `AnalyticsController::global()`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-analyticscontrollerglobal)

**Files:**
- Modify: `src/Http/Controllers/Nova/AnalyticsController.php:45-72` (metodo `global()`)
- Test: `tests/Feature/AnalyticsControllerGlobalTest.php`

**Interfaces:**
- Consumes: `AnalyticsService::getRouteFilterUsage(string $range): array` (Task 1)
- Produces: chiave `ranking_route_filters` nella risposta JSON di `GET
  /nova-vendor/layer-analytics/global`

- [ ] **Step 1: Scrivi il test che verifica la nuova chiave nella risposta**

In `tests/Feature/AnalyticsControllerGlobalTest.php` aggiungi l'import mancante, subito dopo `use
Illuminate\Support\Facades\Http;`:

```php
use Illuminate\Http\Client\Request;
```

(serve per il test dello Step 5 più avanti in questo task; aggiungerlo ora evita di dover
ripassare sull'intestazione del file due volte).

Poi, nel test
`test_administrator_receives_aggregated_data_structure`, aggiungi `'ranking_route_filters'`
all'array passato a `assertJsonStructure`:

```php
    public function test_administrator_receives_aggregated_data_structure(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $response = $this->actingAs($admin)->getJson('/nova-vendor/layer-analytics/global');

        $response->assertOk();
        $response->assertJsonStructure(['total', 'unique_users', 'daily_breakdown', 'ranking_layers', 'ranking_tracks', 'ranking_route_filters']);
    }
```

- [ ] **Step 2: Esegui il test e verifica che fallisca** (la chiave non esiste ancora nella
      risposta)

Run: `docker exec -it php-forestas bash -lc "cd wm-package && vendor/bin/pest --filter=test_administrator_receives_aggregated_data_structure"`
Expected: FAIL — `ranking_route_filters` assente dalla struttura JSON

- [ ] **Step 3: Aggiungi la chiamata e la chiave nel controller**

In `src/Http/Controllers/Nova/AnalyticsController.php`, dentro `global()` (righe 52-71), aggiungi
la chiamata nel blocco `try` esistente e la chiave nell'array di risposta:

```php
        try {
            $usage = $service->getGlobalUsage($range);
            $rankingLayers = $service->getAllLayersUsage($range);
            $rankingUserPresence = $service->getAllLayersUserPresence($range);
            $rankingTracks = $service->getAllTracksDownloads($range);
            $rankingTrackShares = $service->getAllTracksShares($range);
            $searchTotal = $service->getTotalSearches($range);
            $rankingSearchQueries = $service->getTopSearchQueries($range);
            $rankingRouteFilters = $service->getRouteFilterUsage($range);
        } catch (AnalyticsQueryException|LockTimeoutException $e) {
            return response()->json(['error' => 'analytics_query_failed'], 502);
        }

        return response()->json(array_merge($usage, [
            'ranking_layers' => $rankingLayers,
            'ranking_user_presence' => $rankingUserPresence,
            'ranking_tracks' => $rankingTracks,
            'ranking_track_shares' => $rankingTrackShares,
            'search_total' => $searchTotal,
            'ranking_search_queries' => $rankingSearchQueries,
            'ranking_route_filters' => $rankingRouteFilters,
        ]));
```

- [ ] **Step 4: Esegui il test e verifica che passi**

Run: `docker exec -it php-forestas bash -lc "cd wm-package && vendor/bin/pest --filter=test_administrator_receives_aggregated_data_structure"`
Expected: PASS

- [ ] **Step 5: Scrivi il test per la risposta 502 quando la nuova query fallisce**

Aggiungi in `tests/Feature/AnalyticsControllerGlobalTest.php`, dopo
`test_administrator_receives_502_when_posthog_query_fails`:

```php
    public function test_administrator_receives_502_when_route_filter_query_fails(): void
    {
        // Fa fallire solo la query di getRouteFilterUsage() (riconosciuta dal contenuto SQL),
        // tutte le altre metriche di global() rispondono ok — verifica che global() propaghi
        // comunque un 502 anche quando l'unica query a fallire è quella nuova, non solo quando
        // fallisce la prima (già coperto da test_administrator_receives_502_when_posthog_query_fails).
        Http::fake(function (Request $request) {
            $sql = $request->data()['query']['query'] ?? '';
            if (str_contains($sql, "event = 'filterUsed'") && str_contains($sql, "filter_type = 'route'")) {
                return Http::response('Internal Server Error', 500);
            }

            return Http::response(['results' => []]);
        });

        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $response = $this->actingAs($admin)->getJson('/nova-vendor/layer-analytics/global');

        $response->assertStatus(502);
        $response->assertJson(['error' => 'analytics_query_failed']);
    }
```

- [ ] **Step 6: Esegui il test e verifica che passi**

Run: `docker exec -it php-forestas bash -lc "cd wm-package && vendor/bin/pest --filter=test_administrator_receives_502_when_route_filter_query_fails"`
Expected: PASS

- [ ] **Step 7: Esegui l'intera suite feature**

Run: `docker exec -it php-forestas bash -lc "cd wm-package && composer test"`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add src/Http/Controllers/Nova/AnalyticsController.php tests/Feature/AnalyticsControllerGlobalTest.php
git commit -m "feat(oc:8585): espone ranking_route_filters da AnalyticsController::global()"
```

---

## Task 3: `LayerAnalyticsCard.vue` — nuova sezione "Uso dei filtri"

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-layeranalyticscardvue)

**Files:**
- Modify: `src/Nova/Cards/LayerAnalytics/resources/js/components/LayerAnalyticsCard.vue`

**Interfaces:**
- Consumes: `data.ranking_route_filters` — array di `{filter_id, name, total, breakdown: [{lib,
  total}]}` (Task 2), sempre 7 elementi.

- [ ] **Step 1: Includi `ranking_route_filters` nella condizione che mostra il blocco
      "Classifiche globali"**

In `src/Nova/Cards/LayerAnalytics/resources/js/components/LayerAnalyticsCard.vue`, riga 111, la
condizione del `<div v-if="card.mode === 'global' && (...)">` diventa:

```html
      <div v-if="card.mode === 'global' && (data.ranking_layers?.length || data.ranking_user_presence?.length || data.ranking_tracks?.length || data.ranking_track_shares?.length || data.ranking_search_queries?.length || data.ranking_route_filters?.length)" style="margin-top:24px;">
```

- [ ] **Step 2: Aggiungi il nuovo blocco template subito dopo "Cammini più frequentati"**

Nello stesso file, subito dopo il tag di chiusura `</div>` che chiude il blocco
`ranking_user_presence` (riga 219, il `</div>` che segue il `<button v-if="data.ranking_user_presence.length > 10" ...>`) e prima del `<div v-if="!showRestOfAnalytics && (...)"` (riga 221), inserisci:

```html
        <div v-if="data.ranking_route_filters?.length" style="margin-bottom:24px;">
          <p style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; margin-bottom:8px;">Uso dei filtri</p>
          <div style="display:flex; align-items:center; justify-content:center; gap:16px; margin-bottom:16px;">
            <div v-for="p in platforms" :key="p.lib" style="display:flex; align-items:center; gap:6px;">
              <span :style="{ display:'inline-block', width:'40px', height:'12px', borderRadius:'2px', background: p.color }"></span>
              <span style="font-size:12px; color:#374151;">{{ p.label }}</span>
            </div>
          </div>
          <div>
            <div
              v-for="row in data.ranking_route_filters"
              :key="row.filter_id"
              style="display:flex; align-items:center; gap:12px; margin-bottom:8px;"
            >
              <span
                style="width:220px; flex-shrink:0; font-size:0.8125rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:#374151;"
              >{{ row.name }}</span>
              <div style="flex:1; display:flex; align-items:center; gap:8px; min-width:0;">
                <div
                  tabindex="0"
                  style="position:relative; flex:1; background:#f3f4f6; border-radius:0 4px 4px 0; height:20px; overflow:visible; display:flex; outline:none;"
                  @mouseenter="hoveredRouteFilterId = row.filter_id"
                  @mouseleave="hoveredRouteFilterId = null"
                  @focus="hoveredRouteFilterId = row.filter_id"
                  @blur="hoveredRouteFilterId = null"
                >
                  <div style="position:absolute; inset:0; border-radius:0 4px 4px 0; overflow:hidden; display:flex;">
                    <div
                      v-for="seg in routeFilterBarSegments(row)"
                      :key="seg.lib"
                      :style="{ width: seg.widthPercent + '%', height: '20px', background: seg.color, borderRadius: seg.isLast ? '0 4px 4px 0' : '0', filter: hoveredRouteFilterId === row.filter_id ? 'brightness(1.1)' : 'none' }"
                    ></div>
                  </div>
                  <div
                    v-if="hoveredRouteFilterId === row.filter_id"
                    style="position:absolute; bottom:calc(100% + 6px); left:0; z-index:20; background:rgba(0,0,0,0.8); color:#fff; padding:6px; border-radius:6px; font-size:12px; white-space:nowrap;"
                  >
                    <div style="font-weight:bold; margin-bottom:6px;">{{ row.name }}</div>
                    <div v-for="seg in routeFilterBarTooltipRows(row)" :key="seg.lib" style="display:flex; align-items:center; gap:8px; line-height:1.4;">
                      <span :style="{ display:'inline-block', width:'10px', height:'10px', borderRadius:'2px', background: seg.color, flexShrink:0 }"></span>
                      <span>{{ seg.label }}: {{ seg.total }}</span>
                    </div>
                  </div>
                </div>
                <span style="width:44px; flex-shrink:0; font-size:0.8125rem; font-weight:600; color:#6b7280; text-align:right;">{{ row.total }}</span>
              </div>
            </div>
          </div>
        </div>
```

- [ ] **Step 3: Aggiungi lo stato per l'hover e i due metodi che costruiscono barra e tooltip**

Nello stesso file, nel blocco `data()` (riga 372, accanto a `hoveredUserPresenceLinkId: null,`),
aggiungi:

```js
      hoveredRouteFilterId: null,
```

Nel blocco `methods`, subito dopo la chiusura di `layerBarTooltipRows(row) { ... }` (il metodo
inizia a riga 562), aggiungi:

```js
    routeFilterBarSegments(row) {
      const max = Math.max(...(this.data.ranking_route_filters || []).map((r) => r.total), 1)
      const breakdown = row.breakdown || []

      const segments = PLATFORMS.map(({ lib, color }) => {
        const entry = breakdown.find((b) => b.lib === lib)
        const value = entry ? entry.total : 0
        return { lib, color, widthPercent: (value / max) * 100 }
      }).filter((seg) => seg.widthPercent > 0)

      return segments.map((seg, i) => ({ ...seg, isLast: i === segments.length - 1 }))
    },

    routeFilterBarTooltipRows(row) {
      const breakdown = row.breakdown || []
      return PLATFORMS
        .map(({ lib, label, color }) => {
          const entry = breakdown.find((b) => b.lib === lib)
          return entry ? { lib, label, color, total: entry.total } : null
        })
        .filter(Boolean)
    },
```

- [ ] **Step 4: Ricompila la card e verifica che la build non fallisca**

Run (dalla cartella della card, mai dalla root — vedi CLAUDE.md):
```bash
cd src/Nova/Cards/LayerAnalytics && npm run prod
```
Expected: build completata senza errori. Verifica con `git diff --stat` che il `dist`
aggiornato non contenga modifiche spurie oltre a quelle attese.

- [ ] **Step 5: Verifica manuale nel browser** (non automatizzabile: nessun test JS in questo
      repo per le card Nova)

Apri la card "Analytics — Tutti i cammini" in Nova (vista globale) con dati reali o con la
risposta di `global()` mockata via devtools, e verifica: la sezione "Uso dei filtri" compare
subito dopo "Cammini più frequentati", mostra 7 righe (Lunghezza, Tappe, Tipologia, Portata,
Regioni, Temi, Stagioni) anche quando alcune sono a zero, ordinate per totale decrescente, con
tooltip al passaggio del mouse che mostra il breakdown Android/iOS/Webapp.

- [ ] **Step 6: Commit**

```bash
git add src/Nova/Cards/LayerAnalytics/resources/js/components/LayerAnalyticsCard.vue src/Nova/Cards/LayerAnalytics/dist
git commit -m "feat(oc:8585): mostra 'Uso dei filtri' nella card Analytics globale"
```

---

## Self-Review (esito)

**1. Spec coverage:** tutti i 12 punti della sezione Requisiti dell'overview sono coperti — i
punti 1-9 e 11 dal Task 1, il punto 9 (wiring) anche dal Task 2, il punto 10 dal Task 3, il punto
12 dal Task 2.

**2. Placeholder scan:** nessuno — ogni step ha codice reale, nessun "TODO"/"gestisci
opportunamente".

**3. Type consistency:** `getRouteFilterUsage()` (Task 1) → consumato identico in
`AnalyticsController::global()` (Task 2) → la chiave `ranking_route_filters` e la forma
`{filter_id, name, total, breakdown}` sono le stesse lette da `LayerAnalyticsCard.vue` (Task 3,
`row.filter_id`/`row.name`/`row.total`/`row.breakdown`).

**4. Review Focus:** i 5 punti della sezione omonima sono tutti riportati sopra; il primo
(formato del timestamp) è esplicitamente **non testabile in questo piano** con fixture sintetici
— richiede una verifica manuale post-implementazione contro una risposta HogQL reale, annotata
nello Step 5 del Task 3 come parte della verifica manuale complessiva.
