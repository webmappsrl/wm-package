> Ticket: oc:7648

# Analytics Layer — Selezione range temporale — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aggiungere un selettore di range temporale alla card Analytics del layer in Nova (30gg, 90gg, 365gg, o singolo mese da `created_at` al mese corrente), sostituendo il range fisso di 30 giorni attualmente hardcodato.

**Architecture:** Il controller legge `?days` o `?month` dalla request e li passa ad `AnalyticsService`, che costruisce la clausola WHERE dinamicamente e usa cache keys scoped per range con TTL differenziato. Il componente Vue aggiunge una dropdown, rifà la fetch al cambio range, e riceve `tracking_since` come prop per generare la lista mesi.

**Tech Stack:** PHP 8.x / Laravel, Vue 3 (Nova), Chart.js, PostHog HogQL API, Laravel Cache (Redis), Laravel Mix (`npm run prod`)

## Global Constraints

- Repo di lavoro: `wm-package/` (submodule) — tutti i file sono relativi a questa root
- Commit convention: `feat(oc:7648): ...`
- NO commit o branch automatici — i commit sono istruzioni testuali per il developer
- Il dist `dist/js/card.js` va ricompilato con `npm run prod` dalla cartella `src/Nova/Cards/LayerAnalytics/`
- I test si eseguono con: `docker exec laravel-camminiditalia php artisan test tests/Unit/AnalyticsServiceTest.php`
- Validazione: `?month` deve matchare `/^\d{4}-\d{2}$/`, `?days` in whitelist `[30, 90, 365]` — fallback a 30gg
- Precedenza: se entrambi `?month` e `?days` presenti, vince `?month`
- Cache lock per range 90gg e 365gg (stampede protection), non per 30gg e mesi
- TTL: 900s (30gg), 3600s (90gg), 21600s (365gg + mesi)
- Fallback `tracking_since` nel Vue a `'2026-01-01'` se prop assente o non valida

---

## File Map

| File | Azione |
|------|--------|
| `src/Services/PostHog/AnalyticsService.php` | Modifica — accetta `string $range`, WHERE dinamico, TTL e cache key per range |
| `src/Http/Controllers/Nova/AnalyticsController.php` | Modifica — legge e valida `?days`/`?month`, include `track_downloads` nella risposta |
| `src/Nova/Cards/LayerAnalytics/src/LayerAnalyticsCard.php` | Modifica — aggiunge prop `tracking_since` |
| `src/Nova/Cards/LayerAnalytics/resources/js/components/LayerAnalyticsCard.vue` | Modifica — dropdown range, fetch dinamica, titolo dinamico |
| `src/Nova/Cards/LayerAnalytics/dist/js/card.js` | Rebuild con `npm run prod` |
| `tests/Unit/AnalyticsServiceTest.php` | Modifica — test per nuovi range e cache key |

---

## Task 1: AnalyticsService — range dinamico, cache key e TTL differenziato

**Files:**
- Modify: `src/Services/PostHog/AnalyticsService.php`
- Modify: `tests/Unit/AnalyticsServiceTest.php`

**Interfaces:**
- Produces: `getLayerUsage(int $id, string $range = 'last_30_days'): array`
  - `$range` può essere `'last_30_days'`, `'last_90_days'`, `'last_365_days'`, o `'month:2026-03'`
  - Il campo `'range'` nell'array restituito contiene il valore di `$range`

- [ ] **Step 1: Scrivi i test per i nuovi range**

Aggiungi questi test in `tests/Unit/AnalyticsServiceTest.php`, nella sezione `// Cache`:

```php
public function test_range_is_included_in_cache_key(): void
{
    Http::fake([
        '*' => Http::sequence()
            ->push(['results' => []])
            ->push(['results' => []])
            ->push(['results' => [[0]]])
            ->push(['results' => []])
            ->push(['results' => []])
            ->push(['results' => [[0]]]),
    ]);

    Cache::flush();
    $service = new AnalyticsService;
    $service->getLayerUsage(1, 'last_30_days');
    $service->getLayerUsage(1, 'last_90_days');

    // 3 query per 30gg + 3 query per 90gg = 6 (nessuna cache hit tra range diversi)
    Http::assertSentCount(6);
}

public function test_same_range_second_call_uses_cache(): void
{
    Http::fake([
        '*' => Http::sequence()
            ->push(['results' => []])
            ->push(['results' => []])
            ->push(['results' => [[0]]]),
    ]);

    Cache::flush();
    $service = new AnalyticsService;
    $service->getLayerUsage(1, 'last_90_days');
    $service->getLayerUsage(1, 'last_90_days');

    Http::assertSentCount(3); // solo la prima chiamata va su PostHog
}

public function test_month_range_returns_correct_range_field(): void
{
    Http::fake([
        '*' => Http::sequence()
            ->push(['results' => []])
            ->push(['results' => []])
            ->push(['results' => [[0]]]),
    ]);

    $result = (new AnalyticsService)->getLayerUsage(1, 'month:2026-03');

    $this->assertSame('month:2026-03', $result['range']);
}

public function test_365_days_range_returns_correct_range_field(): void
{
    Http::fake([
        '*' => Http::sequence()
            ->push(['results' => []])
            ->push(['results' => []])
            ->push(['results' => [[0]]]),
    ]);

    $result = (new AnalyticsService)->getLayerUsage(1, 'last_365_days');

    $this->assertSame('last_365_days', $result['range']);
}
```

- [ ] **Step 2: Esegui i test per verificare che falliscano**

```bash
docker exec laravel-camminiditalia php artisan test tests/Unit/AnalyticsServiceTest.php
```

Atteso: i 4 nuovi test falliscono con "too few arguments" o simile.

- [ ] **Step 3: Riscrivi `AnalyticsService.php`**

Sostituisci il contenuto completo del file:

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Services\PostHog;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnalyticsService
{
    private const LIBS = ['posthog-ios', 'posthog-android', 'web'];

    private const TTL_MAP = [
        'last_30_days'  => 900,
        'last_90_days'  => 3600,
        'last_365_days' => 21600,
    ];

    private const LOCK_RANGES = ['last_90_days', 'last_365_days'];

    private string $host;
    private string $projectId;
    private string $apiKey;

    public function __construct()
    {
        $this->host      = rtrim(config('services.posthog.host'), '/');
        $this->projectId = (string) config('services.posthog.project_id');
        $this->apiKey    = (string) config('services.posthog.personal_api_key');
    }

    public function getLayerUsage(int $id, string $range = 'last_30_days'): array
    {
        return $this->getUsage('layerOpened', 'layer_id', $id, $range);
    }

    private function getUsage(string $event, string $idProperty, int $id, string $range): array
    {
        $cacheKey = "posthog:{$event}:{$id}:usage:{$range}";
        $ttl      = $this->ttlFor($range);

        if (in_array($range, self::LOCK_RANGES, true)) {
            $lock = Cache::lock("lock:{$cacheKey}", 15);
            return $lock->block(15, fn () => Cache::remember(
                $cacheKey,
                now()->addSeconds($ttl),
                fn () => $this->fetchUsage($event, $idProperty, $id, $range)
            ));
        }

        return Cache::remember(
            $cacheKey,
            now()->addSeconds($ttl),
            fn () => $this->fetchUsage($event, $idProperty, $id, $range)
        );
    }

    private function ttlFor(string $range): int
    {
        return self::TTL_MAP[$range] ?? 21600; // mesi storici: 6h
    }

    private function fetchUsage(string $event, string $idProperty, int $id, string $range): array
    {
        $whereClause = $this->whereClause($range);

        $dailyBreakdown = $this->queryDailyBreakdown($event, $idProperty, $id, $whereClause);
        $breakdown      = $this->queryBreakdown($event, $idProperty, $id, $whereClause);
        $uniqueUsers    = $this->queryUniqueUsers($event, $idProperty, $id, $whereClause);
        $total          = array_sum(array_column($breakdown, 'total'));

        return [
            'id'              => $id,
            'event'           => $event,
            'range'           => $range,
            'total'           => $total,
            'daily_breakdown' => $dailyBreakdown,
            'breakdown'       => $breakdown,
            'unique_users'    => $uniqueUsers,
        ];
    }

    private function whereClause(string $range): string
    {
        if (str_starts_with($range, 'month:')) {
            $month = substr($range, 6); // es. '2026-03'
            return "toYYYYMM(timestamp) = toUInt32(replace('{$month}', '-', ''))";
        }

        $days = match ($range) {
            'last_90_days'  => 90,
            'last_365_days' => 365,
            default         => 30,
        };

        return "timestamp >= now() - INTERVAL {$days} DAY";
    }

    private function queryDailyBreakdown(string $event, string $idProperty, int $id, string $whereClause): array
    {
        $libs = $this->libList();
        $sql  = <<<SQL
SELECT
    toDate(timestamp) AS day,
    properties.\$lib AS lib,
    count() AS total
FROM events
WHERE event = '{$event}'
  AND properties.{$idProperty} = '{$id}'
  AND properties.\$lib IN ({$libs})
  AND {$whereClause}
GROUP BY day, lib
ORDER BY day
SQL;

        return array_map(fn ($row) => [
            'date'  => (string) $row[0],
            'lib'   => (string) $row[1],
            'total' => (int) $row[2],
        ], $this->runQuery($sql));
    }

    private function queryBreakdown(string $event, string $idProperty, int $id, string $whereClause): array
    {
        $libs = $this->libList();
        $sql  = <<<SQL
SELECT
    properties.\$lib AS lib,
    count() AS total
FROM events
WHERE event = '{$event}'
  AND properties.{$idProperty} = '{$id}'
  AND properties.\$lib IN ({$libs})
  AND {$whereClause}
GROUP BY lib
ORDER BY total DESC
SQL;

        return array_map(fn ($row) => [
            'lib'   => (string) $row[0],
            'total' => (int) $row[1],
        ], $this->runQuery($sql));
    }

    private function queryUniqueUsers(string $event, string $idProperty, int $id, string $whereClause): int
    {
        $libs = $this->libList();
        $sql  = <<<SQL
SELECT
    count(DISTINCT person_id) AS unique_users
FROM events
WHERE event = '{$event}'
  AND properties.{$idProperty} = '{$id}'
  AND properties.\$lib IN ({$libs})
  AND {$whereClause}
SQL;

        $rows = $this->runQuery($sql);

        return isset($rows[0][0]) ? (int) $rows[0][0] : 0;
    }

    private function libList(): string
    {
        return implode(', ', array_map(fn ($l) => "'{$l}'", self::LIBS));
    }

    /** @return list<list<mixed>> */
    private function runQuery(string $sql): array
    {
        $url = "{$this->host}/api/projects/{$this->projectId}/query";

        $response = Http::withToken($this->apiKey)
            ->timeout(10)
            ->post($url, [
                'query' => [
                    'kind'  => 'HogQLQuery',
                    'query' => $sql,
                ],
            ]);

        if (! $response->successful()) {
            Log::error('PostHog query failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
                'sql'    => $sql,
            ]);

            return [];
        }

        return $response->json('results', []);
    }
}
```

- [ ] **Step 4: Aggiorna il test esistente `test_get_layer_usage_returns_expected_structure`**

Il test controlla `$result['range'] === 'last_30_days'` — era già corretto. Verifica che non abbia regression controllando i test:

```bash
docker exec laravel-camminiditalia php artisan test tests/Unit/AnalyticsServiceTest.php
```

Atteso: tutti i test passano, inclusi i 4 nuovi.

- [ ] **Step 5: Commit**

```bash
git -C wm-package add src/Services/PostHog/AnalyticsService.php tests/Unit/AnalyticsServiceTest.php
git -C wm-package commit -m "feat(oc:7648): add dynamic range support to AnalyticsService with per-range cache and TTL"
```

---

## Task 2: AnalyticsController — validazione e routing del range

**Files:**
- Modify: `src/Http/Controllers/Nova/AnalyticsController.php`

**Interfaces:**
- Consumes: `AnalyticsService::getLayerUsage(int $id, string $range): array`
- Produces: endpoint `GET /nova-vendor/layer-analytics/{layer}?days=90` o `?month=2026-03`

- [ ] **Step 1: Riscrivi il controller**

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Http\Controllers\Nova;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\PostHog\AnalyticsService;

class AnalyticsController extends Controller
{
    public function layer(Request $request, Layer $layer): JsonResponse
    {
        $service = app(AnalyticsService::class);
        $range   = $this->resolveRange($request);

        $usage          = $service->getLayerUsage($layer->id, $range);
        $trackDownloads = $service->getLayerTrackDownloads($layer, $range);

        return response()->json(array_merge($usage, [
            'track_downloads' => $trackDownloads,
        ]));
    }

    private function resolveRange(Request $request): string
    {
        // ?month ha precedenza su ?days
        $month = $request->query('month');
        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            return 'month:' . $month;
        }

        $days = (int) $request->query('days', 30);
        if (in_array($days, [90, 365], true)) {
            return "last_{$days}_days";
        }

        return 'last_30_days';
    }
}
```

- [ ] **Step 2: Verifica manualmente la logica di `resolveRange`**

Esempi attesi (puoi usare `php artisan tinker` nel container):

```php
// ?month=2026-03  → 'month:2026-03'
// ?month=invalid  → 'last_30_days'
// ?days=90        → 'last_90_days'
// ?days=365       → 'last_365_days'
// ?days=999       → 'last_30_days'
// (nessun param)  → 'last_30_days'
// ?month=2026-03&days=365 → 'month:2026-03' (month vince)
```

- [ ] **Step 3: Commit**

```bash
git -C wm-package add src/Http/Controllers/Nova/AnalyticsController.php
git -C wm-package commit -m "feat(oc:7648): AnalyticsController reads and validates ?days/?month query params"
```

---

## Task 3: LayerAnalyticsCard.php — prop `tracking_since`

**Files:**
- Modify: `src/Nova/Cards/LayerAnalytics/src/LayerAnalyticsCard.php`

**Interfaces:**
- Produces: prop `tracking_since` (stringa ISO date `'Y-m-d'`) disponibile nel Vue come `this.card.tracking_since`

- [ ] **Step 1: Modifica `LayerAnalyticsCard.php`**

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Cards\LayerAnalytics;

use Laravel\Nova\Card;
use Wm\WmPackage\Models\Layer;

class LayerAnalyticsCard extends Card
{
    public $component = 'layer-analytics-card';

    public $width = 'full';

    public $onlyOnDetail = true;

    private ?int $layerId;

    private ?string $trackingSince;

    public function __construct(Layer $layer)
    {
        parent::__construct();
        $this->layerId       = $layer->id ?? null;
        $this->trackingSince = $layer->created_at?->format('Y-m-d') ?? '2026-01-01';
    }

    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'endpoint'       => '/nova-vendor/layer-analytics/' . $this->layerId,
            'layer_id'       => $this->layerId,
            'tracking_since' => $this->trackingSince,
        ]);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git -C wm-package add src/Nova/Cards/LayerAnalytics/src/LayerAnalyticsCard.php
git -C wm-package commit -m "feat(oc:7648): pass tracking_since prop from LayerAnalyticsCard"
```

---

## Task 4: Vue — dropdown range, fetch dinamica, titolo dinamico

**Files:**
- Modify: `src/Nova/Cards/LayerAnalytics/resources/js/components/LayerAnalyticsCard.vue`

**Interfaces:**
- Consumes: `this.card.endpoint` (base URL), `this.card.tracking_since` (stringa `'YYYY-MM-DD'`)
- La fetch diventa: `fetch(this.card.endpoint + '?' + queryString)`

- [ ] **Step 1: Sostituisci il contenuto del componente Vue**

```vue
<template>
  <card class="p-6" ref="cardRoot">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <h4 style="font-size:0.75rem; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em; margin:0;">
        Analytics Layer — {{ rangeLabel }}
      </h4>
      <div style="display:flex; gap:8px; align-items:center;">
        <select
          v-model="selectedRange"
          @change="onRangeChange"
          style="font-size:0.75rem; padding:4px 8px; border-radius:6px; border:1px solid #d1d5db; background:#fff; color:#374151; cursor:pointer;"
        >
          <optgroup label="Finestre mobili">
            <option value="days:30">Ultimi 30 giorni</option>
            <option value="days:90">Ultimi 90 giorni</option>
            <option value="days:365">Ultimi 365 giorni</option>
          </optgroup>
          <optgroup label="Mese specifico">
            <option v-for="m in monthOptions" :key="m.value" :value="m.value">
              {{ m.label }}
            </option>
          </optgroup>
        </select>
        <button
          v-if="!loading && !error"
          @click="exportPng"
          style="font-size:0.75rem; padding:4px 12px; border-radius:6px; border:1px solid #d1d5db; background:#fff; color:#6b7280; cursor:pointer;"
        >
          ↓ PNG
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-gray-400 text-sm">Caricamento...</div>
    <div v-else-if="error" class="text-red-500 text-sm">{{ error }}</div>

    <template v-else>
      <!-- KPI row -->
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
      </div>

      <!-- Stacked bar chart -->
      <div style="margin-bottom:24px;">
        <p style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; margin-bottom:8px;">Aperture giornaliere per piattaforma</p>
        <canvas ref="dailyChart" style="width:100%; height:220px;"></canvas>
      </div>

      <!-- Breakdown totali -->
      <div v-if="data.breakdown && data.breakdown.length">
        <p style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; margin-bottom:8px;">Totale per piattaforma</p>
        <div style="display:flex; gap:16px;">
          <div
            v-for="item in data.breakdown"
            :key="item.lib"
            style="display:flex; align-items:center; gap:8px; font-size:0.875rem;"
          >
            <span
              style="display:inline-block; width:12px; height:12px; border-radius:3px;"
              :style="{ backgroundColor: platformColor(item.lib) }"
            ></span>
            <span style="color:#6b7280;">{{ libLabel(item.lib) }}:</span>
            <span style="font-weight:600;">{{ item.total }}</span>
          </div>
        </div>
      </div>
    </template>
  </card>
</template>

<script>
import html2canvas from 'html2canvas'
import {
  Chart,
  BarController,
  BarElement,
  LinearScale,
  CategoryScale,
  Tooltip,
  Legend,
} from 'chart.js'

Chart.register(BarController, BarElement, LinearScale, CategoryScale, Tooltip, Legend)

const PLATFORMS = [
  { lib: 'posthog-android', label: 'Android', color: '#10b981' },
  { lib: 'posthog-ios',     label: 'iOS',     color: '#6366f1' },
  { lib: 'web',             label: 'Webapp',  color: '#f59e0b' },
]

const MONTH_NAMES = ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno',
                     'Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre']

export default {
  props: {
    card: { type: Object, required: true },
  },

  data() {
    return {
      selectedRange: 'days:30',
      loading: true,
      error: null,
      data: null,
      chartInstance: null,
    }
  },

  computed: {
    trackingSince() {
      const raw = this.card.tracking_since
      if (!raw || !/^\d{4}-\d{2}/.test(raw)) return '2026-01-01'
      return raw
    },

    monthOptions() {
      const start  = new Date(this.trackingSince)
      const now    = new Date()
      const options = []
      const cursor = new Date(start.getFullYear(), start.getMonth(), 1)

      while (cursor <= now) {
        const y = cursor.getFullYear()
        const m = String(cursor.getMonth() + 1).padStart(2, '0')
        options.push({
          value: `month:${y}-${m}`,
          label: `${MONTH_NAMES[cursor.getMonth()]} ${y}`,
        })
        cursor.setMonth(cursor.getMonth() + 1)
      }

      return options
    },

    rangeLabel() {
      if (this.selectedRange.startsWith('month:')) {
        const [y, m] = this.selectedRange.slice(6).split('-')
        return `${MONTH_NAMES[parseInt(m, 10) - 1]} ${y}`
      }
      const days = this.selectedRange.split(':')[1]
      return `Ultimi ${days} giorni`
    },

    avgPerDay() {
      if (!this.data?.daily_breakdown?.length) return 0
      const days = new Set(this.data.daily_breakdown.map((r) => r.date)).size
      return days ? Math.round(this.data.total / days) : 0
    },

    fetchUrl() {
      const base = this.card.endpoint
      if (this.selectedRange.startsWith('month:')) {
        const month = this.selectedRange.slice(6)
        return `${base}?month=${month}`
      }
      const days = this.selectedRange.split(':')[1]
      return `${base}?days=${days}`
    },
  },

  watch: {
    data(val) {
      if (val) this.$nextTick(() => this.renderChart())
    },
  },

  async mounted() {
    await this.fetchData()
  },

  beforeUnmount() {
    if (this.chartInstance) this.chartInstance.destroy()
  },

  methods: {
    async onRangeChange() {
      await this.fetchData()
    },

    async fetchData() {
      this.loading = true
      this.error   = null
      try {
        const response = await fetch(this.fetchUrl, {
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
          },
        })
        if (!response.ok) throw new Error(`HTTP ${response.status}`)
        this.data = await response.json()
      } catch (e) {
        this.error = 'Impossibile caricare i dati analytics.'
        console.error(e)
      } finally {
        this.loading = false
      }
    },

    renderChart() {
      const canvas = this.$refs.dailyChart
      if (!canvas || !this.data?.daily_breakdown) return
      if (this.chartInstance) this.chartInstance.destroy()

      const days = [...new Set(this.data.daily_breakdown.map((r) => r.date))].sort()

      const lookup = {}
      for (const row of this.data.daily_breakdown) {
        lookup[`${row.date}|${row.lib}`] = row.total
      }

      const datasets = PLATFORMS.map(({ lib, label, color }) => ({
        label,
        data: days.map((d) => lookup[`${d}|${lib}`] ?? 0),
        backgroundColor: color,
        borderRadius: 2,
      }))

      this.chartInstance = new Chart(canvas, {
        type: 'bar',
        data: { labels: days, datasets },
        options: {
          responsive: false,
          plugins: {
            legend: { position: 'top' },
            tooltip: { mode: 'index' },
          },
          scales: {
            x: { stacked: true },
            y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
          },
        },
      })
    },

    platformColor(lib) {
      return PLATFORMS.find((p) => p.lib === lib)?.color ?? '#9ca3af'
    },

    libLabel(lib) {
      return PLATFORMS.find((p) => p.lib === lib)?.label ?? lib
    },

    async exportPng() {
      const el = this.$refs.cardRoot?.$el ?? this.$refs.cardRoot
      if (!el) return
      const canvas = await html2canvas(el, { backgroundColor: '#ffffff', scale: 2 })
      const link   = document.createElement('a')
      link.download = `layer-analytics-${this.card.layer_id}.png`
      link.href     = canvas.toDataURL('image/png')
      link.click()
    },
  },
}
</script>
```

- [ ] **Step 2: Rebuild del dist**

```bash
cd wm-package/src/Nova/Cards/LayerAnalytics
npm install   # solo se node_modules non esiste
npm run prod
```

Verifica che `dist/js/card.js` sia stato aggiornato (timestamp modificato).

- [ ] **Step 3: Pubblica gli asset Nova nel progetto principale**

```bash
docker exec laravel-camminiditalia php artisan nova:publish --force
```

- [ ] **Step 4: Verifica visuale in browser**

Apri un layer nel pannello Nova. Nella sezione Analytics verifica:
- La dropdown mostra "Ultimi 30 giorni" selezionato di default
- Selezionando "Ultimi 90 giorni" il grafico si aggiorna (loading → dati)
- Selezionando un mese (es. "Gennaio 2026") il grafico mostra i dati di quel mese
- Il titolo in alto si aggiorna coerentemente con la selezione
- Il pulsante PNG funziona ancora

- [ ] **Step 5: Commit**

```bash
git -C wm-package add src/Nova/Cards/LayerAnalytics/resources/js/components/LayerAnalyticsCard.vue
git -C wm-package add src/Nova/Cards/LayerAnalytics/dist/js/card.js
git -C wm-package commit -m "feat(oc:7648): add range selector dropdown to LayerAnalyticsCard Vue component"
```

---

---

## Task 5: Download per traccia — service, controller e tabella Vue

**Files:**
- Modify: `src/Services/PostHog/AnalyticsService.php` — aggiunge `getLayerTrackDownloads`
- Modify: `src/Nova/Cards/LayerAnalytics/resources/js/components/LayerAnalyticsCard.vue` — tabella download
- Modify: `tests/Unit/AnalyticsServiceTest.php` — test per il nuovo metodo

**Interfaces:**
- Produces: `getLayerTrackDownloads(Layer $layer, string $range = 'last_30_days'): array`
  - Ritorna array di `['track_id' => int, 'name' => string, 'downloads' => int]` ordinati per `downloads` desc
- Il controller (Task 2) include già `track_downloads` nella risposta — questo task aggiunge solo il metodo al service e la tabella Vue

- [ ] **Step 1: Scrivi il test per `getLayerTrackDownloads`**

Aggiungi in `tests/Unit/AnalyticsServiceTest.php`:

```php
public function test_get_layer_track_downloads_returns_normalized_structure(): void
{
    Http::fake([
        '*' => Http::sequence()
            ->push(['results' => [['42', 15], ['7', 3]]]),
    ]);

    // Layer mock con ecTracks che restituisce track IDs 42 e 7
    $layer = $this->createLayerMockWithTrackIds([42, 7]);

    $result = (new AnalyticsService)->getLayerTrackDownloads($layer, 'last_30_days');

    $this->assertCount(2, $result);
    $this->assertSame(42, $result[0]['track_id']);
    $this->assertSame(15, $result[0]['downloads']);
    $this->assertSame(7, $result[1]['track_id']);
    $this->assertSame(3, $result[1]['downloads']);
}

public function test_get_layer_track_downloads_returns_empty_when_no_tracks(): void
{
    $layer = $this->createLayerMockWithTrackIds([]);

    $result = (new AnalyticsService)->getLayerTrackDownloads($layer, 'last_30_days');

    $this->assertSame([], $result);
    Http::assertNothingSent();
}

// Aggiungi questo helper privato nella classe di test:
private function createLayerMockWithTrackIds(array $ids): object
{
    $query = \Mockery::mock();
    $query->shouldReceive('pluck')->with('ec_tracks.id')->andReturn(collect($ids));

    $layer = \Mockery::mock(\Wm\WmPackage\Models\Layer::class)->makePartial();
    $layer->shouldReceive('ecTracks')->andReturn($query);

    return $layer;
}
```

- [ ] **Step 2: Esegui i test per verificare che falliscano**

```bash
docker exec laravel-camminiditalia php artisan test tests/Unit/AnalyticsServiceTest.php --filter=track_downloads
```

Atteso: FAIL — metodo `getLayerTrackDownloads` non esiste.

- [ ] **Step 3: Aggiungi `getLayerTrackDownloads` ad `AnalyticsService.php`**

Aggiungi dopo il metodo `getLayerUsage`:

```php
public function getLayerTrackDownloads(\Wm\WmPackage\Models\Layer $layer, string $range = 'last_30_days'): array
{
    $trackIds = $layer->ecTracks()->pluck('ec_tracks.id')->toArray();

    if (empty($trackIds)) {
        return [];
    }

    $cacheKey = 'posthog:trackDownloaded:layer:' . $layer->id . ':downloads:' . $range;
    $ttl      = $this->ttlFor($range);

    $rows = Cache::remember(
        $cacheKey,
        now()->addSeconds($ttl),
        fn () => $this->queryTrackDownloads($trackIds, $range)
    );

    // Arricchisce con i nomi delle tracce dal DB
    $ecTrackModel = config('wm-package.ec_track_model', \Wm\WmPackage\Models\EcTrack::class);
    $tracks = $ecTrackModel::whereIn('id', array_column($rows, 'track_id'))
        ->get(['id', 'name'])
        ->keyBy('id');

    return array_map(fn ($row) => [
        'track_id'  => $row['track_id'],
        'name'      => $tracks[$row['track_id']]?->getTranslation('name', app()->getLocale()) ?? "Track #{$row['track_id']}",
        'downloads' => $row['downloads'],
    ], $rows);
}

private function queryTrackDownloads(array $trackIds, string $range): array
{
    $whereClause = $this->whereClause($range);
    $inList      = implode(', ', array_map(fn ($id) => "'{$id}'", $trackIds));

    $sql = <<<SQL
SELECT
    properties.track_id AS track_id,
    count() AS downloads
FROM events
WHERE event = 'trackDownloaded'
  AND properties.track_id IN ({$inList})
  AND {$whereClause}
GROUP BY track_id
ORDER BY downloads DESC
SQL;

    return array_map(fn ($row) => [
        'track_id'  => (int) $row[0],
        'downloads' => (int) $row[1],
    ], $this->runQuery($sql));
}
```

- [ ] **Step 4: Esegui tutti i test**

```bash
docker exec laravel-camminiditalia php artisan test tests/Unit/AnalyticsServiceTest.php
```

Atteso: tutti i test passano.

- [ ] **Step 5: Aggiungi la tabella nel componente Vue**

Nella sezione `<template>`, aggiungi dopo il blocco `<!-- Breakdown totali -->`:

```vue
<!-- Download per traccia -->
<div v-if="data.track_downloads && data.track_downloads.length" style="margin-top:24px;">
  <p style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; margin-bottom:8px;">Download per traccia</p>
  <table style="width:100%; border-collapse:collapse; font-size:0.875rem;">
    <thead>
      <tr style="border-bottom:1px solid #e5e7eb;">
        <th style="text-align:left; padding:6px 8px; color:#6b7280; font-weight:500;">Traccia</th>
        <th style="text-align:right; padding:6px 8px; color:#6b7280; font-weight:500;">Download</th>
      </tr>
    </thead>
    <tbody>
      <tr
        v-for="row in data.track_downloads"
        :key="row.track_id"
        style="border-bottom:1px solid #f3f4f6;"
      >
        <td style="padding:6px 8px; color:#374151;">{{ row.name }}</td>
        <td style="padding:6px 8px; text-align:right; font-weight:600; color:#10b981;">{{ row.downloads }}</td>
      </tr>
    </tbody>
  </table>
</div>
```

- [ ] **Step 6: Rebuild del dist e publish**

```bash
cd wm-package/src/Nova/Cards/LayerAnalytics
npm run prod
docker exec laravel-camminiditalia php artisan nova:publish --force
```

- [ ] **Step 7: Verifica visuale in browser**

Apri un layer con tracce nel pannello Nova. Verifica:
- La tabella "Download per traccia" appare sotto il breakdown piattaforme
- Cambiando range (es. "Ultimi 90 giorni") la tabella si aggiorna insieme al grafico
- Se il layer non ha tracce o non ci sono download nel periodo, la tabella non appare

- [ ] **Step 8: Commit**

```bash
git -C wm-package add src/Services/PostHog/AnalyticsService.php
git -C wm-package add src/Nova/Cards/LayerAnalytics/resources/js/components/LayerAnalyticsCard.vue
git -C wm-package add src/Nova/Cards/LayerAnalytics/dist/js/card.js
git -C wm-package add tests/Unit/AnalyticsServiceTest.php
git -C wm-package commit -m "feat(oc:7648): add per-track download table to LayerAnalyticsCard"
```

---

## Self-Review

**Spec coverage:**
- [x] Dropdown range 30/90/365gg + mesi → Task 4
- [x] Fetch dinamica per range → Task 4
- [x] Titolo dinamico → Task 4
- [x] `AnalyticsService` range dinamico + WHERE corretto → Task 1
- [x] Cache key scoped per range → Task 1
- [x] TTL differenziato → Task 1
- [x] `Cache::lock` per 90gg e 365gg → Task 1
- [x] `LayerAnalyticsCard.php` con `tracking_since` → Task 3
- [x] Controller valida `?days`/`?month` → Task 2
- [x] Precedenza `?month` su `?days` → Task 2
- [x] Fallback `tracking_since` nel Vue → Task 4 (`trackingSince` computed)
- [x] Rebuild `dist/js/card.js` → Task 4 Step 2 e Task 5 Step 6
- [x] Download per traccia: query PostHog `trackDownloaded`, arricchimento nomi DB → Task 5
- [x] Tabella download nel Vue, aggiornata al cambio range → Task 5 Step 5
- [x] Layer senza tracce: query non eseguita, tabella nascosta → Task 5

**Placeholder scan:** nessun TBD o placeholder trovato.

**Type consistency:** `getLayerUsage(int $id, string $range = 'last_30_days'): array` — firma usata identicamente in Task 1 (service) e Task 2 (controller).
