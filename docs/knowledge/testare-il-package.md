# Testare il package

Dove girano i test di `wm-package` e cosa li fa fallire per motivi che non c'entrano col codice
sotto test.

## Stato attuale

### Due suite, due `TestCase`

Il package ha una CI propria (`.github/workflows/run-tests.yml`) che gira su
`Wm\WmPackage\Tests\TestCase`. I consumer hanno la loro suite, che gira su `Tests\TestCase`.

**`Wm\WmPackage\Tests\` non è in `autoload-dev` dei consumer** (verificato su forestas, che
registra solo `Tests\` e le factory del package). Conseguenza: un test del package che non
dichiara esplicitamente `uses(Tests\TestCase::class)` **fallisce silenziosamente** se lanciato dal
consumer, con `BindingResolutionException: Target class [config] does not exist` — e resta
invisibile finché non lo raccoglie la CI indipendente del package (oc:8231, oc:8180, oc:8272).

Chi scrive un test che deve girare dalla suite del consumer usa `Tests\TestCase`; chi ne scrive uno
per la suite del package usa quella del package e lo verifica in CI (oc:8133, oc:8160).

### Trappole ricorrenti

- **`App::factory()` fa partire l'observer.** `AppObserver::saved()` scrive `config.json` su AWS a
  ogni salvataggio, anche in creazione: nei test serve `App::factory()->createQuietly()` (oc:7749,
  oc:8242).
- **In forestas `apps.overlays_label` è NOT NULL** e non è popolata dall'`AppFactory` del package:
  ogni test che usa `App::factory()` va integrato a mano (oc:8175).
- **`EcPoiFactory` ha `properties` come stringa JSON-encoded** assegnata a un campo con cast
  `'array'`: produce doppia serializzazione se non sovrascritto con `'properties' => []`. Bug
  preesistente del factory (oc:8043).
- **`request()->user()` è sempre `null`** quando una Nova Action è invocata direttamente in un test
  (bypassa il kernel HTTP, il resolver Auth non si riaggancia): si usa `auth()->user()`, identico
  in produzione e robusto nei test diretti (oc:8486).
- **I test che toccano le pivot taxonomy** hanno bisogno di
  `DB::statement("SET session_replication_role = replica")` per bypassare le FK (oc:8094).
- **Il ServiceProvider registra le route sia sotto `/api` sia sotto `/api/v2`**: nei test lo stesso
  endpoint risponde su due URL, e quello canonico va scelto esplicitamente (oc:8242).
- **`GeohubImportService::$logger`** è un `Illuminate\Log\Logger` concreto catturato nel
  costruttore: `Log::spy()` non lo intercetta (oc:8094).
- **`develop.compose.yml` fissa `platform: linux/amd64` per MinIO**: su Mac ARM il container va
  sostituito per i test locali sui media (oc:8158).

### Isolamento verso l'esterno

**Qualsiasi test che crea un'App con `native_app_deep_link_enabled = true` deve fakare il Bus o il
disco `well_known_registry`** (`Bus::fake()` + `Storage::fake('well_known_registry')` nel
`setUp()`). Con le credenziali SFTP reali in `.env` scrive **per davvero** sul registro condiviso:
è successo, con nove entry di test finite nel file reale sul server (oc:8251).

Le suite hanno già svuotato accidentalmente il DB di sviluppo (oc:8182), e i test dei comandi di
publish migration cancellano file reali dal working tree (oc:8094).

### Formattazione

`composer format` è `vendor/bin/pint` senza argomenti: **riformatta l'intero repo**, non i file
del task. È già capitato due volte in un solo ciclo. Controllare sempre `git status` /
`git diff --stat` dopo, e scartare i file fuori scope prima che il dev committi (oc:8343).

## Come ci siamo arrivati

- «`composer install` su `wm-package` non completa per credenziali `laravel/nova` scadute» era vero
  nell'ambiente in cui è stato scritto (oc:7546, ripetuto in oc:8367), e aveva come conseguenza
  l'impossibilità di eseguire Pest e PHPStan in locale, con la verifica rimandata alla CI. **Non
  regge più**: nel container `php-forestas` (verificato 2026-09-12) `wm-package/vendor` è completo,
  `laravel/nova` è installato e `wm-package/vendor/bin/pest` esiste. Prima di dichiarare non
  eseguibile la suite, va provata.
- «La suite del package non è eseguibile end-to-end: `vendor/bin/pest` senza filtro fallisce su 19
  file preesistenti che importano `Tests\TestCase` inesistente» (oc:8183) è la stessa cosa vista
  dal lato del consumer, e oggi è coperta dalla voce sui due `TestCase` qui sopra. Resta vera
  l'indicazione di eseguire un file alla volta quando si lavora da un consumer che non registra
  l'autoload del package.
