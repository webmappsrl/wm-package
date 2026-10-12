<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wm\WmPackage\Jobs\UpdateUgcTrackDemStatsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;

beforeEach(function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 40.0);
    $this->withoutMiddleware('auth.jwt');
    $this->artisan('jwt:secret --always-no');
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
    Bus::fake([UpdateUgcTrackDemStatsJob::class]);
});

/** @return list<array<string, mixed>> */
function statsWalk(int $points = 11): array
{
    return array_map(fn ($i) => [
        'time' => $i * 10_000, 'latitude' => 43.0 + $i * 0.00009, 'longitude' => 13.0,
        'accuracy' => 5.0, 'altitude' => 100.0, 'speed' => 4.0,
    ], range(0, $points - 1));
}

function statsFeature(array $locations, array $extra = []): array
{
    return [
        'type' => 'Feature',
        'geometry' => ['type' => 'LineString', 'coordinates' => array_map(fn ($l) => [$l['longitude'], $l['latitude'], $l['altitude']], $locations)],
        'properties' => array_merge(['name' => 'stats', 'uuid' => (string) Str::uuid(), 'locations' => $locations], $extra),
    ];
}

function storeStatsTrack($test, array $feature): UgcTrack
{
    $id = $test->actingAs($test->user, 'api')->postJson('/api/v2/ugc/track/store', $feature)->assertStatus(201)->json('id');

    return UgcTrack::find($id);
}

it('calcola stats alla creazione e accoda il DEM', function () {
    $track = storeStatsTrack($this, statsFeature(statsWalk(), ['app_id' => $this->app_->id]));

    expect($track->properties['stats']['distance'])->toBe(0.1);
    expect($track->properties['stats']['ascent'])->toBeNull();
    Bus::assertDispatched(UpdateUgcTrackDemStatsJob::class, fn ($job) => $job->ugcTrackId === $track->id);
});

it('non salva mai uno stats mandato dal client alla creazione', function () {
    $track = storeStatsTrack($this, statsFeature(statsWalk(), ['app_id' => $this->app_->id, 'stats' => ['ascent' => 9999, 'distance' => 999]]));

    expect($track->properties['stats']['distance'])->toBe(0.1);
    expect($track->properties['stats']['ascent'])->toBeNull();
});

it('conserva stats, DEM compreso, quando cambiano solo altre properties', function () {
    $track = storeStatsTrack($this, statsFeature(statsWalk(), ['app_id' => $this->app_->id]));
    DB::update("UPDATE ugc_tracks SET properties = jsonb_set(properties, '{stats,ascent}', '313') WHERE id = ?", [$track->id]);
    $track->refresh();
    Bus::fake([UpdateUgcTrackDemStatsJob::class]);

    $properties = $track->properties;
    $properties['layer_id'] = 7;
    $track->properties = $properties;
    $track->save();

    expect($track->fresh()->properties['stats']['ascent'])->toBe(313);
    Bus::assertNotDispatched(UpdateUgcTrackDemStatsJob::class);
});

it('nell\'edit dell\'app con gli stessi punti ripulisce la geometria e conserva stats', function () {
    $feature = statsFeature(statsWalk(), ['app_id' => $this->app_->id]);
    $track = storeStatsTrack($this, $feature);
    DB::update("UPDATE ugc_tracks SET properties = jsonb_set(properties, '{stats,ascent}', '313') WHERE id = ?", [$track->id]);

    $feature['properties']['id'] = $track->id;
    $feature['properties']['stats'] = ['ascent' => 1, 'distance' => 1];
    $this->actingAs($this->user, 'api')->postJson('/api/v2/ugc/track/edit', $feature)->assertStatus(200);

    expect($track->fresh()->properties['stats']['ascent'])->toBe(313);
    expect($track->fresh()->properties['stats']['distance'])->toBe(0.1);
});

it('ricalcola stats e azzera il DEM quando cambiano i punti', function () {
    $feature = statsFeature(statsWalk(), ['app_id' => $this->app_->id]);
    $track = storeStatsTrack($this, $feature);
    DB::update("UPDATE ugc_tracks SET properties = jsonb_set(properties, '{stats,ascent}', '313') WHERE id = ?", [$track->id]);
    Bus::fake([UpdateUgcTrackDemStatsJob::class]);

    $feature = statsFeature(statsWalk(21), ['app_id' => $this->app_->id, 'id' => $track->id, 'uuid' => $feature['properties']['uuid']]);
    $this->actingAs($this->user, 'api')->postJson('/api/v2/ugc/track/edit', $feature)->assertStatus(200);

    expect($track->fresh()->properties['stats']['distance'])->toBe(0.2);
    expect($track->fresh()->properties['stats']['ascent'])->toBeNull();
    Bus::assertDispatched(UpdateUgcTrackDemStatsJob::class);
});

it('non scrive stats per una traccia senza locations e toglie quello del client', function () {
    $feature = statsFeature(statsWalk(), ['app_id' => $this->app_->id, 'stats' => ['distance' => 5]]);
    unset($feature['properties']['locations']);

    $track = storeStatsTrack($this, $feature);

    expect($track->properties)->not->toHaveKey('stats');
    Bus::assertNotDispatched(UpdateUgcTrackDemStatsJob::class);
});

it('nel secondo store con lo stesso uuid (merge di oc:8718) non salva lo stats del client', function () {
    $feature = statsFeature(statsWalk(), ['app_id' => $this->app_->id]);
    $track = storeStatsTrack($this, $feature);
    DB::update("UPDATE ugc_tracks SET properties = jsonb_set(properties, '{stats,ascent}', '313') WHERE id = ?", [$track->id]);

    $feature['properties']['stats'] = ['ascent' => 9999, 'distance' => 999];
    $again = $this->actingAs($this->user, 'api')->postJson('/api/v2/ugc/track/store', $feature);
    expect($again->status())->toBeIn([200, 201]);
    expect($again->json('id'))->toBe($track->id);

    expect($track->fresh()->properties['stats']['ascent'])->toBe(313);
    expect($track->fresh()->properties['stats']['distance'])->toBe(0.1);
});
