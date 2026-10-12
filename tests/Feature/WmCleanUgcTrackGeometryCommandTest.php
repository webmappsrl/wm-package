<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Jobs\CleanUgcTrackGeometryJob;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
use Wm\WmPackage\Jobs\UpdateUgcTrackDemStatsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\UgcTrackCleanupService;
use Wm\WmPackage\Services\Models\UgcTrackStatsService;

beforeEach(function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 40.0);
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
});

/**
 * Traccia «vecchia»: creata senza observer, con la geometria grezza (punto sbagliato incluso),
 * come quelle salvate prima di oc:8719.
 */
function legacyTrack(int $userId, int $appId): UgcTrack
{
    $locations = [
        ['time' => 0, 'latitude' => 43.0, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0],
        ['time' => 1000, 'latitude' => 43.5, 'longitude' => 13.5, 'accuracy' => 3000.0, 'altitude' => 10.0],
        ['time' => 2000, 'latitude' => 43.0001, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0],
    ];
    $track = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create([
        'user_id' => $userId,
        'app_id' => $appId,
        'properties' => ['name' => 'vecchia', 'locations' => $locations],
    ]));
    DB::update(
        "UPDATE ugc_tracks SET geometry = ST_GeomFromEWKT('SRID=4326;MULTILINESTRING Z ((13 43 10, 13.5 43.5 10, 13 43.0001 10))') WHERE id = ?",
        [$track->id]
    );

    return $track->refresh();
}

function pointCount(int $id): int
{
    return (int) DB::selectOne('SELECT ST_NPoints(geometry::geometry) AS n FROM ugc_tracks WHERE id = ?', [$id])->n;
}

it('con --dry-run elenca le tracce che cambierebbero senza scrivere né accodare', function () {
    Bus::fake();
    $track = legacyTrack($this->user->id, $this->app_->id);

    $this->artisan('wm:clean-ugc-track-geometry', ['--dry-run' => true])
        ->expectsOutputToContain('1 tracce cambierebbero')
        ->assertSuccessful();

    Bus::assertNotDispatched(CleanUgcTrackGeometryJob::class);
    expect(pointCount($track->id))->toBe(3);
});

it('senza --dry-run accoda un job per ogni traccia che cambia', function () {
    Bus::fake();
    $track = legacyTrack($this->user->id, $this->app_->id);

    $this->artisan('wm:clean-ugc-track-geometry')->assertSuccessful();

    Bus::assertDispatched(CleanUgcTrackGeometryJob::class, fn ($job) => $job->ugcTrackId === $track->id);
});

it('il job riscrive la geometria pulita ed è idempotente', function () {
    // Il job accoda il ricalcolo delle località (osmfeatures): senza Bus finto girerebbe in sync.
    Bus::fake();
    $track = legacyTrack($this->user->id, $this->app_->id);

    (new CleanUgcTrackGeometryJob($track->id))->handle(app(UgcTrackCleanupService::class));
    expect(pointCount($track->id))->toBe(2);
    expect(UgcTrackCleanupService::make()->wouldChange($track->refresh()))->toBeFalse();

    (new CleanUgcTrackGeometryJob($track->id))->handle(app(UgcTrackCleanupService::class));
    expect(pointCount($track->id))->toBe(2);
});

it('salta le tracce già pulite e quelle senza locations', function () {
    Bus::fake();
    UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'senza locations']]);

    $this->artisan('wm:clean-ugc-track-geometry', ['--dry-run' => true])
        ->expectsOutputToContain('0 tracce cambierebbero')
        ->assertSuccessful();
});

it('non considera cambiata una traccia che differisce solo per gli arrotondamenti dei decimali', function () {
    Bus::fake();
    $locations = [
        ['time' => 0, 'latitude' => 37.4990239051437, 'longitude' => 14.072766889436808, 'accuracy' => 5, 'altitude' => 10],
        ['time' => 1000, 'latitude' => 37.499014402456424, 'longitude' => 14.072644807635621, 'accuracy' => 5, 'altitude' => 10],
    ];
    $track = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create([
        'user_id' => $this->user->id,
        'app_id' => $this->app_->id,
        'properties' => ['name' => 'nove decimali', 'locations' => $locations],
    ]));
    DB::update(
        "UPDATE ugc_tracks SET geometry = ST_GeomFromEWKT('SRID=4326;MULTILINESTRING Z ((14.072766889 37.499023905 10, 14.072644808 37.499014402 10))') WHERE id = ?",
        [$track->id]
    );
    $before = DB::selectOne('SELECT ST_AsEWKT(geometry::geometry) AS t FROM ugc_tracks WHERE id = ?', [$track->id])->t;

    expect(UgcTrackCleanupService::make()->wouldChange($track->refresh()))->toBeFalse();

    $this->artisan('wm:clean-ugc-track-geometry', ['--dry-run' => true])
        ->expectsOutputToContain('0 tracce cambierebbero')
        ->assertSuccessful();

    (new CleanUgcTrackGeometryJob($track->id))->handle(app(UgcTrackCleanupService::class));
    $after = DB::selectOne('SELECT ST_AsEWKT(geometry::geometry) AS t FROM ugc_tracks WHERE id = ?', [$track->id])->t;
    expect($after)->toBe($before);
});

it('il job, quando riscrive la geometria, accoda il ricalcolo delle località', function () {
    $track = legacyTrack($this->user->id, $this->app_->id);
    Bus::fake();

    (new CleanUgcTrackGeometryJob($track->id))->handle(app(UgcTrackCleanupService::class));

    Bus::assertDispatched(
        UpdateModelWithGeometryTaxonomyWhere::class,
        fn ($job) => (fn () => $this->model)->call($job)->is($track)
    );
});

it('il job, quando la geometria è già pulita, non accoda il ricalcolo delle località', function () {
    // Bus finto già dalla prima esecuzione: il ricalcolo delle località chiamerebbe osmfeatures.
    Bus::fake();
    $track = legacyTrack($this->user->id, $this->app_->id);
    (new CleanUgcTrackGeometryJob($track->id))->handle(app(UgcTrackCleanupService::class));
    Bus::fake();

    (new CleanUgcTrackGeometryJob($track->id))->handle(app(UgcTrackCleanupService::class));

    Bus::assertNotDispatched(UpdateModelWithGeometryTaxonomyWhere::class);
});

it('nel dry-run i km prima sono quelli della geometria salvata, non dei locations', function () {
    Bus::fake();
    // Il punto (0,0) c'è solo nella geometria: i locations sono già puliti.
    $locations = [
        ['time' => 0, 'latitude' => 43.0, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0],
        ['time' => 1000, 'latitude' => 43.0001, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0],
    ];
    $track = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create([
        'user_id' => $this->user->id,
        'app_id' => $this->app_->id,
        'properties' => ['name' => 'zero nella geometria', 'locations' => $locations],
    ]));
    DB::update(
        "UPDATE ugc_tracks SET geometry = ST_GeomFromEWKT('SRID=4326;MULTILINESTRING Z ((0 0 0, 13 43 10, 13 43.0001 10))') WHERE id = ?",
        [$track->id]
    );
    $storedKm = (float) DB::selectOne('SELECT ST_Length(geometry::geography) / 1000 AS km FROM ugc_tracks WHERE id = ?', [$track->id])->km;

    $this->artisan('wm:clean-ugc-track-geometry', ['--dry-run' => true])
        ->expectsOutputToContain(sprintf('%.1f', $storedKm))
        ->expectsOutputToContain('1 tracce cambierebbero')
        ->expectsOutputToContain('dry-run')
        ->assertSuccessful();
});

it('ricorda di rivedere il dry-run prima del run reale, sia in dry-run sia nel run reale', function () {
    Bus::fake();

    $this->artisan('wm:clean-ugc-track-geometry', ['--dry-run' => true])
        ->expectsOutputToContain('Prima del run reale rivedi')
        ->assertSuccessful();
    $this->artisan('wm:clean-ugc-track-geometry')
        ->expectsOutputToContain('Prima del run reale rivedi')
        ->assertSuccessful();
});

it('nel dry-run i km prima sono la lunghezza in pianta, senza i dislivelli', function () {
    Bus::fake();
    $locations = [
        ['time' => 0, 'latitude' => 43.0, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0],
        ['time' => 1000, 'latitude' => 43.0001, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0],
    ];
    $track = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create([
        'user_id' => $this->user->id,
        'app_id' => $this->app_->id,
        'properties' => ['name' => 'quote diverse', 'locations' => $locations],
    ]));
    // Quote molto diverse fra i punti: in 3D la lunghezza supera di molto quella in pianta.
    DB::update(
        "UPDATE ugc_tracks SET geometry = ST_GeomFromEWKT('SRID=4326;MULTILINESTRING Z ((13 43 0, 13.1 43.1 9000, 13.2 43.2 0))') WHERE id = ?",
        [$track->id]
    );
    $planarKm = (float) DB::selectOne('SELECT ST_Length(ST_Force2D(geometry::geometry)::geography) / 1000 AS km FROM ugc_tracks WHERE id = ?', [$track->id])->km;
    $km3d = (float) DB::selectOne('SELECT ST_Length(geometry::geography) / 1000 AS km FROM ugc_tracks WHERE id = ?', [$track->id])->km;
    expect(sprintf('%.1f', $km3d))->not->toBe(sprintf('%.1f', $planarKm));

    $this->artisan('wm:clean-ugc-track-geometry', ['--dry-run' => true])
        ->expectsOutputToContain(sprintf('%.1f', $planarKm))
        ->assertSuccessful();
});

it('calcola stats anche per una traccia la cui geometria non cambia', function () {
    Bus::fake([UpdateUgcTrackDemStatsJob::class, UpdateModelWithGeometryTaxonomyWhere::class]);
    $locations = array_map(fn ($i) => ['time' => $i * 10_000, 'latitude' => 43.0 + $i * 0.00009, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 100.0, 'speed' => 4.0], range(0, 10));
    $track = UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'pulita', 'locations' => $locations]]);
    DB::update("UPDATE ugc_tracks SET properties = properties - 'stats' WHERE id = ?", [$track->id]);

    (new CleanUgcTrackGeometryJob($track->id))->handle(UgcTrackCleanupService::make());

    expect($track->fresh()->properties['stats']['distance'])->toBe(0.1);
    Bus::assertDispatched(UpdateUgcTrackDemStatsJob::class);
});

it('conserva i valori DEM se la geometria non cambia', function () {
    Bus::fake([UpdateUgcTrackDemStatsJob::class, UpdateModelWithGeometryTaxonomyWhere::class]);
    $locations = array_map(fn ($i) => ['time' => $i * 10_000, 'latitude' => 43.0 + $i * 0.00009, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 100.0, 'speed' => 4.0], range(0, 10));
    $track = UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'con dem', 'locations' => $locations]]);
    $dem = json_encode(['ascent' => 313, 'descent' => 610, 'ele_min' => 1, 'ele_max' => 2, 'ele_from' => 1, 'ele_to' => 2]);
    DB::update("UPDATE ugc_tracks SET properties = jsonb_set(properties, '{stats}', (properties->'stats') || ?::jsonb) WHERE id = ?", [$dem, $track->id]);
    Bus::fake([UpdateUgcTrackDemStatsJob::class, UpdateModelWithGeometryTaxonomyWhere::class]);

    (new CleanUgcTrackGeometryJob($track->id))->handle(UgcTrackCleanupService::make());

    expect($track->fresh()->properties['stats']['ascent'])->toBe(313);
    Bus::assertNotDispatched(UpdateUgcTrackDemStatsJob::class);
});

it('azzera i valori DEM e richiede il DEM se il job cambia la geometria, anche con DEM completo', function () {
    Bus::fake([UpdateUgcTrackDemStatsJob::class, UpdateModelWithGeometryTaxonomyWhere::class]);
    $track = legacyTrack($this->user->id, $this->app_->id);
    // DEM completo salvato, ma calcolato sulla geometria grezza (con il punto sbagliato).
    $dem = json_encode(['distance' => 99.0, 'ascent' => 313, 'descent' => 610, 'ele_min' => 1, 'ele_max' => 2, 'ele_from' => 1, 'ele_to' => 2]);
    DB::update("UPDATE ugc_tracks SET properties = jsonb_set(properties, '{stats}', ?::jsonb) WHERE id = ?", [$dem, $track->id]);

    (new CleanUgcTrackGeometryJob($track->id))->handle(UgcTrackCleanupService::make());

    expect(pointCount($track->id))->toBe(2);
    $stats = $track->fresh()->properties['stats'];
    foreach (UgcTrackStatsService::DEM_KEYS as $key) {
        expect($stats)->toHaveKey($key);
        expect($stats[$key])->toBeNull("$key deve tornare null: è stato calcolato sulla geometria vecchia");
    }
    Bus::assertDispatched(
        UpdateUgcTrackDemStatsJob::class,
        fn ($job) => $job->ugcTrackId === $track->id && $job->computedAt === $stats['computed_at']
    );
});

it('nel dry-run conta fra quelle che riceverebbero stats solo le tracce con almeno 2 punti tenuti', function () {
    Bus::fake([UpdateUgcTrackDemStatsJob::class, UpdateModelWithGeometryTaxonomyWhere::class]);
    legacyTrack($this->user->id, $this->app_->id);
    // locations con un solo punto tenuto: il (0,0) si scarta sempre.
    UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create([
        'user_id' => $this->user->id,
        'app_id' => $this->app_->id,
        'properties' => ['name' => 'un punto', 'locations' => [
            ['time' => 0, 'latitude' => 43.0, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0],
            ['time' => 1000, 'latitude' => 0, 'longitude' => 0, 'accuracy' => 5.0, 'altitude' => 10.0],
        ]],
    ]));

    $this->artisan('wm:clean-ugc-track-geometry', ['--dry-run' => true])
        ->expectsOutputToContain('1 riceverebbero stats, 1 con meno di 2 punti tenuti resterebbero senza')
        ->assertSuccessful();
});

it('il command accoda il job per ogni traccia con locations, anche senza cambi di geometria', function () {
    Bus::fake([CleanUgcTrackGeometryJob::class, UpdateUgcTrackDemStatsJob::class]);
    $locations = array_map(fn ($i) => ['time' => $i * 10_000, 'latitude' => 43.0 + $i * 0.00009, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 100.0], range(0, 10));
    UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'a', 'locations' => $locations]]);

    $this->artisan('wm:clean-ugc-track-geometry')->assertSuccessful();

    Bus::assertDispatchedTimes(CleanUgcTrackGeometryJob::class, 1);
});
