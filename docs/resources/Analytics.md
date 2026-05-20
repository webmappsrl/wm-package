# PostHog Analytics in Nova — Guida all'implementazione

## Come funziona

```
PostHog (self-hosted)
    ↓ HogQL Query API (POST /api/projects/{id}/query)
AnalyticsService
    ↓ JSON normalizzato + cache 15min
AnalyticsController
    ↓ GET /nova-vendor/{model}-analytics/{id}
Nova Card (PHP) → Vue component
    ↓ fetch() verso l'endpoint
Grafico + KPI dentro la pagina dettaglio Nova
```

## Struttura dei file

```
wm-package/src/
├── Services/PostHog/
│   └── AnalyticsService.php          # Tutte le query HogQL, cache, normalizzazione
├── Http/Controllers/Nova/
│   └── AnalyticsController.php       # Un metodo pubblico per modello
├── Nova/Cards/LayerAnalytics/
│   ├── src/
│   │   ├── LayerAnalyticsCard.php    # Classe Nova Card (PHP)
│   │   └── CardServiceProvider.php  # Registra gli asset JS in Nova
│   ├── resources/js/
│   │   ├── card.js                   # Entry point Vue
│   │   └── components/
│   │       └── LayerAnalyticsCard.vue # Componente Vue con Chart.js
│   ├── dist/                         # Asset compilati (non editare)
│   ├── package.json
│   └── webpack.mix.js
└── WmPackageServiceProvider.php      # Registra route + CardServiceProvider
```

## Configurazione richiesta (.env)

```
POSTHOG_HOST=https://posthog.webmapp.it
POSTHOG_PROJECT_ID=1
POSTHOG_PERSONAL_API_KEY=phx_...
POSTHOG_ANALYTICS_CACHE_TTL=900   # secondi, default 15 min
```

## Visibilità della card

La card viene mostrata solo se sull'App associata al modello è attivo almeno uno tra:
- `properties->analytics_app_enabled`
- `properties->analytics_webapp_enabled`

Questo controllo avviene nel metodo `cards()` del resource Nova del modello.

---

## Come aggiungere analytics per un nuovo modello

### Esempio: aggiungere analytics per EcTrack con evento `trackViewed`

### 1. AnalyticsService — aggiungere il metodo pubblico

In `src/Services/PostHog/AnalyticsService.php`:

```php
public function getEcTrackUsage(int $id): array
{
    return $this->getUsage('trackViewed', 'track_id', $id);
}
```

Il metodo generico `getUsage(string $event, string $idProperty, int $id)` fa tutto:
- 3 query HogQL (serie giornaliera per piattaforma, breakdown totale, utenti unici)
- cache con chiave `posthog:{event}:{id}:usage:last_30_days`
- ritorna sempre questa struttura:

```json
{
  "id": 42,
  "event": "trackViewed",
  "range": "last_30_days",
  "total": 128,
  "daily_breakdown": [
    { "date": "2026-05-01", "lib": "posthog-android", "total": 10 },
    { "date": "2026-05-01", "lib": "posthog-ios", "total": 3 }
  ],
  "breakdown": [
    { "lib": "posthog-android", "total": 90 },
    { "lib": "posthog-ios", "total": 38 }
  ],
  "unique_users": 45
}
```

Le piattaforme filtrate sono `posthog-ios`, `posthog-android`, `web` (costante `LIBS` nel service).

### 2. AnalyticsController — aggiungere il metodo

In `src/Http/Controllers/Nova/AnalyticsController.php`:

```php
use Wm\WmPackage\Models\EcTrack;

public function ecTrack(EcTrack $ecTrack): JsonResponse
{
    return response()->json(
        app(AnalyticsService::class)->getEcTrackUsage($ecTrack->id)
    );
}
```

### 3. Route — registrare il nuovo endpoint

In `src/WmPackageServiceProvider.php`, dentro il blocco `$this->app->call(...)`:

```php
Route::middleware(['nova'])
    ->prefix('nova-vendor/ec-track-analytics')
    ->group(function () {
        Route::get('/{ecTrack}', [\Wm\WmPackage\Http\Controllers\Nova\AnalyticsController::class, 'ecTrack']);
    });
```

### 4. Nova Card PHP — creare la card

Creare `src/Nova/Cards/EcTrackAnalytics/src/EcTrackAnalyticsCard.php`:

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Cards\EcTrackAnalytics;

use Laravel\Nova\Card;
use Wm\WmPackage\Models\EcTrack;

class EcTrackAnalyticsCard extends Card
{
    public $component = 'ec-track-analytics-card'; // nome del componente Vue
    public $width = 'full';
    public $onlyOnDetail = true;

    private ?int $trackId;

    public function __construct(EcTrack $track)
    {
        parent::__construct();
        $this->trackId = $track->id ?? null;
    }

    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'endpoint' => '/nova-vendor/ec-track-analytics/' . $this->trackId,
            'track_id' => $this->trackId,
        ]);
    }
}
```

### 5. Vue component — creare il componente

Se il layout è lo stesso (KPI + stacked bar + breakdown piattaforme) copia
`LayerAnalyticsCard.vue` e adattalo. I dati hanno sempre la stessa struttura JSON
quindi le modifiche sono minime (titolo, nome file PNG nel metodo `exportPng`).

Registrare il componente nel `card.js` della nuova card:

```js
Nova.booting((app) => {
  app.component('ec-track-analytics-card', require('./components/EcTrackAnalyticsCard').default)
})
```

### 6. CardServiceProvider + WmPackageServiceProvider

Creare `src/Nova/Cards/EcTrackAnalytics/src/CardServiceProvider.php` (copia da LayerAnalytics, aggiorna il nome mix).

Registrarlo in `WmPackageServiceProvider.php`:

```php
use Wm\WmPackage\Nova\Cards\EcTrackAnalytics\CardServiceProvider as EcTrackAnalyticsCardServiceProvider;

// dentro register():
$this->app->register(EcTrackAnalyticsCardServiceProvider::class);
```

### 7. Nova Resource — mostrare la card

In `src/Nova/EcTrack.php` (o `app/Nova/EcTrack.php`):

```php
public function cards(NovaRequest $request): array
{
    if (! $request->resourceId) {
        return [];
    }

    /** @var \Wm\WmPackage\Models\EcTrack $track */
    $track = $request->findModelOrFail();

    $app = $track->appOwner; // o relazione equivalente
    $analyticsEnabled = $app &&
        (($app->properties['analytics_app_enabled'] ?? false) ||
         ($app->properties['analytics_webapp_enabled'] ?? false));

    return $analyticsEnabled ? [new EcTrackAnalyticsCard($track)] : [];
}
```

### 8. Build frontend

```bash
cd wm-package/src/Nova/Cards/EcTrackAnalytics
npm install
npm run prod
```

---

## Debug

**Cache vuota manuale:**
```bash
docker exec php-camminiditalia php artisan tinker \
  --execute="Cache::forget('posthog:trackViewed:42:usage:last_30_days'); echo 'ok';"
```

**Testare l'endpoint direttamente (browser autenticato su Nova):**
```
/nova-vendor/ec-track-analytics/42
```

**Query HogQL fallisce:** controllare `storage/logs/laravel-*.log` — `PostHog query failed` con status e body della risposta.

**La card non appare:** verificare che `analytics_app_enabled` o `analytics_webapp_enabled` sia `true` sull'App associata al modello.
