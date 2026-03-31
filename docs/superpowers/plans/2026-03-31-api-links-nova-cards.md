# API Links Nova Cards — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aggiungere card Nova nelle detail view di Layer e EcTrack che mostrano link diretti a Elasticsearch e MinIO.

**Architecture:** Nuovo mini-package `ApiLinksCard` in `wm-package/src/Nova/Cards/ApiLinksCard/` con build webpack Vue 3 (stesso pattern di LayerFeatures). Due sottoclassi PHP (`LayerApiLinksCard`, `EcTrackApiLinksCard`) costruiscono gli URL e li passano al componente Vue. Le card vengono registrate nei metodi `cards()` di `Layer.php` e `EcTrack.php` in wm-package.

**Tech Stack:** Laravel Nova 5, Vue 3, laravel-mix, PHP 8.4

---

## File Map

**Nuovi file (wm-package):**
- `wm-package/src/Nova/Cards/ApiLinksCard/src/CardServiceProvider.php`
- `wm-package/src/Nova/Cards/ApiLinksCard/src/ApiLinksCard.php`
- `wm-package/src/Nova/Cards/ApiLinksCard/src/LayerApiLinksCard.php`
- `wm-package/src/Nova/Cards/ApiLinksCard/src/EcTrackApiLinksCard.php`
- `wm-package/src/Nova/Cards/ApiLinksCard/resources/js/card.js`
- `wm-package/src/Nova/Cards/ApiLinksCard/resources/js/components/ApiLinksCard.vue`
- `wm-package/src/Nova/Cards/ApiLinksCard/webpack.mix.js`
- `wm-package/src/Nova/Cards/ApiLinksCard/package.json`

**File modificati (wm-package):**
- `wm-package/src/WmPackageServiceProvider.php` — registra `CardServiceProvider`
- `wm-package/src/Nova/Layer.php` — aggiunge `cards()`
- `wm-package/src/Nova/EcTrack.php` — aggiunge `cards()`

---

## Task 1: Struttura directory e build setup

**Files:**
- Create: `wm-package/src/Nova/Cards/ApiLinksCard/package.json`
- Create: `wm-package/src/Nova/Cards/ApiLinksCard/webpack.mix.js`

- [ ] **Step 1: Crea package.json**

```json
{
  "name": "wm/api-links-card",
  "description": "Nova card that renders API links",
  "license": "MIT",
  "scripts": {
    "dev": "mix",
    "watch": "mix watch",
    "prod": "mix --production"
  },
  "devDependencies": {
    "laravel-mix": "^6.0.49",
    "laravel-nova-devtool": "file:vendor/laravel/nova-devtool",
    "vue": "^3.2.31",
    "vue-loader": "^17.0.0",
    "@vue/compiler-sfc": "^3.2.31"
  }
}
```

- [ ] **Step 2: Crea webpack.mix.js**

```js
const mix = require('laravel-mix')

mix
  .setPublicPath('dist')
  .js('resources/js/card.js', 'js')
  .vue({ version: 3 })
  .version()
  .webpackConfig({
    externals: { vue: 'Vue' },
    output: { uniqueName: 'wm/api-links-card' },
  })
```

- [ ] **Step 3: Installa e compila**

```bash
cd wm-package/src/Nova/Cards/ApiLinksCard
cp -r ../../../Nova/Fields/LayerFeatures/vendor .
npm install
npm run prod
```

Output atteso: `dist/mix-manifest.json` + `dist/js/card.js` creati.

---

## Task 2: Vue component

**Files:**
- Create: `wm-package/src/Nova/Cards/ApiLinksCard/resources/js/components/ApiLinksCard.vue`
- Create: `wm-package/src/Nova/Cards/ApiLinksCard/resources/js/card.js`

- [ ] **Step 1: Crea ApiLinksCard.vue**

```vue
<template>
  <card class="flex flex-col p-4">
    <h4 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">
      API Links
    </h4>
    <div class="flex flex-col gap-2">
      <a
        v-for="link in links"
        :key="link.label"
        :href="link.url"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg
               bg-primary-500 text-white hover:bg-primary-600 transition-colors break-all"
      >
        {{ link.label }}
      </a>
    </div>
  </card>
</template>

<script>
export default {
  props: {
    card: { type: Object, required: true },
  },
  computed: {
    links() {
      return this.card.links ?? []
    },
  },
}
</script>
```

- [ ] **Step 2: Crea card.js**

```js
Nova.booting((app) => {
  app.component('api-links-card', require('./components/ApiLinksCard').default)
})
```

- [ ] **Step 3: Ricompila**

```bash
cd wm-package/src/Nova/Cards/ApiLinksCard
npm run prod
```

---

## Task 3: CardServiceProvider PHP

**Files:**
- Create: `wm-package/src/Nova/Cards/ApiLinksCard/src/CardServiceProvider.php`

- [ ] **Step 1: Crea CardServiceProvider**

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Cards\ApiLinksCard;

use Illuminate\Support\ServiceProvider;
use Laravel\Nova\Events\ServingNova;
use Laravel\Nova\Nova;

class CardServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Nova::serving(function (ServingNova $event) {
            Nova::mix('api-links-card', __DIR__.'/../dist/mix-manifest.json');
        });
    }

    public function register(): void {}
}
```

---

## Task 4: Classi PHP Card

**Files:**
- Create: `wm-package/src/Nova/Cards/ApiLinksCard/src/ApiLinksCard.php`
- Create: `wm-package/src/Nova/Cards/ApiLinksCard/src/LayerApiLinksCard.php`
- Create: `wm-package/src/Nova/Cards/ApiLinksCard/src/EcTrackApiLinksCard.php`

- [ ] **Step 1: Crea ApiLinksCard.php**

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Cards\ApiLinksCard;

use Laravel\Nova\Card;

class ApiLinksCard extends Card
{
    public $component = 'api-links-card';

    public $width = '1/3';

    /** @param array<int, array{label: string, url: string}> $links */
    public function __construct(private array $links) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'links' => $this->links,
        ]);
    }
}
```

- [ ] **Step 2: Crea LayerApiLinksCard.php**

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Cards\ApiLinksCard;

use Wm\WmPackage\Models\Layer;

class LayerApiLinksCard extends ApiLinksCard
{
    public function __construct(Layer $layer)
    {
        parent::__construct([
            [
                'label' => 'Elasticsearch',
                'url' => url('/api/v2/elasticsearch')
                    . '?app=geohub_app_' . $layer->app_id
                    . '&layer=' . $layer->id,
            ],
        ]);
    }
}
```

- [ ] **Step 3: Crea EcTrackApiLinksCard.php**

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Cards\ApiLinksCard;

use Wm\WmPackage\Models\EcTrack;

class EcTrackApiLinksCard extends ApiLinksCard
{
    public function __construct(EcTrack $track)
    {
        $shardName = config('wm-package.shard_name', config('app.name'));
        $wmfeUrl = rtrim(env('AWS_WMFE_URL', config('app.url').'/wmfe'), '/');

        parent::__construct([
            [
                'label' => 'MinIO Track JSON',
                'url' => $wmfeUrl.'/'.$shardName.'/tracks/'.$track->id.'.json',
            ],
        ]);
    }
}
```

---

## Task 5: Registrazione in WmPackageServiceProvider

**Files:**
- Modify: `wm-package/src/WmPackageServiceProvider.php` (riga ~59)

- [ ] **Step 1: Aggiungi la registrazione dopo le altre**

Trova il blocco:
```php
$this->app->register(\Wm\WmPackage\Nova\Fields\FeatureCollectionGrid\FieldServiceProvider::class);
```

Aggiungi subito dopo:
```php
$this->app->register(\Wm\WmPackage\Nova\Cards\ApiLinksCard\CardServiceProvider::class);
```

---

## Task 6: Cards nelle risorse Nova

**Files:**
- Modify: `wm-package/src/Nova/Layer.php`
- Modify: `wm-package/src/Nova/EcTrack.php`

- [ ] **Step 1: Aggiungi cards() a Layer.php**

Aggiungi tra gli use (la riga `use Laravel\Nova\Http\Requests\NovaRequest;` è già presente alla riga 14):

```php
use Wm\WmPackage\Nova\Cards\ApiLinksCard\LayerApiLinksCard;
```

Aggiungi il metodo dopo `actions()`:

```php
public function cards(NovaRequest $request): array
{
    return [
        new LayerApiLinksCard($this->resource),
    ];
}
```

- [ ] **Step 2: Aggiungi cards() a EcTrack.php**

Aggiungi tra gli use:

```php
use Wm\WmPackage\Nova\Cards\ApiLinksCard\EcTrackApiLinksCard;
```

Aggiungi il metodo dopo `actions()`:

```php
public function cards(NovaRequest $request): array
{
    return [
        new EcTrackApiLinksCard($this->resource),
    ];
}
```

---

## Task 7: Autoload check

- [ ] **Step 1: Verifica namespace**

```bash
grep -A3 '"psr-4"' wm-package/composer.json
```

Se `"Wm\\WmPackage\\": "src/"` è presente, nessuna modifica necessaria.

- [ ] **Step 2: Dump autoload**

```bash
cd wm-package && composer dump-autoload
```

---

## Task 8: Test manuale

- [ ] **Verifica Layer**: Nova → Layer → detail view → card "API Links" con bottone Elasticsearch che punta a `{APP_URL}/api/v2/elasticsearch?app=geohub_app_{app_id}&layer={id}`

- [ ] **Verifica EcTrack**: Nova → EcTrack → detail view → card con bottone "MinIO Track JSON" che punta a `{AWS_WMFE_URL}/{SHARD_NAME}/tracks/{id}.json`

- [ ] **Verifica EcPoi**: Nova → EcPoi → detail view → nessuna card API Links presente

---

## Note

- `getTrackPath()` in `StorageService` è `private` — URL costruita inline in `EcTrackApiLinksCard` per non modificare l'interfaccia pubblica del service.
- Il prefisso `geohub_app_` è legacy. Il numero è sempre l'id dell'App Nova associata al Layer.
- `$width = '1/3'` — cambia in `'1/2'` o `'full'` se il layout risulta stretto.
- Nessun commit autonomo: il codice va revisionato dall'utente prima del commit.
