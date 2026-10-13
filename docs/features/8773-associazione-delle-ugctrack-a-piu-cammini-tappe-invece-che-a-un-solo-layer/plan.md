> Ticket: oc:8773

# Corrispondenza UgcTrack ↔ EcTrack per segmenti — piano wm-package

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** per ogni UgcTrack salvare quali segmenti di quali EcTrack copre, ricavarne i layer attraversati, mostrarli in Nova, e smettere di scrivere `properties->layer_id` sulle UgcTrack.

**Architecture:** due tabelle nuove (corrispondenza per coppia UgcTrack/EcTrack con gli indici dei segmenti coperti; stato del calcolo per UgcTrack). Un service con la SQL PostGIS di segmentazione e corrispondenza, tre job in coda dedicata (per UgcTrack, per EcTrack, per App), trigger espliciti da observer e dai percorsi SQL, un command per le tracce esistenti, un campo HTML e un filtro nella risorsa Nova UgcTrack, due parametri per App nella risorsa Nova App.

**Tech Stack:** Laravel 12, Nova 5, PostgreSQL + PostGIS (≥ 3.4 per `ST_LineSubstring` su geography), Pest.

**Spec:** [overview.md](overview.md) (questo cantiere) e, per la parte consumer, `docs/features/8773-associazione-delle-ugctrack-a-piu-cammini-tappe-invece-che-a-un-solo-layer/overview.md` di camminiditalia.

## Global Constraints

- PHP minimo `>8.1`: niente `const` dentro i trait.
- Le geometrie passano sempre da SQL puro, mai dall'ORM.
- Le traduzioni del package stanno in `resources/lang/*.json` (it, en, fr, es, de); chiavi in inglese, frasi specifiche (niente chiavi brevi condivise come "Layer").
- Mai `env()` nei modelli, sempre `config()`; i valori numerici letti da env vanno castati a `int`.
- Il tipo delle EcTrack in `layerables` si ricava con `getMorphClass()` del modello `config('wm-package.ec_track_model')`, mai scritto a mano.
- Default dei parametri: segmento 400 m, distanza massima 50 m. Limiti: segmento 50–2000 m, distanza massima 5–200 m.
- Coda dei job: `config('wm-package.ugc_track_matching.queue')`, default `ugc-track-matching`.
- Documentazione, commenti e commit in italiano; scope dei commit `feat(oc:8773): …`.
- **Nessun commit, push o branch automatico**: i comandi `git commit` qui sotto sono istruzioni per il dev, da eseguire solo dopo la sua approvazione.

## Review Focus

1. EcTrack fatta di più pezzi (MultiLineString con 2+ linee): gli indici dei segmenti devono essere unici per EcTrack e stabili fra due calcoli → test in Task 2.
2. UgcTrack con geometria a lunghezza zero (tutti i punti uguali): stato "calcolato", zero righe, nessuna eccezione → test in Task 2.
3. UgcTrack e EcTrack sovrapposte ma di App diverse: nessuna riga → test in Task 2.
4. Retry dell'app che aggiorna una UgcTrack esistente cambiando geometria: ricalcolo accodato → test in Task 4.
5. Layer cancellato con righe orfane in `layerables`: il campo Nova lo ignora, il filtro non lo propone → test in Task 7.

## Esecuzione dei test

Dalla root di camminiditalia (DB `wm_package` già presente nel Postgres del progetto):

```bash
docker run --rm --network camminiditalia_default -e DB_HOST=postgres-camminiditalia \
  -v "$PWD/wm-package:/app" -w /app wm-phpfpm:8.4 vendor/bin/pest <percorso-test>
```

Nel resto del piano questo comando è abbreviato in `PEST <percorso-test>`.

## Struttura dei file

| File | Responsabilità |
|---|---|
| `database/migrations/zz_2026_10_13_000001_create_ugc_track_matching_tables.php.stub` | tabelle `ugc_track_ec_track_matches` e `ugc_track_matching_runs` |
| `src/Models/UgcTrackEcTrackMatch.php` | modello della corrispondenza |
| `src/Models/UgcTrackMatchingRun.php` | modello dello stato del calcolo |
| `src/Services/Models/UgcTrackMatchingService.php` | parametri per App, SQL di segmentazione e corrispondenza, stato |
| `src/Jobs/MatchUgcTrackToEcTracksJob.php` | calcolo per una UgcTrack |
| `src/Jobs/MatchEcTrackToUgcTracksJob.php` | ricalcolo per una EcTrack contro le UgcTrack vicine |
| `src/Jobs/RematchAppUgcTracksJob.php` | accoda i job per le UgcTrack non aggiornate di un'App |
| `src/Commands/WmMatchUgcTracksCommand.php` | command per le tracce esistenti + pulizia `layer_id` |
| `src/Nova/Support/UgcTrackMatchesHtml.php` | HTML del campo Nova |
| `src/Nova/Filters/UgcTrackLayerFilter.php` | filtro per layer |
| `src/Observers/UgcObserver.php` | stop a `populateLayerId()` sulle UgcTrack |
| `src/Observers/UgcTrackGeometryCleanupObserver.php` | trigger alla creazione/cambio geometria |
| `src/Jobs/CleanUgcTrackGeometryJob.php` | trigger esplicito dopo l'UPDATE SQL |
| `src/Services/Models/EcTrackService.php` | trigger su creazione, cambio geometria, `reverse()` |
| `src/Observers/AppObserver.php` | trigger al cambio dei parametri |
| `src/Models/UgcTrack.php` | relazioni |
| `src/Nova/UgcTrack.php`, `src/Nova/App.php` | campo, filtro, parametri |
| `config/wm-package.php`, `config/wm-horizon.php` | parametri di default, supervisor della coda |
| `resources/lang/{it,en,fr,es,de}.json` | etichette |
| `tests/Feature/UgcTrackMatching/*.php` | test |

---

### Task 1: Migration, modelli e config

**Files:**
- Create: `database/migrations/zz_2026_10_13_000001_create_ugc_track_matching_tables.php.stub`
- Create: `src/Models/UgcTrackEcTrackMatch.php`, `src/Models/UgcTrackMatchingRun.php`
- Modify: `config/wm-package.php` (nuova chiave `ugc_track_matching`), `src/Models/UgcTrack.php` (relazioni)
- Test: `tests/Feature/UgcTrackMatching/MatchingSchemaTest.php`

**Interfaces:**
- Produces: tabelle `ugc_track_ec_track_matches(id, ugc_track_id, ec_track_id, app_id, matched_segments jsonb, total_segments int, segment_length_meters int, max_distance_meters int, timestamps)` con unique `(ugc_track_id, ec_track_id)`; `ugc_track_matching_runs(ugc_track_id PK, status, segment_length_meters, max_distance_meters, error, computed_at, timestamps)`; `UgcTrack::ecTrackMatches(): HasMany`, `UgcTrack::matchingRun(): HasOne`; costanti `UgcTrackMatchingRun::STATUS_COMPUTED = 'computed'`, `STATUS_FAILED = 'failed'`.

- [ ] **Step 1: Scrivi il test che fallisce**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;

it('crea le tabelle di corrispondenza e di stato con cancellazione a cascata', function () {
    expect(Schema::hasTable('ugc_track_ec_track_matches'))->toBeTrue();
    expect(Schema::hasTable('ugc_track_matching_runs'))->toBeTrue();

    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $track = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create(['user_id' => $user->id, 'app_id' => $app->id]));

    DB::table('ugc_track_matching_runs')->insert([
        'ugc_track_id' => $track->id, 'status' => 'computed', 'computed_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    expect($track->matchingRun)->not->toBeNull();

    DB::delete('DELETE FROM ugc_tracks WHERE id = ?', [$track->id]);
    expect(DB::table('ugc_track_matching_runs')->count())->toBe(0);
});

it('legge i parametri di default dalla config come interi', function () {
    expect(config('wm-package.ugc_track_matching.segment_length_meters'))->toBe(400);
    expect(config('wm-package.ugc_track_matching.max_distance_meters'))->toBe(50);
    expect(config('wm-package.ugc_track_matching.queue'))->toBe('ugc-track-matching');
});
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `PEST tests/Feature/UgcTrackMatching/MatchingSchemaTest.php`
Expected: FAIL, tabella `ugc_track_ec_track_matches` assente.

- [ ] **Step 3: Scrivi la migration stub**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quali segmenti di quale EcTrack copre ogni UgcTrack (oc:8773). matched_segments è l'elenco
        // ordinato degli indici coperti, numerati da 0 dall'inizio della EcTrack, unici anche
        // quando la EcTrack è fatta di più pezzi. I parametri usati stanno sulla riga: righe
        // calcolate con parametri diversi non si possono unire.
        if (! Schema::hasTable('ugc_track_ec_track_matches')) {
            Schema::create('ugc_track_ec_track_matches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ugc_track_id')->constrained('ugc_tracks')->cascadeOnDelete();
                $table->foreignId('ec_track_id')->constrained('ec_tracks')->cascadeOnDelete();
                $table->integer('app_id')->nullable();
                $table->jsonb('matched_segments');
                $table->integer('total_segments');
                $table->integer('segment_length_meters');
                $table->integer('max_distance_meters');
                $table->timestamps();

                $table->unique(['ugc_track_id', 'ec_track_id']);
                $table->index('ec_track_id');
            });
        }

        // Stato del calcolo per UgcTrack, anche quando la corrispondenza è zero: distingue
        // "nessuna EcTrack coperta" da "calcolo mai riuscito".
        if (! Schema::hasTable('ugc_track_matching_runs')) {
            Schema::create('ugc_track_matching_runs', function (Blueprint $table) {
                $table->foreignId('ugc_track_id')->primary()->constrained('ugc_tracks')->cascadeOnDelete();
                $table->string('status');
                $table->integer('segment_length_meters')->nullable();
                $table->integer('max_distance_meters')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('computed_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ugc_track_ec_track_matches');
        Schema::dropIfExists('ugc_track_matching_runs');
    }
};
```

- [ ] **Step 4: Modelli**

`src/Models/UgcTrackEcTrackMatch.php`:

```php
<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Segmenti di una EcTrack coperti da una UgcTrack (oc:8773). */
class UgcTrackEcTrackMatch extends Model
{
    protected $table = 'ugc_track_ec_track_matches';

    protected $guarded = [];

    protected $casts = [
        'matched_segments' => 'array',
    ];

    public function ugcTrack(): BelongsTo
    {
        return $this->belongsTo(UgcTrack::class);
    }

    public function ecTrack(): BelongsTo
    {
        return $this->belongsTo(config('wm-package.ec_track_model', EcTrack::class), 'ec_track_id');
    }
}
```

`src/Models/UgcTrackMatchingRun.php`:

```php
<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Model;

/** Stato del calcolo di corrispondenza di una UgcTrack (oc:8773). */
class UgcTrackMatchingRun extends Model
{
    public const STATUS_COMPUTED = 'computed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'ugc_track_matching_runs';

    protected $primaryKey = 'ugc_track_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'computed_at' => 'datetime',
    ];
}
```

In `src/Models/UgcTrack.php`, dopo `author()`:

```php
    /** Segmenti di EcTrack coperti da questa traccia (oc:8773). */
    public function ecTrackMatches(): HasMany
    {
        return $this->hasMany(UgcTrackEcTrackMatch::class);
    }

    /** Stato del calcolo di corrispondenza (oc:8773): null se non è mai stato fatto. */
    public function matchingRun(): HasOne
    {
        return $this->hasOne(UgcTrackMatchingRun::class);
    }
```

con gli `use Illuminate\Database\Eloquent\Relations\HasMany;` e `use Illuminate\Database\Eloquent\Relations\HasOne;`.

- [ ] **Step 5: Config**

In `config/wm-package.php`, dopo `'default_layer_mode'`:

```php
    /*
    | Corrispondenza UgcTrack ↔ EcTrack per segmenti (oc:8773). Default dei parametri per App: ogni
    | App li può sovrascrivere da Nova (properties ugc_track_matching_*). Cast espliciti: env()
    | restituisce stringhe.
    */
    'ugc_track_matching' => [
        'segment_length_meters' => (int) env('UGC_TRACK_MATCHING_SEGMENT_LENGTH_METERS', 400),
        'max_distance_meters' => (int) env('UGC_TRACK_MATCHING_MAX_DISTANCE_METERS', 50),
        'queue' => env('UGC_TRACK_MATCHING_QUEUE', 'ugc-track-matching'),
    ],
```

- [ ] **Step 6: Esegui il test e verifica che passi**

Run: `PEST tests/Feature/UgcTrackMatching/MatchingSchemaTest.php`
Expected: PASS.

- [ ] **Step 7: Commit (solo dopo ok del dev)**

```bash
git add database/migrations/zz_2026_10_13_000001_create_ugc_track_matching_tables.php.stub src/Models config/wm-package.php tests/Feature/UgcTrackMatching/MatchingSchemaTest.php
git commit -m "feat(oc:8773): tabelle di corrispondenza UgcTrack/EcTrack e stato del calcolo"
```

---

### Task 2: Service di segmentazione e corrispondenza

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-service-di-segmentazione-e-corrispondenza)

**Files:**
- Create: `src/Services/Models/UgcTrackMatchingService.php`
- Create: `tests/Feature/UgcTrackMatching/Helpers.php` (funzioni di test), `tests/Feature/UgcTrackMatching/UgcTrackMatchingServiceTest.php`

**Interfaces:**
- Consumes: tabelle e modelli del Task 1.
- Produces:
  - `UgcTrackMatchingService::make(): static` (da `Wm\WmPackage\Services\BaseService`);
  - `params(int $appId): array{segment_length_meters:int, max_distance_meters:int}`;
  - `matchUgcTrack(int $ugcTrackId): void` — sostituisce le righe della traccia e scrive lo stato `computed`;
  - `matchEcTrack(int $ecTrackId): void` — sostituisce le righe della EcTrack confrontandola con le UgcTrack vicine della stessa App;
  - `markFailed(int $ugcTrackId, string $error): void`;
  - `unmatchedUgcTrackIdsQuery(int $appId): \Illuminate\Database\Query\Builder` — UgcTrack dell'App senza stato, fallite o calcolate con parametri diversi da quelli attuali;
  - `ecTrackMorphClass(): string`;
  - `UgcTrack::matchedLayerIds(): array<int>` — id dei layer esistenti attraversati, ricavati via `layerables`.

- [ ] **Step 1: Funzioni di test condivise**

`tests/Feature/UgcTrackMatching/Helpers.php` (caricato con `require_once` dai test di questa cartella):

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

/** EcTrack con la geometria data in WKT 2D/3D (MULTILINESTRING), senza observer né catena di job. */
function matchingEcTrack(int $appId, string $wkt): int
{
    $track = EcTrack::withoutEvents(fn () => EcTrack::factory()->create(['app_id' => $appId]));
    DB::update('UPDATE ec_tracks SET geometry = ST_Force3D(ST_GeomFromText(?, 4326))::geography WHERE id = ?', [$wkt, $track->id]);

    return $track->id;
}

/** UgcTrack con la geometria data, senza observer. */
function matchingUgcTrack(int $userId, int $appId, string $wkt): int
{
    $track = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create(['user_id' => $userId, 'app_id' => $appId, 'properties' => []]));
    DB::update('UPDATE ugc_tracks SET geometry = ST_Force3D(ST_GeomFromText(?, 4326))::geography WHERE id = ?', [$wkt, $track->id]);

    return $track->id;
}

/** Associa una EcTrack a un layer in layerables, con il morph type del modello configurato. */
function matchingAttachToLayer(int $layerId, int $ecTrackId): void
{
    DB::table('layerables')->insert([
        'layer_id' => $layerId,
        'layerable_id' => $ecTrackId,
        'layerable_type' => UgcTrackMatchingService::make()->ecTrackMorphClass(),
    ]);
}

/** Indici coperti salvati per la coppia, o null se la riga non esiste. */
function matchedSegments(int $ugcTrackId, int $ecTrackId): ?array
{
    $row = DB::table('ugc_track_ec_track_matches')->where(['ugc_track_id' => $ugcTrackId, 'ec_track_id' => $ecTrackId])->first();

    return $row ? json_decode($row->matched_segments, true) : null;
}
```

Geometrie usate nei test (a latitudine 44°, 0,0036° di latitudine ≈ 400 m, 0,00025° di longitudine ≈ 20 m):
- EcTrack nord-sud lunga circa 2000 m: `MULTILINESTRING((11 44, 11 44.018))` → 6 segmenti (0–5, l'ultimo corto).
- UgcTrack parallela a 20 m sul primo chilometro: `MULTILINESTRING((11.00025 44, 11.00025 44.009))` → copre i segmenti 0, 1, 2 (il 2 va da 800 a 1200 m e contiene il punto finale della traccia, a 1000 m).

- [ ] **Step 2: Scrivi i test che falliscono**

`tests/Feature/UgcTrackMatching/UgcTrackMatchingServiceTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrackMatchingRun;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
    $this->service = UgcTrackMatchingService::make();
});

it('salva i segmenti coperti da una traccia parallela entro la distanza massima', function () {
    $ec = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44, 11.00025 44.009))');

    $this->service->matchUgcTrack($ugc);

    expect(matchedSegments($ugc, $ec))->toBe([0, 1, 2]);
    $row = DB::table('ugc_track_ec_track_matches')->where('ugc_track_id', $ugc)->first();
    expect($row->total_segments)->toBe(6);
    expect($row->segment_length_meters)->toBe(400);
    expect($row->max_distance_meters)->toBe(50);
    expect(UgcTrackMatchingRun::find($ugc)->status)->toBe(UgcTrackMatchingRun::STATUS_COMPUTED);
});

it('non salva righe per una traccia lontana ma registra il calcolo', function () {
    $ec = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.01 44, 11.01 44.009))');

    $this->service->matchUgcTrack($ugc);

    expect(matchedSegments($ugc, $ec))->toBeNull();
    expect(UgcTrackMatchingRun::find($ugc)->status)->toBe(UgcTrackMatchingRun::STATUS_COMPUTED);
});

it('ignora le EcTrack di un\'altra App', function () {
    $other = App::factory()->createQuietly();
    $ec = matchingEcTrack($other->id, 'MULTILINESTRING((11 44, 11 44.018))');
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44, 11.00025 44.009))');

    $this->service->matchUgcTrack($ugc);

    expect(matchedSegments($ugc, $ec))->toBeNull();
});

it('numera i segmenti in modo unico su una EcTrack fatta di due pezzi, e stabile fra due calcoli', function () {
    // Primo pezzo ~800 m (segmenti 0,1), secondo pezzo ~800 m più a nord (segmenti 2,3).
    $ec = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.0072),(11 44.0100, 11 44.0172))');
    // La traccia copre solo il secondo pezzo.
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44.0100, 11.00025 44.0172))');

    $this->service->matchUgcTrack($ugc);
    $first = matchedSegments($ugc, $ec);
    $this->service->matchUgcTrack($ugc);

    expect($first)->toBe([2, 3]);
    expect(matchedSegments($ugc, $ec))->toBe($first);
    expect(DB::table('ugc_track_ec_track_matches')->where('ugc_track_id', $ugc)->value('total_segments'))->toBe(4);
});

it('tratta una traccia di lunghezza zero come calcolata senza righe', function () {
    matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44.001, 11 44.001))');

    $this->service->matchUgcTrack($ugc);

    expect(DB::table('ugc_track_ec_track_matches')->where('ugc_track_id', $ugc)->count())->toBe(0);
    expect(UgcTrackMatchingRun::find($ugc)->status)->toBe(UgcTrackMatchingRun::STATUS_COMPUTED);
});

it('usa i parametri della App quando sono impostati', function () {
    $this->app_->properties = array_merge($this->app_->properties ?? [], [
        'ugc_track_matching_segment_length_meters' => 1000,
        'ugc_track_matching_max_distance_meters' => 10,
    ]);
    $this->app_->saveQuietly();

    expect($this->service->params($this->app_->id))->toBe(['segment_length_meters' => 1000, 'max_distance_meters' => 10]);
});

it('ricalcola una EcTrack contro le UgcTrack vicine anche se non avevano righe', function () {
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44, 11.00025 44.009))');
    $this->service->matchUgcTrack($ugc); // nessuna EcTrack ancora: zero righe
    $ec = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');

    $this->service->matchEcTrack($ec);

    expect(matchedSegments($ugc, $ec))->toBe([0, 1, 2]);
});

it('elenca come da calcolare le tracce senza stato, fallite o con parametri vecchi', function () {
    $never = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44, 11 44.001))');
    $failed = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44, 11 44.001))');
    $stale = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44, 11 44.001))');
    $fresh = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44, 11 44.001))');

    $this->service->markFailed($failed, 'timeout');
    $this->service->matchUgcTrack($stale);
    DB::table('ugc_track_matching_runs')->where('ugc_track_id', $stale)->update(['segment_length_meters' => 300]);
    $this->service->matchUgcTrack($fresh);

    $ids = $this->service->unmatchedUgcTrackIdsQuery($this->app_->id)->pluck('id')->all();
    sort($ids);
    expect($ids)->toBe([$never, $failed, $stale]);
});
```

- [ ] **Step 3: Esegui i test e verifica che falliscano**

Run: `PEST tests/Feature/UgcTrackMatching/UgcTrackMatchingServiceTest.php`
Expected: FAIL, classe `UgcTrackMatchingService` non trovata.

- [ ] **Step 4: Implementa il service**

```php
<?php

namespace Wm\WmPackage\Services\Models;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\UgcTrackMatchingRun;
use Wm\WmPackage\Services\BaseService;

/**
 * Corrispondenza UgcTrack ↔ EcTrack per segmenti (oc:8773).
 *
 * Ogni EcTrack è divisa in segmenti di lunghezza fissa misurati su geography, numerati da 0
 * dall'inizio della geometria e in modo unico anche quando la EcTrack è fatta di più pezzi
 * (l'indice continua da un pezzo al successivo, nell'ordine in cui sono salvati). Un segmento è
 * coperto da una UgcTrack se, preso come linea continua, passa entro la distanza massima dalla traccia
 * (ST_DWithin su geography). È la regola del passaporto di Cammini d'Italia (oc:8165) presa
 * traccia per traccia: l'unione delle tracce di un utente copre un segmento se e solo se almeno
 * una traccia lo copre, quindi il consumer ottiene la corrispondenza per utente unendo gli indici.
 */
class UgcTrackMatchingService extends BaseService
{
    public const SEGMENT_LENGTH_KEY = 'ugc_track_matching_segment_length_meters';

    public const MAX_DISTANCE_KEY = 'ugc_track_matching_max_distance_meters';

    public const SEGMENT_LENGTH_MIN = 50;

    public const SEGMENT_LENGTH_MAX = 2000;

    public const MAX_DISTANCE_MIN = 5;

    public const MAX_DISTANCE_MAX = 200;

    /** @return array{segment_length_meters:int, max_distance_meters:int} */
    public function params(int $appId): array
    {
        $properties = App::query()->whereKey($appId)->value('properties');
        $properties = is_array($properties) ? $properties : (json_decode((string) $properties, true) ?? []);

        return [
            'segment_length_meters' => $this->numeric($properties[self::SEGMENT_LENGTH_KEY] ?? null, 'segment_length_meters'),
            'max_distance_meters' => $this->numeric($properties[self::MAX_DISTANCE_KEY] ?? null, 'max_distance_meters'),
        ];
    }

    public function ecTrackMorphClass(): string
    {
        $class = config('wm-package.ec_track_model', EcTrack::class);

        return class_exists($class) ? (new $class)->getMorphClass() : $class;
    }

    public function matchUgcTrack(int $ugcTrackId): void
    {
        $track = DB::table('ugc_tracks')->where('id', $ugcTrackId)->first(['id', 'app_id']);
        if (! $track) {
            return;
        }
        $params = $this->params((int) $track->app_id);

        DB::transaction(function () use ($ugcTrackId, $params) {
            DB::table('ugc_track_ec_track_matches')->where('ugc_track_id', $ugcTrackId)->delete();

            DB::statement($this->insertSql(
                ugcSource: 'SELECT g.id, g.app_id, g.geometry FROM ugc_tracks g WHERE g.id = :ugc',
                ecSource: 'SELECT e.id, e.geometry FROM '.$this->ecTable().' e, u WHERE e.app_id = u.app_id AND ST_DWithin(e.geometry, u.geometry, :max_distance)',
            ), ['ugc' => $ugcTrackId, 'seg' => $params['segment_length_meters'], 'distanza massima' => $params['max_distance_meters']]);

            $this->markComputed($ugcTrackId, $params);
        });
    }

    public function matchEcTrack(int $ecTrackId): void
    {
        $appId = DB::table($this->ecTable())->where('id', $ecTrackId)->value('app_id');
        if ($appId === null) {
            return;
        }
        $params = $this->params((int) $appId);

        DB::transaction(function () use ($ecTrackId, $params) {
            DB::table('ugc_track_ec_track_matches')->where('ec_track_id', $ecTrackId)->delete();

            DB::statement($this->insertSql(
                ugcSource: 'SELECT g.id, g.app_id, g.geometry FROM ugc_tracks g, '.$this->ecTable().' t'
                    .' WHERE t.id = :ec AND g.app_id = t.app_id AND ST_DWithin(g.geometry, t.geometry, :max_distance)',
                ecSource: 'SELECT e.id, e.geometry FROM '.$this->ecTable().' e WHERE e.id = :ec',
            ), ['ec' => $ecTrackId, 'seg' => $params['segment_length_meters'], 'distanza massima' => $params['max_distance_meters']]);
        });
    }

    public function markFailed(int $ugcTrackId, string $error): void
    {
        if (! DB::table('ugc_tracks')->where('id', $ugcTrackId)->exists()) {
            return;
        }

        UgcTrackMatchingRun::query()->updateOrCreate(['ugc_track_id' => $ugcTrackId], [
            'status' => UgcTrackMatchingRun::STATUS_FAILED,
            'segment_length_meters' => null,
            'max_distance_meters' => null,
            'error' => mb_substr($error, 0, 2000),
            'computed_at' => now(),
        ]);
    }

    public function unmatchedUgcTrackIdsQuery(int $appId): Builder
    {
        $params = $this->params($appId);

        return DB::table('ugc_tracks as g')
            ->leftJoin('ugc_track_matching_runs as r', 'r.ugc_track_id', '=', 'g.id')
            ->where('g.app_id', $appId)
            ->where(function ($q) use ($params) {
                $q->whereNull('r.ugc_track_id')
                    ->orWhere('r.status', UgcTrackMatchingRun::STATUS_FAILED)
                    ->orWhere('r.segment_length_meters', '<>', $params['segment_length_meters'])
                    ->orWhere('r.max_distance_meters', '<>', $params['max_distance_meters']);
            })
            ->orderBy('g.id')
            ->select('g.id');
    }

    /**
     * INSERT delle righe di corrispondenza. ugcSource definisce il CTE `u` (UgcTrack candidate),
     * ecSource il CTE `t` (EcTrack candidate, può riferirsi a `u`). Le tracce a lunghezza zero
     * restano fuori senza errori.
     */
    private function insertSql(string $ugcSource, string $ecSource): string
    {
        return <<<SQL
            WITH u AS (
                SELECT x.id, x.app_id, x.geometry FROM ({$ugcSource}) x
                WHERE ST_Length(x.geometry) > 0
            ),
            t AS ({$ecSource}),
            parts AS (
                SELECT t.id, d.path[1] AS part, d.geom AS l
                FROM t, LATERAL ST_Dump(ST_Force2D(t.geometry::geometry)) d
            ),
            lens AS (
                SELECT id, part, l, ST_Length(l::geography) AS len FROM parts WHERE ST_Length(l::geography) > 0
            ),
            counted AS (
                SELECT id, part, l, len, CEIL(len / :seg)::int AS n,
                       COALESCE(SUM(CEIL(len / :seg)::int) OVER (
                           PARTITION BY id ORDER BY part ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
                       ), 0)::int AS offs
                FROM lens
            ),
            totals AS (SELECT id, SUM(n)::int AS total FROM counted GROUP BY id),
            segs AS (
                SELECT c.id, c.offs + i AS idx,
                       ST_LineSubstring(c.l::geography, i * :seg / c.len, LEAST((i + 1) * :seg / c.len, 1)) AS s
                FROM counted c CROSS JOIN LATERAL generate_series(0, c.n - 1) AS i
            )
            INSERT INTO ugc_track_ec_track_matches
                (ugc_track_id, ec_track_id, app_id, matched_segments, total_segments, segment_length_meters, max_distance_meters, created_at, updated_at)
            SELECT u.id, s.id, u.app_id, jsonb_agg(s.idx ORDER BY s.idx), tt.total, :seg, :max_distance, NOW(), NOW()
            FROM segs s
            JOIN totals tt ON tt.id = s.id
            JOIN u ON ST_DWithin(s.s, u.geometry, :max_distance)
            GROUP BY u.id, s.id, u.app_id, tt.total
        SQL;
    }

    private function markComputed(int $ugcTrackId, array $params): void
    {
        UgcTrackMatchingRun::query()->updateOrCreate(['ugc_track_id' => $ugcTrackId], [
            'status' => UgcTrackMatchingRun::STATUS_COMPUTED,
            'segment_length_meters' => $params['segment_length_meters'],
            'max_distance_meters' => $params['max_distance_meters'],
            'error' => null,
            'computed_at' => now(),
        ]);
    }

    private function numeric(mixed $value, string $configKey): int
    {
        return is_numeric($value) && (int) $value > 0
            ? (int) $value
            : (int) config("wm-package.ugc_track_matching.{$configKey}");
    }

    private function ecTable(): string
    {
        return config('wm-package.ec_track_table', 'ec_tracks');
    }
}
```

Nota sulla SQL: i parametri con nome ripetuti (`:seg`, `:max_distance`) funzionano con pdo_pgsql, come nello stash di oc:8165 (`il service del passaporto`). Se il driver li rifiuta, trasformali in segnaposti posizionali ripetendo i valori.

- [ ] **Step 5: Layer ricavati sul modello**

Aggiungi il test in `UgcTrackMatchingServiceTest.php`:

```php
it('ricava i layer attraversati via layerables, ignorando i layer inesistenti', function () {
    $layer = \Wm\WmPackage\Models\Layer::factory()->createQuietly(['app_id' => $this->app_->id]);
    $ec = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');
    matchingAttachToLayer($layer->id, $ec);
    matchingAttachToLayer(999999, $ec);
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44, 11.00025 44.009))');
    $this->service->matchUgcTrack($ugc);

    expect(\Wm\WmPackage\Models\UgcTrack::find($ugc)->matchedLayerIds())->toBe([$layer->id]);
});
```

e in `src/Models/UgcTrack.php` (aggiungi `use Wm\WmPackage\Services\Models\UgcTrackMatchingService;`; `DB` è già importato):

```php
    /**
     * Id dei layer attraversati (oc:8773), ricavati dalla corrispondenza per segmenti e da layerables;
     * le righe di layerables che puntano a layer cancellati sono ignorate.
     *
     * @return array<int, int>
     */
    public function matchedLayerIds(): array
    {
        return DB::table('ugc_track_ec_track_matches as c')
            ->join('layerables as lb', function ($join) {
                $join->on('lb.layerable_id', '=', 'c.ec_track_id')
                    ->where('lb.layerable_type', UgcTrackMatchingService::make()->ecTrackMorphClass());
            })
            ->join('layers as l', 'l.id', '=', 'lb.layer_id')
            ->where('c.ugc_track_id', $this->id)
            ->distinct()->orderBy('lb.layer_id')
            ->pluck('lb.layer_id')->map(fn ($id) => (int) $id)->all();
    }
```

- [ ] **Step 6: Esegui i test e verifica che passino**

Run: `PEST tests/Feature/UgcTrackMatching/UgcTrackMatchingServiceTest.php`
Expected: PASS (9 test).

- [ ] **Step 7: Commit (solo dopo ok del dev)**

```bash
git add src/Services/Models/UgcTrackMatchingService.php src/Models/UgcTrack.php tests/Feature/UgcTrackMatching
git commit -m "feat(oc:8773): calcolo dei segmenti di EcTrack coperti da una UgcTrack"
```

---

### Task 3: Job e coda dedicata

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-job-e-coda-dedicata)

**Files:**
- Create: `src/Jobs/MatchUgcTrackToEcTracksJob.php`, `src/Jobs/MatchEcTrackToUgcTracksJob.php`, `src/Jobs/RematchAppUgcTracksJob.php`
- Modify: `config/wm-horizon.php` (supervisor della coda)
- Test: `tests/Feature/UgcTrackMatching/MatchingJobsTest.php`

**Interfaces:**
- Consumes: `UgcTrackMatchingService` (Task 2).
- Produces: `MatchUgcTrackToEcTracksJob(int $ugcTrackId)`, `MatchEcTrackToUgcTracksJob(int $ecTrackId)`, `RematchAppUgcTracksJob(int $appId)`; tutti su `config('wm-package.ugc_track_matching.queue')`.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\Jobs\MatchUgcTrackToEcTracksJob;
use Wm\WmPackage\Jobs\RematchAppUgcTracksJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrackMatchingRun;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
});

it('il job per traccia gira sulla coda dedicata ed è unico per traccia', function () {
    $job = new MatchUgcTrackToEcTracksJob(42);

    expect($job->queue)->toBe('ugc-track-matching');
    expect($job->uniqueId())->toBe('42');
});

it('se il job fallisce lo stato della traccia diventa failed con il motivo', function () {
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44, 11 44.001))');

    (new MatchUgcTrackToEcTracksJob($ugc))->failed(new RuntimeException('timeout PostGIS'));

    $run = UgcTrackMatchingRun::find($ugc);
    expect($run->status)->toBe(UgcTrackMatchingRun::STATUS_FAILED);
    expect($run->error)->toContain('timeout PostGIS');
});

it('il job per App accoda solo le tracce da calcolare', function () {
    Bus::fake([MatchUgcTrackToEcTracksJob::class]);
    $todo = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44, 11 44.001))');
    $done = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44, 11 44.001))');
    UgcTrackMatchingService::make()->matchUgcTrack($done);

    (new RematchAppUgcTracksJob($this->app_->id))->handle(UgcTrackMatchingService::make());

    Bus::assertDispatched(MatchUgcTrackToEcTracksJob::class, fn ($j) => $j->ugcTrackId === $todo);
    Bus::assertNotDispatched(MatchUgcTrackToEcTracksJob::class, fn ($j) => $j->ugcTrackId === $done);
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `PEST tests/Feature/UgcTrackMatching/MatchingJobsTest.php`
Expected: FAIL, classi job non trovate.

- [ ] **Step 3: Implementa i job**

`src/Jobs/MatchUgcTrackToEcTracksJob.php`:

```php
<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

/** Calcola la corrispondenza di una UgcTrack (oc:8773). Unico per traccia finché non parte. */
class MatchUgcTrackToEcTracksJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $ugcTrackId)
    {
        $this->onQueue(config('wm-package.ugc_track_matching.queue', 'ugc-track-matching'));
    }

    public function uniqueId(): string
    {
        return (string) $this->ugcTrackId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("ugc-track-matching:{$this->ugcTrackId}"))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(UgcTrackMatchingService $service): void
    {
        $service->matchUgcTrack($this->ugcTrackId);
    }

    public function failed(?Throwable $exception): void
    {
        UgcTrackMatchingService::make()->markFailed($this->ugcTrackId, $exception?->getMessage() ?? 'unknown error');
    }
}
```

`src/Jobs/MatchEcTrackToUgcTracksJob.php`: stessa struttura, con `public int $ecTrackId`, chiave unica e lock `ugc-track-matching-ec:{$this->ecTrackId}`, `handle()` che chiama `$service->matchEcTrack($this->ecTrackId)` e nessun `failed()` (lo stato è per UgcTrack; un fallimento resta nei failed job di Horizon).

`src/Jobs/RematchAppUgcTracksJob.php`:

```php
<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

/**
 * Accoda il calcolo per le UgcTrack di un'App non aggiornate (oc:8773): mai calcolate, fallite o
 * calcolate con parametri diversi da quelli attuali. Unico per App: più cambi di parametri di
 * fila producono un solo giro, e ogni job per traccia legge i parametri quando gira.
 */
class RematchAppUgcTracksJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600;

    public function __construct(public int $appId)
    {
        $this->onQueue(config('wm-package.ugc_track_matching.queue', 'ugc-track-matching'));
    }

    public function uniqueId(): string
    {
        return (string) $this->appId;
    }

    public function handle(UgcTrackMatchingService $service): void
    {
        $service->unmatchedUgcTrackIdsQuery($this->appId)->chunk(500, function ($rows) {
            foreach ($rows as $row) {
                MatchUgcTrackToEcTracksJob::dispatch((int) $row->id);
            }
        });
    }
}
```

- [ ] **Step 4: Supervisor Horizon**

In `config/wm-horizon.php`, dentro `'defaults'`, dopo `'supervisor-layers'`:

```php
        // Corrispondenza UgcTrack ↔ EcTrack (oc:8773): coda separata, un ricalcolo di massa non
        // ritarda mail, DEM e import.
        'supervisor-ugc-track-matching' => [
            'connection' => 'redis',
            'queue' => ['ugc-track-matching'],
            'balance' => 'simple',
            'maxProcesses' => 2,
            'maxTime' => 3600,
            'maxJobs' => 1000,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 300,
            'nice' => 0,
        ],
```

Controlla in `config/wm-horizon.php` come viene definito il `'timeout'` di `redis` in `queue.connections` (`retry_after`): deve restare maggiore di 300, altrimenti alza il `retry_after` della connessione usata da questa coda e annotalo in `notes.md`.

- [ ] **Step 5: Esegui i test e verifica che passino**

Run: `PEST tests/Feature/UgcTrackMatching/MatchingJobsTest.php`
Expected: PASS.

- [ ] **Step 6: Commit (solo dopo ok del dev)**

```bash
git add src/Jobs/MatchUgcTrackToEcTracksJob.php src/Jobs/MatchEcTrackToUgcTracksJob.php src/Jobs/RematchAppUgcTracksJob.php config/wm-horizon.php tests/Feature/UgcTrackMatching/MatchingJobsTest.php
git commit -m "feat(oc:8773): job di corrispondenza su coda dedicata"
```

---

### Task 4: Trigger lato UgcTrack e stop a `layer_id`

**Files:**
- Modify: `src/Observers/UgcObserver.php:24-31` (`created()`)
- Modify: `src/Observers/UgcTrackGeometryCleanupObserver.php:44` (`saved()`)
- Modify: `src/Jobs/CleanUgcTrackGeometryJob.php:52`
- Test: `tests/Feature/UgcTrackMatching/UgcTrackTriggersTest.php`

**Interfaces:**
- Consumes: `MatchUgcTrackToEcTracksJob` (Task 3).

- [ ] **Step 1: Scrivi i test che falliscono**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Jobs\CleanUgcTrackGeometryJob;
use Wm\WmPackage\Jobs\MatchUgcTrackToEcTracksJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\UgcTrackCleanupService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    Bus::fake();
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
});

it('una UgcTrack nuova accoda il calcolo e non riceve layer_id', function () {
    $track = UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'nuova']]);

    Bus::assertDispatched(MatchUgcTrackToEcTracksJob::class, fn ($j) => $j->ugcTrackId === $track->id);
    expect($track->refresh()->properties)->not->toHaveKey('layer_id');
});

it('un aggiornamento che cambia la geometria accoda il ricalcolo (retry dell\'app)', function () {
    $track = UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => []]);
    Bus::fake();

    $track->geometry = DB::raw("ST_GeomFromText('MULTILINESTRING Z ((11 44 0, 11 44.01 0))', 4326)::geography");
    $track->save();

    Bus::assertDispatched(MatchUgcTrackToEcTracksJob::class, fn ($j) => $j->ugcTrackId === $track->id);
});

it('il job di pulizia GPS accoda il ricalcolo dopo l\'UPDATE SQL', function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 40.0);
    $locations = [
        ['time' => 0, 'latitude' => 43.0, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0],
        ['time' => 1000, 'latitude' => 43.5, 'longitude' => 13.5, 'accuracy' => 3000.0, 'altitude' => 10.0],
        ['time' => 2000, 'latitude' => 43.0001, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0],
    ];
    $track = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create([
        'user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['locations' => $locations],
    ]));
    DB::update("UPDATE ugc_tracks SET geometry = ST_GeomFromEWKT('SRID=4326;MULTILINESTRING Z ((13 43 10, 13.5 43.5 10, 13 43.0001 10))') WHERE id = ?", [$track->id]);

    (new CleanUgcTrackGeometryJob($track->id))->handle(app(UgcTrackCleanupService::class));

    Bus::assertDispatched(MatchUgcTrackToEcTracksJob::class, fn ($j) => $j->ugcTrackId === $track->id);
});

it('gli UgcPoi continuano a ricevere layer_id come prima', function () {
    // Stesso scenario del test esistente su populateLayerId per gli UgcPoi: un layer con una
    // EcTrack vicina al punto; dopo la creazione properties.layer_id è valorizzato.
    // Copia qui il setup del test di UgcObserver/UgcService già presente nella suite (cerca
    // "resolveLayer" in tests/) e verifica che passi invariato.
})->todo();
```

Prima di eseguire, sostituisci il test `->todo()` con il setup reale: cerca in `tests/` il test esistente che copre `UgcService::resolveLayer()`/`populateLayerId()` per gli UgcPoi e riusane la preparazione; se non esiste, crea un layer con una EcTrack (`matchingEcTrack` + `matchingAttachToLayer`) e un `UgcPoi::factory()->create()` con geometria a 10 m dalla EcTrack, e verifica `properties['layer_id'] === $layer->id`.

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `PEST tests/Feature/UgcTrackMatching/UgcTrackTriggersTest.php`
Expected: FAIL (nessun `MatchUgcTrackToEcTracksJob` accodato; `layer_id` presente se una EcTrack è vicina).

- [ ] **Step 3: Stop a `populateLayerId()` per le UgcTrack**

In `src/Observers/UgcObserver.php`:

```php
    public function created(Model $model): void
    {
        if (! $model instanceof GeometryModel) {
            return;
        }

        // oc:8773: una UgcTrack attraversa più layer, quindi non ha un layer_id singolo; i layer si
        // ricavano dalla corrispondenza per segmenti (UgcTrackMatchingService). Gli UgcPoi restano
        // invariati.
        if ($model instanceof UgcTrack) {
            return;
        }

        $this->populateLayerId($model);
    }
```

- [ ] **Step 4: Trigger alla creazione e al cambio di geometria**

In `src/Observers/UgcTrackGeometryCleanupObserver.php`, all'inizio di `saved()`:

```php
    public function saved(UgcTrack $track): void
    {
        // oc:8773: corrispondenza per segmenti, dopo il commit, su traccia nuova o con geometria
        // cambiata (compreso il retry dell'app che aggiorna per uuid, oc:8718).
        if ($track->wasRecentlyCreated || $track->wasChanged('geometry')) {
            MatchUgcTrackToEcTracksJob::dispatch($track->id)->afterCommit();
        }

        $stats = $track->properties['stats'] ?? null;
        // … resto invariato
```

- [ ] **Step 5: Trigger esplicito dopo la pulizia GPS**

In `src/Jobs/CleanUgcTrackGeometryJob.php`, alla riga 52, accanto a `UpdateModelWithGeometryTaxonomyWhere::dispatch($fresh);`:

```php
            UpdateModelWithGeometryTaxonomyWhere::dispatch($fresh);
            // oc:8773: l'UPDATE SQL qui sopra non fa scattare gli observer.
            MatchUgcTrackToEcTracksJob::dispatch($fresh->id);
```

- [ ] **Step 6: Esegui i test e verifica che passino**

Run: `PEST tests/Feature/UgcTrackMatching/UgcTrackTriggersTest.php tests/Feature/WmCleanUgcTrackGeometryCommandTest.php`
Expected: PASS (anche i test esistenti della pulizia GPS).

- [ ] **Step 7: Commit (solo dopo ok del dev)**

```bash
git add src/Observers/UgcObserver.php src/Observers/UgcTrackGeometryCleanupObserver.php src/Jobs/CleanUgcTrackGeometryJob.php tests/Feature/UgcTrackMatching/UgcTrackTriggersTest.php
git commit -m "feat(oc:8773): calcolo di corrispondenza alla creazione e al cambio di geometria delle UgcTrack, niente layer_id"
```

---

### Task 5: Trigger lato EcTrack e lato App

**Files:**
- Modify: `src/Services/Models/EcTrackService.php` (`createDataChain()` riga 336, `updateDataChain()` riga 615, `reverse()` riga 485)
- Modify: `src/Observers/AppObserver.php:28` (`saved()`)
- Test: `tests/Feature/UgcTrackMatching/EcTrackAndAppTriggersTest.php`

**Interfaces:**
- Consumes: `MatchEcTrackToUgcTracksJob`, `RematchAppUgcTracksJob` (Task 3); costanti `UgcTrackMatchingService::SEGMENT_LENGTH_KEY`/`MAX_DISTANCE_KEY` (Task 2).
- Produces: `EcTrackService::dispatchUgcTrackMatching(EcTrack $track): void`.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\Jobs\MatchEcTrackToUgcTracksJob;
use Wm\WmPackage\Jobs\RematchAppUgcTracksJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Models\EcTrackService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    Bus::fake();
    $this->app_ = App::factory()->createQuietly();
});

it('createDataChain accoda il ricalcolo della EcTrack fuori dalla catena', function () {
    $id = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');

    app(EcTrackService::class)->createDataChain(EcTrack::find($id));

    Bus::assertDispatched(MatchEcTrackToUgcTracksJob::class, fn ($j) => $j->ecTrackId === $id);
});

it('reverse della geometria accoda il ricalcolo', function () {
    $id = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');

    app(EcTrackService::class)->reverse(EcTrack::find($id), true, []);

    Bus::assertDispatched(MatchEcTrackToUgcTracksJob::class, fn ($j) => $j->ecTrackId === $id);
});

it('cambiare segmento o distanza massima della App accoda un solo ricalcolo per App', function () {
    $this->app_->properties = array_merge($this->app_->properties ?? [], ['ugc_track_matching_segment_length_meters' => 500]);
    $this->app_->save();

    Bus::assertDispatched(RematchAppUgcTracksJob::class, fn ($j) => $j->appId === $this->app_->id);
});

it('salvare la App senza toccare i parametri non accoda nulla', function () {
    $this->app_->properties = array_merge($this->app_->properties ?? [], ['analytics_app_enabled' => true]);
    $this->app_->save();

    Bus::assertNotDispatched(RematchAppUgcTracksJob::class);
});
```

Prima di eseguire, apri `reverse()` (riga 485) e verifica la firma reale (`reverse(EcTrack $track, bool $geometry, array $swaps)`): se richiede `$swaps` non vuoto o un formato preciso, adatta l'argomento del test.

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `PEST tests/Feature/UgcTrackMatching/EcTrackAndAppTriggersTest.php`
Expected: FAIL.

- [ ] **Step 3: Trigger in `EcTrackService`**

Aggiungi il metodo:

```php
    /**
     * Ricalcolo della corrispondenza UgcTrack di questa EcTrack (oc:8773), accodato fuori dalla
     * catena: un job della catena che fallisce (DEM, PBF) non deve lasciare la corrispondenza vecchia.
     */
    public function dispatchUgcTrackMatching(EcTrack $track): void
    {
        MatchEcTrackToUgcTracksJob::dispatch($track->id)->afterCommit();
    }
```

e chiamalo:
- in `createDataChain()`, subito dopo `Bus::chain($chain)->dispatch();`;
- in `updateDataChain()`, dentro `if ($track->wasChanged('geometry')) { … }`;
- in `reverse()`, nel ramo in cui `$geometry` è true, subito dopo `Bus::chain($jobs)->dispatch();`.

`ApproveTrailApplication` chiama `createDataChain()` (riga 93): è già coperto.

- [ ] **Step 4: Trigger in `AppObserver::saved()`**

```php
    public function saved(App $app)
    {
        // … codice esistente invariato …

        // oc:8773: un cambio di segmento o distanza massima ricalcola le UgcTrack dell'App.
        $before = $app->getOriginal('properties') ?? [];
        $before = is_array($before) ? $before : (json_decode((string) $before, true) ?? []);
        $after = $app->properties ?? [];
        foreach ([UgcTrackMatchingService::SEGMENT_LENGTH_KEY, UgcTrackMatchingService::MAX_DISTANCE_KEY] as $key) {
            if (($before[$key] ?? null) != ($after[$key] ?? null)) {
                RematchAppUgcTracksJob::dispatch($app->id)->afterCommit();
                break;
            }
        }
    }
```

Se in `saved()` `getOriginal()` restituisce già i valori nuovi (sync dopo il save), usa `getRawOriginal()` oppure sposta il confronto in un `saving()` che memorizza un flag sul modello, e annotalo in `notes.md`.

- [ ] **Step 5: Esegui i test e verifica che passino**

Run: `PEST tests/Feature/UgcTrackMatching/EcTrackAndAppTriggersTest.php`
Expected: PASS.

- [ ] **Step 6: Commit (solo dopo ok del dev)**

```bash
git add src/Services/Models/EcTrackService.php src/Observers/AppObserver.php tests/Feature/UgcTrackMatching/EcTrackAndAppTriggersTest.php
git commit -m "feat(oc:8773): ricalcolo di corrispondenza su EcTrack nuove, modificate o invertite e al cambio dei parametri dell'App"
```

---

### Task 6: Command per le tracce esistenti

**Files:**
- Create: `src/Commands/WmMatchUgcTracksCommand.php`
- Modify: `src/WmPackageServiceProvider.php` (registrazione del command, accanto a `WmCleanUgcTrackGeometryCommand`)
- Test: `tests/Feature/UgcTrackMatching/MatchUgcTracksCommandTest.php`

**Interfaces:**
- Consumes: `unmatchedUgcTrackIdsQuery()` (Task 2), `MatchUgcTrackToEcTracksJob` (Task 3).
- Produces: `php artisan wm:match-ugc-tracks {--dry-run} {--app=}`.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Jobs\MatchUgcTrackToEcTracksJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    Bus::fake();
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
});

function trackWithLayerId(int $userId, int $appId): int
{
    $id = matchingUgcTrack($userId, $appId, 'MULTILINESTRING((11 44, 11 44.001))');
    DB::update("UPDATE ugc_tracks SET properties = properties || '{\"layer_id\": 7, \"layer_id_auto_resolved\": true, \"name\": \"x\"}'::jsonb WHERE id = ?", [$id]);

    return $id;
}

it('con --dry-run conta senza scrivere né accodare', function () {
    $id = trackWithLayerId($this->user->id, $this->app_->id);

    $this->artisan('wm:match-ugc-tracks', ['--dry-run' => true])
        ->expectsOutputToContain('1 tracce da calcolare')
        ->expectsOutputToContain('1 tracce con layer_id da togliere')
        ->assertSuccessful();

    Bus::assertNotDispatched(MatchUgcTrackToEcTracksJob::class);
    expect(DB::table('ugc_tracks')->where('id', $id)->value('properties'))->toContain('layer_id');
});

it('senza --dry-run toglie layer_id e accoda il calcolo, lasciando le altre properties', function () {
    $id = trackWithLayerId($this->user->id, $this->app_->id);

    $this->artisan('wm:match-ugc-tracks')->assertSuccessful();

    $properties = json_decode(DB::table('ugc_tracks')->where('id', $id)->value('properties'), true);
    expect($properties)->not->toHaveKey('layer_id');
    expect($properties)->not->toHaveKey('layer_id_auto_resolved');
    expect($properties['name'])->toBe('x');
    Bus::assertDispatched(MatchUgcTrackToEcTracksJob::class, fn ($j) => $j->ugcTrackId === $id);
});

it('è idempotente: una traccia già calcolata con i parametri attuali non viene riaccodata', function () {
    $id = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44, 11 44.001))');
    UgcTrackMatchingService::make()->matchUgcTrack($id);

    $this->artisan('wm:match-ugc-tracks')->assertSuccessful();

    Bus::assertNotDispatched(MatchUgcTrackToEcTracksJob::class);
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `PEST tests/Feature/UgcTrackMatching/MatchUgcTracksCommandTest.php`
Expected: FAIL, command inesistente.

- [ ] **Step 3: Implementa il command**

```php
<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Jobs\MatchUgcTrackToEcTracksJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

/**
 * Corrispondenza per segmenti delle UgcTrack esistenti (oc:8773): toglie properties.layer_id e
 * layer_id_auto_resolved dalle UgcTrack e accoda il calcolo per quelle mai calcolate, fallite o
 * calcolate con parametri diversi da quelli attuali della loro App. Da lanciare dopo il backup
 * e dopo la pulizia GPS (wm:clean-ugc-track-geometry).
 */
class WmMatchUgcTracksCommand extends Command
{
    protected $signature = 'wm:match-ugc-tracks
        {--dry-run : conta senza scrivere né accodare}
        {--app= : id di una sola App}';

    protected $description = 'Calcola la corrispondenza per segmenti delle UgcTrack esistenti e toglie layer_id dalle UgcTrack';

    public function handle(UgcTrackMatchingService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $appIds = $this->option('app') ? [(int) $this->option('app')] : App::query()->orderBy('id')->pluck('id')->all();

        $withLayerId = DB::table('ugc_tracks')
            ->when($this->option('app'), fn ($q) => $q->where('app_id', (int) $this->option('app')))
            ->whereRaw("properties ?? 'layer_id' OR properties ?? 'layer_id_auto_resolved'");
        $layerIdCount = (clone $withLayerId)->count();

        $pending = 0;
        foreach ($appIds as $appId) {
            $pending += $service->unmatchedUgcTrackIdsQuery((int) $appId)->count();
        }

        $this->info("{$pending} tracce da calcolare");
        $this->info("{$layerIdCount} tracce con layer_id da togliere");

        if ($dryRun) {
            return self::SUCCESS;
        }

        $withLayerId->update(['properties' => DB::raw("properties - 'layer_id' - 'layer_id_auto_resolved'")]);

        foreach ($appIds as $appId) {
            $service->unmatchedUgcTrackIdsQuery((int) $appId)->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    MatchUgcTrackToEcTracksJob::dispatch((int) $row->id);
                }
            });
        }

        return self::SUCCESS;
    }
}
```

`??` dentro `whereRaw` è l'operatore jsonb `?` con l'escape di PDO. Se il driver lo interpreta comunque come segnaposto, usa `jsonb_exists(properties, 'layer_id')`.

Registra il command in `WmPackageServiceProvider` nello stesso elenco di `WmCleanUgcTrackGeometryCommand`.

- [ ] **Step 4: Esegui i test e verifica che passino**

Run: `PEST tests/Feature/UgcTrackMatching/MatchUgcTracksCommandTest.php`
Expected: PASS.

- [ ] **Step 5: Commit (solo dopo ok del dev)**

```bash
git add src/Commands/WmMatchUgcTracksCommand.php src/WmPackageServiceProvider.php tests/Feature/UgcTrackMatching/MatchUgcTracksCommandTest.php
git commit -m "feat(oc:8773): command per la corrispondenza delle UgcTrack esistenti e la rimozione di layer_id"
```

---

### Task 7: Nova — campo, filtro, parametri, traduzioni

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-7-nova-campo-filtro-parametri-traduzioni)

**Files:**
- Create: `src/Nova/Support/UgcTrackMatchesHtml.php`, `src/Nova/Filters/UgcTrackLayerFilter.php`
- Modify: `src/Nova/UgcTrack.php` (campo nel detail, `filters()`), `src/Nova/App.php` (tab `ugc_track_matching`)
- Modify: `resources/lang/{it,en,fr,es,de}.json`
- Test: `tests/Feature/UgcTrackMatching/MatchingNovaTest.php`

**Interfaces:**
- Consumes: modelli e service dei Task 1–2.
- Produces: `UgcTrackMatchesHtml::render(UgcTrack $track): string`; `UgcTrackLayerFilter` (Select, valore = id del layer).

- [ ] **Step 1: Scrivi i test che falliscono**

```php
<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\Filters\UgcTrackLayerFilter;
use Wm\WmPackage\Nova\Support\UgcTrackMatchesHtml;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
    $this->layerA = Layer::factory()->createQuietly(['app_id' => $this->app_->id, 'name' => ['it' => 'Via degli Dei']]);
    $this->layerB = Layer::factory()->createQuietly(['app_id' => $this->app_->id, 'name' => ['it' => 'Via <b>Mater</b> Dei']]);
    $this->ec = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');
    $this->ecFree = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11.0005 44, 11.0005 44.009))');
    matchingAttachToLayer($this->layerA->id, $this->ec);
    matchingAttachToLayer($this->layerB->id, $this->ec);
    matchingAttachToLayer(999999, $this->ec); // riga orfana: layer inesistente
    $this->ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44, 11.00025 44.009))');
    UgcTrackMatchingService::make()->matchUgcTrack($this->ugc);
});

it('mostra ogni layer con le EcTrack coperte, il gruppo senza layer, e ignora il layer orfano', function () {
    $html = UgcTrackMatchesHtml::render(UgcTrack::find($this->ugc));

    expect($html)->toContain('Via degli Dei');
    expect($html)->toContain('Via &lt;b&gt;Mater&lt;/b&gt; Dei');      // nome escapato
    expect($html)->toContain('3/6');                                    // segmenti coperti / totali
    expect($html)->toContain(__('EcTracks crossed outside any layer'));
    expect($html)->not->toContain('999999');
    expect(substr_count($html, 'rel="noopener noreferrer"'))->toBeGreaterThan(0);
});

it('dice quando il calcolo non è mai stato fatto', function () {
    $other = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((12 45, 12 45.001))');

    expect(UgcTrackMatchesHtml::render(UgcTrack::find($other)))->toContain(__('Matching in progress: reload the page shortly'));
});

it('dice quando il calcolo è fallito', function () {
    UgcTrackMatchingService::make()->markFailed($this->ugc, 'timeout');

    expect(UgcTrackMatchesHtml::render(UgcTrack::find($this->ugc)))->toContain(__('Matching failed'));
});

it('il filtro per layer trova le tracce che passano su una EcTrack del layer', function () {
    $far = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((12 45, 12 45.001))');
    UgcTrackMatchingService::make()->matchUgcTrack($far);

    $ids = (new UgcTrackLayerFilter)->apply(new Request, UgcTrack::query(), $this->layerA->id)->pluck('id')->all();

    expect($ids)->toBe([$this->ugc]);
});

it('il filtro non propone layer inesistenti', function () {
    $options = (new UgcTrackLayerFilter)->options(new Request);

    expect(array_values($options))->toContain($this->layerA->id);
    expect(array_values($options))->not->toContain(999999);
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `PEST tests/Feature/UgcTrackMatching/MatchingNovaTest.php`
Expected: FAIL, classi inesistenti.

- [ ] **Step 3: HTML del campo**

```php
<?php

namespace Wm\WmPackage\Nova\Support;

use Illuminate\Support\Facades\DB;
use Laravel\Nova\Nova;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\UgcTrackMatchingRun;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

/**
 * Layer ed EcTrack attraversati da una UgcTrack (oc:8773), con la corrispondenza di ogni EcTrack.
 * Una EcTrack in più layer compare sotto ciascuno; le EcTrack senza layer in un gruppo a parte;
 * le righe di layerables che puntano a layer cancellati sono ignorate.
 */
class UgcTrackMatchesHtml
{
    public static function render(UgcTrack $track): string
    {
        $run = $track->matchingRun;
        if ($run === null) {
            return '<p>'.e(__('Matching in progress: reload the page shortly')).'</p>';
        }
        if ($run->status === UgcTrackMatchingRun::STATUS_FAILED) {
            return '<p>'.e(__('Matching failed')).': '.e((string) $run->error).'</p>';
        }

        $rows = DB::table('ugc_track_ec_track_matches as c')
            ->leftJoin('layerables as lb', function ($join) {
                $join->on('lb.layerable_id', '=', 'c.ec_track_id')
                    ->where('lb.layerable_type', UgcTrackMatchingService::make()->ecTrackMorphClass())
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('layers')->whereColumn('layers.id', 'lb.layer_id'));
            })
            ->where('c.ugc_track_id', $track->id)
            ->orderBy('lb.layer_id')->orderBy('c.ec_track_id')
            ->get(['lb.layer_id', 'c.ec_track_id', 'c.matched_segments', 'c.total_segments']);

        if ($rows->isEmpty()) {
            return '<p>'.e(__('The track does not cross any EcTrack')).'</p>';
        }

        $layers = Layer::query()->whereIn('id', $rows->pluck('layer_id')->filter()->unique())->get()->keyBy('id');
        $ecClass = config('wm-package.ec_track_model', EcTrack::class);
        $ecTracks = $ecClass::query()->whereIn('id', $rows->pluck('ec_track_id')->unique())->get()->keyBy('id');

        $html = '';
        foreach ($rows->groupBy(fn ($r) => $r->layer_id ?? 0) as $layerId => $group) {
            $title = $layerId === 0
                ? e(__('EcTracks crossed outside any layer'))
                : self::link('layers', (int) $layerId, $layers[$layerId]?->getStringName() ?? (string) $layerId);
            $html .= '<p><strong>'.$title.'</strong></p><ul>';
            foreach ($group as $row) {
                $covered = count(json_decode($row->matched_segments, true) ?? []);
                $percent = $row->total_segments > 0 ? (int) round(100 * $covered / $row->total_segments) : 0;
                $name = self::ecTrackName($ecTracks[$row->ec_track_id] ?? null, (int) $row->ec_track_id);
                $html .= '<li>'.self::link('ec-tracks', (int) $row->ec_track_id, $name)
                    .' — '.$covered.'/'.$row->total_segments.' ('.$percent.'%)</li>';
            }
            $html .= '</ul>';
        }

        return $html;
    }

    private static function link(string $uriKey, int $id, string $label): string
    {
        $url = '/'.trim(Nova::path(), '/').'/resources/'.$uriKey.'/'.$id;

        return '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer" class="link-default">'.e($label).'</a>';
    }

    private static function ecTrackName(?object $ecTrack, int $id): string
    {
        if ($ecTrack === null) {
            return (string) $id;
        }
        foreach ([app()->getLocale(), 'it', 'en'] as $locale) {
            $name = $ecTrack->getTranslation('name', $locale, false);
            if (! empty($name)) {
                return $name;
            }
        }

        return (string) $id;
    }
}
```

Prima di usarli, verifica gli `uriKey()` reali delle risorse Nova `Layer` ed `EcTrack` del package (`layers`, `ec-tracks`): se sono diversi, usa `\Wm\WmPackage\Nova\Layer::uriKey()` e `\Wm\WmPackage\Nova\EcTrack::uriKey()`.

- [ ] **Step 4: Filtro per layer**

```php
<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

/** UgcTrack con almeno un segmento coperto su una EcTrack del layer scelto (oc:8773). */
class UgcTrackLayerFilter extends Filter
{
    public $component = 'select-filter';

    public function name()
    {
        return __('Layer crossed by the track');
    }

    public function apply(Request $request, $query, $value)
    {
        $morph = UgcTrackMatchingService::make()->ecTrackMorphClass();

        return $query->whereExists(function ($q) use ($morph, $value) {
            $q->selectRaw('1')
                ->from('ugc_track_ec_track_matches as c')
                ->join('layerables as lb', function ($join) use ($morph) {
                    $join->on('lb.layerable_id', '=', 'c.ec_track_id')->where('lb.layerable_type', $morph);
                })
                ->whereColumn('c.ugc_track_id', 'ugc_tracks.id')
                ->where('lb.layer_id', (int) $value);
        });
    }

    public function options(Request $request)
    {
        return Layer::query()->orderBy('id')->get()
            ->mapWithKeys(fn (Layer $layer) => [$layer->getStringName().' (#'.$layer->id.')' => $layer->id])
            ->all();
    }
}
```

- [ ] **Step 5: Campo e filtro nella risorsa Nova UgcTrack**

In `src/Nova/UgcTrack.php`, nel `return` di `fields()` dopo `$this->originField(),`:

```php
            // oc:8773: layer ed EcTrack attraversati, solo nel dettaglio (fa query).
            Text::make(__('Layers crossed by the track'), fn () => $this->resource instanceof UgcTrackModel
                ? UgcTrackMatchesHtml::render($this->resource)
                : null)
                ->asHtml()
                ->onlyOnDetail(),
```

e aggiungi:

```php
    public function filters(NovaRequest $request): array
    {
        return [
            ...parent::filters($request),
            new UgcTrackLayerFilter,
        ];
    }
```

Nessun `canSee()` per ruolo: campo e filtro seguono l'autorizzazione della risorsa.

- [ ] **Step 6: Parametri nella risorsa Nova App**

In `src/Nova/App.php`, in `fields()`, dopo `Tab::make('overlays', $this->overlays_tab()),` aggiungi `Tab::make('ugc_track_matching', $this->ugc_track_matching_tab()),` e il metodo:

```php
    /** Parametri della corrispondenza UgcTrack ↔ EcTrack per segmenti (oc:8773). */
    protected function ugc_track_matching_tab(): array
    {
        $help = __('Changing this value recomputes the matches of every UGC track of the app.');

        return [
            Number::make(__('EC track segment length (m)'), 'properties->'.UgcTrackMatchingService::SEGMENT_LENGTH_KEY)
                ->min(UgcTrackMatchingService::SEGMENT_LENGTH_MIN)
                ->max(UgcTrackMatchingService::SEGMENT_LENGTH_MAX)
                ->step(1)
                ->nullable()
                ->rules('nullable', 'integer', 'between:'.UgcTrackMatchingService::SEGMENT_LENGTH_MIN.','.UgcTrackMatchingService::SEGMENT_LENGTH_MAX)
                ->placeholder((string) config('wm-package.ugc_track_matching.segment_length_meters'))
                ->hideFromIndex()
                ->help($help),
            Number::make(__('Maximum distance from the UGC track (m)'), 'properties->'.UgcTrackMatchingService::MAX_DISTANCE_KEY)
                ->min(UgcTrackMatchingService::MAX_DISTANCE_MIN)
                ->max(UgcTrackMatchingService::MAX_DISTANCE_MAX)
                ->step(1)
                ->nullable()
                ->rules('nullable', 'integer', 'between:'.UgcTrackMatchingService::MAX_DISTANCE_MIN.','.UgcTrackMatchingService::MAX_DISTANCE_MAX)
                ->placeholder((string) config('wm-package.ugc_track_matching.max_distance_meters'))
                ->hideFromIndex()
                ->help($help),
        ];
    }
```

- [ ] **Step 7: Traduzioni**

Aggiungi in tutti e cinque `resources/lang/*.json` (testo italiano in `it.json`, inglese in `en.json`, e le traduzioni in fr/es/de):

| Chiave | it | fr | es | de |
|---|---|---|---|---|
| `Layers crossed by the track` | Layer attraversati dalla traccia | Couches traversées par la trace | Capas atravesadas por la traza | Von der Spur durchquerte Ebenen |
| `Layer crossed by the track` | Layer attraversato dalla traccia | Couche traversée par la trace | Capa atravesada por la traza | Von der Spur durchquerte Ebene |
| `EcTracks crossed outside any layer` | Percorsi attraversati fuori da ogni layer | Parcours traversés hors de toute couche | Recorridos atravesados fuera de toda capa | Durchquerte Strecken ohne Ebene |
| `The track does not cross any EcTrack` | La traccia non attraversa nessun percorso | La trace ne traverse aucun parcours | La traza no atraviesa ningún recorrido | Die Spur durchquert keine Strecke |
| `Matching in progress: reload the page shortly` | Calcolo della corrispondenza in corso: ricarica la pagina tra poco | Calcul de la correspondance en cours : rechargez la page dans un instant | Cálculo de la correspondencia en curso: vuelve a cargar la página en breve | Zuordnung wird berechnet: Seite gleich neu laden |
| `Matching failed` | Calcolo della corrispondenza fallito | Échec du calcul de la correspondance | Error en el cálculo de la correspondencia | Berechnung der Zuordnung fehlgeschlagen |
| `EC track segment length (m)` | Lunghezza del segmento della EC track (m) | Longueur du segment de l'EC track (m) | Longitud del segmento de la EC track (m) | Segmentlänge der EC track (m) |
| `Maximum distance from the UGC track (m)` | Distanza massima dalla UGC track (m) | Distance maximale de l'UGC track (m) | Distancia máxima de la UGC track (m) | Maximaler Abstand zur UGC track (m) |
| `Changing this value recomputes the matches of every UGC track of the app.` | Cambiare questo valore ricalcola la corrispondenza di tutte le tracce UGC dell'app. | Modifier cette valeur recalcule la correspondance de toutes les traces UGC de l'app. | Cambiar este valor recalcula la correspondencia de todas las trazas UGC de la app. | Eine Änderung berechnet die Zuordnung aller UGC-Spuren der App neu. |

In `en.json` il valore è uguale alla chiave.

- [ ] **Step 8: Esegui i test e verifica che passino**

Run: `PEST tests/Feature/UgcTrackMatching/MatchingNovaTest.php`
Expected: PASS.

- [ ] **Step 9: Commit (solo dopo ok del dev)**

```bash
git add src/Nova resources/lang tests/Feature/UgcTrackMatching/MatchingNovaTest.php
git commit -m "feat(oc:8773): layer attraversati e filtro per layer su UgcTrack, parametri di corrispondenza in Nova App"
```

---

### Task 8: Verifica finale, documentazione

**Files:**
- Modify: `docs/knowledge/` (nuova pagina o aggiornamento, in Fase update-context), `CLAUDE.md` (riga in `## Conoscenza`), `notes.md` di questo cantiere.

- [ ] **Step 1: Suite UGC e Nova del package**

Run: `PEST tests/Feature/UgcTrackMatching tests/Feature/WmCleanUgcTrackGeometryCommandTest.php`
Expected: PASS. Poi l'intera suite del package (`PEST`), dopo aver chiesto al dev: stima dei tempi prima di lanciarla.

- [ ] **Step 2: PHPStan e Pint**

```bash
docker run --rm -v "$PWD/wm-package:/app" -w /app wm-phpfpm:8.4 composer analyse
docker run --rm -v "$PWD/wm-package:/app" -w /app wm-phpfpm:8.4 vendor/bin/pint --dirty
```

Expected: nessun errore nuovo; `git -C wm-package status` mostra solo i file di questo lavoro.

- [ ] **Step 3: Controllo degli altri consumer prima del merge**

Cerca letture di `properties.layer_id` (o `properties->layer_id`, `['layer_id']`) riferite alle UgcTrack in wm-core e negli altri consumer (forestas, maphub, osm2cai2), sui loro repository aggiornati al remoto. Scrivi l'esito in `notes.md`, sezione `## Decisioni`. Se uno di loro lo legge, fermati e avvisa il dev prima del merge.

- [ ] **Step 4: Commit (solo dopo ok del dev)**

```bash
git add docs CLAUDE.md
git commit -m "docs(oc:8773): corrispondenza UgcTrack/EcTrack per segmenti"
```
