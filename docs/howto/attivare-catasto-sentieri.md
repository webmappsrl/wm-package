# Attivare il Catasto Sentieri su uno shard

> Procedura completa e ordinata. Prima di oc:8539 questi passaggi erano sparsi fra
> [`TrailRegistry.md`](../resources/TrailRegistry.md), [`OptionalDomains.md`](../resources/OptionalDomains.md) e
> la conoscenza di Forestas — l'unico shard che oggi ha il dominio acceso. Ogni passaggio riporta
> il riferimento concreto di Forestas.

## 1. Interruttore `WM_TRAIL_REGISTRY_ENABLED`

Va allineato in **sei** posti, di cui uno fuori dal repository:

| Posto | Riferimento Forestas |
|---|---|
| `.env` (locale, non versionato) | `WM_TRAIL_REGISTRY_ENABLED=true` |
| `.env-deploy` (se la CI lo copia in `.env`) | `WM_TRAIL_REGISTRY_ENABLED=true` |
| `.env-example` (chi installa da zero) | `WM_TRAIL_REGISTRY_ENABLED=true` |
| `phpunit.xml`, accanto a `DB_DATABASE` | `<env name="WM_TRAIL_REGISTRY_ENABLED" value="true"/>` |
| `.env.testing-example` (da cui nasce `.env.testing`, letto dai comandi artisan con `--env=testing`, dove `phpunit.xml` non arriva) | `WM_TRAIL_REGISTRY_ENABLED=true` |
| `.env` di **ogni server** (UAT, produzione) | non versionato: è il posto che si dimentica |

Solo i primi cinque si vedono in un diff.

## 2. Migration del dominio

Pubblicare gli stub, uno per uno:

```bash
php artisan wm-package:publish-migration trail_registry/<nome-stub>
php artisan migrate
```

oppure, per vederli tutti insieme:

```bash
php artisan wm-package:publish-missing-migrations --with=trail_registry
```

Se il repo ha un gate in CI, aggiungere lo stesso `--with` allo step esistente. Su Forestas:
`.github/workflows/run-tests.yml`, `php artisan wm-package:publish-missing-migrations --with=trail_registry --dry-run`.

## 3. Configurazione che varia per shard

Tutto sotto `config('wm-package.features.trail_registry')`, ciascuna chiave con un `env()` proprio.
Valori di Forestas:

| Chiave | Env | Valore su Forestas |
|---|---|---|
| `legacy_code_property` | `WM_TRAIL_LEGACY_CODE_PROPERTY` | `ref` (default del package) |
| `source_url_property` | `WM_TRAIL_SOURCE_URL_PROPERTY` | `forestas.url` |
| `source_label` | `WM_TRAIL_SOURCE_LABEL` | `Drupal` |
| `sector_source` | `WM_TRAIL_SECTOR_SOURCE` | `osm2cai` (default del package) |
| `name_code_pattern` | `WM_TRAIL_NAME_CODE_PATTERN` | parentesi finali, default del package (convenzione Sardegna Sentieri) |
| `nova_uri_keys.*` | `WM_TRAIL_URI_KEY_EC_TRACK` / `_TAXONOMY_WHERE` / `_TRAIL_APPLICATION` | `ec-tracks`, `taxonomy-wheres`, `trail-applications` (default del package) |

`source_url_property` e `source_label` sono **vuote di default**: senza impostarle la lista delle
anomalie perde i collegamenti alla scheda di origine.

## 4. Sottoclassi Nova obbligatorie in `app/Nova`

Il package non registra più le Resource del dominio (oc:8539): serve una sottoclasse per ciascuna,
anche vuota. Non va dichiarato nessun `uriKey`: `uriKey()` è fisso nella classe base
(`trail-registry-codes`, `trail-applications`, `trail-registry-anomalies`) e la sottoclasse lo
eredita, restando coerente con `ComposesTrailRegistryMap.php` e con la config `nova_uri_keys`.
Nova non ha una proprietà `$uriKey`: dichiararla non avrebbe effetto.

```php
namespace App\Nova;

use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryCode as BaseTrailRegistryCode;

class TrailRegistryCode extends BaseTrailRegistryCode {}
```

Le tre classi da creare: `App\Nova\TrailRegistryCode`, `App\Nova\TrailApplication`,
`App\Nova\TrailRegistryAnomaly`. Si registrano come il resto delle Resource dello shard, nel
metodo `resources()` del `NovaServiceProvider`: con `Nova::resourcesIn(app_path('Nova'))` — il
default di Nova, che le scopre da sole in `app/Nova` — oppure elencandole in `Nova::resources([...])`.
Il package non le registra. Su Forestas (oc:8539): `app/Nova/TrailRegistryCode.php`,
`app/Nova/TrailApplication.php`, `app/Nova/TrailRegistryAnomaly.php`, autodiscovery di Nova (nessun
`resources()` dichiarato in `app/Providers/NovaServiceProvider.php`).

A dominio spento le sottoclassi restano in `app/Nova`, ma il trait `HidesWhenTrailRegistryDisabled`
della classe base le toglie dalla navigazione e nega visualizzazione, creazione, modifica,
cancellazione ed esecuzione delle Action. Una sottoclasse che ridefinisce uno di questi metodi
scavalca il trait, e deve rifare sia il controllo di dominio con `static::trailRegistryEnabled()` sia quello di ruolo con `TrailRegistryPolicy::allows()` (oc:8700).

**Sezione di menu «Catasto»**: va dichiarata **vuota** nel `mainMenu` dello shard, solo per
fissarne la posizione — le voci le aggiunge il package
(`WmPackageServiceProvider::injectMenuSectionItems()` / `trailRegistryMenuItems()`), e solo a
dominio acceso, cercando le Resource per uriKey con `Nova::resourceForKey()`. Una voce si omette se
lo shard non ha registrato la Resource con quella chiave. Riferimento Forestas — `app/Providers/NovaServiceProvider.php`:

```php
MenuSection::make(__('Catasto'), [
    // le voci del dominio le inietta il package, non questa dichiarazione
]),
```

Su Forestas (oc:8539): `app/Providers/NovaServiceProvider.php`, sezione di menu «Catasto» già
dichiarata e vuota, sottoclassi in `app/Nova/TrailRegistryCode.php`, `app/Nova/TrailApplication.php`
e `app/Nova/TrailRegistryAnomaly.php`.

## 5. Estendere: modelli, service, tipi di anomalia

**Modelli** — ogni chiave sotto `config('wm-package.features.trail_registry.models')` può puntare
a una sottoclasse dello shard. La sostituzione va in `AppServiceProvider::register()`, non in
`boot()`:

```php
config([
    'wm-package.features.trail_registry.models.code' => \App\Models\TrailRegistryCode::class,
]);
```

`TrailRegistryClasses::assertValid()` gira all'avvio del package (`packageBooted()`) e solleva
un'eccezione chiara se la classe non esiste o non estende quella del package. Una sostituzione
fatta in `boot()` può arrivare dopo quel controllo, e una classe sbagliata passerebbe inosservata.

Chi sostituisce un modello allinea anche `$model` della sottoclasse Nova corrispondente:

```php
class TrailRegistryCode extends BaseTrailRegistryCode
{
    public static $model = \App\Models\TrailRegistryCode::class;
}
```

Il package regge anche il disallineamento — le voci di menu e il `BelongsTo` «Istanza» trovano la
Resource per uriKey, e `newModel()` istanzia la classe della config — ma Nova usa `$model` altrove
(es. `Nova::resourceForModel()` nel resto dello shard), e i due valori devono dire la stessa cosa.
Se la sottoclasse cambia `$model`, `newModel()` segue la sottoclasse e non la config.

**Service** — risolto sempre con `app(TrailRegistryService::class)`: uno shard lo sostituisce con
un binding nel container (`$this->app->bind(TrailRegistryService::class, MioService::class)`).

**Tipi di anomalia** — accanto ai tipi fissi del catasto (`TrailRegistryAnomalyType`), uno shard
dichiara i propri in `config('wm-package.features.trail_registry.anomaly_types')`
(`chiave => classe`), ciascuna classe implementa `AnomalyTypeDefinition` (`label()` e
`detailRows()`, valori già passati da `e()`). Ogni tipo aggiunto dallo shard **deve** avere una
provenienza propria (non `TrailRegistryAnomaly::SOURCE_CATASTO`): il normalize riscrive da zero
solo le anomalie di provenienza `catasto`, e un tipo dello shard con quella provenienza verrebbe
cancellato alla prima esecuzione.

Su Forestas (oc:8539): nessun modello sostituito, nessun binding di service; quattro tipi di
anomalia propri (provenienza `registro`, distinta da `catasto`) dichiarati in
`app/Providers/AppServiceProvider.php::register()` e implementati in
`app/Services/RegistroCatastale/AnomalyTypes/` — dettaglio in `docs/knowledge/registro-catastale.md`
del repo forestas.

## 6. Normalize e il suo aggancio all'import dello shard

```bash
php artisan wm-package:trail-registry-normalize [--dry-run] [--force]
```

Cancella e riscrive **solo** le anomalie con `source = 'catasto'` (`TrailRegistryAnomaly::SOURCE_CATASTO`,
scope `fromSource()`); le anomalie di un'altra provenienza non vengono toccate.

Va lanciato **dopo** che l'import dello shard ha finito di scrivere i codici storici, non a metà:
su Forestas, `ImportSardegnaSentieriCommand::dispatchImportBatch()` lo invoca nella callback
`->finally()` del batch (`Bus::batch($jobs)->finally(...)`), condizionato da
`shouldNormalize($failedJobs, $totalJobs)` — solo se il batch non ha fallito oltre soglia.

## 7. Verifiche finali

- Le voci di menu «Catasto» compaiono solo con il dominio acceso e solo per le Resource che lo
  shard ha effettivamente registrato (`MainMenuInjectionTest`, `MenuSectionInjectionTest`).
- La join «anomalia ⇒ nessun codice» torna zero **per le anomalie di provenienza `catasto`**:

  ```sql
  select count(*) from trail_registry_codes c
  join trail_registry_anomalies a on a.ec_track_id = c.ec_track_id
  where a.source = 'catasto';
  ```

- I test del dominio (`tests/Feature/TrailRegistry/`) passano, in particolare
  `TrailRegistryShardNeutralityTest` (nessuna presunzione sullo shard rientrata di nascosto) e
  `TrailRegistryDomainRegistrationTest` (le Resource del package restano in `src/TrailRegistry/Nova`,
  mai registrate da lui).
