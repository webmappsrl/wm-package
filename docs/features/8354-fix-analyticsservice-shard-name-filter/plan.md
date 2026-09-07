> Ticket: oc:8354

# Fix cross-tenant data leak: AnalyticsService non filtra per shard_name — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Far sì che tutte le query HogQL di `AnalyticsService` filtrino per `shard_name`, così eventi PostHog di altri consumer wm-package non si mescolino nelle metriche di camminiditalia quando gli ID numerici (layer/track) collidono per caso tra progetti diversi. **Scope esteso (Task 3, aggiunto in corso d'opera)**: far sì che le stesse query non scartino silenziosamente la maggior parte degli eventi `layerOpened` reali, che oggi portano `layer_label` invece di `layer_id`.

**Architecture:** `whereClause(string $range): string` è già l'unico punto condiviso da tutte le 8 query private di `AnalyticsService`. Un nuovo metodo privato `shardNameClause()` viene chiamato una sola volta da `whereClause()` e appeso al risultato — nessuna delle 8 query private cambia, eredita automaticamente il filtro passando per lo stesso metodo che già chiamano oggi.

**Tech Stack:** PHP 8.2, Laravel (facade `Log`, `config()`), PHPUnit 10 via Orchestra Testbench (nessun Laravel/app reale, `Http::fake` per isolare PostHog).

**Spec:** `docs/features/8354-fix-analyticsservice-shard-name-filter/overview.md` (stesso repo, wm-package)

**Repo e branch:** `wm-package` (submodule di `camminiditalia`, path assoluto `/Users/peco/Documents/BackEnd/camminiditalia/wm-package`). Base branch: `origin/RDO_ass_cammini_italia_2026_2` — **non** `develop`, **non** `main`, **non** `feature/oc-8159-...`. Tutti gli 8 metodi pubblici coinvolti esistono solo lì (introdotti da oc:8182). Il branch di lavoro va creato con `git checkout -b feature/oc-8354-fix-analyticsservice-shard-name-filter` a partire da `origin/RDO_ass_cammini_italia_2026_2` (questo passo è gestito dal workflow `wm-plan`, non da questo piano — al momento dell'esecuzione la working copy del submodule deve già essere su quel branch).

**Comando di test verificato:** dalla root di `wm-package`, `php vendor/bin/phpunit tests/Unit/AnalyticsServiceTest.php` gira in ~1s senza bisogno di Docker/Postgres — la classe estende `Orchestra\Testbench\TestCase` direttamente e usa SQLite in-memory. Verificato: 41/41 test passano sullo stato attuale del branch (pre-fix).

## Global Constraints

- Nessuna invalidazione esplicita della cache `posthog:*` al deploy — TTL naturale accettato (max 6h).
- Nessun versionamento delle cache key in questo ciclo.
- Nessun backport su `develop`/`main` di wm-package in questo ciclo — da registrare come follow-up in `notes.md` a fine lavoro (fuori scope di questo piano).
- Non toccare `fetchUserMovedPointsRows` né gli altri metodi introdotti da `feature/oc-8159-...` — filtrano già correttamente con `properties.shard_name._value`, hanno una WHERE clause indipendente da `whereClause()`.
- Il fallback `layer_id`→`layer_label` (Task 3) si applica a `idFilterClause()`, `idInFilterClause()` (quando `$idProperty === 'layer_id'`, usato da `validLayerIdsClause()` — trovato come finding Critical in review: un fix che lo escludesse renderebbe il fallback inefficace per `getGlobalUsage()`) e a `queryAllLayersRanking()` — non toccare il ramo `track_id`/`content_id` di questi metodi (`queryTrackDownloads()`/`queryTrackShares()`), sempre presenti al 100%, verificato empiricamente, nessun fallback necessario lì.
- Non toccare il bug cosmetico lato client `layer_label = "1 - [object Object]"` — non impatta l'estrazione dell'ID (serve solo il prefisso numerico), fuori scope.
- Guard fail-open: se `config('wm-package.shard_name')` è vuoto/null, nessuna clausola shard_name viene applicata (comportamento identico a oggi) + `Log::warning`.
- Valore di `shard_name` va escapato (apice) prima dell'interpolazione SQL.

---

## Task 1: Centralizzare il filtro shard_name in `whereClause()`

**Files:**
- Modify: `src/Services/PostHog/AnalyticsService.php:287-304` (metodo `whereClause`)
- Test: `tests/Unit/AnalyticsServiceTest.php` (append due nuovi test dopo il blocco `// Range dinamico`, prima del blocco `// Track downloads`)

**Interfaces:**
- Consumes: nessuna dipendenza da altri task (primo task del piano).
- Produces: nuovo metodo privato `AnalyticsService::shardNameClause(): string`, chiamato da `whereClause()`. Nessuna firma pubblica cambia — tutti gli 8 metodi pubblici e le 8 query private mantengono esattamente le stesse signature. Il Task 2 userà questo stesso comportamento (nessuna nuova interfaccia da imparare).

- [x] **Step 1: Scrivi i due test falliti (RED)**

Apri `tests/Unit/AnalyticsServiceTest.php` e aggiungi questi due metodi subito dopo `test_365_days_range_returns_correct_range_field` (fine del blocco `// Range dinamico`, prima di `// Track downloads`):

```php
    // -------------------------------------------------------------------------
    // Filtro shard_name (oc:8354)
    // -------------------------------------------------------------------------

    public function test_where_clause_filters_by_configured_shard_name(): void
    {
        config(['wm-package.shard_name' => 'camminiditalia']);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getLayerUsage(1);

        Http::assertSent(function (Request $request) {
            $sql = $request->data()['query']['query'];

            return str_contains($sql, "properties.shard_name._value = 'camminiditalia'")
                && str_contains($sql, "properties.shard_name = 'camminiditalia'");
        });
    }

    public function test_where_clause_disables_shard_filter_and_logs_warning_when_shard_name_not_configured(): void
    {
        config(['wm-package.shard_name' => '']);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);
        Log::shouldReceive('warning')
            ->atLeast()->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'shard_name non configurato'));

        (new AnalyticsService)->getLayerUsage(1);

        Http::assertSent(function (Request $request) {
            $sql = $request->data()['query']['query'];

            return ! str_contains($sql, 'shard_name');
        });
    }
```

- [x] **Step 2: Esegui i test per verificare che falliscano**

Run (dalla root di `wm-package`):
```bash
php vendor/bin/phpunit tests/Unit/AnalyticsServiceTest.php --filter test_where_clause
```
Expected: 2 test, entrambi FAIL —
- `test_where_clause_filters_by_configured_shard_name` fallisce perché l'SQL non contiene `shard_name` (nessuna clausola aggiunta oggi).
- `test_where_clause_disables_shard_filter_and_logs_warning_when_shard_name_not_configured` fallisce perché `Log::warning` non viene mai chiamato oggi (Mockery: "expected at least 1 times, called 0 times").

- [x] **Step 3: Implementa `shardNameClause()` e collegala a `whereClause()`**

In `src/Services/PostHog/AnalyticsService.php`, sostituisci il metodo `whereClause` (righe 287-304):

```php
    private function whereClause(string $range): string
    {
        if (str_starts_with($range, 'month:')) {
            $month = substr($range, 6); // es. '2026-05'
            $start = $month.'-01';
            $end = Carbon::parse($start)->addMonth()->format('Y-m-d');

            $clause = "timestamp >= '{$start}' AND timestamp < '{$end}'";
        } else {
            $days = match ($range) {
                'last_90_days' => 90,
                'last_365_days' => 365,
                default => 30,
            };

            $clause = "timestamp >= now() - INTERVAL {$days} DAY";
        }

        return $clause.$this->shardNameClause();
    }

    /**
     * Filtra gli eventi per shard_name (config('wm-package.shard_name')) per evitare che eventi
     * di altri consumer wm-package sullo stesso progetto PostHog condiviso si mescolino nelle
     * metriche. La property ha due forme osservate empiricamente su dati reali di produzione:
     * annidata (properties.shard_name._value, formato attuale eventi mobile camminiditalia) e
     * flat (properties.shard_name, osservato su eventi storici di altri shard) — l'OR copre
     * entrambe. Config vuoto disabilita il filtro (fail-open, comportamento pre-fix) invece di
     * produrre una clausola che non fa mai match e azzererebbe silenziosamente ogni metrica.
     */
    private function shardNameClause(): string
    {
        $shardName = (string) config('wm-package.shard_name');

        if ($shardName === '') {
            Log::warning('AnalyticsService: wm-package.shard_name non configurato, filtro shard disabilitato');

            return '';
        }

        $escaped = str_replace("'", "\\'", $shardName);

        return " AND (properties.shard_name._value = '{$escaped}' OR properties.shard_name = '{$escaped}')";
    }
```

Nessun nuovo `use` è necessario: `Log` è già importato (riga 10), `config()` è un helper globale.

- [x] **Step 4: Esegui i test per verificare che passino**

Run:
```bash
php vendor/bin/phpunit tests/Unit/AnalyticsServiceTest.php --filter test_where_clause
```
Expected: 2/2 PASS.

Poi esegui l'intera classe per verificare zero regressioni sui 41 test esistenti:
```bash
php vendor/bin/phpunit tests/Unit/AnalyticsServiceTest.php --testdox
```
Expected: 43/43 PASS (41 esistenti + 2 nuovi). Nessun test esistente va modificato: nessuno imposta `wm-package.shard_name` nel proprio `setUp()`, quindi per tutti loro `shardNameClause()` prende il ramo guard (config vuoto) e produce esattamente lo stesso SQL di prima del fix.

- [x] **Step 5: Commit**

```bash
git add src/Services/PostHog/AnalyticsService.php tests/Unit/AnalyticsServiceTest.php
git commit -m "fix(oc:8354): centralizza filtro shard_name in AnalyticsService::whereClause"
```

---

## Task 2: Estendere la copertura del filtro shard_name agli altri 7 metodi pubblici

**Files:**
- Modify: `tests/Unit/AnalyticsServiceTest.php` (append test dopo il blocco `// Track downloads` esistente e dopo `// Search` esistente, uno per ciascuno dei 7 metodi rimanenti)

**Interfaces:**
- Consumes: `shardNameClause()` e la `whereClause()` modificata dal Task 1 — nessuna modifica di codice sorgente in questo task, solo test che verificano che il comportamento del Task 1 si propaghi correttamente a tutti i metodi pubblici che non sono `getLayerUsage`.
- Produces: nessuna nuova interfaccia — questo è l'ultimo task del piano.

- [x] **Step 1: Scrivi i test di copertura (uno per metodo, verificano solo la presenza della clausola)**

Aggiungi in `tests/Unit/AnalyticsServiceTest.php`, nel blocco `// Filtro shard_name (oc:8354)` creato nel Task 1 (dopo i due test già presenti):

```php
    public function test_get_global_usage_sql_includes_shard_name_filter(): void
    {
        config(['wm-package.shard_name' => 'camminiditalia']);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getGlobalUsage();

        Http::assertSent(fn (Request $request) => str_contains(
            $request->data()['query']['query'],
            "properties.shard_name._value = 'camminiditalia'"
        ));
    }

    public function test_get_all_layers_usage_sql_includes_shard_name_filter(): void
    {
        config(['wm-package.shard_name' => 'camminiditalia']);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getAllLayersUsage();

        Http::assertSent(fn (Request $request) => str_contains(
            $request->data()['query']['query'],
            "properties.shard_name._value = 'camminiditalia'"
        ));
    }

    public function test_get_layer_track_downloads_sql_includes_shard_name_filter(): void
    {
        config(['wm-package.shard_name' => 'camminiditalia']);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);
        $layer = $this->createLayerMockWithTrackIds([1, 2]);

        (new AnalyticsService)->getLayerTrackDownloads($layer);

        Http::assertSent(fn (Request $request) => str_contains(
            $request->data()['query']['query'],
            "properties.shard_name._value = 'camminiditalia'"
        ));
    }

    public function test_get_all_tracks_downloads_sql_includes_shard_name_filter(): void
    {
        config(['wm-package.shard_name' => 'camminiditalia']);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getAllTracksDownloads();

        Http::assertSent(fn (Request $request) => str_contains(
            $request->data()['query']['query'],
            "properties.shard_name._value = 'camminiditalia'"
        ));
    }

    public function test_get_all_tracks_shares_sql_includes_shard_name_filter(): void
    {
        config(['wm-package.shard_name' => 'camminiditalia']);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getAllTracksShares();

        Http::assertSent(fn (Request $request) => str_contains(
            $request->data()['query']['query'],
            "properties.shard_name._value = 'camminiditalia'"
        ));
    }

    public function test_get_total_searches_sql_includes_shard_name_filter(): void
    {
        config(['wm-package.shard_name' => 'camminiditalia']);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getTotalSearches();

        Http::assertSent(fn (Request $request) => str_contains(
            $request->data()['query']['query'],
            "properties.shard_name._value = 'camminiditalia'"
        ));
    }

    public function test_get_top_search_queries_sql_includes_shard_name_filter(): void
    {
        config(['wm-package.shard_name' => 'camminiditalia']);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getTopSearchQueries();

        Http::assertSent(fn (Request $request) => str_contains(
            $request->data()['query']['query'],
            "properties.shard_name._value = 'camminiditalia'"
        ));
    }
```

Nota: `createLayerMockWithTrackIds()` è l'helper già esistente in fondo al file (usato da `test_get_layer_track_downloads_returns_normalized_structure`) — nessuna modifica necessaria a quell'helper.

- [x] **Step 2: Esegui i nuovi test per verificare che passino già (dovrebbero, essendo il Task 1 già implementato)**

Run:
```bash
php vendor/bin/phpunit tests/Unit/AnalyticsServiceTest.php --filter "sql_includes_shard_name_filter"
```
Expected: 7/7 PASS. Se uno di questi fallisce, significa che quel metodo pubblico non passa per `whereClause()` come previsto dall'analisi — fermati e verifica manualmente la catena di chiamate di quel metodo prima di proseguire (non è un comportamento previsto da questo piano).

- [x] **Step 3: Esegui l'intera suite del file per la verifica di regressione finale**

Run:
```bash
php vendor/bin/phpunit tests/Unit/AnalyticsServiceTest.php --testdox
```
Expected: 50/50 PASS (41 originali + 2 del Task 1 + 7 di questo task).

- [x] **Step 4: Commit**

```bash
git add tests/Unit/AnalyticsServiceTest.php
git commit -m "test(oc:8354): copertura filtro shard_name su tutti gli 8 metodi pubblici di AnalyticsService"
```

---

## Task 3: Fallback layer_id → layer_label (scope esteso)

**Files:**
- Modify: `src/Services/PostHog/AnalyticsService.php` (metodi `idFilterClause()` riga ~617 e `queryAllLayersRanking()` riga ~555; nuovo metodo privato `effectiveLayerIdExpression()`)
- Test: `tests/Unit/AnalyticsServiceTest.php` (append nel blocco `// Filtro shard_name (oc:8354)`, o in un nuovo blocco `// Fallback layer_id -> layer_label (oc:8354)` subito dopo)

**Contesto (verificato empiricamente su PostHog di produzione, non assunto):**
- Solo il 12% degli eventi `layerOpened` Android, il 2% iOS, il 23% web (ultimi 30 giorni) portano `layer_id`. Il resto porta solo `layer_label`, formato `"{id} - {titolo}"` (es. `"56 - Cammino Minerario di Santa Barbara"`).
- Formato verificato **100% consistente** su 90 giorni di dati reali (nessuna eccezione al pattern `^[0-9]+ - `).
- Quando **entrambe** le property sono presenti sullo stesso evento, l'ID estratto da `layer_label` e `layer_id` **non sono mai in contraddizione** (25.622/25.622 casi concordanti su 90 giorni).
- `trackDownloaded`/`contentShared` non hanno questo problema: `track_id`/`content_id` sempre presenti al 100% (verificato), nessun fallback necessario per quei due eventi.

**Interfaces:**
- Consumes: nessuna dipendenza dai Task 1/2 (stesso file, metodi diversi — `idFilterClause()` è chiamato da `queryDailyBreakdown`/`queryBreakdown`/`queryUniqueUsers`/`queryAllLayersRanking`, nessuno di questi cambia firma).
- Produces: nuovo metodo privato `AnalyticsService::effectiveLayerIdExpression(): string`. Nessuna firma pubblica cambia.

- [x] **Step 1: Scrivi i test falliti (RED)**

Aggiungi in `tests/Unit/AnalyticsServiceTest.php`, dopo l'ultimo test del blocco `// Filtro shard_name (oc:8354)`:

```php
    // -------------------------------------------------------------------------
    // Fallback layer_id -> layer_label (oc:8354)
    // -------------------------------------------------------------------------

    public function test_get_layer_usage_filter_falls_back_to_layer_label_when_layer_id_missing(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getLayerUsage(56);

        Http::assertSent(function (Request $request) {
            $sql = $request->data()['query']['query'];

            return str_contains(
                $sql,
                "coalesce(nullIf(properties.layer_id, ''), nullIf(extract(properties.layer_label, '^([0-9]+)'), '')) = '56'"
            );
        });
    }

    public function test_query_all_layers_ranking_sql_uses_layer_label_fallback_in_select_and_group_by(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getAllLayersUsage();

        Http::assertSent(function (Request $request) {
            $sql = $request->data()['query']['query'];

            return str_contains(
                $sql,
                "coalesce(nullIf(properties.layer_id, ''), nullIf(extract(properties.layer_label, '^([0-9]+)'), '')) AS layer_id"
            ) && str_contains($sql, 'GROUP BY layer_id, lib');
        });
    }

    public function test_get_global_usage_sql_uses_layer_label_fallback_in_id_filter(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getGlobalUsage();

        Http::assertSent(function (Request $request) {
            $sql = $request->data()['query']['query'];

            return str_contains(
                $sql,
                "coalesce(nullIf(properties.layer_id, ''), nullIf(extract(properties.layer_label, '^([0-9]+)'), '')) IS NOT NULL AND coalesce(nullIf(properties.layer_id, ''), nullIf(extract(properties.layer_label, '^([0-9]+)'), '')) != ''"
            );
        });
    }
```

- [x] **Step 2: Esegui i test per verificare che falliscano**

Run (dalla root di `wm-package`):
```bash
php vendor/bin/phpunit tests/Unit/AnalyticsServiceTest.php --filter "layer_label"
```
Expected: 3 test, tutti FAIL — l'SQL generato oggi filtra/raggruppa su `properties.layer_id` semplice, non contiene l'espressione `coalesce(...)`.

- [x] **Step 3: Implementa `effectiveLayerIdExpression()` e collegala a `idFilterClause()`/`queryAllLayersRanking()`**

Sostituisci `idFilterClause()` (riga ~617):

```php
    private function idFilterClause(string $idProperty, ?int $id): string
    {
        $expr = $idProperty === 'layer_id' ? $this->effectiveLayerIdExpression() : "properties.{$idProperty}";

        if ($id === null) {
            return "{$expr} IS NOT NULL AND {$expr} != ''";
        }

        return "{$expr} = '{$id}'";
    }

    /**
     * La maggior parte degli eventi layerOpened reali non porta più `layer_id` (verificato:
     * solo 12%/2%/23% degli eventi Android/iOS/web negli ultimi 30 giorni) — porta invece
     * `layer_label`, formato "{id} - {titolo}" (es. "56 - Cammino Minerario di Santa Barbara"),
     * verificato 100% consistente su 90 giorni di dati reali e mai in contraddizione con
     * layer_id quando entrambe le property sono presenti sullo stesso evento. Usa layer_id se
     * presente, altrimenti estrae l'ID numerico da layer_label.
     */
    private function effectiveLayerIdExpression(): string
    {
        return "coalesce(nullIf(properties.layer_id, ''), nullIf(extract(properties.layer_label, '^([0-9]+)'), ''))";
    }
```

**Correzione post-review**: questo testo originariamente diceva "non modificare `idInFilterClause()`, usato solo per `track_id`" — affermazione errata, trovata come finding Critical in review. `idInFilterClause()` è usato anche da `validLayerIdsClause()` per `layer_id`: **va modificato** con lo stesso pattern di `idFilterClause()` sopra (`$expr = $idProperty === 'layer_id' ? $this->effectiveLayerIdExpression() : "properties.{$idProperty}";`), altrimenti il fallback risulta inefficace per `getGlobalUsage()`. Vedi `notes.md` per il dettaglio completo di questo finding.

In `queryAllLayersRanking()` (riga ~555), sostituisci il corpo del metodo con:

```php
    private function queryAllLayersRanking(string $range): array
    {
        $whereClause = $this->whereClause($range);
        $libs = $this->libList();
        $idFilter = $this->idFilterClause('layer_id', null);
        $effectiveLayerId = $this->effectiveLayerIdExpression();

        // Nessun cap sensato a livello di riga qui: ogni riga è una coppia
        // (layer_id, lib), non un layer — il ranking vero (top RANKING_LIMIT
        // per layer) si calcola in PHP sommando le righe per layer_id, quindi
        // un LIMIT stretto taglierebbe arbitrariamente prima dell'aggregazione.
        // Il LIMIT sotto è solo una rete di sicurezza contro layer_id corrotti/
        // enormi (oggi ~118 layer x 3 lib = ~354 righe reali, ben sotto il cap).
        // ATTENZIONE: se mai raggiunto, ORDER BY total DESC taglia righe
        // (layer_id, lib) singole, non layer interi — un layer al margine
        // potrebbe perdere il breakdown di una sola piattaforma (es. resta
        // Android ma sparisce iOS) restando comunque in classifica con un
        // totale incompleto, senza errori visibili. Log::warning sotto come
        // tripwire per accorgersene prima che diventi un problema silenzioso.
        $sql = <<<SQL
SELECT
    {$effectiveLayerId} AS layer_id,
    properties.\$lib AS lib,
    count() AS total
FROM events
WHERE event = 'layerOpened'
  AND {$idFilter}
  AND properties.\$lib IN ({$libs})
  AND {$whereClause}
GROUP BY layer_id, lib
ORDER BY total DESC
LIMIT 1000
SQL;

        $rows = $this->runQuery($sql, true);

        if (count($rows) >= 1000) {
            Log::warning('queryAllLayersRanking() hit the 1000-row safety cap — ranking may be incomplete', ['range' => $range]);
        }

        return array_map(fn ($row) => [
            'layer_id' => (int) $row[0],
            'lib' => (string) $row[1],
            'total' => (int) $row[2],
        ], $rows);
    }
```

(Unica differenza dal codice esistente: la riga `SELECT properties.layer_id AS layer_id,` diventa `SELECT {$effectiveLayerId} AS layer_id,`, e viene calcolata la variabile `$effectiveLayerId` in testa al metodo. Il resto — commenti, cap di sicurezza, mapping dei risultati — resta identico.)

- [x] **Step 4: Esegui i test per verificare che passino**

Run:
```bash
php vendor/bin/phpunit tests/Unit/AnalyticsServiceTest.php --filter "layer_label"
```
Expected: 3/3 PASS.

Poi l'intera suite per zero regressioni:
```bash
php vendor/bin/phpunit tests/Unit/AnalyticsServiceTest.php --testdox
```
Expected: 53/53 PASS (50 esistenti + 3 di questo task). Nessun test esistente va modificato: tutti i test che già passano un `$id` esplicito a `getLayerUsage()` (es. `getLayerUsage(1)`, `getLayerUsage(55)`) continuano a funzionare perché l'espressione `coalesce(...)` è semanticamente equivalente a `properties.layer_id` quando `Http::fake()` intercetta comunque la richiesta prima che qualunque logica ClickHouse reale venga eseguita — i test verificano solo la stringa SQL generata, mai il risultato di `extract()`/`coalesce()` dal vivo.

- [x] **Step 5: Commit**

```bash
git add src/Services/PostHog/AnalyticsService.php tests/Unit/AnalyticsServiceTest.php
git commit -m "fix(oc:8354): fallback layer_id->layer_label quando layer_id e' assente sull'evento layerOpened"
```

---

## Self-Review (eseguita in fase di scrittura del piano)

1. **Copertura spec**: tutti i requisiti di `overview.md` sono coperti — centralizzazione in `whereClause()` (Task 1), guard su config vuoto + log (Task 1), escape apice (Task 1), OR tra le due forme (Task 1), non toccare i metodi oc:8159 (nessun task li modifica), test non tautologici con valore letterale fisso (Task 1 e 2), copertura sui rimanenti 7 metodi pubblici (Task 2), fallback layer_id→layer_label centralizzato senza toccare track_id (Task 3). Nessuna invalidazione cache/versionamento cache key/backport develop: correttamente assenti da questo piano, sono fuori scope per decisione esplicita.
2. **Placeholder scan**: nessun TODO/TBD, ogni step ha codice completo e comando di verifica eseguibile.
3. **Consistenza tipi/nomi**: `shardNameClause(): string` unica firma introdotta, usata solo internamente da `whereClause()`; nessuna altra firma cambia. Nomi dei metodi pubblici testati (`getGlobalUsage`, `getAllLayersUsage`, `getLayerTrackDownloads`, `getAllTracksDownloads`, `getAllTracksShares`, `getTotalSearches`, `getTopSearchQueries`) verificati 1:1 contro il codice reale in `src/Services/PostHog/AnalyticsService.php`.
