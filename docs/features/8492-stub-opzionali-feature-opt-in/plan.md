> Ticket: oc:8492

# Stub di migration opzionali e feature opt-in — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: usa `superpowers:subagent-driven-development` (consigliata) o `superpowers:executing-plans` per implementare task per task. Gli step usano checkbox (`- [ ]`).

**Goal:** dare al wm-package il concetto di dominio opzionale — stub di migration in sottocartella, interruttore per dominio, gate che considera i soli domini accesi — senza cambiare di una virgola il comportamento per i consumer attuali.

**Architecture:** un servizio statico legge `config('wm-package.features')` ed e' l'unica fonte di verita' sui domini. Il trait degli stub impara a leggere le sottocartelle e a usare identificatori qualificati `<dominio>/<basename>`; i due comandi ereditano il comportamento e aggiungono `--with`. Il service provider registra i comandi di un dominio solo se acceso.

**Tech Stack:** PHP 8.1+, Laravel 12, `spatie/laravel-package-tools`, Pest/PHPUnit.

**Spec:** `docs/features/8492-stub-opzionali-feature-opt-in/overview.md` (questo repo) e `forestas/docs/features/8492-stub-opzionali-feature-opt-in/overview.md` (adozione del gate).

## Global Constraints

- **PHP minimo `>8.1`**: mai dichiarare `const` dentro un trait (supportate solo da 8.2). Vedi la decisione oc:8349 in `CLAUDE.md`.
- **Nessun commit automatico.** I passi "Commit" sono istruzioni per il developer. Non eseguire `git add`, `git commit`, `git push` senza sua conferma esplicita, task per task.
- **Comportamento invariato per i 67 stub della root** e per i consumer che non accendono nulla. Questo e' il requisito che ha la precedenza su ogni altro.
- **Asserire per insieme, mai per conteggio.** Nessun test deve contenere il numero 67 o simili: si confronta l'insieme prima/dopo, altrimenti il test diventa rosso al primo stub aggiunto da un ticket non correlato.
- Nomi in inglese, messaggi utente dei comandi in italiano — come il codice esistente nei due comandi.
- Commit: `feat(oc:8492): ...` / `fix(oc:8492): ...` / `refactor(oc:8492): ...`

---

## Task 0: Verificare che la suite sia eseguibile

`CLAUDE.md` di questo package documenta che `composer install` non completa in alcuni ambienti locali per credenziali Nova scadute, e che questo impedisce di eseguire Pest e PHPStan. Tutto il piano e' TDD: senza suite eseguibile non e' applicabile.

- [ ] **Step 1: provare a eseguire la suite esistente sugli stub**

```bash
cd wm-package
vendor/bin/pest tests/Feature/InteractsWithWmPackageMigrationStubsTest.php
```

- [ ] **Step 2: decidere in base all'esito**

Se i test girano: procedi al Task 1.

Se falliscono per ambiente (vendor mancante, credenziali Nova, database di test assente): **fermati e chiedi al developer** come procedere. Non lanciare la suite di forestas al posto di questa e non modificare la configurazione dei database per aggirare il problema.

---

## Task 1: Servizio dei domini

Unica fonte di verita' su quali domini esistono e quali sono accesi. Tutto il resto lo interroga.

**Files:**
- Create: `src/Services/FeaturesService.php`
- Modify: `config/wm-package.php` (aggiunta del blocco `features`)
- Test: `tests/Unit/FeaturesServiceTest.php`

**Interfaces:**
- Produces:
  - `FeaturesService::declaredDomains(): array<int, string>` — chiavi di `config('wm-package.features')`
  - `FeaturesService::isEnabled(string $domain): bool` — legge `features.<domain>.enabled`
  - `FeaturesService::enabledDomains(): array<int, string>`

Metodi statici, senza stato interno: stesso pattern di `RolesAndPermissionsService`, che legge solo `config()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Wm\WmPackage\Tests\Unit;

use Tests\TestCase;
use Wm\WmPackage\Services\FeaturesService;

class FeaturesServiceTest extends TestCase
{
    public function test_declared_domains_lists_config_keys(): void
    {
        config(['wm-package.features' => [
            'trail_registry' => ['enabled' => false],
            'osmfeatures' => ['enabled' => true],
        ]]);

        $this->assertSame(['trail_registry', 'osmfeatures'], FeaturesService::declaredDomains());
    }

    public function test_is_enabled_reads_the_enabled_key(): void
    {
        config(['wm-package.features' => [
            'trail_registry' => ['enabled' => true],
            'osmfeatures' => ['enabled' => false],
        ]]);

        $this->assertTrue(FeaturesService::isEnabled('trail_registry'));
        $this->assertFalse(FeaturesService::isEnabled('osmfeatures'));
    }

    public function test_unknown_domain_is_not_enabled(): void
    {
        config(['wm-package.features' => []]);

        $this->assertFalse(FeaturesService::isEnabled('inesistente'));
    }

    public function test_enabled_domains_returns_only_the_active_ones(): void
    {
        config(['wm-package.features' => [
            'trail_registry' => ['enabled' => true],
            'osmfeatures' => ['enabled' => false],
        ]]);

        $this->assertSame(['trail_registry'], FeaturesService::enabledDomains());
    }

    public function test_missing_features_key_yields_no_domains(): void
    {
        config(['wm-package.features' => null]);

        $this->assertSame([], FeaturesService::declaredDomains());
        $this->assertSame([], FeaturesService::enabledDomains());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/pest tests/Unit/FeaturesServiceTest.php
```

Atteso: FAIL — `Class "Wm\WmPackage\Services\FeaturesService" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace Wm\WmPackage\Services;

class FeaturesService
{
    /**
     * @return array<int, string>
     */
    public static function declaredDomains(): array
    {
        $features = config('wm-package.features');

        return is_array($features) ? array_keys($features) : [];
    }

    public static function isEnabled(string $domain): bool
    {
        return (bool) config("wm-package.features.{$domain}.enabled", false);
    }

    /**
     * @return array<int, string>
     */
    public static function enabledDomains(): array
    {
        return array_values(array_filter(
            self::declaredDomains(),
            fn (string $domain) => self::isEnabled($domain),
        ));
    }
}
```

- [ ] **Step 4: Aggiungere il blocco `features` alla configurazione**

In `config/wm-package.php`, subito dopo `'default_layer_mode'`:

```php
    /*
    | Domini opzionali del package {@see \Wm\WmPackage\Services\FeaturesService}.
    | A dominio spento il package si comporta come se il dominio non esistesse:
    | i suoi stub di migration non sono considerati dai comandi, i suoi comandi
    | e le sue risorse Nova non vengono registrati.
    | Guida: docs/resources/OptionalDomains.md
    */
    'features' => [
        'trail_registry' => [
            'enabled' => env('WM_TRAIL_REGISTRY_ENABLED', false),
        ],
    ],
```

- [ ] **Step 5: Run test to verify it passes**

```bash
vendor/bin/pest tests/Unit/FeaturesServiceTest.php
```

Atteso: PASS, 5 test.

- [ ] **Step 6: Commit** (chiedere conferma al developer prima di eseguire)

```bash
git add src/Services/FeaturesService.php config/wm-package.php tests/Unit/FeaturesServiceTest.php
git commit -m "feat(oc:8492): servizio e configurazione dei domini opzionali"
```

---

## Task 2: Identificatori qualificati nel trait degli stub

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-identificatori-qualificati)


Il trait impara a leggere le sottocartelle e a trattare `<dominio>/<basename>` come identificatore. Il nome del file pubblicato nel consumer **non** deve contenere il dominio.

**Files:**
- Modify: `src/Commands/Concerns/InteractsWithWmPackageMigrationStubs.php`
- Create (fixture): `tests/fixtures/optional-domain/create_fixture_domain_table.php.stub`
- Test: `tests/Feature/InteractsWithWmPackageMigrationStubsTest.php` (estendere)

**Interfaces:**
- Consumes: `FeaturesService::enabledDomains()` dal Task 1.
- Produces:
  - `stubBaseNames(array $extraDomains = []): array` — root piu' i domini accesi piu' `$extraDomains`, gli opzionali qualificati
  - `findStubPath(string $identifier): ?string` — accetta `create_users_table` e `trail_registry/create_settings_table`
  - `baseNameFromIdentifier(string $identifier): string` — la parte dopo l'ultima `/`
  - `stubsNeedingPublishing(array $extraDomains = []): array`
  - `stubsPendingMigration(array $extraDomains = []): array`

- [ ] **Step 1: Write the failing test**

Aggiungere in coda a `tests/Feature/InteractsWithWmPackageMigrationStubsTest.php`:

```php
    public function test_root_stubs_are_unchanged_when_no_domain_is_enabled(): void
    {
        config(['wm-package.features' => ['fixture_domain' => ['enabled' => false]]]);

        $names = $this->subject->stubBaseNames();

        $this->assertContains('zz_2026_06_26_000001_add_editor_role', $names);
        $this->assertSame(
            $names,
            array_values(array_filter($names, fn (string $n) => ! str_contains($n, '/'))),
            'A dominio spento nessun identificatore qualificato deve comparire.'
        );
    }

    public function test_enabled_domain_adds_only_its_own_stubs(): void
    {
        config(['wm-package.features' => ['fixture_domain' => ['enabled' => false]]]);
        $before = $this->subject->stubBaseNames();

        config(['wm-package.features' => ['fixture_domain' => ['enabled' => true]]]);
        $after = $this->subject->stubBaseNames();

        $this->assertSame(
            ['fixture_domain/create_fixture_domain_table'],
            array_values(array_diff($after, $before)),
        );
    }

    public function test_extra_domains_are_added_even_when_disabled(): void
    {
        config(['wm-package.features' => ['fixture_domain' => ['enabled' => false]]]);

        $names = $this->subject->stubBaseNames(['fixture_domain']);

        $this->assertContains('fixture_domain/create_fixture_domain_table', $names);
    }

    public function test_find_stub_path_resolves_a_qualified_identifier(): void
    {
        $path = $this->subject->findStubPath('fixture_domain/create_fixture_domain_table');

        $this->assertNotNull($path);
        $this->assertStringEndsWith(
            'database/migrations/fixture_domain/create_fixture_domain_table.php.stub',
            $path,
        );
    }

    public function test_base_name_from_identifier_strips_the_domain(): void
    {
        $this->assertSame(
            'create_fixture_domain_table',
            $this->subject->baseNameFromIdentifier('fixture_domain/create_fixture_domain_table'),
        );
        $this->assertSame(
            'create_users_table',
            $this->subject->baseNameFromIdentifier('create_users_table'),
        );
    }
```

Lo stub di fixture vive nel package, in `database/migrations/fixture_domain/create_fixture_domain_table.php.stub`, e non e' dichiarato in `config/wm-package.php`: e' visibile solo ai test che accendono `fixture_domain` via `config([...])`.

Contenuto:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wm_fixture_domain', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wm_fixture_domain');
    }
};
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/pest tests/Feature/InteractsWithWmPackageMigrationStubsTest.php
```

Atteso: FAIL sui cinque nuovi test — `stubBaseNames()` non accetta argomenti e non conosce le sottocartelle, `baseNameFromIdentifier()` non esiste.

- [ ] **Step 3: Write minimal implementation**

In `InteractsWithWmPackageMigrationStubs.php`, sostituire `stubBaseNames()` e `findStubPath()` e aggiungere i due helper:

```php
    /**
     * @param  array<int, string>  $extraDomains
     * @return array<int, string>
     */
    public function stubBaseNames(array $extraDomains = []): array
    {
        $paths = glob($this->stubsDirectory().'/*.stub') ?: [];

        $names = array_map(
            fn (string $path) => $this->baseNameFromStubPath($path),
            $paths,
        );

        foreach ($this->domainsInScope($extraDomains) as $domain) {
            $domainPaths = glob($this->stubsDirectory()."/{$domain}/*.stub") ?: [];

            foreach ($domainPaths as $path) {
                $names[] = $domain.'/'.$this->baseNameFromStubPath($path);
            }
        }

        return array_values($names);
    }

    /**
     * @param  array<int, string>  $extraDomains
     * @return array<int, string>
     */
    protected function domainsInScope(array $extraDomains = []): array
    {
        return array_values(array_unique(array_merge(
            FeaturesService::enabledDomains(),
            $extraDomains,
        )));
    }

    public function findStubPath(string $identifier): ?string
    {
        $path = $this->stubsDirectory()."/{$identifier}.php.stub";

        return file_exists($path) ? $path : null;
    }

    public function baseNameFromIdentifier(string $identifier): string
    {
        return Str::afterLast($identifier, '/');
    }
```

`findStubPath()` funziona senza modifiche alla logica: `stubsDirectory()."/trail_registry/create_settings_table.php.stub"` e' gia' il percorso giusto. Va comunque mantenuto il metodo cosi' com'e' — la modifica al corpo non serve, cambia solo il nome del parametro per chiarezza.

Aggiungere l'import in testa al file:

```php
use Wm\WmPackage\Services\FeaturesService;
```

Poi propagare l'identificatore qualificato nei due punti dove oggi si assume che il basename sia il nome del file:

```php
    public function findPublishedPathForStub(string $identifier): ?string
    {
        $needle = $this->baseNameFromIdentifier($identifier).'.php';
        // ...il resto invariato
    }

    public function publishStubToProject(string $identifier): string
    {
        $stubPath = $this->findStubPath($identifier);

        if ($stubPath === null) {
            throw new \InvalidArgumentException("Nessuno stub trovato per \"{$identifier}\".");
        }

        $timestamp = now()->format('Y_m_d_His');
        $baseName = $this->baseNameFromIdentifier($identifier);
        $destination = database_path("migrations/{$timestamp}_{$baseName}.php");
        // ...il resto invariato
    }
```

**Questo e' il punto piu' delicato del task:** senza `baseNameFromIdentifier()` in `publishStubToProject()`, il file di destinazione conterrebbe una barra nel nome (`..._trail_registry/create_settings_table.php`) e la `copy()` fallirebbe con un errore poco chiaro.

Infine, propagare `$extraDomains` alle due funzioni che enumerano:

```php
    /**
     * @param  array<int, string>  $extraDomains
     * @return array<int, string>
     */
    public function stubsNeedingPublishing(array $extraDomains = []): array
    {
        return array_values(array_filter(
            $this->stubBaseNames($extraDomains),
            fn (string $identifier) => $this->needsPublishing($identifier),
        ));
    }

    /**
     * @param  array<int, string>  $extraDomains
     * @return array<int, string>
     */
    public function stubsPendingMigration(array $extraDomains = []): array
    {
        return array_values(array_filter(
            $this->stubBaseNames($extraDomains),
            fn (string $identifier) => $this->publishedFileMatchesStubContent($identifier)
                && ! $this->hasRunPublishedMigrationForStub($identifier),
        ));
    }
```

`schemaGapsForStub()`, `isAppliedToDatabase()` e `needsPublishing()` non vanno toccati: ricevono l'identificatore e lo passano a `findStubPath()`, che ora lo risolve.

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/pest tests/Feature/InteractsWithWmPackageMigrationStubsTest.php
```

Atteso: PASS, inclusi i test preesistenti.

- [ ] **Step 5: Verificare la non-regressione sul comportamento reale**

```bash
docker exec php-forestas php artisan wm-package:publish-missing-migrations --dry-run
```

Atteso: **lo stesso identico output di prima della modifica** — un solo stub disallineato, `create_users_table`, con le tre colonne mancanti. Se compare qualcosa di nuovo, fermarsi: la modifica ha cambiato il comportamento per la root.

- [ ] **Step 6: Commit** (chiedere conferma al developer)

```bash
git add src/Commands/Concerns/InteractsWithWmPackageMigrationStubs.php database/migrations/fixture_domain tests/Feature/InteractsWithWmPackageMigrationStubsTest.php
git commit -m "feat(oc:8492): identificatori qualificati e sottocartelle di dominio negli stub"
```

---

## Task 3: Opzione `--with` sui due comandi

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-opzione-with)


**Files:**
- Modify: `src/Commands/WmPackagePublishMissingMigrationsCommand.php`
- Modify: `src/Commands/WmPackagePublishMigrationCommand.php:12` (solo il testo dell'argomento)
- Test: `tests/Feature/WmPackagePublishMissingMigrationsCommandTest.php` (estendere)

**Interfaces:**
- Consumes: `FeaturesService::declaredDomains()` (Task 1), `stubsNeedingPublishing(array)` e `stubsPendingMigration(array)` (Task 2).
- Produces: opzione `--with=<dominio>`, ripetibile.

- [ ] **Step 1: Write the failing test**

```php
    public function test_with_option_rejects_an_undeclared_domain(): void
    {
        config(['wm-package.features' => ['trail_registry' => ['enabled' => false]]]);

        $this->artisan('wm-package:publish-missing-migrations', [
            '--with' => ['dominio_inesistente'],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('dominio_inesistente')
            ->assertFailed();
    }

    public function test_with_option_accepts_a_declared_domain_without_stubs(): void
    {
        config(['wm-package.features' => ['trail_registry' => ['enabled' => false]]]);

        $this->artisan('wm-package:publish-missing-migrations', [
            '--with' => ['trail_registry'],
            '--dry-run' => true,
        ])->assertSuccessful();
    }
```

Il secondo test e' il caso reale di forestas fra il merge di questo ticket e quello di oc:8489: dominio dichiarato, cartella degli stub ancora inesistente, il comando non deve rompersi.

    public function test_published_optional_stub_is_not_reported_as_missing(): void
    {
        config(['wm-package.features' => ['fixture_domain' => ['enabled' => true]]]);

        // Pubblica lo stub come farebbe un consumer che ha aderito al dominio,
        // passando dal comando pubblico invece che dal trait.
        $this->artisan('wm-package:publish-migration', [
            'stub' => 'fixture_domain/create_fixture_domain_table',
        ])->assertSuccessful();

        $published = glob(database_path('migrations/*_create_fixture_domain_table.php')) ?: [];
        $this->assertCount(1, $published, 'Lo stub deve essere pubblicato senza il dominio nel nome del file.');

        try {
            $this->artisan('migrate')->assertSuccessful();

            // Con lo stub pubblicato e migrato, il gate non deve piu' nominarlo.
            $this->artisan('wm-package:publish-missing-migrations', ['--dry-run' => true])
                ->doesntExpectOutputToContain('fixture_domain')
                ->assertSuccessful();
        } finally {
            @unlink($published[0]);
        }
    }

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/pest tests/Feature/WmPackagePublishMissingMigrationsCommandTest.php
```

Atteso: FAIL — l'opzione `--with` non esiste, il comando esce con errore di opzione sconosciuta.

- [ ] **Step 3: Write minimal implementation**

In `WmPackagePublishMissingMigrationsCommand.php`:

```php
    protected $signature = 'wm-package:publish-missing-migrations
                            {--dry-run : Elenca stub non allineati; exit code non-zero se ce ne sono (gate CI)}
                            {--with=* : Include anche gli stub di questi domini opzionali, oltre a quelli gia\' accesi in configurazione}';
```

E in testa a `handle()`, prima di tutto il resto:

```php
    public function handle(): int
    {
        $extraDomains = (array) $this->option('with');
        $declared = FeaturesService::declaredDomains();
        $unknown = array_diff($extraDomains, $declared);

        if ($unknown !== []) {
            $this->error(sprintf(
                'Dominio non dichiarato in config wm-package.features: %s',
                implode(', ', $unknown),
            ));
            $this->line('Domini disponibili: '.($declared === [] ? '(nessuno)' : implode(', ', $declared)));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $toPublish = $this->stubsNeedingPublishing($extraDomains);
        $pendingMigrate = $this->stubsPendingMigration($extraDomains);

        // ...il resto di handle() invariato
```

Aggiungere l'import:

```php
use Wm\WmPackage\Services\FeaturesService;
```

In `WmPackagePublishMigrationCommand.php` cambia solo la descrizione dell'argomento, perche' la risoluzione la fa gia' il trait:

```php
    protected $signature = 'wm-package:publish-migration {stub : Identificatore dello stub, senza estensione. Per un dominio opzionale: <dominio>/<nome>}';
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/pest tests/Feature/WmPackagePublishMissingMigrationsCommandTest.php tests/Feature/WmPackagePublishMigrationCommandTest.php
```

Atteso: PASS, inclusi i test preesistenti dei due comandi.

- [ ] **Step 5: Commit** (chiedere conferma al developer)

```bash
git add src/Commands/WmPackagePublishMissingMigrationsCommand.php src/Commands/WmPackagePublishMigrationCommand.php tests/Feature/WmPackagePublishMissingMigrationsCommandTest.php
git commit -m "feat(oc:8492): opzione --with per includere domini opzionali nel gate"
```

---

## Task 4: Registrazione condizionale nel service provider

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-registrazione-condizionale)


A dominio spento non deve esistere nulla di visibile: niente comandi, niente route, niente Nova.

**Files:**
- Modify: `src/WmPackageServiceProvider.php` (metodo `configurePackage()` e `packageRegistered()`)
- Test: `tests/Feature/OptionalDomainRegistrationTest.php`

**Interfaces:**
- Consumes: `FeaturesService::isEnabled()` dal Task 1.
- Produces: `registerDomainCommands()` protected, punto unico dove i domini futuri agganciano i propri comandi.

In questo ticket nessun dominio ha ancora comandi propri — il catasto arriva con oc:8489. Il task crea quindi **il punto di aggancio e la sua prova**, non una registrazione reale: il test verifica che un comando registrato attraverso quel punto compaia solo a dominio acceso.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Wm\WmPackage\Tests\Feature;

use Tests\TestCase;
use Wm\WmPackage\Services\FeaturesService;

class OptionalDomainRegistrationTest extends TestCase
{
    public function test_disabled_domain_registers_no_commands(): void
    {
        config(['wm-package.features' => ['trail_registry' => ['enabled' => false]]]);

        $this->assertFalse(FeaturesService::isEnabled('trail_registry'));
        $this->assertArrayNotHasKey(
            'wm-package:trail-registry-placeholder',
            \Illuminate\Support\Facades\Artisan::all(),
        );
    }

    public function test_existing_commands_are_registered_regardless_of_domains(): void
    {
        config(['wm-package.features' => []]);

        $commands = \Illuminate\Support\Facades\Artisan::all();

        $this->assertArrayHasKey('wm-package:publish-missing-migrations', $commands);
        $this->assertArrayHasKey('wm-package:publish-migration', $commands);
    }
}
```

Il secondo test e' la rete di sicurezza che conta davvero: qualunque cosa si faccia con i domini, i comandi esistenti restano registrati per tutti.

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/pest tests/Feature/OptionalDomainRegistrationTest.php
```

Atteso: il secondo test passa gia' (nessuna regressione da introdurre), il primo passa banalmente. **Se entrambi passano subito, e' corretto:** questo task e' difensivo, il suo valore e' impedire che i task futuri rompano la registrazione esistente. Annotarlo e proseguire.

- [ ] **Step 3: Aggiungere il punto di aggancio**

In `WmPackageServiceProvider::packageRegistered()`, in coda:

```php
        $this->registerDomainCommands();
```

E il metodo, con il commento che spiega perche' esiste vuoto:

```php
    /**
     * Registra i comandi dei domini opzionali accesi.
     *
     * Vuoto finche' nessun dominio porta comandi propri: il primo sara' il
     * catasto (oc:8489). Il punto di aggancio esiste da subito perche' la
     * regola — un dominio spento non registra nulla — va scritta una volta e
     * rispettata da ogni dominio futuro.
     *
     * @see \Wm\WmPackage\Services\FeaturesService
     * @see docs/resources/OptionalDomains.md
     */
    protected function registerDomainCommands(): void
    {
        // Esempio della forma attesa, da seguire quando un dominio avra' comandi:
        //
        // if (FeaturesService::isEnabled('trail_registry')) {
        //     $this->commands([TrailRegistryCommand::class]);
        // }
    }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/pest tests/Feature/OptionalDomainRegistrationTest.php
```

Atteso: PASS, 2 test.

- [ ] **Step 5: Commit** (chiedere conferma al developer)

```bash
git add src/WmPackageServiceProvider.php tests/Feature/OptionalDomainRegistrationTest.php
git commit -m "feat(oc:8492): punto di aggancio per la registrazione condizionale dei domini"
```

---

## Task 5: Documentazione

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-documentazione)


**Files:**
- Create: `docs/resources/OptionalDomains.md`
- Modify: `CLAUDE.md` (sezione "Migration wm-package (stub obbligatori)")

- [ ] **Step 1: Scrivere la guida**

`docs/resources/OptionalDomains.md` deve contenere, in quest'ordine:

1. **Cos'e' un dominio opzionale** — un insieme di stub, comandi e impostazioni che un consumer riceve solo se lo attiva. Oggi: `trail_registry` (catasto sentieri).
2. **Attivare un dominio su un consumer**, come checklist numerata:
   - `php artisan wm-package:publish-missing-migrations --with=<dominio> --dry-run` per vedere cosa comporta, senza toccare nulla
   - `WM_<DOMINIO>_ENABLED=true` in `.env` (e in `.env-deploy` se il repo ha la CI, e in `.env-example` per i colleghi, e nel `.env` del server per gli ambienti remoti)
   - `<env name="WM_<DOMINIO>_ENABLED" value="true"/>` in `phpunit.xml`, altrimenti la suite gira con il dominio spento
   - `php artisan wm-package:publish-migration <dominio>/<stub>` per ogni stub del dominio, poi `migrate`, poi commit dei file pubblicati
   - se il repo ha il gate in CI, aggiungere `--with=<dominio>` allo step esistente
3. **`vendor:publish` non pubblica gli stub dei domini** — la scoperta delle migration di `spatie/laravel-package-tools` non e' ricorsiva. E' cio' che protegge chi non ha aderito, ed e' il motivo per cui chi ha aderito deve usare `publish-migration`.
4. **Aggiungere un dominio nuovo** — sezione in `config/wm-package.php` sotto `features` con `enabled`, sottocartella in `database/migrations/<dominio>/`, comandi agganciati in `WmPackageServiceProvider::registerDomainCommands()`. **Vincolo:** due domini non possono avere stub che creano la stessa tabella, perche' nel consumer le migration finiscono in una cartella piatta.
5. **Spegnere un dominio** — spegnere l'interruttore nasconde la feature ma **non rimuove la tabella**. L'ordine corretto e' bonifica dei dati con i comandi del dominio, poi spegnimento, poi rimozione dello schema: spegnere per primo toglie i comandi, cioe' lo strumento che serve per i due passi successivi.

- [ ] **Step 2: Aggiornare `CLAUDE.md`**

Rinominare la sezione da `## Migration wm-package (stub obbligatori)` a `## Migration wm-package (stub obbligatori e domini opzionali)` e aggiungere, subito sotto la riga "Valido per ogni consumer":

```markdown
Gli stub della root sono obbligatori per ogni consumer. Gli stub in sottocartella
appartengono a un **dominio opzionale** e riguardano solo chi lo ha attivato:
guida completa in `docs/resources/OptionalDomains.md`. `vendor:publish` non li
pubblica — serve `publish-migration <dominio>/<stub>`.
```

Non duplicare la checklist qui: un solo posto, la guida.

- [ ] **Step 3: Commit** (chiedere conferma al developer)

```bash
git add docs/resources/OptionalDomains.md CLAUDE.md
git commit -m "docs(oc:8492): guida ai domini opzionali del package"
```

---

## Ordine e dipendenze

Task 0 → 1 → 2 → 3 → 4 → 5. Il Task 2 dipende dal servizio del Task 1; il Task 3 dipende da entrambi. Il Task 4 e il Task 5 sono indipendenti fra loro ma vanno dopo il 3, perche' la guida descrive il comportamento finale dei comandi.

L'adozione del gate in forestas ha un piano suo: `forestas/docs/features/8492-stub-opzionali-feature-opt-in/plan.md`. Va eseguito **dopo** il merge di questo, perche' usa `--with`.
