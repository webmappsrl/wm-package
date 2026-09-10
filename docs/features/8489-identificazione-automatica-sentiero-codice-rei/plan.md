> Ticket: oc:8489

# Registro dei codici REI — Implementation Plan (wm-package)

> **For agentic workers:** REQUIRED SUB-SKILL: usa `superpowers:subagent-driven-development` (consigliata) o `superpowers:executing-plans` per eseguire questo piano task per task. Gli step usano checkbox (`- [ ]`).

**Goal:** dare al Catasto la capacità di proporre, riservare, confermare e liberare il codice identificativo di un sentiero, dentro il dominio opzionale `trail_registry` del package.

**Architecture:** un registro unico dei codici (una riga per codice, con stato e riferimenti), una tabella di storia in sola aggiunta, il modello dell'istanza che porta la traccia, e un solo service di dominio `TrailRegistryService`. L'unicità è garantita dal database con un indice unico **parziale** sui soli codici attivi; il prefisso si ricava sempre dalla geometria via PostGIS.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL + PostGIS, Nova 5.7.6, Pest.

**Spec:** `docs/features/8489-identificazione-automatica-sentiero-codice-rei/overview.md` (in questo repo) e `forestas/docs/features/8489-identificazione-automatica-sentiero-codice-rei/overview.md` (parte Nova di forestas). Decisioni e dati misurati: `notes.md` accanto a questo file. Leggere entrambe le overview prima di iniziare.

## Global Constraints

- **PHP minimo `>8.1`**: mai `const` dentro un trait (supportate solo da PHP 8.2). Vedi `CLAUDE.md` di questo repo.
- **Nova pinnata a `5.7.6`**: la licenza è scaduta, dalla 5.8.0 le release rispondono HTTP 402. Non aggiornare.
- **Dominio opzionale `trail_registry`**: a interruttore spento il package si comporta esattamente come oggi. Le Resource Nova del dominio **non possono stare in `src/Nova`**, che `Nova::resourcesIn()` scandisce ricorsivamente: vanno in `src/TrailRegistry/Nova/` e si dichiarano in `config('wm-package.features.trail_registry.nova_resources')`.
- **Stub di migration obbligatori**: ogni tabella nuova ha il suo stub in `database/migrations/trail_registry/`, pubblicabile solo con `php artisan wm-package:publish-migration trail_registry/<nome>` (`vendor:publish` non è ricorsivo). Nomi-base mai in collisione con gli stub della root.
- **Formato del codice**: `full_code` del settore (5 caratteri) + numero a 2 cifre + variante. Senza trattini. `0` in `variant` significa «senza variante» e **non compare mai in uscita**.
- **`variant` non è mai `NULL`**: due `NULL` non collidono e l'indice unico non proteggerebbe.
- **Test del package**: girano sul database `wm_package` (vedi `phpunit.xml.dist`), distinto da `forestas`. Va creato una volta con PostGIS abilitato. **Non lanciare la suite senza aver verificato che il database di test sia isolato da quello reale.**
- **Nessun commit automatico**: gli step «Commit» sono istruzioni testuali per il developer. Non eseguire `git add`, `git commit`, `git push`, non creare branch.
- **Nessun `composer format` sull'intero repo**: riformatterebbe file estranei alla feature. Solo sui file toccati.

---

### Task 1: Enum di stato

**Files:**
- Create: `src/TrailRegistry/Enums/TrailCodeStatus.php`
- Create: `src/TrailRegistry/Enums/TrailApplicationStatus.php`
- Test: `tests/Unit/TrailRegistry/TrailRegistryEnumsTest.php`

**Interfaces:**
- Consumes: nulla.
- Produces: `TrailCodeStatus::Reserved|Assigned|Released|Conflict` (backed string: `reserved`, `assigned`, `released`, `conflict`), con `TrailCodeStatus::active(): array` che ritorna `[Reserved, Assigned]`. `TrailApplicationStatus::UnderReview|Rejected|Approved` (`under_review`, `rejected`, `approved`).

- [ ] **Step 1: scrivi il test che fallisce**

```php
<?php

use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;

it('espone i quattro stati del codice con i valori attesi', function () {
    expect(array_map(fn ($c) => $c->value, TrailCodeStatus::cases()))
        ->toBe(['reserved', 'assigned', 'released', 'conflict']);
});

it('considera attivi solo riservato e assegnato', function () {
    expect(TrailCodeStatus::active())
        ->toBe([TrailCodeStatus::Reserved, TrailCodeStatus::Assigned]);
});

it('espone i tre stati dell istruttoria', function () {
    expect(array_map(fn ($c) => $c->value, TrailApplicationStatus::cases()))
        ->toBe(['under_review', 'rejected', 'approved']);
});
```

- [ ] **Step 2: esegui il test e verifica che fallisca**

Run: `vendor/bin/pest tests/Unit/TrailRegistry/TrailRegistryEnumsTest.php`
Expected: FAIL, `Class "Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus" not found`.

- [ ] **Step 3: implementa i due enum**

```php
<?php

namespace Wm\WmPackage\TrailRegistry\Enums;

/**
 * Stato di un codice nel registro.
 *
 * `Conflict` e' il doppione storico: resta in tabella con la sua regione vera
 * e non viola l'indice unico, che e' parziale sui soli stati attivi. Non va
 * confuso con `Released`, che e' riassegnabile.
 */
enum TrailCodeStatus: string
{
    case Reserved = 'reserved';
    case Assigned = 'assigned';
    case Released = 'released';
    case Conflict = 'conflict';

    /**
     * Gli stati che occupano un codice. Devono coincidere con la clausola
     * WHERE dell'indice unico parziale in database/migrations/trail_registry.
     *
     * @return array<int, self>
     */
    public static function active(): array
    {
        return [self::Reserved, self::Assigned];
    }
}
```

```php
<?php

namespace Wm\WmPackage\TrailRegistry\Enums;

/**
 * Stato dell'istruttoria di un'istanza.
 *
 * Tre valori e non cinque: «presentata» non e' raggiungibile (un'istanza che
 * non passa la prevalidazione non viene scritta) e «prevalidata» e' il fatto
 * di avere una riga nel registro, che nasce solo da reserve().
 */
enum TrailApplicationStatus: string
{
    case UnderReview = 'under_review';
    case Rejected = 'rejected';
    case Approved = 'approved';
}
```

- [ ] **Step 4: esegui il test e verifica che passi**

Run: `vendor/bin/pest tests/Unit/TrailRegistry/TrailRegistryEnumsTest.php`
Expected: PASS, 3 test.

- [ ] **Step 5: commit (istruzione per il developer, non eseguire)**

```bash
git add src/TrailRegistry/Enums tests/Unit/TrailRegistry/TrailRegistryEnumsTest.php
git commit -m "feat(oc:8489): enum di stato per codici e istanze del catasto"
```

---

### Task 2: Stub di migration delle tre tabelle

**Files:**
- Create: `database/migrations/trail_registry/zz_2026_09_09_000001_create_trail_applications_table.php.stub`
- Create: `database/migrations/trail_registry/zz_2026_09_09_000002_create_trail_registry_codes_table.php.stub`
- Create: `database/migrations/trail_registry/zz_2026_09_09_000003_create_trail_registry_code_events_table.php.stub`
- Create: `database/migrations/trail_registry/zz_2026_09_09_000004_add_gist_index_to_taxonomy_wheres.php.stub`
- Test: `tests/Feature/TrailRegistry/TrailRegistrySchemaTest.php`

**Interfaces:**
- Consumes: gli enum del Task 1 (solo come documentazione dei valori ammessi; la migration scrive stringhe).
- Produces: tabelle `trail_applications`, `trail_registry_codes`, `trail_registry_code_events`; indice unico parziale `trail_registry_codes_active_unique`; indice GiST `taxonomy_wheres_geometry_gist`.

- [ ] **Step 1: scrivi il test che fallisce**

```php
<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('crea le tre tabelle del dominio', function () {
    expect(Schema::hasTable('trail_applications'))->toBeTrue();
    expect(Schema::hasTable('trail_registry_codes'))->toBeTrue();
    expect(Schema::hasTable('trail_registry_code_events'))->toBeTrue();
});

it('rifiuta un numero fuori dalle due cifre', function () {
    DB::statement("
        INSERT INTO trail_registry_codes
            (region, province, area, sector, number, variant, status, created_at, updated_at)
        VALUES ('Z', 'NU', 'B', '5', 150, '0', 'reserved', now(), now())
    ");
})->throws(\Illuminate\Database\QueryException::class);

it('rifiuta una variante non ammessa dallo schema', function () {
    DB::statement("
        INSERT INTO trail_registry_codes
            (region, province, area, sector, number, variant, status, created_at, updated_at)
        VALUES ('Z', 'NU', 'B', '5', 35, '-', 'reserved', now(), now())
    ");
})->throws(\Illuminate\Database\QueryException::class);

it('rifiuta due codici attivi identici', function () {
    $insert = fn () => DB::statement("
        INSERT INTO trail_registry_codes
            (region, province, area, sector, number, variant, status, created_at, updated_at)
        VALUES ('Z', 'NU', 'B', '5', 35, '0', 'reserved', now(), now())
    ");

    $insert();
    $insert();
})->throws(\Illuminate\Database\QueryException::class);

it('ammette due codici identici se non sono attivi', function () {
    foreach (['released', 'conflict'] as $status) {
        DB::statement("
            INSERT INTO trail_registry_codes
                (region, province, area, sector, number, variant, status, created_at, updated_at)
            VALUES ('Z', 'NU', 'B', '5', 35, '0', '{$status}', now(), now())
        ");
    }

    DB::statement("
        INSERT INTO trail_registry_codes
            (region, province, area, sector, number, variant, status, created_at, updated_at)
        VALUES ('Z', 'NU', 'B', '5', 35, '0', 'conflict', now(), now())
    ");

    expect(DB::table('trail_registry_codes')->count())->toBe(3);
});
```

- [ ] **Step 2: esegui il test e verifica che fallisca**

Run: `vendor/bin/pest tests/Feature/TrailRegistry/TrailRegistrySchemaTest.php`
Expected: FAIL, `Schema::hasTable('trail_applications')` è `false`.

- [ ] **Step 3: scrivi lo stub delle istanze**

`zz_2026_09_09_000001_create_trail_applications_table.php.stub`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trail_applications')) {
            return;
        }

        Schema::create('trail_applications', function (Blueprint $table) {
            $table->id();

            // Chi ha inserito l'istanza nel Catasto: il client API quando
            // arriva da API, l'utente autenticato quando nasce d'ufficio.
            // Non e' il proponente (che non ha un account qui) e non e'
            // l'operatore che istruisce.
            $table->foreignId('user_id')->constrained('users');

            // Come e' arrivata la domanda. Mai il nome dello sportello:
            // questo codice servira' anche Lombardia e Toscana.
            $table->string('source', 16);

            $table->string('status', 32)->index();

            $table->text('name')->nullable();
            $table->jsonb('properties')->nullable();

            $table->timestamps();
        });

        // La geometria PostGIS non passa da Blueprint: nel package viene
        // sempre scritta con DB::statement.
        DB::statement("
            ALTER TABLE trail_applications
            ADD COLUMN geometry geometry(MultiLineString, 4326)
        ");

        DB::statement('
            CREATE INDEX IF NOT EXISTS trail_applications_geometry_gist
            ON trail_applications USING GIST (geometry)
        ');

        DB::statement("
            ALTER TABLE trail_applications
            ADD CONSTRAINT trail_applications_source_check
            CHECK (source IN ('api', 'office'))
        ");

        DB::statement("
            ALTER TABLE trail_applications
            ADD CONSTRAINT trail_applications_status_check
            CHECK (status IN ('under_review', 'rejected', 'approved'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('trail_applications');
    }
};
```

- [ ] **Step 4: scrivi lo stub del registro**

`zz_2026_09_09_000002_create_trail_registry_codes_table.php.stub`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trail_registry_codes')) {
            return;
        }

        Schema::create('trail_registry_codes', function (Blueprint $table) {
            $table->id();

            // Le quattro lettere del full_code restano colonne separate:
            // rendono interrogabile lo spazio dei codici senza confronti su
            // stringa, e congelano il codice emesso anche se il settore
            // cambia in un reimport di OSM2CAI.
            $table->char('region', 1);
            $table->string('province', 2)->index();
            $table->char('area', 1);
            $table->char('sector', 1);

            // Intero, due cifre: e' cio' che rende realizzabile la
            // contiguita' numerica con una riga di SQL.
            $table->unsignedSmallInteger('number');

            // Mai NULL: due NULL non collidono e l'indice unico non
            // proteggerebbe proprio nel caso piu' frequente.
            $table->char('variant', 1)->default('0');

            $table->string('status', 16)->index();

            // Da dove e' stato ricavato il prefisso: permette di accorgersi
            // se quel settore cambia o viene rimosso da un reimport.
            $table->foreignId('taxonomy_where_id')->nullable()
                ->constrained('taxonomy_wheres')->nullOnDelete();

            // Due colonne distinte e non un riferimento polimorfico: si
            // conservano entrambi i legami, e sono chiavi esterne vere.
            $table->foreignId('trail_application_id')->nullable()
                ->constrained('trail_applications');
            $table->foreignId('ec_track_id')->nullable()
                ->constrained('ec_tracks');

            $table->timestamps();
        });

        // Forma delle colonne: il database dichiara cosa e' rappresentabile,
        // il codice PHP cosa e' ammesso oggi. Su variant il margine 1-9 e'
        // deliberato (sottosentieri del modello CAI, oggi fuori scope): se un
        // domani vanno ammessi, si allenta il controllo applicativo senza una
        // migration su tabella popolata.
        DB::statement("
            ALTER TABLE trail_registry_codes
            ADD CONSTRAINT trail_registry_codes_number_check
            CHECK (number BETWEEN 0 AND 99)
        ");

        DB::statement("
            ALTER TABLE trail_registry_codes
            ADD CONSTRAINT trail_registry_codes_variant_check
            CHECK (variant ~ '^[0-9A-Z]$')
        ");

        DB::statement("
            ALTER TABLE trail_registry_codes
            ADD CONSTRAINT trail_registry_codes_region_check
            CHECK (region ~ '^[A-Z]$')
        ");

        // I quattro valori devono coincidere con TrailCodeStatus.
        DB::statement("
            ALTER TABLE trail_registry_codes
            ADD CONSTRAINT trail_registry_codes_status_check
            CHECK (status IN ('reserved', 'assigned', 'released', 'conflict'))
        ");

        // Coerenza fra stato e riferimenti. Ogni prenotazione nasce da
        // un'istanza, anche quella fatta d'ufficio, quindi non esiste il caso
        // «riservato senza istanza».
        DB::statement("
            ALTER TABLE trail_registry_codes
            ADD CONSTRAINT trail_registry_codes_holder_check
            CHECK (
                (status = 'reserved' AND trail_application_id IS NOT NULL AND ec_track_id IS NULL)
                OR (status = 'assigned' AND ec_track_id IS NOT NULL)
                OR (status IN ('released', 'conflict'))
            )
        ");

        // L'indice e' PARZIALE: una riga liberata resta in tabella con i suoi
        // riferimenti, altrimenti un numero liberato non sarebbe piu'
        // riassegnabile a nessuno. La clausola WHERE deve coincidere con
        // TrailCodeStatus::active().
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS trail_registry_codes_active_unique
            ON trail_registry_codes (region, province, area, sector, number, variant)
            WHERE status IN ('reserved', 'assigned')
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS trail_registry_codes_active_unique');
        Schema::dropIfExists('trail_registry_codes');
    }
};
```

⚠️ **Attenzione ai due vincoli di questo stub:** la clausola `WHERE` dell'indice unico parziale e l'elenco del vincolo `..._status_check` devono restare allineati a `TrailCodeStatus::active()` e a `TrailCodeStatus::cases()`. Se un giorno si aggiunge uno stato, va toccata anche la migration — su tabella popolata, che è la ragione per cui vanno scritti giusti adesso.

- [ ] **Step 5: scrivi lo stub della storia**

`zz_2026_09_09_000003_create_trail_registry_code_events_table.php.stub`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trail_registry_code_events')) {
            return;
        }

        // Tabella in sola aggiunta: nessun update, nessuna cancellazione.
        // Serve perche' un numero puo' essere liberato e riassegnato piu'
        // volte, e tre colonne reserved_at/assigned_at/released_at
        // sovrascriverebbero il ciclo precedente.
        Schema::create('trail_registry_code_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('trail_registry_code_id')
                ->constrained('trail_registry_codes')
                ->cascadeOnDelete();

            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);

            // Il perche': «liberato per deaccatastamento» e «liberato perche'
            // l'istanza e' stata respinta» sono due fatti diversi che nello
            // stato corrente si appiattirebbero entrambi in `released`.
            $table->string('reason', 64);

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at');
        });

        DB::statement("
            ALTER TABLE trail_registry_code_events
            ADD CONSTRAINT trail_registry_code_events_to_status_check
            CHECK (to_status IN ('reserved', 'assigned', 'released', 'conflict'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('trail_registry_code_events');
    }
};
```

- [ ] **Step 6: scrivi lo stub dell'indice GiST sui settori**

`zz_2026_09_09_000004_add_gist_index_to_taxonomy_wheres.php.stub`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('taxonomy_wheres')) {
            return;
        }

        // Requisito, non ottimizzazione: durante la pianificazione una query
        // ST_Intersects fra tracce e taxonomy_wheres ha richiesto oltre due
        // minuti sul database di sviluppo. In prevalidazione resolveSector()
        // deve rispondere entro il tempo di una chiamata API.
        DB::statement('
            CREATE INDEX IF NOT EXISTS taxonomy_wheres_geometry_gist
            ON taxonomy_wheres USING GIST (geometry)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS taxonomy_wheres_geometry_gist');
    }
};
```

- [ ] **Step 7: verifica che gli stub siano pubblicabili e non in collisione**

Run: `php artisan wm-package:publish-missing-migrations --with=trail_registry --dry-run`
Expected: elenca i quattro stub come da pubblicare, senza errori di nome-base duplicato.

- [ ] **Step 8: esegui il test e verifica che passi**

Prima verifica l'isolamento del database di test (`phpunit.xml.dist` → `wm_package`), poi:

Run: `vendor/bin/pest tests/Feature/TrailRegistry/TrailRegistrySchemaTest.php`
Expected: PASS, 5 test.

- [ ] **Step 9: commit (istruzione per il developer, non eseguire)**

```bash
git add database/migrations/trail_registry tests/Feature/TrailRegistry/TrailRegistrySchemaTest.php
git commit -m "feat(oc:8489): tabelle del registro codici, istanze e storia degli stati"
```

---

### Task 3: Lettura di un codice esistente (funzione pura)

**Files:**
- Create: `src/TrailRegistry/TrailCodeParser.php`
- Test: `tests/Unit/TrailRegistry/TrailCodeParserTest.php`

**Interfaces:**
- Consumes: nulla — nessun database, nessuno stato.
- Produces: `TrailCodeParser::parseTail(string $raw): ?array` che ritorna `['number' => int, 'variant' => string]` oppure `null` se il testo non è interpretabile; `TrailCodeParser::sectorDigitFrom(string $raw): ?string`.

**Perché è separato dal service:** è una funzione pura e va provata da sola sui 580 codici reali. Il prefisso **non** si legge mai dal testo: si ricava dalla geometria. Dal `ref` si estraggono solo le ultime due cifre e l'eventuale variante — parte che è identica in tutte e cinque le forme.

- [ ] **Step 1: scrivi il test che fallisce**

```php
<?php

use Wm\WmPackage\TrailRegistry\TrailCodeParser;

dataset('codici reali', [
    // forma completa (361 casi sul database)
    ['Z-NU-B-535A', 35, 'A', '5'],
    // variante staccata (37 casi)
    ['Z-NU-G-310-A', 10, 'A', '3'],
    // area e numero (66 casi)
    ['C-402', 2, '0', '4'],
    ['T-513A', 13, 'A', '5'],
    // solo numero (90 casi)
    ['206', 6, '0', '2'],
    ['328A', 28, 'A', '3'],
    // numero tondo: lo zero finale e' cifra, non variante
    ['Z-NU-B-440', 40, '0', '4'],
    // spazi come separatore
    ['D 700', 0, '0', '7'],
]);

it('estrae numero e variante da tutte le forme', function (string $raw, int $number, string $variant) {
    expect(TrailCodeParser::parseTail($raw))
        ->toBe(['number' => $number, 'variant' => $variant]);
})->with('codici reali');

it('estrae la cifra del settore scritta nel codice', function (string $raw, int $n, string $v, string $sector) {
    expect(TrailCodeParser::sectorDigitFrom($raw))->toBe($sector);
})->with('codici reali');

it('rifiuta una coda di quattro cifre, che sarebbe un sottosentiero', function () {
    expect(TrailCodeParser::parseTail('3111'))->toBeNull();
});

it('rifiuta testo non interpretabile', function (string $raw) {
    expect(TrailCodeParser::parseTail($raw))->toBeNull();
})->with(['', 'sentiero del monte', 'Z-NU-B', 'AB']);
```

- [ ] **Step 2: esegui il test e verifica che fallisca**

Run: `vendor/bin/pest tests/Unit/TrailRegistry/TrailCodeParserTest.php`
Expected: FAIL, classe non trovata.

- [ ] **Step 3: implementa il parser**

```php
<?php

namespace Wm\WmPackage\TrailRegistry;

/**
 * Lettura di un codice sentiero gia' scritto.
 *
 * Funzione pura: nessun database, nessuno stato. Dal testo si estraggono solo
 * la coda numerica e l'eventuale variante — il prefisso (regione, provincia,
 * area, settore) si ricava SEMPRE dalla geometria, mai da come il codice e'
 * scritto: i ref storici hanno cinque forme diverse e due numeri uguali
 * possono appartenere a settori diversi (il caso `D 700` / `T-700`).
 *
 * La coda regolare e' di tre cifre: la prima e' il settore, le altre due il
 * numero. Una coda di quattro cifre sarebbe un sottosentiero del modello
 * nazionale CAI (3111, 3112), fuori scope: si rifiuta.
 */
class TrailCodeParser
{
    /**
     * @return array{number: int, variant: string}|null
     */
    public static function parseTail(string $raw): ?array
    {
        $matches = self::match($raw);

        if ($matches === null) {
            return null;
        }

        return [
            'number' => (int) substr($matches['digits'], -2),
            'variant' => $matches['variant'],
        ];
    }

    /**
     * La cifra del settore come e' scritta nel codice. Serve solo alla prova a
     * vuoto, per confrontarla con il settore dedotto dalla geometria e misurare
     * la qualita' del dato: non e' una fonte da cui comporre il codice.
     */
    public static function sectorDigitFrom(string $raw): ?string
    {
        $matches = self::match($raw);

        return $matches === null ? null : substr($matches['digits'], 0, 1);
    }

    /**
     * @return array{digits: string, variant: string}|null
     */
    private static function match(string $raw): ?array
    {
        // Si normalizza il separatore e si guarda solo la fine della stringa:
        // tutte le forme finiscono con tre cifre piu' l'eventuale lettera.
        $normalized = strtoupper(preg_replace('/[\s\-_]+/', '', trim($raw)) ?? '');

        if (! preg_match('/(?<digits>\d{3})(?<variant>[A-Z])?$/', $normalized, $m)) {
            return null;
        }

        // Quattro o piu' cifre consecutive in coda: sottosentiero, si rifiuta.
        if (preg_match('/\d{4}[A-Z]?$/', $normalized)) {
            return null;
        }

        return [
            'digits' => $m['digits'],
            'variant' => $m['variant'] ?? '0',
        ];
    }
}
```

- [ ] **Step 4: esegui il test e verifica che passi**

Run: `vendor/bin/pest tests/Unit/TrailRegistry/TrailCodeParserTest.php`
Expected: PASS. Se un caso del dataset fallisce, correggi l'espressione regolare e non il dataset: i casi vengono dai dati reali.

- [ ] **Step 5: commit (istruzione per il developer, non eseguire)**

```bash
git add src/TrailRegistry/TrailCodeParser.php tests/Unit/TrailRegistry/TrailCodeParserTest.php
git commit -m "feat(oc:8489): lettura della coda numerica dai ref storici"
```

---

### Task 4: Modelli

**Files:**
- Create: `src/TrailRegistry/Models/TrailApplication.php`
- Create: `src/TrailRegistry/Models/TrailRegistryCode.php`
- Create: `src/TrailRegistry/Models/TrailRegistryCodeEvent.php`
- Test: `tests/Feature/TrailRegistry/TrailRegistryModelsTest.php`

**Interfaces:**
- Consumes: enum del Task 1, tabelle del Task 2.
- Produces: `TrailApplication` (estende `MultiLineString`, relazione `codes()`, `activeCode()`, `user()`); `TrailRegistryCode` con accessor `code` e `fullCode`, relazioni `application()`, `ecTrack()`, `taxonomyWhere()`, `events()`, e accessor `denomination`; `TrailRegistryCodeEvent`.

- [ ] **Step 1: scrivi il test che fallisce**

```php
<?php

use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

function makeCode(array $attributes = []): TrailRegistryCode
{
    return TrailRegistryCode::create(array_merge([
        'region' => 'Z',
        'province' => 'NU',
        'area' => 'B',
        'sector' => '5',
        'number' => 35,
        'variant' => '0',
        'status' => TrailCodeStatus::Reserved,
    ], $attributes));
}

it('compone il codice omettendo la variante zero', function () {
    expect(makeCode()->code)->toBe('ZNUB535');
});

it('compone il codice con la variante quando c e', function () {
    expect(makeCode(['variant' => 'A'])->code)->toBe('ZNUB535A');
});

it('riempie il numero con lo zero davanti', function () {
    expect(makeCode(['number' => 7])->code)->toBe('ZNUB507');
});

it('espone il full code del settore', function () {
    expect(makeCode()->fullCode)->toBe('ZNUB5');
});

it('non conserva codice e full code come colonne', function () {
    $columns = Schema::getColumnListing('trail_registry_codes');

    expect($columns)->not->toContain('code')->not->toContain('full_code');
});

it('prende la denominazione dal sentiero quando assegnato, altrimenti dall istanza', function () {
    $user = User::factory()->create();

    $application = TrailApplication::create([
        'user_id' => $user->id,
        'source' => 'api',
        'status' => TrailApplicationStatus::UnderReview,
        'name' => 'Domanda del monte',
    ]);

    $code = makeCode(['trail_application_id' => $application->id]);
    expect($code->denomination)->toBe('Domanda del monte');

    $track = EcTrack::factory()->create(['name' => 'Sentiero del monte']);
    $code->update([
        'status' => TrailCodeStatus::Assigned,
        'ec_track_id' => $track->id,
    ]);

    expect($code->fresh()->denomination)->toBe('Sentiero del monte');
});
```

- [ ] **Step 2: esegui il test e verifica che fallisca**

Run: `vendor/bin/pest tests/Feature/TrailRegistry/TrailRegistryModelsTest.php`
Expected: FAIL, classe non trovata.

- [ ] **Step 3: implementa `TrailApplication`**

```php
<?php

namespace Wm\WmPackage\TrailRegistry\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Wm\WmPackage\Models\Abstracts\MultiLineString;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;

/**
 * La domanda di accatastamento di un sentiero.
 *
 * Eredita da MultiLineString (quindi da GeometryModel) e non da EcTrack: riusa
 * geometria PostGIS ed esportazioni senza portarsi dietro indicizzazione nella
 * ricerca, observer, preferiti e rigenerazione delle mappe vettoriali, che su
 * una domanda non ancora approvata la farebbero comparire nell'app.
 *
 * Non esistono istanze non prevalidate: se il controllo formale non passa,
 * l'API rifiuta e Nova non salva. Ogni riga qui porta quindi gia' il proprio
 * codice riservato.
 */
class TrailApplication extends MultiLineString
{
    protected $fillable = [
        'user_id',
        'source',
        'status',
        'name',
        'geometry',
        'properties',
    ];

    protected $casts = [
        'properties' => 'array',
        'status' => TrailApplicationStatus::class,
    ];

    /**
     * HasPackageFactory risolve la factory da get_called_class(): senza questo
     * override, una sottoclasse in un altro namespace romperebbe ::factory().
     * Vedi CLAUDE.md di questo repo.
     */
    protected static function newFactory(): Factory
    {
        return \Wm\WmPackage\TrailRegistry\Database\Factories\TrailApplicationFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function codes(): HasMany
    {
        return $this->hasMany(TrailRegistryCode::class);
    }

    /**
     * Il codice attivo di questa istanza: quello riservato prima
     * dell'istruttoria, quello assegnato dopo l'approvazione.
     */
    public function activeCode(): HasOne
    {
        return $this->hasOne(TrailRegistryCode::class)
            ->whereIn('status', array_map(
                fn (TrailCodeStatus $s) => $s->value,
                TrailCodeStatus::active(),
            ));
    }
}
```

- [ ] **Step 4: implementa `TrailRegistryCode`**

```php
<?php

namespace Wm\WmPackage\TrailRegistry\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;

/**
 * Una riga per ogni codice del registro.
 *
 * Il codice e' rappresentato dalle sue colonne, non da una stringa: `code` e
 * `fullCode` si calcolano e non si conservano, cosi' non c'e' nulla da tenere
 * allineato e la divergenza e' impossibile per costruzione.
 */
class TrailRegistryCode extends Model
{
    protected $table = 'trail_registry_codes';

    protected $fillable = [
        'region',
        'province',
        'area',
        'sector',
        'number',
        'variant',
        'status',
        'taxonomy_where_id',
        'trail_application_id',
        'ec_track_id',
    ];

    protected $casts = [
        'number' => 'integer',
        'status' => TrailCodeStatus::class,
    ];

    /** Il full_code del settore: l'ambito in cui il numero e' unico. */
    protected function fullCode(): Attribute
    {
        return Attribute::get(
            fn () => $this->region.$this->province.$this->area.$this->sector,
        );
    }

    /**
     * Il codice in uscita. La variante `0` significa «senza variante» e non
     * compare mai: e' implicita.
     */
    protected function code(): Attribute
    {
        return Attribute::get(fn () => sprintf(
            '%s%02d%s',
            $this->fullCode,
            $this->number,
            $this->variant === '0' ? '' : $this->variant,
        ));
    }

    /**
     * Riferimento leggibile: il nome del sentiero se il codice e' assegnato,
     * altrimenti quello dell'istanza. E' l'unico appiglio in un elenco di
     * codici.
     */
    protected function denomination(): Attribute
    {
        return Attribute::get(
            fn () => $this->ecTrack?->name ?? $this->application?->name,
        );
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(TrailApplication::class, 'trail_application_id');
    }

    public function ecTrack(): BelongsTo
    {
        return $this->belongsTo(EcTrack::class, 'ec_track_id');
    }

    public function taxonomyWhere(): BelongsTo
    {
        return $this->belongsTo(TaxonomyWhere::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TrailRegistryCodeEvent::class, 'trail_registry_code_id')
            ->orderBy('created_at');
    }
}
```

- [ ] **Step 5: implementa `TrailRegistryCodeEvent` e la factory dell'istanza**

```php
<?php

namespace Wm\WmPackage\TrailRegistry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;

/**
 * Un passaggio di stato di un codice. Tabella in sola aggiunta: non si
 * aggiorna e non si cancella, e le righe le scrive solo TrailRegistryService.
 */
class TrailRegistryCodeEvent extends Model
{
    protected $table = 'trail_registry_code_events';

    public $timestamps = false;

    protected $fillable = [
        'trail_registry_code_id',
        'from_status',
        'to_status',
        'reason',
        'user_id',
        'created_at',
    ];

    protected $casts = [
        'from_status' => TrailCodeStatus::class,
        'to_status' => TrailCodeStatus::class,
        'created_at' => 'datetime',
    ];

    public function code(): BelongsTo
    {
        return $this->belongsTo(TrailRegistryCode::class, 'trail_registry_code_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

`src/TrailRegistry/Database/Factories/TrailApplicationFactory.php`:

```php
<?php

namespace Wm\WmPackage\TrailRegistry\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

class TrailApplicationFactory extends Factory
{
    protected $model = TrailApplication::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source' => 'api',
            'status' => TrailApplicationStatus::UnderReview,
            'name' => $this->faker->words(3, true),
            // properties come array, non stringa JSON: il cast e' 'array' e
            // una stringa produrrebbe doppia serializzazione (vedi la nota su
            // EcPoiFactory nel CLAUDE.md del package).
            'properties' => [],
        ];
    }
}
```

- [ ] **Step 6: esegui il test e verifica che passi**

Run: `vendor/bin/pest tests/Feature/TrailRegistry/TrailRegistryModelsTest.php`
Expected: PASS, 6 test.

- [ ] **Step 7: commit (istruzione per il developer, non eseguire)**

```bash
git add src/TrailRegistry/Models src/TrailRegistry/Database tests/Feature/TrailRegistry/TrailRegistryModelsTest.php
git commit -m "feat(oc:8489): modelli di istanza, codice e storia degli stati"
```

---

### Task 5: `resolveSector()` — il settore dalla geometria

**Files:**
- Create: `src/TrailRegistry/TrailRegistryService.php`
- Create: `src/TrailRegistry/Exceptions/SectorNotFoundException.php`
- Test: `tests/Feature/TrailRegistry/ResolveSectorTest.php`

**Interfaces:**
- Consumes: indice GiST del Task 2.
- Produces: `TrailRegistryService::resolveSector(string $geometryWkt): TaxonomyWhere` (solleva `SectorNotFoundException` se la geometria non ricade in alcun settore CAI).

- [ ] **Step 1: scrivi il test che fallisce**

```php
<?php

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Exceptions\SectorNotFoundException;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

function makeSector(string $fullCode, string $polygonWkt, string $source = 'osm2cai'): int
{
    $id = DB::table('taxonomy_wheres')->insertGetId([
        'name' => $fullCode,
        'properties' => json_encode(['source' => $source, 'full_code' => $fullCode]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::statement(
        "UPDATE taxonomy_wheres SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?",
        [$polygonWkt, $id]
    );

    return $id;
}

it('trova il settore che contiene la traccia', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $sector = app(TrailRegistryService::class)
        ->resolveSector('MULTILINESTRING((1 1, 2 2))');

    expect($sector->properties['full_code'])->toBe('ZNUB5');
});

it('ignora i poligoni amministrativi che non sono settori CAI', function () {
    // Sul database reale una traccia interseca anche il proprio comune, la
    // provincia e la regione: 420 poligoni estranei alla numerazione.
    makeSector('', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))', 'osmfeatures');
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $sector = app(TrailRegistryService::class)
        ->resolveSector('MULTILINESTRING((1 1, 2 2))');

    expect($sector->properties['full_code'])->toBe('ZNUB5');
});

it('scegli il settore in cui la traccia corre piu a lungo', function () {
    makeSector('ZNUB1', 'POLYGON((0 0, 0 10, 1 10, 1 0, 0 0))');
    makeSector('ZNUB2', 'POLYGON((1 0, 1 10, 10 10, 10 0, 1 0))');

    // La traccia entra appena nel primo settore e prosegue nel secondo.
    $sector = app(TrailRegistryService::class)
        ->resolveSector('MULTILINESTRING((0.9 5, 9 5))');

    expect($sector->properties['full_code'])->toBe('ZNUB2');
});

it('solleva un errore esplicito se nessun settore contiene la traccia', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))');

    app(TrailRegistryService::class)->resolveSector('MULTILINESTRING((50 50, 51 51))');
})->throws(SectorNotFoundException::class);
```

- [ ] **Step 2: esegui il test e verifica che fallisca**

Run: `vendor/bin/pest tests/Feature/TrailRegistry/ResolveSectorTest.php`
Expected: FAIL, classe non trovata.

- [ ] **Step 3: implementa l'eccezione**

```php
<?php

namespace Wm\WmPackage\TrailRegistry\Exceptions;

use RuntimeException;

/**
 * La geometria non ricade in alcun settore CAI, quindi non e' possibile
 * comporre un codice. Non e' un errore da nascondere: e' la risposta «non
 * posso proporre un numero», e chi chiama deve poterla riferire al
 * richiedente.
 */
class SectorNotFoundException extends RuntimeException
{
    public static function forGeometry(): self
    {
        return new self('La traccia non ricade in alcun settore del catasto.');
    }
}
```

- [ ] **Step 4: implementa `resolveSector()`**

```php
<?php

namespace Wm\WmPackage\TrailRegistry;

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\TrailRegistry\Exceptions\SectorNotFoundException;

/**
 * Unico service di dominio del catasto.
 */
class TrailRegistryService
{
    /**
     * Il settore CAI in cui ricade una geometria.
     *
     * Il filtro su `source = 'osm2cai'` e sulla presenza di `full_code` non e'
     * una rifinitura: senza, la traccia interseca anche comune, provincia e
     * regione (420 poligoni amministrativi estranei alla numerazione). Le due
     * condizioni coincidono sui dati (63 record, 63 con full_code) ma vanno
     * messe entrambe — la prima dice l'origine, la seconda impedisce che un
     * futuro record osm2cai incompleto entri silenziosamente.
     *
     * Tre esiti: un solo settore (il caso normale), piu' settori perche' un
     * sentiero lungo li attraversa — e allora vince quello in cui corre piu' a
     * lungo, calcolato nella stessa query — oppure nessuno, che solleva.
     *
     * NON usare GeometryModel::getOrderedTaxonomyWheres(): legge una copia
     * gia' calcolata in properties['taxonomy_where'] popolata da OSMFeatures
     * (un'altra sorgente), restituisce solo nomi senza full_code, ordina per
     * livello amministrativo, e su una traccia mai elaborata torna vuoto in
     * silenzio.
     */
    public function resolveSector(string $geometryWkt): TaxonomyWhere
    {
        $row = DB::selectOne(
            <<<'SQL'
            SELECT tw.id
            FROM taxonomy_wheres tw
            WHERE tw.properties->>'source' = 'osm2cai'
              AND COALESCE(tw.properties->>'full_code', '') <> ''
              AND ST_Intersects(tw.geometry, ST_GeomFromText(?, 4326))
            ORDER BY ST_Length(
                ST_Intersection(tw.geometry, ST_GeomFromText(?, 4326))::geography
            ) DESC
            LIMIT 1
            SQL,
            [$geometryWkt, $geometryWkt],
        );

        if ($row === null) {
            throw SectorNotFoundException::forGeometry();
        }

        return TaxonomyWhere::findOrFail($row->id);
    }
}
```

- [ ] **Step 5: esegui il test e verifica che passi**

Run: `vendor/bin/pest tests/Feature/TrailRegistry/ResolveSectorTest.php`
Expected: PASS, 4 test.

- [ ] **Step 6: commit (istruzione per il developer, non eseguire)**

```bash
git add src/TrailRegistry/TrailRegistryService.php src/TrailRegistry/Exceptions tests/Feature/TrailRegistry/ResolveSectorTest.php
git commit -m "feat(oc:8489): risoluzione del settore CAI dalla geometria"
```

---

### Task 6: `propose()` e `availableNumbers()`

**Files:**
- Modify: `src/TrailRegistry/TrailRegistryService.php`
- Create: `src/TrailRegistry/Exceptions/SectorExhaustedException.php`
- Test: `tests/Feature/TrailRegistry/ProposeTest.php`

**Interfaces:**
- Consumes: `resolveSector()` del Task 5, modelli del Task 4.
- Produces: `TrailRegistryService::propose(string $geometryWkt): array` che ritorna `['taxonomy_where_id' => int, 'region' => string, 'province' => string, 'area' => string, 'sector' => string, 'number' => int, 'variant' => string]`; `TrailRegistryService::availableNumbers(string $fullCode): array` (lista di interi liberi); `SectorExhaustedException`.

**`propose()` non scrive.** La preistruttoria di oc:8490 deve poter mostrare un numero prima di riservarlo: se proporre scrivesse, ogni traccia scartata lascerebbe dietro un numero bloccato per sempre.

- [ ] **Step 1: scrivi il test che fallisce**

```php
<?php

use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Exceptions\SectorExhaustedException;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

it('propone il primo numero libero del settore', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $proposal = app(TrailRegistryService::class)
        ->propose('MULTILINESTRING((1 1, 2 2))');

    expect($proposal['number'])->toBe(0)
        ->and($proposal['variant'])->toBe('0')
        ->and($proposal['region'])->toBe('Z')
        ->and($proposal['province'])->toBe('NU')
        ->and($proposal['area'])->toBe('B')
        ->and($proposal['sector'])->toBe('5');
});

it('salta i numeri occupati, riservati o assegnati', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    foreach ([[0, TrailCodeStatus::Assigned], [1, TrailCodeStatus::Reserved]] as [$number, $status]) {
        makeCode(['number' => $number, 'status' => $status, 'ec_track_id' => null, 'trail_application_id' => null]);
    }

    expect(app(TrailRegistryService::class)->propose('MULTILINESTRING((1 1, 2 2))')['number'])
        ->toBe(2);
});

it('considera libero un numero liberato', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    makeCode(['number' => 0, 'status' => TrailCodeStatus::Released]);

    expect(app(TrailRegistryService::class)->propose('MULTILINESTRING((1 1, 2 2))')['number'])
        ->toBe(0);
});

it('non confonde i settori: lo spazio dei numeri e per settore', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    makeCode(['sector' => '4', 'number' => 0, 'status' => TrailCodeStatus::Assigned]);

    expect(app(TrailRegistryService::class)->propose('MULTILINESTRING((1 1, 2 2))')['number'])
        ->toBe(0);
});

it('elenca i numeri liberi di un settore', function () {
    makeCode(['number' => 0, 'status' => TrailCodeStatus::Assigned]);
    makeCode(['number' => 1, 'status' => TrailCodeStatus::Reserved]);

    $free = app(TrailRegistryService::class)->availableNumbers('ZNUB5');

    expect($free)->not->toContain(0)->not->toContain(1)
        ->and($free[0])->toBe(2)
        ->and(count($free))->toBe(98);
});

it('solleva un errore esplicito quando il settore e esaurito', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    for ($n = 0; $n <= 99; $n++) {
        foreach (array_merge(['0'], range('A', 'Z')) as $variant) {
            makeCode(['number' => $n, 'variant' => $variant, 'status' => TrailCodeStatus::Assigned]);
        }
    }

    app(TrailRegistryService::class)->propose('MULTILINESTRING((1 1, 2 2))');
})->throws(SectorExhaustedException::class);
```

⚠️ L'ultimo test inserisce 2700 righe: se risulta troppo lento, riduci lo spazio con una costante di configurazione già prevista dal Task 11 (`features.trail_registry.code_format`) invece di indebolire l'asserzione.

- [ ] **Step 2: esegui il test e verifica che fallisca**

Run: `vendor/bin/pest tests/Feature/TrailRegistry/ProposeTest.php`
Expected: FAIL, metodo `propose` non definito.

- [ ] **Step 3: implementa l'eccezione di esaurimento**

```php
<?php

namespace Wm\WmPackage\TrailRegistry\Exceptions;

use RuntimeException;

/**
 * Nel settore non ci sono piu' posizioni libere nello spazio numero x
 * variante.
 *
 * Solleva invece di restituire vuoto: un null senza spiegazione si propaga
 * fino al consumer come risposta non gestita, mentre «settore ZNUB5 esaurito»
 * e' un'informazione che il gestore del catasto deve avere comunque.
 */
class SectorExhaustedException extends RuntimeException
{
    public static function forFullCode(string $fullCode): self
    {
        return new self("Settore {$fullCode}: nessun numero libero.");
    }
}
```

- [ ] **Step 4: implementa `propose()` e `availableNumbers()`**

Aggiungi a `TrailRegistryService`:

```php
    /**
     * Il primo numero libero del settore in cui ricade la geometria.
     *
     * NON scrive: la preistruttoria deve poter mostrare un numero prima di
     * riservarlo, altrimenti ogni traccia scartata lascerebbe dietro un numero
     * bloccato per sempre.
     *
     * Occupato significa riservato O assegnato, in un unico registro. Una riga
     * liberata non occupa: il numero e' riassegnabile.
     *
     * Si prova per prima la posizione senza variante e si passa alle lettere
     * solo quando quella e' occupata — ma e' l'ordine di ricerca, non il
     * significato di `0`: ZNUB535 e ZNUB535A sono due sentieri indipendenti.
     *
     * @return array{taxonomy_where_id: int, region: string, province: string, area: string, sector: string, number: int, variant: string}
     */
    public function propose(string $geometryWkt): array
    {
        $sector = $this->resolveSector($geometryWkt);
        $fullCode = $sector->properties['full_code'];

        $taken = TrailRegistryCode::query()
            ->where('region', substr($fullCode, 0, 1))
            ->where('province', substr($fullCode, 1, 2))
            ->where('area', substr($fullCode, 3, 1))
            ->where('sector', substr($fullCode, 4, 1))
            ->whereIn('status', $this->activeStatusValues())
            ->get(['number', 'variant'])
            ->map(fn ($row) => $row->number.':'.$row->variant)
            ->all();

        foreach (range(0, 99) as $number) {
            foreach ($this->variantSearchOrder() as $variant) {
                if (! in_array($number.':'.$variant, $taken, true)) {
                    return [
                        'taxonomy_where_id' => $sector->id,
                        'region' => substr($fullCode, 0, 1),
                        'province' => substr($fullCode, 1, 2),
                        'area' => substr($fullCode, 3, 1),
                        'sector' => substr($fullCode, 4, 1),
                        'number' => $number,
                        'variant' => $variant,
                    ];
                }
            }
        }

        throw SectorExhaustedException::forFullCode($fullCode);
    }

    /**
     * I numeri liberi di un settore, senza variante: e' l'elenco che serve al
     * gestore per sostituire a mano il numero proposto.
     *
     * @return array<int, int>
     */
    public function availableNumbers(string $fullCode): array
    {
        $taken = TrailRegistryCode::query()
            ->where('region', substr($fullCode, 0, 1))
            ->where('province', substr($fullCode, 1, 2))
            ->where('area', substr($fullCode, 3, 1))
            ->where('sector', substr($fullCode, 4, 1))
            ->where('variant', '0')
            ->whereIn('status', $this->activeStatusValues())
            ->pluck('number')
            ->all();

        return array_values(array_diff(range(0, 99), $taken));
    }

    /**
     * L'ordine di ricerca nello spazio della variante: prima la posizione
     * senza variante, poi le lettere.
     *
     * Le cifre da 1 a 9 NON sono in questo elenco: sono i sottosentieri del
     * modello nazionale CAI, fuori scope. Il database le ammette (il vincolo
     * e' `[0-9A-Z]`) perche' se un domani vanno accolte si allenta qui senza
     * una migration su tabella popolata.
     *
     * @return array<int, string>
     */
    protected function variantSearchOrder(): array
    {
        return array_merge(['0'], range('A', 'Z'));
    }

    /**
     * @return array<int, string>
     */
    protected function activeStatusValues(): array
    {
        return array_map(
            fn (TrailCodeStatus $status) => $status->value,
            TrailCodeStatus::active(),
        );
    }
```

Aggiungi gli `use` necessari: `Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus`, `Wm\WmPackage\TrailRegistry\Exceptions\SectorExhaustedException`, `Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode`.

- [ ] **Step 5: esegui il test e verifica che passi**

Run: `vendor/bin/pest tests/Feature/TrailRegistry/ProposeTest.php`
Expected: PASS, 6 test.

- [ ] **Step 6: commit (istruzione per il developer, non eseguire)**

```bash
git add src/TrailRegistry tests/Feature/TrailRegistry/ProposeTest.php
git commit -m "feat(oc:8489): proposta del primo numero libero e elenco dei disponibili"
```

---

### Task 7: `reserve()`, `confirm()`, `release()` e la storia

**Files:**
- Modify: `src/TrailRegistry/TrailRegistryService.php`
- Test: `tests/Feature/TrailRegistry/ReserveConfirmReleaseTest.php`
- Test: `tests/Feature/TrailRegistry/ConcurrentReservationTest.php`

**Interfaces:**
- Consumes: `propose()` del Task 6, modelli del Task 4.
- Produces: `reserve(TrailApplication $application, ?array $proposal = null): TrailRegistryCode`; `confirm(TrailRegistryCode $code, EcTrack $track, ?int $userId = null): TrailRegistryCode`; `release(TrailRegistryCode $code, string $reason, ?int $userId = null): TrailRegistryCode`; `replaceNumber(TrailRegistryCode $code, int $number, ?int $userId = null): TrailRegistryCode`.

- [ ] **Step 1: scrivi il test del ciclo di vita**

```php
<?php

use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $this->application = TrailApplication::factory()->create();

    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING((1 1, 2 2))', $this->application->id]
    );

    $this->application->refresh();
    $this->service = app(TrailRegistryService::class);
});

it('riserva un numero e registra il passaggio nella storia', function () {
    $code = $this->service->reserve($this->application);

    expect($code->status)->toBe(TrailCodeStatus::Reserved)
        ->and($code->code)->toBe('ZNUB500')
        ->and($code->trail_application_id)->toBe($this->application->id)
        ->and($code->ec_track_id)->toBeNull();

    expect($code->events)->toHaveCount(1);
    expect($code->events->first()->to_status)->toBe(TrailCodeStatus::Reserved);
    expect($code->events->first()->reason)->toBe('reserved_on_application');
});

it('conferma il codice sulla stessa riga, senza cambiare il numero', function () {
    $code = $this->service->reserve($this->application);
    $track = EcTrack::factory()->create();

    $confirmed = $this->service->confirm($code, $track);

    expect($confirmed->id)->toBe($code->id)
        ->and($confirmed->code)->toBe('ZNUB500')
        ->and($confirmed->status)->toBe(TrailCodeStatus::Assigned)
        ->and($confirmed->ec_track_id)->toBe($track->id)
        // il legame con l'istanza resta: si sa sempre da quale domanda il
        // codice e' nato
        ->and($confirmed->trail_application_id)->toBe($this->application->id);

    expect($confirmed->events)->toHaveCount(2);
});

it('libera il codice con la causa scritta nella storia', function () {
    $code = $this->service->reserve($this->application);

    $released = $this->service->release($code, 'application_rejected');

    expect($released->status)->toBe(TrailCodeStatus::Released);
    expect($released->events->last()->reason)->toBe('application_rejected');
    expect($released->events->last()->from_status)->toBe(TrailCodeStatus::Reserved);
});

it('rende un numero liberato di nuovo proponibile', function () {
    $code = $this->service->reserve($this->application);
    $this->service->release($code, 'application_rejected');

    $other = TrailApplication::factory()->create();
    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING((1 1, 2 2))', $other->id]
    );

    expect($this->service->reserve($other->refresh())->code)->toBe('ZNUB500');
});

it('sostituisce il numero in una sola transazione', function () {
    $code = $this->service->reserve($this->application);

    $replaced = $this->service->replaceNumber($code, 42);

    expect($replaced->code)->toBe('ZNUB542')
        ->and($replaced->status)->toBe(TrailCodeStatus::Reserved);

    // il vecchio codice risulta liberato e riproponibile
    expect(TrailRegistryCode::where('number', 0)->first()->status)
        ->toBe(TrailCodeStatus::Released);
});

it('rifiuta la sostituzione con un numero occupato', function () {
    $code = $this->service->reserve($this->application);
    makeCode(['number' => 42, 'status' => TrailCodeStatus::Assigned]);

    $this->service->replaceNumber($code, 42);
})->throws(\Illuminate\Database\QueryException::class);
```

- [ ] **Step 2: scrivi il test della concorrenza**

```php
<?php

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

it('non assegna lo stesso numero a due richieste, e la seconda ne ottiene uno diverso', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $service = app(TrailRegistryService::class);

    // Si simula la finestra fra il «guarda se esiste» e lo «scrivi»: la prima
    // riga viene inserita di soppiatto dopo che propose() ha giа' deciso, come
    // farebbe un'altra richiesta arrivata nello stesso istante.
    $applications = TrailApplication::factory()->count(2)->create();

    foreach ($applications as $application) {
        DB::statement(
            'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
            ['MULTILINESTRING((1 1, 2 2))', $application->id]
        );
    }

    $first = $service->reserve($applications[0]->refresh());
    $second = $service->reserve($applications[1]->refresh());

    expect($first->code)->not->toBe($second->code);
    expect(TrailRegistryCode::count())->toBe(2);
});

it('riprova quando perde la corsa sul vincolo, invece di propagare l errore', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $application = TrailApplication::factory()->create();
    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING((1 1, 2 2))', $application->id]
    );

    $service = app(TrailRegistryService::class);

    // Un listener che, appena prima della scrittura, occupa il numero che
    // propose() ha appena proposto: e' esattamente il perdente della corsa.
    DB::listen(function ($query) use ($application) {
        static $done = false;

        if ($done || ! str_contains($query->sql, 'insert into "trail_registry_codes"')) {
            return;
        }

        $done = true;

        DB::statement("
            INSERT INTO trail_registry_codes
                (region, province, area, sector, number, variant, status, trail_application_id, created_at, updated_at)
            VALUES ('Z', 'NU', 'B', '5', 0, '0', 'reserved', {$application->id}, now(), now())
        ");
    });

    $code = $service->reserve($application->refresh());

    // Non ha ricevuto un errore: ha preso il numero successivo libero.
    expect($code->number)->toBe(1);
});
```

⚠️ Se il listener risulta troppo fragile nell'ambiente reale, sostituiscilo con un doppio inserimento esplicito che verifichi il solo ritentativo (`reserveWithRetry` chiamato dopo aver occupato il numero proposto), **senza** rinunciare all'asserzione «non riceve un errore».

- [ ] **Step 3: esegui i test e verifica che falliscano**

Run: `vendor/bin/pest tests/Feature/TrailRegistry/ReserveConfirmReleaseTest.php tests/Feature/TrailRegistry/ConcurrentReservationTest.php`
Expected: FAIL, metodo `reserve` non definito.

- [ ] **Step 4: implementa i quattro metodi**

Aggiungi a `TrailRegistryService`:

```php
    /**
     * Blocca un numero per un'istanza. E' l'unico punto in cui nasce una riga
     * nel registro: l'esistenza di quella riga E' la prova che la
     * prevalidazione e' passata.
     *
     * Se qualcuno vince la corsa sullo stesso numero, si riprova con il
     * successivo libero: il vincolo del database garantisce che due richieste
     * non ottengano lo stesso numero, non che la seconda ne ottenga uno.
     */
    public function reserve(TrailApplication $application, ?array $proposal = null): TrailRegistryCode
    {
        $geometry = $this->wktOf($application);

        for ($attempt = 1; $attempt <= self::RESERVE_ATTEMPTS; $attempt++) {
            $candidate = $proposal ?? $this->propose($geometry);
            $proposal = null;

            try {
                return DB::transaction(function () use ($candidate, $application) {
                    $code = TrailRegistryCode::create([
                        'region' => $candidate['region'],
                        'province' => $candidate['province'],
                        'area' => $candidate['area'],
                        'sector' => $candidate['sector'],
                        'number' => $candidate['number'],
                        'variant' => $candidate['variant'],
                        'status' => TrailCodeStatus::Reserved,
                        'taxonomy_where_id' => $candidate['taxonomy_where_id'],
                        'trail_application_id' => $application->id,
                    ]);

                    $this->recordEvent($code, null, TrailCodeStatus::Reserved, 'reserved_on_application', $application->user_id);

                    return $code;
                });
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e) || $attempt === self::RESERVE_ATTEMPTS) {
                    throw $e;
                }
                // ritenta: il numero e' stato preso fra la proposta e la scrittura
            }
        }

        throw SectorExhaustedException::forFullCode('sconosciuto');
    }

    /**
     * Il codice riservato diventa definitivo e si lega al sentiero appena
     * creato. Non viene riemesso: cambia lo stato e cambia il detentore, il
     * numero resta identico — e' gia' stato comunicato al richiedente.
     */
    public function confirm(TrailRegistryCode $code, EcTrack $track, ?int $userId = null): TrailRegistryCode
    {
        return DB::transaction(function () use ($code, $track, $userId) {
            $from = $code->status;

            $code->update([
                'status' => TrailCodeStatus::Assigned,
                'ec_track_id' => $track->id,
            ]);

            $this->recordEvent($code, $from, TrailCodeStatus::Assigned, 'application_approved', $userId);

            return $code->refresh();
        });
    }

    /**
     * Il numero torna disponibile. Non e' invocabile «per se'»: e' sempre la
     * conseguenza di un atto, e la causa va scritta nella storia — «liberato
     * per deaccatastamento» e «liberato perche' l'istanza e' stata respinta»
     * sono due fatti diversi che nello stato corrente si appiattirebbero
     * entrambi in `released`.
     */
    public function release(TrailRegistryCode $code, string $reason, ?int $userId = null): TrailRegistryCode
    {
        return DB::transaction(function () use ($code, $reason, $userId) {
            $from = $code->status;

            $code->update(['status' => TrailCodeStatus::Released]);

            $this->recordEvent($code, $from, TrailCodeStatus::Released, $reason, $userId);

            return $code->refresh();
        });
    }

    /**
     * Sostituzione a mano del numero proposto, scegliendo fra i liberi.
     *
     * Libera il vecchio e riserva il nuovo in UNA transazione: come due passi
     * separati, nel mezzo il numero appena liberato potrebbe essere preso da
     * un'altra richiesta.
     */
    public function replaceNumber(TrailRegistryCode $code, int $number, ?int $userId = null): TrailRegistryCode
    {
        return DB::transaction(function () use ($code, $number, $userId) {
            $application = $code->application;

            $this->release($code, 'number_replaced', $userId);

            return $this->reserve($application, [
                'taxonomy_where_id' => $code->taxonomy_where_id,
                'region' => $code->region,
                'province' => $code->province,
                'area' => $code->area,
                'sector' => $code->sector,
                'number' => $number,
                'variant' => '0',
            ]);
        });
    }

    protected function recordEvent(
        TrailRegistryCode $code,
        ?TrailCodeStatus $from,
        TrailCodeStatus $to,
        string $reason,
        ?int $userId,
    ): void {
        TrailRegistryCodeEvent::create([
            'trail_registry_code_id' => $code->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'user_id' => $userId,
            'created_at' => now(),
        ]);
    }

    protected function wktOf(TrailApplication $application): string
    {
        $row = DB::selectOne(
            'SELECT ST_AsText(geometry) AS wkt FROM trail_applications WHERE id = ?',
            [$application->id],
        );

        return $row->wkt;
    }

    protected function isUniqueViolation(QueryException $e): bool
    {
        // 23505 = unique_violation in PostgreSQL
        return ($e->errorInfo[0] ?? null) === '23505';
    }
```

E in cima alla classe:

```php
    /**
     * Tentativi di riserva prima di arrendersi. Serve a non girare a vuoto se
     * il settore e' davvero esaurito, caso che ricade in
     * SectorExhaustedException.
     */
    protected const RESERVE_ATTEMPTS = 5;
```

- [ ] **Step 5: esegui i test e verifica che passino**

Run: `vendor/bin/pest tests/Feature/TrailRegistry/`
Expected: PASS, tutti i test del dominio.

- [ ] **Step 6: commit (istruzione per il developer, non eseguire)**

```bash
git add src/TrailRegistry tests/Feature/TrailRegistry
git commit -m "feat(oc:8489): riserva, conferma, liberazione e sostituzione del numero"
```

---

### Task 8: `validate()` — correttezza formale di un codice

**Files:**
- Modify: `src/TrailRegistry/TrailRegistryService.php`
- Test: `tests/Unit/TrailRegistry/ValidateCodeTest.php`

**Interfaces:**
- Consumes: nulla oltre alla configurazione del formato (Task 11 la rende sostituibile; qui basta il default).
- Produces: `TrailRegistryService::validate(string $code): bool`.

- [ ] **Step 1: scrivi il test che fallisce**

```php
<?php

use Wm\WmPackage\TrailRegistry\TrailRegistryService;

it('accetta un codice ben formato', function (string $code) {
    expect(app(TrailRegistryService::class)->validate($code))->toBeTrue();
})->with(['ZNUB535', 'ZNUB535A', 'ZNUB500', 'ZCAC412B']);

it('rifiuta un codice mal formato', function (string $code) {
    expect(app(TrailRegistryService::class)->validate($code))->toBeFalse();
})->with([
    'Z-NU-B-535A',  // con trattini: il REI standard non li ha
    'znub535',      // minuscolo
    'ZNUB5',        // manca il numero
    'ZNUB5351',     // variante numerica: sottosentiero, fuori scope
    'ZNUB5350',     // quattro cifre in coda
    'ZNU B535',     // spazio
    '',
]);
```

- [ ] **Step 2: esegui il test e verifica che fallisca**

Run: `vendor/bin/pest tests/Unit/TrailRegistry/ValidateCodeTest.php`
Expected: FAIL, metodo `validate` non definito.

- [ ] **Step 3: implementa `validate()`**

```php
    /**
     * Un codice scritto e' formalmente corretto?
     *
     * Sette caratteri senza variante, otto con: regione (1 lettera), provincia
     * (2), area (1), settore (1 cifra), numero (2 cifre), variante opzionale
     * (1 lettera). Senza trattini: il codice REI standard non li ha.
     *
     * La variante numerica e' rifiutata: appartiene al modello dei
     * sottosentieri, fuori scope.
     */
    public function validate(string $code): bool
    {
        return (bool) preg_match('/^[A-Z][A-Z]{2}[A-Z]\d\d\d[A-Z]?$/', $code)
            && ! preg_match('/\d{4}/', $code);
    }
```

- [ ] **Step 4: esegui il test e verifica che passi**

Run: `vendor/bin/pest tests/Unit/TrailRegistry/ValidateCodeTest.php`
Expected: PASS, 11 casi.

- [ ] **Step 5: commit (istruzione per il developer, non eseguire)**

```bash
git add src/TrailRegistry/TrailRegistryService.php tests/Unit/TrailRegistry/ValidateCodeTest.php
git commit -m "feat(oc:8489): verifica formale di un codice esistente"
```

---

### Task 9: Comando di normalizzazione, solo prova a vuoto

**Files:**
- Create: `src/TrailRegistry/Commands/TrailRegistryNormalizeCommand.php`
- Test: `tests/Feature/TrailRegistry/TrailRegistryNormalizeCommandTest.php`

**Interfaces:**
- Consumes: `TrailCodeParser` (Task 3), `resolveSector()` (Task 5), modelli (Task 4).
- Produces: comando `wm-package:trail-registry-normalize`, che in questo ciclo **accetta solo `--dry-run`** e rifiuta di scrivere.

**Cosa deve misurare il rapporto** (sono le tre domande rimandate a questa fase, vedi `notes.md`): quante geometrie cadono fuori da ogni settore; per quante il settore dedotto dalla geometria diverge dalla cifra scritta nel codice; quanti doppioni restano **dopo** aver ricavato il settore — il conteggio testuale di sette è provvisorio e sarà più basso.

- [ ] **Step 1: scrivi il test che fallisce**

```php
<?php

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;

it('non scrive nulla in prova a vuoto', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $track = EcTrack::factory()->create(['properties' => ['ref' => 'Z-NU-B-535']]);
    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING((1 1, 2 2))', $track->id]
    );

    $this->artisan('wm-package:trail-registry-normalize --dry-run')
        ->assertExitCode(0);

    expect(DB::table('trail_registry_codes')->count())->toBe(0);
});

it('rifiuta di girare senza prova a vuoto in questo ciclo', function () {
    $this->artisan('wm-package:trail-registry-normalize')
        ->expectsOutputToContain('--dry-run')
        ->assertExitCode(1);

    expect(DB::table('trail_registry_codes')->count())->toBe(0);
});

it('riporta le tracce la cui geometria non ricade in alcun settore', function () {
    $track = EcTrack::factory()->create(['properties' => ['ref' => '206']]);
    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING((50 50, 51 51))', $track->id]
    );

    $this->artisan('wm-package:trail-registry-normalize --dry-run')
        ->expectsOutputToContain('fuori da ogni settore: 1');
});

it('riporta le divergenze fra settore dedotto e settore scritto nel codice', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    // il codice dice settore 3, la geometria cade nel settore 5
    $track = EcTrack::factory()->create(['properties' => ['ref' => '332A']]);
    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING((1 1, 2 2))', $track->id]
    );

    $this->artisan('wm-package:trail-registry-normalize --dry-run')
        ->expectsOutputToContain('settore discordante: 1');
});

it('conta i doppioni dopo aver ricavato il settore, non prima', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    makeSector('ZNUG7', 'POLYGON((20 20, 20 30, 30 30, 30 20, 20 20))');

    // Stesso testo `700` ma settori diversi: NON e' un doppione.
    foreach ([['D 700', 'MULTILINESTRING((1 1, 2 2))'], ['T-700', 'MULTILINESTRING((21 21, 22 22))']] as [$ref, $wkt]) {
        $track = EcTrack::factory()->create(['properties' => ['ref' => $ref]]);
        DB::statement('UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?', [$wkt, $track->id]);
    }

    $this->artisan('wm-package:trail-registry-normalize --dry-run')
        ->expectsOutputToContain('doppioni reali: 0');
});
```

- [ ] **Step 2: esegui il test e verifica che fallisca**

Run: `vendor/bin/pest tests/Feature/TrailRegistry/TrailRegistryNormalizeCommandTest.php`
Expected: FAIL, comando non registrato.

- [ ] **Step 3: implementa il comando**

```php
<?php

namespace Wm\WmPackage\TrailRegistry\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Exceptions\SectorNotFoundException;
use Wm\WmPackage\TrailRegistry\TrailCodeParser;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * Legge i codici storici dei sentieri e riporta cosa entrerebbe nel registro.
 *
 * In questo ciclo esiste SOLO in prova a vuoto: la scrittura e' fuori scope, e
 * il rapporto serve anche da collaudo del servizio sui codici veri.
 */
class TrailRegistryNormalizeCommand extends Command
{
    protected $signature = 'wm-package:trail-registry-normalize {--dry-run}';

    protected $description = 'Riporta cosa entrerebbe nel registro dei codici, senza scrivere';

    public function handle(TrailRegistryService $service): int
    {
        if (! $this->option('dry-run')) {
            $this->error('In questo ciclo il comando gira solo con --dry-run: la scrittura e\' fuori scope.');

            return self::FAILURE;
        }

        $outsideAnySector = 0;
        $sectorMismatch = 0;
        $unparsable = 0;
        $byFullCodeAndTail = [];

        DB::table('ec_tracks')
            ->select('id', 'name', 'properties')
            ->selectRaw('ST_AsText(geometry) AS wkt')
            ->whereRaw("COALESCE(properties->>'ref', '') <> ''")
            ->orderBy('id')
            ->chunk(200, function ($tracks) use ($service, &$outsideAnySector, &$sectorMismatch, &$unparsable, &$byFullCodeAndTail) {
                foreach ($tracks as $track) {
                    $ref = json_decode($track->properties, true)['ref'] ?? '';
                    $tail = TrailCodeParser::parseTail($ref);

                    if ($tail === null) {
                        $unparsable++;
                        $this->line("  codice non interpretabile: #{$track->id} «{$ref}»");

                        continue;
                    }

                    try {
                        $sector = $service->resolveSector($track->wkt);
                    } catch (SectorNotFoundException) {
                        $outsideAnySector++;
                        $this->line("  fuori da ogni settore: #{$track->id} «{$ref}»");

                        continue;
                    }

                    $fullCode = $sector->properties['full_code'];

                    // La cifra del settore scritta nel codice deve coincidere
                    // con quella dedotta dalla geometria: e' una misura della
                    // qualita' del dato, gratis perche' entrambe sono qui.
                    if (TrailCodeParser::sectorDigitFrom($ref) !== substr($fullCode, 4, 1)) {
                        $sectorMismatch++;
                        $this->line("  settore discordante: #{$track->id} «{$ref}» geometria dice {$fullCode}");
                    }

                    $key = $fullCode.'-'.$tail['number'].'-'.$tail['variant'];
                    $byFullCodeAndTail[$key][] = $track->id;
                }
            });

        $duplicates = array_filter($byFullCodeAndTail, fn (array $ids) => count($ids) > 1);

        $this->newLine();
        $this->info('Rapporto (nessuna scrittura effettuata)');
        $this->line('  codici letti: '.count($byFullCodeAndTail));
        $this->line('  fuori da ogni settore: '.$outsideAnySector);
        $this->line('  settore discordante: '.$sectorMismatch);
        $this->line('  codice non interpretabile: '.$unparsable);
        $this->line('  doppioni reali: '.count($duplicates));

        foreach ($duplicates as $key => $ids) {
            $this->line("    {$key}: tracce ".implode(', ', $ids));
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: registra il comando nel dominio**

In `config/wm-package.php`, dentro `features.trail_registry`:

```php
            'commands' => [
                \Wm\WmPackage\TrailRegistry\Commands\TrailRegistryNormalizeCommand::class,
            ],
```

- [ ] **Step 5: esegui il test e verifica che passi**

Run: `vendor/bin/pest tests/Feature/TrailRegistry/TrailRegistryNormalizeCommandTest.php`
Expected: PASS, 5 test.

- [ ] **Step 6: commit (istruzione per il developer, non eseguire)**

```bash
git add src/TrailRegistry/Commands config/wm-package.php tests/Feature/TrailRegistry/TrailRegistryNormalizeCommandTest.php
git commit -m "feat(oc:8489): comando di normalizzazione in prova a vuoto con rapporto"
```

---

### Task 10: Resource Nova del registro e delle istanze

**Files:**
- Create: `src/TrailRegistry/Nova/TrailRegistryCode.php`
- Create: `src/TrailRegistry/Nova/TrailApplication.php`
- Create: `src/TrailRegistry/Nova/Actions/ApproveTrailApplication.php`
- Create: `src/TrailRegistry/Nova/Actions/RejectTrailApplication.php`
- Create: `src/TrailRegistry/Nova/Actions/ReplaceTrailCodeNumber.php`
- Create: `src/TrailRegistry/Nova/CodeHistoryRenderer.php`
- Test: `tests/Feature/TrailRegistry/TrailRegistryNovaResourcesTest.php`
- Test: `tests/Feature/TrailRegistry/ApproveTrailApplicationTest.php`

**Interfaces:**
- Consumes: service (Task 5-8), modelli (Task 4).
- Produces: le due Resource, le tre Action, `CodeHistoryRenderer::render(TrailRegistryCode $code): string`.

**Vincoli della Resource del registro:** `authorizedToCreate()` e `authorizedToUpdate()` a `false` — un codice non si modifica da un form. Index a cinque colonne (Codice, Denominazione, Stato, Istanza, Sentiero), filtri su provincia, area, settore, stato. Nel detail la storia come campo HTML di sola lettura. **Nessuna Action «Libera numero» sul registro**: sarebbe l'unica operazione capace di liberare un codice senza che sia accaduto nulla.

**Vincoli della Resource dell'istanza:** index a sei colonne (Denominazione, Codice, Stato istruttoria, Provenienza, Inserita da, Presentata il), filtri su stato istruttoria e provenienza. **Nessun form di modifica** in questo ciclo: cosa sia modificabile dopo la presentazione è una domanda aperta con Forestas (vedi «Da chiarire con Forestas» nell'overview).

- [ ] **Step 1: scrivi il test che fallisce**

```php
<?php

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Nova\TrailApplication as TrailApplicationResource;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryCode as TrailRegistryCodeResource;

it('non permette di creare o modificare un codice a mano', function () {
    $resource = new TrailRegistryCodeResource(makeCode());
    $request = NovaRequest::create('/');

    expect($resource->authorizedToUpdate($request))->toBeFalse();
    expect(TrailRegistryCodeResource::authorizedToCreate($request))->toBeFalse();
});

it('mostra nel registro le cinque colonne decise', function () {
    $fields = collect((new TrailRegistryCodeResource(makeCode()))
        ->fields(NovaRequest::create('/')))
        ->map(fn ($f) => $f->name)
        ->all();

    expect($fields)->toContain('Codice', 'Denominazione', 'Stato', 'Istanza', 'Sentiero');
});

it('non espone una action che libera un numero dal registro', function () {
    $actions = collect((new TrailRegistryCodeResource(makeCode()))
        ->actions(NovaRequest::create('/')))
        ->map(fn ($a) => class_basename($a))
        ->all();

    expect($actions)->not->toContain('ReleaseTrailCode');
});

it('non permette di modificare un istanza in questo ciclo', function () {
    $resource = new TrailApplicationResource(
        \Wm\WmPackage\TrailRegistry\Models\TrailApplication::factory()->create()
    );

    expect($resource->authorizedToUpdate(NovaRequest::create('/')))->toBeFalse();
});
```

- [ ] **Step 2: scrivi il test dell'approvazione**

```php
<?php

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\Actions\ApproveTrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\Actions\RejectTrailApplication;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $this->application = TrailApplication::factory()->create(['name' => 'Domanda del monte']);
    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING((1 1, 2 2))', $this->application->id]
    );
    $this->application->refresh();

    $this->code = app(TrailRegistryService::class)->reserve($this->application);
});

it('approvando crea il sentiero, gli attacca la geometria e assegna il codice', function () {
    $before = EcTrack::count();

    (new ApproveTrailApplication)->handle(
        new \Laravel\Nova\Fields\ActionFields(collect(), collect()),
        collect([$this->application->fresh()]),
    );

    expect(EcTrack::count())->toBe($before + 1);

    $track = EcTrack::latest('id')->first();
    expect($track->name)->toBe('Domanda del monte');

    $code = $this->code->fresh();
    expect($code->status)->toBe(TrailCodeStatus::Assigned)
        ->and($code->ec_track_id)->toBe($track->id)
        // il numero non cambia: e' gia' stato comunicato
        ->and($code->code)->toBe('ZNUB500')
        // il legame con l'istanza resta
        ->and($code->trail_application_id)->toBe($this->application->id);

    expect($this->application->fresh()->status)->toBe(TrailApplicationStatus::Approved);
});

it('approvando due volte non crea due sentieri', function () {
    $action = new ApproveTrailApplication;
    $fields = new \Laravel\Nova\Fields\ActionFields(collect(), collect());

    $action->handle($fields, collect([$this->application->fresh()]));
    $before = EcTrack::count();
    $action->handle($fields, collect([$this->application->fresh()]));

    expect(EcTrack::count())->toBe($before);
});

it('respingendo libera il numero con la causa nella storia', function () {
    (new RejectTrailApplication)->handle(
        new \Laravel\Nova\Fields\ActionFields(collect(), collect()),
        collect([$this->application->fresh()]),
    );

    $code = $this->code->fresh();

    expect($code->status)->toBe(TrailCodeStatus::Released);
    expect($code->events->last()->reason)->toBe('application_rejected');
    expect($this->application->fresh()->status)->toBe(TrailApplicationStatus::Rejected);
});
```

- [ ] **Step 3: esegui i test e verifica che falliscano**

Run: `vendor/bin/pest tests/Feature/TrailRegistry/TrailRegistryNovaResourcesTest.php tests/Feature/TrailRegistry/ApproveTrailApplicationTest.php`
Expected: FAIL, classi non trovate.

- [ ] **Step 4: implementa il renderer della storia**

```php
<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode as TrailRegistryCodeModel;

/**
 * La storia dei cambi di stato come HTML di sola lettura per il detail.
 *
 * Nessuna Resource dedicata alla storia e nessun HasMany: decisione esplicita
 * del dev. Stesso schema di ConfigDetailPreviewRenderer (oc:8181).
 */
class CodeHistoryRenderer
{
    public static function render(TrailRegistryCodeModel $code): string
    {
        $rows = $code->events->map(function ($event) {
            return sprintf(
                '<tr><td style="padding:4px 12px 4px 0">%s</td><td style="padding:4px 12px 4px 0">%s &rarr; %s</td><td style="padding:4px 12px 4px 0">%s</td><td style="padding:4px 0">%s</td></tr>',
                e($event->created_at?->format('d/m/Y H:i') ?? ''),
                e($event->from_status?->value ?? '—'),
                e($event->to_status->value),
                e($event->reason),
                e($event->user?->name ?? '—'),
            );
        })->implode('');

        if ($rows === '') {
            return '<p>Nessun passaggio registrato.</p>';
        }

        return '<table style="width:100%;font-size:0.875rem">'
            .'<thead><tr><th align="left">Quando</th><th align="left">Passaggio</th><th align="left">Causa</th><th align="left">Chi</th></tr></thead>'
            ."<tbody>{$rows}</tbody></table>";
    }
}
```

- [ ] **Step 5: implementa la Resource del registro**

```php
<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use Wm\WmPackage\TrailRegistry\Nova\Actions\ReplaceTrailCodeNumber;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailCodeAreaFilter;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailCodeProvinceFilter;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailCodeSectorFilter;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailCodeStatusFilter;

class TrailRegistryCode extends Resource
{
    public static $model = \Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode::class;

    public static $title = 'code';

    public static $search = ['number'];

    public static function label(): string
    {
        return __('Registro dei codici');
    }

    /**
     * Un codice non si crea e non si modifica da un form: cambiare `number` su
     * una riga assegnata significherebbe cambiare un numero gia' comunicato al
     * richiedente. Le righe le scrive solo TrailRegistryService.
     */
    public static function authorizedToCreate(\Illuminate\Http\Request $request): bool
    {
        return false;
    }

    public function authorizedToUpdate(\Illuminate\Http\Request $request): bool
    {
        return false;
    }

    public function authorizedToDelete(\Illuminate\Http\Request $request): bool
    {
        return false;
    }

    public function fields(NovaRequest $request): array
    {
        return [
            Text::make(__('Codice'), fn () => $this->code)->sortable(),

            // Il nome del sentiero se assegnato, altrimenti quello
            // dell'istanza: l'unico appiglio leggibile in un elenco di codici.
            Text::make(__('Denominazione'), fn () => $this->denomination),

            Text::make(__('Stato'), fn () => $this->status->value),

            BelongsTo::make(__('Istanza'), 'application', TrailApplication::class),

            // La Resource del sentiero e del settore NON si referenziano come
            // `\App\Nova\EcTrack`: il package non puo' dipendere dal
            // namespace applicativo di un consumer. Si risolve a runtime con
            // Nova::resourceForModel(), che ritorna la Resource canonica
            // registrata per quel modello.
            BelongsTo::make(
                __('Sentiero'),
                'ecTrack',
                \Laravel\Nova\Nova::resourceForModel(\Wm\WmPackage\Models\EcTrack::class),
            )->nullable(),

            // Le sei colonne scomposte hanno senso solo nella scheda del
            // singolo codice. La variante si mostra per il suo valore reale,
            // `0` incluso: l'omissione riguarda il codice in uscita.
            Text::make(__('Regione'), 'region')->onlyOnDetail(),
            Text::make(__('Provincia'), 'province')->onlyOnDetail(),
            Text::make(__('Area'), 'area')->onlyOnDetail(),
            Text::make(__('Settore'), 'sector')->onlyOnDetail(),
            Number::make(__('Numero'), 'number')->onlyOnDetail(),
            Text::make(__('Variante'), 'variant')->onlyOnDetail(),

            BelongsTo::make(
                __('Settore di riferimento'),
                'taxonomyWhere',
                \Laravel\Nova\Nova::resourceForModel(\Wm\WmPackage\Models\TaxonomyWhere::class),
            )->nullable()->onlyOnDetail(),

            Text::make(__('Storia dei cambi di stato'), fn () => CodeHistoryRenderer::render($this->resource))
                ->asHtml()
                ->onlyOnDetail(),
        ];
    }

    public function filters(NovaRequest $request): array
    {
        return [
            new TrailCodeProvinceFilter,
            new TrailCodeAreaFilter,
            new TrailCodeSectorFilter,
            new TrailCodeStatusFilter,
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            (new ReplaceTrailCodeNumber)->canRun(
                fn ($request, $code) => $code->status === \Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus::Reserved,
            ),
        ];
    }
}
```

I quattro filtri sono `Laravel\Nova\Filters\Filter` con `apply()` che fa `->where('<colonna>', $value)` e `options()` che pesca i valori distinti dalla tabella; per lo stato le opzioni vengono da `TrailCodeStatus::cases()`. Scrivili in `src/TrailRegistry/Nova/Filters/`.

- [ ] **Step 6: implementa la Resource dell'istanza e le due Action**

`src/TrailRegistry/Nova/TrailApplication.php`: index con `Text::make(__('Denominazione'), 'name')`, `Text::make(__('Codice'), fn () => $this->activeCode?->code)`, `Text::make(__('Stato istruttoria'), fn () => $this->status->value)`, `Text::make(__('Provenienza'), 'source')`, `BelongsTo::make(__('Inserita da'), 'user', Nova::resourceForModel(\Wm\WmPackage\Models\User::class))` — mai `\App\Nova\User::class`, che vive nel consumer, `DateTime::make(__('Presentata il'), 'created_at')`. In detail aggiungi il campo mappa già usato per gli `EcTrack` e il contenuto di `properties`. `authorizedToUpdate()` a `false`. Le Action: `ApproveTrailApplication` e `RejectTrailApplication`.

```php
<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * Approva l'istanza: crea il sentiero, gli attacca il tipo, replica i media e
 * rende definitivo il codice riservato.
 *
 * Il pattern segue ConvertUgcPoiToEcPoi (che esiste solo per i POI), con una
 * divergenza deliberata: il legame verso il sentiero e' una chiave esterna
 * vera nel registro, non una voce in properties.
 */
class ApproveTrailApplication extends Action
{
    use InteractsWithQueue, Queueable;

    public function name(): string
    {
        return __('Approva');
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(TrailRegistryService::class);

        foreach ($models as $application) {
            $code = $application->activeCode;

            // Idempotenza: una seconda esecuzione non crea un altro sentiero.
            if ($code === null || $code->ec_track_id !== null) {
                continue;
            }

            DB::transaction(function () use ($application, $code, $service) {
                $track = EcTrack::create([
                    'name' => $application->name,
                    'user_id' => auth()->id(),
                ]);

                // La geometria non passa da Eloquent.
                DB::statement(
                    'UPDATE ec_tracks SET geometry = (SELECT geometry FROM trail_applications WHERE id = ?) WHERE id = ?',
                    [$application->id, $track->id],
                );

                $this->copyMedia($application, $track);
                $this->attachTrailType($track);

                $service->confirm($code, $track, auth()->id());

                $application->update(['status' => TrailApplicationStatus::Approved]);
            });
        }

        return Action::message(__('Istanze approvate.'));
    }

    /**
     * Il tipo va attaccato dalla conversione e non lasciato all'operatore: sul
     * database di forestas ci sono 6 tracce che sono sentieri a tutti gli
     * effetti a cui manca solo la riga in pivot.
     *
     * L'identificatore e' configurabile perche' e' un dato del consumer, non
     * del package.
     */
    protected function attachTrailType(EcTrack $track): void
    {
        $identifier = config('wm-package.features.trail_registry.trail_type_identifier');

        if (! $identifier) {
            return;
        }

        $activity = \Wm\WmPackage\Models\TaxonomyActivity::where('identifier', $identifier)->first();

        if ($activity === null) {
            Log::warning('trail_registry: tassonomia del tipo sentiero non trovata', ['identifier' => $identifier]);

            return;
        }

        $track->taxonomyActivities()->syncWithoutDetaching([$activity->id]);
    }

    protected function copyMedia($application, EcTrack $track): void
    {
        foreach ($application->media as $media) {
            try {
                $copy = $media->replicate();
                $copy->model()->associate($track);
                $copy->save();
            } catch (\Exception $e) {
                // Un allegato non deve far cadere l'intera conversione.
                Log::error('trail_registry: copia media fallita -> '.$e->getMessage());
            }
        }
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
```

`RejectTrailApplication` è più breve: in transazione porta l'istanza a `TrailApplicationStatus::Rejected` e chiama `$service->release($code, 'application_rejected', auth()->id())`.

`ReplaceTrailCodeNumber` espone un `Select` con `availableNumbers($code->fullCode)` e chiama `replaceNumber()`.

- [ ] **Step 7: esegui i test e verifica che passino**

Run: `vendor/bin/pest tests/Feature/TrailRegistry/`
Expected: PASS.

- [ ] **Step 8: commit (istruzione per il developer, non eseguire)**

```bash
git add src/TrailRegistry/Nova tests/Feature/TrailRegistry
git commit -m "feat(oc:8489): resource Nova del registro e delle istanze con le action di istruttoria"
```

---

### Task 11: Configurazione del dominio e menu

**Files:**
- Modify: `config/wm-package.php:86-96`
- Modify: `src/WmPackageServiceProvider.php` (nel blocco del menu, righe 557-660)
- Test: `tests/Feature/TrailRegistry/TrailRegistryDomainRegistrationTest.php`

**Interfaces:**
- Consumes: tutto quanto sopra.
- Produces: `features.trail_registry` con `commands`, `nova_resources`, `code_format`, `trail_type_identifier`; iniezione della sezione di menu `Catasto`.

- [ ] **Step 1: scrivi il test che fallisce**

```php
<?php

use Wm\WmPackage\Services\FeaturesService;

it('a dominio spento non registra le resource del catasto', function () {
    config(['wm-package.features.trail_registry.enabled' => false]);

    expect(FeaturesService::isEnabled('trail_registry'))->toBeFalse();
    expect(FeaturesService::enabledDomains())->not->toContain('trail_registry');
});

it('non mette le resource del dominio sotto src/Nova, che Nova scandisce', function () {
    // src/Nova viene letta ricorsivamente da Nova::resourcesIn(): una resource
    // del dominio la' dentro verrebbe registrata anche a interruttore spento.
    $paths = glob(__DIR__.'/../../../src/Nova/TrailRegistry*');

    expect($paths)->toBe([]);
});

it('dichiara le due resource e il comando del dominio', function () {
    expect(config('wm-package.features.trail_registry.nova_resources'))->toBe([
        \Wm\WmPackage\TrailRegistry\Nova\TrailApplication::class,
        \Wm\WmPackage\TrailRegistry\Nova\TrailRegistryCode::class,
    ]);

    expect(config('wm-package.features.trail_registry.commands'))->toContain(
        \Wm\WmPackage\TrailRegistry\Commands\TrailRegistryNormalizeCommand::class,
    );
});
```

- [ ] **Step 2: esegui il test e verifica che fallisca**

Run: `vendor/bin/pest tests/Feature/TrailRegistry/TrailRegistryDomainRegistrationTest.php`
Expected: FAIL, `nova_resources` è vuoto.

- [ ] **Step 3: popola la configurazione**

In `config/wm-package.php`, sostituisci la sezione `trail_registry`:

```php
    'features' => [
        'trail_registry' => [
            'enabled' => env('WM_TRAIL_REGISTRY_ENABLED', false),

            // Comandi artisan e risorse Nova del dominio: registrati solo a
            // dominio acceso. Le risorse Nova NON possono stare in src/Nova,
            // che viene scandita integralmente da Nova::resourcesIn().
            'commands' => [
                \Wm\WmPackage\TrailRegistry\Commands\TrailRegistryNormalizeCommand::class,
            ],
            'nova_resources' => [
                \Wm\WmPackage\TrailRegistry\Nova\TrailApplication::class,
                \Wm\WmPackage\TrailRegistry\Nova\TrailRegistryCode::class,
            ],

            // Formato del codice: impostabile, non scritto nel codice. Se il
            // formato reale confermato da Forestas divergesse, questo e' il
            // punto da cambiare.
            'code_format' => [
                'number_digits' => 2,
                'number_max' => 99,
                // `0` significa «senza variante» e in uscita si omette.
                'variant_none' => '0',
                'variant_letters' => true,
            ],

            // Identificatore della tassonomia che marca un tracciato come
            // sentiero. E' un dato del consumer, non del package: in forestas
            // vale 'sardegnasentieri:type:sentiero'.
            'trail_type_identifier' => env('WM_TRAIL_TYPE_IDENTIFIER'),
        ],
    ],
```

- [ ] **Step 4: inietta la sezione di menu**

In `WmPackageServiceProvider`, nel blocco che già avvolge `Nova::$mainMenuCallback` per la sezione `Tools` (righe 557-660), aggiungi la stessa logica per `Catasto`: se il consumer ha già una `MenuSection` con quel nome vi accoda le due voci, altrimenti la crea. **Solo se `FeaturesService::isEnabled('trail_registry')`.**

Le voci: `MenuItem::resource(TrailApplication::class)` etichettata `__('Istanze')` e `MenuItem::resource(TrailRegistryCode::class)` etichettata `__('Registro dei codici')`.

- [ ] **Step 5: esegui il test e verifica che passi**

Run: `vendor/bin/pest tests/Feature/TrailRegistry/TrailRegistryDomainRegistrationTest.php`
Expected: PASS, 3 test.

- [ ] **Step 5-bis: aggancia `validate()` alla configurazione**

Il requisito «il formato del codice è impostabile da configurazione, non scritto nel codice» non è soddisfatto finché `validate()` (Task 8) usa una regex scritta a mano. Ora che `code_format` esiste, la regex va composta da lì:

```php
    public function validate(string $code): bool
    {
        $format = config('wm-package.features.trail_registry.code_format');
        $digits = (int) ($format['number_digits'] ?? 2);

        // Prefisso: regione (1) + provincia (2) + area (1) + settore (1 cifra).
        // Coda: le cifre del numero, piu' la variante come lettera se ammessa.
        $variant = ($format['variant_letters'] ?? true) ? '[A-Z]?' : '';

        $pattern = '/^[A-Z][A-Z]{2}[A-Z]\d\d{'.$digits.'}'.$variant.'$/';

        return (bool) preg_match($pattern, $code)
            // Una coda di quattro cifre sarebbe un sottosentiero: fuori scope.
            && ! preg_match('/\d{'.($digits + 2).'}/', $code);
    }
```

Riesegui il test del Task 8 dopo la modifica:

Run: `vendor/bin/pest tests/Unit/TrailRegistry/ValidateCodeTest.php`
Expected: PASS, gli stessi 11 casi. Se un caso cambia esito, è la composizione della regex che è sbagliata: i casi vengono dal formato confermato in scrum.

- [ ] **Step 6: verifica la suite completa e PHPStan**

Run: `vendor/bin/pest` (dopo aver verificato l'isolamento del database di test)
Run: `vendor/bin/phpstan analyse`
Expected: verde entrambi. PHPStan è a livello 5 con baseline: non aggiungere righe alla baseline per il codice nuovo.

- [ ] **Step 7: commit (istruzione per il developer, non eseguire)**

```bash
git add config/wm-package.php src/WmPackageServiceProvider.php tests/Feature/TrailRegistry/TrailRegistryDomainRegistrationTest.php
git commit -m "feat(oc:8489): registrazione del dominio trail_registry e menu Catasto"
```

---

### Task 12: Documentazione

**Files:**
- Create: `docs/resources/TrailRegistry.md`
- Modify: `docs/resources/OptionalDomains.md`
- Modify: `CLAUDE.md` (sezioni «Feature disponibili» e «Decisioni architetturali»)

- [ ] **Step 1: scrivi `docs/resources/TrailRegistry.md`**

Deve contenere: cos'è il registro e perché è un dominio opzionale; la forma del codice con la tabella delle parti; il ciclo di vita (riserva → conferma o liberazione) con il diagramma già presente nell'overview; i metodi del service con firma e comportamento; il significato di `conflitto` e perché l'indice unico è parziale; il margine su `variant` e la ragione; come si popola il registro con il comando in prova a vuoto; cosa succede a dominio spento.

- [ ] **Step 2: aggiungi la riga a `OptionalDomains.md`**

Nella tabella dei domini, aggiorna la riga `trail_registry` indicando che oc:8489 è realizzato e rimandando a `docs/resources/TrailRegistry.md`.

- [ ] **Step 3: aggiorna `CLAUDE.md`**

In «Feature disponibili» una riga per oc:8489 con i moduli toccati. In «Decisioni architetturali» un blocco in cima con: perché l'indice unico è parziale e cosa succede a chi lo scrive completo; perché `variant` non è mai `NULL`; perché le Resource del dominio non stanno in `src/Nova`; perché il legame verso il sentiero è una FK e non una voce in `properties`; il fatto che la FK verso `ec_tracks` rende il dominio non più «spegnibile» a tabella popolata.

- [ ] **Step 4: commit (istruzione per il developer, non eseguire)**

```bash
git add docs CLAUDE.md
git commit -m "docs(oc:8489): documentazione del registro dei codici e decisioni architetturali"
```

---

## Cosa questo piano NON fa

Dichiarato per non farlo scoprire a metà esecuzione:

- **Nessuna scrittura nel registro dai dati storici.** Il comando esiste solo in prova a vuoto: le 620 righe non vengono create in questo ciclo.
- **Nessun form di modifica sull'istanza**, in attesa della risposta di Forestas sulla modificabilità della traccia.
- **Nessuna API**: oc:8490 e oc:8491.
- **Nessuna scadenza della riserva**: un'istanza che si arena tiene il numero per sempre. Durata da concordare.
- **Nessuna quarantena** su un numero liberato: può essere riassegnato subito, e la segnaletica sul terreno potrebbe puntare al sentiero sbagliato.
- **Nessun controllo di validità del valore `type` all'import** (decisione esplicita del dev).
- **Nessuna misura preventiva** delle tre incognite sui dati: si vedono con la prova a vuoto del Task 9, come deciso dal dev. Se il rapporto rivelasse molte geometrie fuori settore o molti settori discordanti, la scomposizione in colonne del Task 2 va rimessa in discussione — ed è la parte più costosa da correggere, perché la migration porta il vincolo unico parziale e i CHECK.

---

### Task 13: Scrittura nel registro (aggiunto il 09/09/2026)

**Files:**
- Modify: `src/TrailRegistry/Commands/TrailRegistryNormalizeCommand.php`
- Test: `tests/Feature/TrailRegistry/TrailRegistryNormalizeWriteTest.php`

**Perché è stato aggiunto dopo.** La prima stesura del piano lasciava il comando in sola lettura, perché la scrittura dipendeva da una decisione aperta: come trattare i doppioni. Quella decisione è stata presa il 09/09 — prendono lo stato `conflitto`, che il vincolo di unicità parziale non guarda — quindi l'ostacolo è caduto. Decisione del dev: si normalizza, e ogni caso anomalo finisce in un elenco da portare a Forestas.

**Interfaces:**
- Consumes: `TrailCodeParser`, `TrailRegistryService::resolveSector()`, i modelli del registro.
- Produces: il comando accetta `--dry-run` (comportamento attuale, invariato) **oppure** la scrittura; senza alcuna opzione, chiede conferma prima di scrivere.

**Cosa entra e cosa resta fuori** — è il cuore del task:

| Caso | Esito |
|---|---|
| codice leggibile, un solo settore, posizione libera | riga `assegnato`, legata all'`EcTrack` |
| codice leggibile, posizione già occupata da un altro | riga `conflitto` |
| **codice fuori forma** (coda irregolare, 3 casi sui dati reali) | **non entra**, elencato nel rapporto |
| **geometria fuori da ogni settore** | **non entra**: senza settore non c'è prefisso, quindi non esiste un codice da comporre |
| tipo `sentiero` senza `ref`, codice scritto nel nome (40 casi) | **non entra**, elencato: che significhi l'assenza del `ref` è una domanda aperta con Forestas |

- [ ] **Step 1: scrivi i test che falliscono**

```php
<?php

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

beforeEach(function () {
    runTrailRegistryStubs();
    Bus::fake();
});

function trackWithRef(string $ref, string $wkt): EcTrack
{
    $track = EcTrack::factory()->createQuietly(['properties' => ['ref' => $ref]]);

    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        [$wkt, $track->id]
    );

    return $track->refresh();
}

it('carica nel registro un codice leggibile, legandolo al sentiero', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $track = trackWithRef('Z-NU-B-535', 'MULTILINESTRING Z((1 1 0, 2 2 0))');

    $this->artisan('wm-package:trail-registry-normalize --force')->assertExitCode(0);

    $code = TrailRegistryCode::firstOrFail();

    expect($code->code)->toBe('ZNUB535')
        ->and($code->status)->toBe(TrailCodeStatus::Assigned)
        ->and($code->ec_track_id)->toBe($track->id)
        ->and($code->taxonomy_where_id)->not->toBeNull();
});

it('marca in conflitto il secondo codice che occupa la stessa posizione', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    trackWithRef('Z-NU-B-535', 'MULTILINESTRING Z((1 1 0, 2 2 0))');
    trackWithRef('535', 'MULTILINESTRING Z((3 3 0, 4 4 0))');

    $this->artisan('wm-package:trail-registry-normalize --force')->assertExitCode(0);

    expect(TrailRegistryCode::where('status', TrailCodeStatus::Assigned->value)->count())->toBe(1);
    expect(TrailRegistryCode::where('status', TrailCodeStatus::Conflict->value)->count())->toBe(1);
});

it('non carica un codice fuori forma e lo elenca', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $track = trackWithRef('sentiero del monte', 'MULTILINESTRING Z((1 1 0, 2 2 0))');

    $this->artisan('wm-package:trail-registry-normalize --force')
        ->expectsOutputToContain('codice non interpretabile: 1')
        ->assertExitCode(0);

    expect(TrailRegistryCode::count())->toBe(0);
});

it('non carica un sentiero la cui geometria non ricade in alcun settore', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))');
    trackWithRef('Z-NU-B-535', 'MULTILINESTRING Z((50 50 0, 51 51 0))');

    $this->artisan('wm-package:trail-registry-normalize --force')
        ->expectsOutputToContain('fuori da ogni settore: 1')
        ->assertExitCode(0);

    expect(TrailRegistryCode::count())->toBe(0);
});

it('e idempotente: due esecuzioni non creano due righe per lo stesso sentiero', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    trackWithRef('Z-NU-B-535', 'MULTILINESTRING Z((1 1 0, 2 2 0))');

    $this->artisan('wm-package:trail-registry-normalize --force');
    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryCode::count())->toBe(1);
});

it('con --dry-run non scrive niente, come prima', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    trackWithRef('Z-NU-B-535', 'MULTILINESTRING Z((1 1 0, 2 2 0))');

    $this->artisan('wm-package:trail-registry-normalize --dry-run')->assertExitCode(0);

    expect(TrailRegistryCode::count())->toBe(0);
});
```

- [ ] **Step 2: esegui i test e verifica che falliscano**

Run: `docker exec php_wm_package vendor/bin/pest tests/Feature/TrailRegistry/TrailRegistryNormalizeWriteTest.php`
Expected: FAIL — oggi il comando senza `--dry-run` esce con codice 1 e non scrive.

- [ ] **Step 3: apri la scrittura nel comando**

Il comando oggi rifiuta di girare senza `--dry-run`. Sostituisci quel rifiuto con:
- `--dry-run` → comportamento attuale, nessuna scrittura (invariato);
- `--force` → scrive senza chiedere, per l'uso da script;
- nessuna opzione → chiede conferma interattiva prima di scrivere, e se l'utente rifiuta esce senza scrivere.

**Le righe si scrivono in una transazione per sentiero, non una sola per tutto**: su 580 record un errore a metà non deve annullare il lavoro già buono, e il rapporto deve dire quante sono entrate.

**Idempotenza**: prima di scrivere, verifica che non esista già una riga attiva per quell'`ec_track_id`. Una seconda esecuzione non deve creare doppioni di sé stessa — che sarebbero doppioni finti, indistinguibili da quelli veri.

**Come si decide `assegnato` contro `conflitto`**: si tenta l'inserimento come `assegnato`; se il vincolo di unicità parziale lo rifiuta (`23505`), la posizione è già occupata e la riga entra come `conflitto`. È il database a stabilire chi è arrivato primo, non un controllo applicativo che lascerebbe la finestra fra il guardare e lo scrivere.

**Nessun codice va scritto per i tre casi che restano fuori** (fuori forma, fuori settore, `ref` assente): il comando li conta e li elenca, come già fa oggi.

- [ ] **Step 4: esegui i test e verifica che passino**

Run: `docker exec php_wm_package vendor/bin/pest tests/Feature/TrailRegistry/TrailRegistryNormalizeWriteTest.php`
Expected: PASS, 6 test. Poi l'intera suite del dominio, che deve restare verde.

- [ ] **Step 5: commit (istruzione per il developer, non eseguire)**

```bash
git add src/TrailRegistry/Commands tests/Feature/TrailRegistry/TrailRegistryNormalizeWriteTest.php
git commit -m "feat(oc:8489): carica il registro dai codici storici, elencando i casi anomali"
```

---

### Task 14: Estrarre la registrazione di un codice storico nel service (aggiunto il 10/09/2026)

**Files:**
- Modify: `src/TrailRegistry/TrailRegistryService.php`
- Modify: `src/TrailRegistry/Commands/TrailRegistryNormalizeCommand.php`
- Test: `tests/Feature/TrailRegistry/RegisterExistingCodeTest.php`

**Perché.** Il comando di normalizzazione sa già fare tutto: legge il `ref`, ne estrae la coda, ricava il settore dalla geometria, tenta la scrittura come `assegnato` e ripiega su `conflitto` se la posizione è presa. La stessa cosa deve ora accadere **all'import** (Task 6 del piano di forestas). Se la si riscrive là, la regola vive in due posti e prima o poi divergono — e sono la regola che decide quale numero un sentiero porta.

Quindi la si estrae qui, nel service, e il comando diventa un suo chiamante.

**Interfaces:**
- Consumes: `TrailCodeParser::parseTail()`, `resolveSector()`, i modelli del registro.
- Produces: `TrailRegistryService::registerExistingCode(int $ecTrackId, string $ref, string $geometryWkt): TrailCodeRegistrationOutcome` — un oggetto che dice cosa è successo, perché il chiamante deve poterlo raccontare (il comando in un rapporto, l'import in un log).

**L'esito è un valore, non un booleano.** I casi da distinguere sono cinque, e ridurli a «riuscito / fallito» perderebbe proprio l'informazione che serve:

| Esito | Quando |
|---|---|
| `assigned` | codice leggibile, settore trovato, posizione libera |
| `conflict` | come sopra, ma la posizione è già occupata |
| `alreadyRegistered` | esiste già una riga attiva per quel sentiero: seconda esecuzione |
| `unparsableRef` | dal `ref` non si estrae una coda valida |
| `noSector` | la geometria non ricade in alcun settore |

- [ ] **Step 1: scrivi il test che fallisce**

```php
<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    runTrailRegistryStubs();
    Bus::fake();
    $this->service = app(TrailRegistryService::class);
});

function trackWithGeometry(string $wkt): EcTrack
{
    $track = EcTrack::factory()->createQuietly();

    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        [$wkt, $track->id]
    );

    return $track->refresh();
}

it('registra un codice leggibile come assegnato', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $track = trackWithGeometry('MULTILINESTRING Z((1 1 0, 2 2 0))');

    $outcome = $this->service->registerExistingCode(
        $track->id,
        'Z-NU-B-535',
        'MULTILINESTRING Z((1 1 0, 2 2 0))'
    );

    expect($outcome->status)->toBe('assigned');

    $code = TrailRegistryCode::firstOrFail();
    expect($code->code)->toBe('ZNUB535')
        ->and($code->status)->toBe(TrailCodeStatus::Assigned)
        ->and($code->ec_track_id)->toBe($track->id);
});

it('marca in conflitto la seconda occupazione della stessa posizione', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $wkt = 'MULTILINESTRING Z((1 1 0, 2 2 0))';

    $this->service->registerExistingCode(trackWithGeometry($wkt)->id, 'Z-NU-B-535', $wkt);
    $second = $this->service->registerExistingCode(trackWithGeometry($wkt)->id, '535', $wkt);

    expect($second->status)->toBe('conflict');
    expect(TrailRegistryCode::where('status', TrailCodeStatus::Conflict->value)->count())->toBe(1);
});

it('non riscrive un sentiero gia registrato', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $wkt = 'MULTILINESTRING Z((1 1 0, 2 2 0))';
    $track = trackWithGeometry($wkt);

    $this->service->registerExistingCode($track->id, 'Z-NU-B-535', $wkt);
    $again = $this->service->registerExistingCode($track->id, 'Z-NU-B-535', $wkt);

    expect($again->status)->toBe('alreadyRegistered');
    expect(TrailRegistryCode::count())->toBe(1);
});

it('riporta un ref non interpretabile senza scrivere', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $wkt = 'MULTILINESTRING Z((1 1 0, 2 2 0))';

    $outcome = $this->service->registerExistingCode(trackWithGeometry($wkt)->id, 'sentiero del monte', $wkt);

    expect($outcome->status)->toBe('unparsableRef');
    expect(TrailRegistryCode::count())->toBe(0);
});

it('riporta una geometria fuori da ogni settore senza scrivere', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))');
    $wkt = 'MULTILINESTRING Z((50 50 0, 51 51 0))';

    $outcome = $this->service->registerExistingCode(trackWithGeometry($wkt)->id, 'Z-NU-B-535', $wkt);

    expect($outcome->status)->toBe('noSector');
    expect(TrailRegistryCode::count())->toBe(0);
});

it('segnala quando il settore dedotto non coincide con quello scritto nel codice', function () {
    // Il codice dice settore 3, la geometria dice 5: e' una delle 13 anomalie
    // misurate sui dati reali, e chi chiama deve poterla riportare.
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $wkt = 'MULTILINESTRING Z((1 1 0, 2 2 0))';

    $outcome = $this->service->registerExistingCode(trackWithGeometry($wkt)->id, '332', $wkt);

    expect($outcome->status)->toBe('assigned')
        ->and($outcome->sectorMismatch)->toBeTrue();
});
```

- [ ] **Step 2: esegui il test e verifica che fallisca**

Run: `docker exec php_wm_package vendor/bin/pest tests/Feature/TrailRegistry/RegisterExistingCodeTest.php`
Expected: FAIL, metodo `registerExistingCode` non definito.

- [ ] **Step 3: sposta la logica dal comando al service**

Il comando ha già tutto: `hasActiveRow()`, `writeRow()` e il ramo che sceglie fra `assegnato` e `conflitto` tentando la scrittura e catturando la violazione di unicità. Spostali nel service, dentro `registerExistingCode()`, e fai in modo che restituiscano l'esito invece di stampare.

**Non cambiare le regole mentre le sposti.** In particolare devono restare: la transazione per singolo sentiero; il fatto che a decidere fra assegnato e conflitto sia il database (`23505`) e non un controllo applicativo; la guardia di idempotenza sull'`ec_track_id`.

L'oggetto dell'esito può essere una classe semplice in `src/TrailRegistry/TrailCodeRegistrationOutcome.php`, con lo stato, il codice creato quando c'è, e il flag del settore discordante.

- [ ] **Step 4: fai chiamare il service dal comando**

Il comando conserva il proprio compito — leggere le tracce a blocchi, contare, stampare il rapporto — ma per ogni sentiero chiama `registerExistingCode()` e aggrega gli esiti. Il rapporto deve restare **identico** a oggi: stesse righe, stessi conteggi.

- [ ] **Step 5: verifica che nulla sia cambiato**

Run: `docker exec php_wm_package vendor/bin/pest tests/Feature/TrailRegistry tests/Unit/TrailRegistry`
Expected: tutti i test verdi, **compresi quelli del comando scritti al Task 9 e al Task 13, che non vanno modificati**: sono la prova che l'estrazione non ha cambiato il comportamento.

- [ ] **Step 6: commit (istruzione per il developer, non eseguire)**

```bash
git add src/TrailRegistry tests/Feature/TrailRegistry/RegisterExistingCodeTest.php
git commit -m "refactor(oc:8489): estrae nel service la registrazione di un codice storico"
```

---

### Task 15: Registro delle anomalie (aggiunto il 10/09/2026)

**Files:**
- Create: `database/migrations/trail_registry/zz_2026_09_10_000001_create_trail_registry_anomalies_table.php.stub`
- Create: `src/TrailRegistry/Enums/TrailRegistryAnomalyType.php`
- Create: `src/TrailRegistry/Models/TrailRegistryAnomaly.php`
- Create: `src/TrailRegistry/Nova/TrailRegistryAnomaly.php`
- Create: `src/TrailRegistry/Nova/Filters/TrailAnomalyTypeFilter.php`
- Modify: `config/wm-package.php`
- Modify: `src/TrailRegistry/Commands/TrailRegistryNormalizeCommand.php`
- Modify: `src/WmPackageServiceProvider.php` (la voce di menu)
- Test: `tests/Feature/TrailRegistry/TrailRegistryAnomaliesTest.php`

**Perché esiste.** Le anomalie dei codici non le correggiamo noi: **il dato lo possiede Drupal**, quindi il cliente sistema le schede alla fonte e l'import successivo se le porta via da sé. Perché possa farlo deve però vederle, e oggi vivono solo nell'output di un comando che nessuno lancia dal backoffice.

Non è quindi un cruscotto: è la **lista di lavoro** del cliente. Ogni riga deve dire cosa non va, su quale sentiero, e possibilmente cosa scrivere per sistemarlo.

**Interfaces:**
- Consumes: `TrailCodeParser`, `resolveSector()`, i modelli del registro; il comando di normalizzazione, che le scrive.
- Produces: la Resource `Anomalie` nel menu `Catasto`.

**I sei tipi**, in un enum `TrailRegistryAnomalyType`:

| Caso | Significato | Quante sui dati reali oggi |
|---|---|---|
| `codice_conteso` | la posizione è di un altro sentiero: **questo resta senza numero** | 18 |
| `settore_discordante` | il settore scritto nel codice non è quello in cui la traccia ricade | 13 |
| `codice_fuori_dal_campo` | il codice non sta nella proprietà dedicata ma nel nome | 40 |
| `geometria_duplicata` | due tracce con la stessa identica geometria | 6 coppie |
| `codice_illeggibile` | dalla proprietà non si ricava una coda valida | 0 |
| `fuori_da_ogni_settore` | nessun settore contiene la traccia | 0 |

Gli ultimi due sono a zero oggi ma sono categorie vere — i tre codici fuori forma misurati l'08/09 e il rischio che una traccia nuova cada fuori: averle nell'enum significa che quando capiteranno finiranno nella lista invece che in nessun posto.

**Il nome del tipo non nomina `ref`.** `codice_fuori_dal_campo` resta vero comunque si chiami la proprietà che dovrebbe contenere il codice — vedi lo Step 1.

- [ ] **Step 1: rendi configurabile la proprietà che contiene il codice storico**

`ref` è oggi **cablato in tre punti**: due nel comando (`properties->>'ref'` nella query e `$properties['ref']` nel ciclo) e uno nell'aggancio all'import in forestas. Su un altro consumer quella proprietà si chiamerà diversamente.

Aggiungi in `config/wm-package.php`, dentro `features.trail_registry`:

```php
            // In quale proprieta' del tracciato vive il codice storico.
            // Su forestas e' `ref`, ereditato dall'import da Sardegna
            // Sentieri; un altro catasto la chiamera' altrimenti. Il service
            // non la usa — riceve il codice come parametro — ma la usano i
            // suoi chiamanti: il comando di normalizzazione e l'import.
            'legacy_code_property' => env('WM_TRAIL_LEGACY_CODE_PROPERTY', 'ref'),
```

e fai leggere quella chiave ai due punti nel comando. **Il terzo punto è in forestas** e lo sistema il Task 7 di quel piano: non toccarlo da qui.

Attenzione alla query: il nome della proprietà finisce dentro un `->>` in SQL. **Non interpolarlo nella stringa**: passalo come parametro legato, o validalo contro un'espressione regolare stretta prima di comporla. È configurazione, non input utente, ma una chiave scritta male produrrebbe SQL rotto invece di un errore chiaro.

- [ ] **Step 2: scrivi il test che fallisce**

```php
<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Commands\TrailRegistryNormalizeCommand;
use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;

beforeEach(function () {
    runTrailRegistryStubs();
    $this->app[Kernel::class]->registerCommand(new TrailRegistryNormalizeCommand);
    Bus::fake();
});

function trackWith(array $properties, string $wkt, ?string $name = null): EcTrack
{
    $track = EcTrack::factory()->createQuietly(array_filter([
        'properties' => $properties,
        'name' => $name,
    ]));

    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        [$wkt, $track->id]
    );

    return $track->refresh();
}

it('registra il sentiero rimasto senza numero perche il codice e conteso', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $wkt = 'MULTILINESTRING Z((1 1 0, 2 2 0))';

    $winner = trackWith(['ref' => 'Z-NU-B-535'], $wkt);
    $loser = trackWith(['ref' => '535'], $wkt);

    $this->artisan('wm-package:trail-registry-normalize --force')->assertExitCode(0);

    $anomaly = TrailRegistryAnomaly::where('ec_track_id', $loser->id)->firstOrFail();

    expect($anomaly->type)->toBe(TrailRegistryAnomalyType::CodiceConteso)
        // La riga deve dire *con chi* il codice e' conteso: e' cio' che serve
        // al cliente per decidere a chi spetta.
        ->and($anomaly->related_ec_track_id)->toBe($winner->id);

    expect(TrailRegistryAnomaly::where('ec_track_id', $winner->id)->exists())->toBeFalse();
});

it('registra il settore discordante', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    // Il codice dice settore 3, la geometria dice 5.
    $track = trackWith(['ref' => '332'], 'MULTILINESTRING Z((1 1 0, 2 2 0))');

    $this->artisan('wm-package:trail-registry-normalize --force');

    $anomaly = TrailRegistryAnomaly::where('ec_track_id', $track->id)->firstOrFail();

    expect($anomaly->type)->toBe(TrailRegistryAnomalyType::SettoreDiscordante)
        ->and($anomaly->detail)->toContain('ZNUB5');
});

it('registra il codice che sta nel nome invece che nella proprieta dedicata', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $track = trackWith([], 'MULTILINESTRING Z((1 1 0, 2 2 0))', 'Bivio Loddue - Sant Anna (G 110)');

    $this->artisan('wm-package:trail-registry-normalize --force');

    $anomaly = TrailRegistryAnomaly::where('ec_track_id', $track->id)->firstOrFail();

    expect($anomaly->type)->toBe(TrailRegistryAnomalyType::CodiceFuoriDalCampo)
        // Il suggerimento e' il punto: dice al cliente cosa scrivere.
        ->and($anomaly->suggested_code)->toBe('G 110');
});

it('registra le due tracce con la stessa geometria', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $wkt = 'MULTILINESTRING Z((1 1 0, 2 2 0))';

    $first = trackWith(['ref' => 'Z-NU-B-535'], $wkt);
    $second = trackWith(['ref' => 'T-535'], $wkt);

    $this->artisan('wm-package:trail-registry-normalize --force');

    $duplicates = TrailRegistryAnomaly::where('type', TrailRegistryAnomalyType::GeometriaDuplicata->value)->get();

    expect($duplicates)->toHaveCount(2)
        ->and($duplicates->pluck('ec_track_id')->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all());
});

it('registra la traccia che non ricade in alcun settore', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))');
    $track = trackWith(['ref' => 'Z-NU-B-535'], 'MULTILINESTRING Z((50 50 0, 51 51 0))');

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryAnomaly::where('ec_track_id', $track->id)->firstOrFail()->type)
        ->toBe(TrailRegistryAnomalyType::FuoriDaOgniSettore);
});

it('sostituisce le anomalie a ogni esecuzione invece di accumularle', function () {
    // E' il senso della lista: sistemata la scheda su Drupal e rifatto
    // l'import, l'anomalia deve sparire da se'. Se le righe si accumulassero,
    // il cliente lavorerebbe su una lista che non si accorcia mai.
    makeSector('ZNUB5', 'POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))');
    $track = trackWith(['ref' => 'Z-NU-B-535'], 'MULTILINESTRING Z((50 50 0, 51 51 0))');

    $this->artisan('wm-package:trail-registry-normalize --force');
    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryAnomaly::count())->toBe(1);

    // Sistemata la geometria, l'anomalia sparisce.
    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z((0.2 0.2 0, 0.3 0.3 0))', $track->id]
    );

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryAnomaly::where('type', TrailRegistryAnomalyType::FuoriDaOgniSettore->value)->count())->toBe(0);
});

it('legge il codice dalla proprieta indicata in configurazione', function () {
    config(['wm-package.features.trail_registry.legacy_code_property' => 'codice_storico']);

    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    trackWith(['codice_storico' => 'Z-NU-B-535'], 'MULTILINESTRING Z((1 1 0, 2 2 0))');

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(\Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode::count())->toBe(1);
});
```

- [ ] **Step 3: esegui i test e verifica che falliscano**

Run: `docker exec php_wm_package vendor/bin/pest tests/Feature/TrailRegistry/TrailRegistryAnomaliesTest.php`
Expected: FAIL, la tabella e l'enum non esistono.

- [ ] **Step 4: crea lo stub della tabella**

Colonne: `ec_track_id` (chiave esterna vera, cancellazione a cascata: se il sentiero sparisce, la sua anomalia non ha più senso), `type`, `related_ec_track_id` (nullable — il contendente, per i codici contesi e le geometrie duplicate), `detail` (testo, il dettaglio leggibile: quale settore, quale codice), `suggested_code` (nullable — cosa sembra essere il codice, per quelli scritti nel nome), `created_at`.

Un `CHECK` sui valori di `type`, allineato all'enum, come già fatto per gli stati del registro.

Nessun vincolo di unicità: **un sentiero può avere più anomalie insieme** — il caso reale è una traccia con il settore discordante che finisce anche in conflitto, e sono due cose diverse da sistemare.

- [ ] **Step 5: fai scrivere le anomalie al comando**

Il comando calcola già quasi tutto: contesi, settori discordanti, codici illeggibili, fuori settore. Mancano due rilevamenti nuovi:

- **codice nel nome**: per le tracce **senza** la proprietà del codice, estrai dal nome con `TrailCodeParser` e registra l'anomalia con il suggerimento. Se dal nome non esce niente, nessuna anomalia — quella traccia semplicemente non ha un codice da nessuna parte.
- **geometria duplicata**: due tracce con lo stesso `md5(ST_AsText(geometry))`. Falla in **una query sola** sull'intera tabella, non confrontando le tracce a due a due nel ciclo: su 767 record il confronto a coppie sarebbe inutilmente costoso.

**Le anomalie si riscrivono da zero a ogni esecuzione**, dentro una transazione: cancella tutto e reinserisci. È ciò che fa sparire una riga quando il cliente ha sistemato la scheda, ed è il motivo per cui la lista ha senso. Non serve conservare lo storico: quello vive nel registro, non qui.

⚠️ La riscrittura deve avvenire **anche in `--dry-run`?** No: in prova a vuoto non si scrive niente, nemmeno le anomalie. Il rapporto a schermo resta l'unica uscita.

- [ ] **Step 6: la Resource Nova**

Index: sentiero (link alla sua scheda), tipo, dettaglio, codice suggerito, contendente (link), rilevata il. Filtro sul tipo, con le opzioni prese dai casi dell'enum. Ordinamento predefinito per tipo, così le anomalie dello stesso genere stanno insieme — è così che si lavorano.

`authorizedToCreate()`, `authorizedToUpdate()`, `authorizedToDelete()` a **`false`**: le righe le scrive solo il comando, e si correggono su Drupal, non qui. Una riga cancellata a mano tornerebbe alla prossima esecuzione, dando l'impressione che il backoffice non funzioni.

Dichiarala in `features.trail_registry.nova_resources` accanto alle altre due, e aggiungi la voce al menu `Catasto` in `WmPackageServiceProvider`, dove già si accodano `Istanze` e `Registro dei codici`.

- [ ] **Step 7: esegui i test e la suite del dominio**

Run: `docker exec php_wm_package vendor/bin/pest tests/Feature/TrailRegistry tests/Unit/TrailRegistry`
Expected: i sette nuovi verdi, e i 132 preesistenti **invariati** — in particolare quelli del comando, che presidiano il rapporto a schermo.

Poi PHPStan: `docker exec php_wm_package vendor/bin/phpstan analyse src/TrailRegistry --level=4 --no-progress`, che deve restare pulito.

- [ ] **Step 8: commit (istruzione per il developer, non eseguire)**

```bash
git add database/migrations/trail_registry src/TrailRegistry config/wm-package.php src/WmPackageServiceProvider.php tests/Feature/TrailRegistry/TrailRegistryAnomaliesTest.php
git commit -m "feat(oc:8489): registro delle anomalie dei codici, come lista di lavoro per il cliente"
```

---

### Task 16: Provenienza del codice (aggiunto il 10/09/2026)

**Files:**
- Modify: `database/migrations/trail_registry/zz_2026_09_09_000002_create_trail_registry_codes_table.php.stub`
- Create: `src/TrailRegistry/Enums/TrailCodeOrigin.php`
- Modify: `src/TrailRegistry/Models/TrailRegistryCode.php`
- Modify: `src/TrailRegistry/TrailRegistryService.php`
- Modify: `src/TrailRegistry/Commands/TrailRegistryNormalizeCommand.php`
- Modify: `src/TrailRegistry/Enums/TrailRegistryAnomalyType.php`
- Modify: `src/TrailRegistry/Nova/TrailRegistryCode.php`
- Test: `tests/Feature/TrailRegistry/TrailCodeOriginTest.php`

**Due cose insieme, perché sono la stessa decisione.**

**La prima:** i 39 sentieri che portano il codice nelle parentesi finali del nome **entrano nel registro**. L'estrazione ora è affidabile — tutti e 39 con la lettera dell'area — e tenerli fuori significherebbe lasciare senza numero sentieri il cui numero conosciamo. La loro anomalia **resta**, ma cambia significato: non più «questo sentiero non ha un codice» bensì «il codice l'abbiamo preso dal nome, alla fonte il campo è vuoto». Sparirà da sé quando il cliente compilerà la scheda su Drupal e l'import troverà il codice al posto giusto. Se la assorbissimo in silenzio nessuno sistemerebbe la fonte, e alla dismissione di Drupal il difetto resterebbe congelato nel dato.

**La seconda:** ogni riga del registro dice **come** il suo codice è nato. Su 601 codici, poter distinguere quelli letti da un campo, quelli dedotti da un nome e quelli assegnati dalla piattaforma è ciò che permette a Forestas di fidarsi o di verificare.

**I tre valori**, in un enum `TrailCodeOrigin`:

| Valore | Significato | Quanti oggi |
|---|---|---|
| `campo_dedicato` | letto dalla proprietà che deve contenerlo (`ref` su forestas) | 562 |
| `nome` | estratto dalle parentesi finali del nome | 39 |
| `assegnato` | proposto dalla piattaforma, primo numero libero | 0 |

Nessuno dei nomi cita `ref`: è come quella proprietà si chiama **qui**, non altrove. Il terzo è a zero oggi ma è quello che conta domani: il numero di un'istanza nuova dal SUS nascerà così, e si distinguerà a colpo d'occhio dai codici storici.

**Rinomina anche il tipo di anomalia** `codice_fuori_dal_campo` in **`codice_nel_nome`**: il primo nessuno lo capisce senza spiegazione — verificato sul campo — mentre il secondo dice il fatto e resta valido comunque si chiami la proprietà.

- [ ] **Step 1: modifica lo stub esistente, NON aggiungere una migration**

Aggiungi la colonna `origin` allo stub `zz_2026_09_09_000002_create_trail_registry_codes_table.php.stub`, con il suo `CHECK` allineato all'enum, come già fatto per `status`.

**Decisione esplicita del dev:** finché il codice non è uscito dall'ambiente locale, gli stub si modificano invece di accumulare migration incrementali per una tabella che nessuno ha ancora in produzione. Da quando il dominio sarà rilasciato su un server, questa strada si chiude e ogni cambiamento sarà una migration nuova.

Conseguenza operativa su forestas: le tabelle del dominio vanno **cancellate e ricreate**. I dati dentro sono tutti derivati — il registro lo ricostruisce il comando, le anomalie pure, le istanze sono zero — quindi non si perde nulla.

- [ ] **Step 2: scrivi il test che fallisce**

```php
<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Commands\TrailRegistryNormalizeCommand;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeOrigin;
use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

beforeEach(function () {
    runTrailRegistryStubs();
    $this->app[Kernel::class]->registerCommand(new TrailRegistryNormalizeCommand);
    Bus::fake();
});

it('marca come letto dal campo il codice che viene dalla proprieta dedicata', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    trackWith(['ref' => 'Z-NU-B-535'], 'MULTILINESTRING Z((1 1 0, 2 2 0))');

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryCode::firstOrFail()->origin)->toBe(TrailCodeOrigin::CampoDedicato);
});

it('registra il codice scritto nel nome, marcandolo come tale', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    trackWith([], 'MULTILINESTRING Z((1 1 0, 2 2 0))', 'Nuscale - SP 45 - Janna e Ferulargiu (B 535)');

    $this->artisan('wm-package:trail-registry-normalize --force');

    $code = TrailRegistryCode::firstOrFail();

    expect($code->code)->toBe('ZNUB535')
        ->and($code->origin)->toBe(TrailCodeOrigin::Nome);
});

it('lascia l anomalia anche dopo aver registrato il codice preso dal nome', function () {
    // La fonte resta da sistemare: il registro e' derivato, Drupal e' la fonte.
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $track = trackWith([], 'MULTILINESTRING Z((1 1 0, 2 2 0))', 'Nuscale (B 535)');

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryCode::where('ec_track_id', $track->id)->exists())->toBeTrue();
    expect(TrailRegistryAnomaly::where('ec_track_id', $track->id)->firstOrFail()->type)
        ->toBe(TrailRegistryAnomalyType::CodiceNelNome);
});

it('il codice dal nome sottosta alle stesse regole degli altri', function () {
    // Se la posizione e' gia' presa, finisce in conflitto come chiunque altro:
    // venire dal nome non da' precedenza.
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    trackWith(['ref' => 'Z-NU-B-535'], 'MULTILINESTRING Z((1 1 0, 2 2 0))');
    $fromName = trackWith([], 'MULTILINESTRING Z((3 3 0, 4 4 0))', 'Altro sentiero (B 535)');

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryCode::where('ec_track_id', $fromName->id)->firstOrFail()->status->value)
        ->toBe('conflict');
});

it('non registra nulla se dal nome non esce un codice', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    trackWith([], 'MULTILINESTRING Z((1 1 0, 2 2 0))', 'C100T - Via Catalana');

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryCode::count())->toBe(0);
    expect(TrailRegistryAnomaly::count())->toBe(0);
});
```

- [ ] **Step 3: aggiungi la provenienza al service**

`registerExistingCode()` deve accettare la provenienza come parametro e scriverla sulla riga. **Non dedurla dentro il service**: chi chiama sa da dove ha preso il codice, il service no — e indovinarlo significherebbe passargli il nome del tracciato per un motivo che non lo riguarda.

Il valore predefinito è `campo_dedicato`, così i chiamanti esistenti — il comando e l'aggancio all'import in forestas — non cambiano firma né comportamento.

- [ ] **Step 4: fai registrare al comando anche i codici dal nome**

Oggi il comando salta le tracce senza la proprietà del codice. Ora, quando dal nome esce un codice interpretabile, lo registra con provenienza `nome` **e** scrive comunque l'anomalia.

Le regole restano quelle di sempre: se la posizione è occupata finisce in `conflitto`, se il settore non torna scatta anche `settore_discordante`. Venire dal nome non dà precedenza su nessuno.

Aggiorna il rapporto a schermo con una riga che dica quanti codici sono stati presi dal nome: è un'informazione nuova e chi lancia il comando deve vederla.

- [ ] **Step 5: mostra la provenienza in Nova**

Una colonna nell'index del registro e un filtro. È il primo posto dove Forestas vedrà la differenza fra i 562 letti da un campo e i 39 dedotti da un nome.

- [ ] **Step 6: esegui i test e PHPStan**

Run: `docker exec php_wm_package vendor/bin/pest tests/Feature/TrailRegistry tests/Unit/TrailRegistry`
Expected: i 144 preesistenti più i 5 nuovi. **I test che presidiano il rapporto a schermo cambieranno di una riga** — quella nuova sui codici dal nome — ed è l'unica modifica ammessa a un test esistente: se ne serve un'altra, fermati e riferiscila.

Poi PHPStan pulito.

- [ ] **Step 7: commit (istruzione per il developer, non eseguire)**

```bash
git add database/migrations/trail_registry src/TrailRegistry tests/Feature/TrailRegistry
git commit -m "feat(oc:8489): provenienza del codice e registrazione di quelli scritti nel nome"
```
