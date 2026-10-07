<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;

// Base TestCase (Wm\WmPackage\Tests\TestCase, con RefreshDatabase) applicato da tests/Pest.php.

beforeEach(function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 40.0);
    $this->withoutMiddleware('auth.jwt');
    $this->artisan('jwt:secret --always-no');
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
});

/**
 * Traccia sintetica con le stesse caratteristiche della UgcTrack 169: punti buoni lungo un
 * meridiano, una sequenza di posizioni da rete cellulare a chilometri di distanza in mezzo.
 *
 * @return list<array<string, mixed>>
 */
function locationsWithSpikes(): array
{
    $points = [];
    for ($i = 0; $i < 6; $i++) {
        $points[] = ['time' => $i * 10_000, 'latitude' => 43.0 + $i * 0.0001, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 1000.0 + $i, 'speed' => 1.2];
    }
    $points[] = ['time' => 60_000, 'latitude' => 43.05, 'longitude' => 13.08, 'accuracy' => 2000.0, 'altitude' => 900.0, 'speed' => 0];
    $points[] = ['time' => 70_000, 'latitude' => 42.97, 'longitude' => 12.93, 'accuracy' => 7857.0, 'altitude' => 900.0, 'speed' => 0];
    for ($i = 6; $i < 10; $i++) {
        $points[] = ['time' => ($i + 2) * 10_000, 'latitude' => 43.0 + $i * 0.0001, 'longitude' => 13.0, 'accuracy' => 8.0, 'altitude' => 1000.0 + $i, 'speed' => 1.2];
    }

    return $points;
}

/** @param  list<array<string, mixed>>  $locations */
function featureFromLocations(array $locations, array $extraProperties = [], bool $zeroPointFirst = false): array
{
    $coordinates = array_map(fn ($l) => [$l['longitude'], $l['latitude'], $l['altitude']], $locations);
    if ($zeroPointFirst) {
        array_unshift($coordinates, [0, 0, 0]);
    }

    return [
        'type' => 'Feature',
        'geometry' => ['type' => 'LineString', 'coordinates' => $coordinates],
        'properties' => array_merge(['name' => 'Traccia di prova', 'uuid' => (string) Str::uuid(), 'locations' => $locations], $extraProperties),
    ];
}

function storedPointCount(int $id): int
{
    return (int) DB::selectOne('SELECT ST_NPoints(geometry::geometry) AS n FROM ugc_tracks WHERE id = ?', [$id])->n;
}

function storedTypeAndDims(int $id): array
{
    $row = DB::selectOne('SELECT ST_GeometryType(geometry::geometry) AS t, ST_NDims(geometry::geometry) AS d FROM ugc_tracks WHERE id = ?', [$id]);

    return [$row->t, (int) $row->d];
}

it('salva via API una geometria senza i punti con accuracy oltre la soglia', function () {
    $feature = featureFromLocations(locationsWithSpikes(), ['app_id' => $this->app_->id]);

    $id = $this->actingAs($this->user, 'api')
        ->postJson('/api/v2/ugc/track/store', $feature)
        ->assertStatus(201)
        ->json('id');

    expect(storedPointCount($id))->toBe(10);
    expect(storedTypeAndDims($id))->toBe(['ST_MultiLineString', 3]);
    expect(UgcTrack::find($id)->properties['locations'])->toHaveCount(12);
});

it('toglie il punto (0,0) che l\'app mette in testa alla geometria', function () {
    $locations = array_slice(locationsWithSpikes(), 0, 6);
    $feature = featureFromLocations($locations, ['app_id' => $this->app_->id], zeroPointFirst: true);

    $id = $this->actingAs($this->user, 'api')->postJson('/api/v2/ugc/track/store', $feature)->assertStatus(201)->json('id');

    expect(storedPointCount($id))->toBe(6);
    $minLon = DB::selectOne('SELECT ST_XMin(geometry::geometry) AS x FROM ugc_tracks WHERE id = ?', [$id])->x;
    expect((float) $minLon)->toBeGreaterThan(12.9);
});

it('ripulisce la geometria grezza rimandata dall\'app con la route edit', function () {
    $feature = featureFromLocations(locationsWithSpikes(), ['app_id' => $this->app_->id]);
    $id = $this->actingAs($this->user, 'api')->postJson('/api/v2/ugc/track/store', $feature)->json('id');

    $feature['properties']['id'] = $id;
    $feature['properties']['name'] = 'Nome cambiato dall\'app';
    $this->actingAs($this->user, 'api')->postJson('/api/v2/ugc/track/edit', $feature)->assertStatus(200);

    expect(storedPointCount($id))->toBe(10);
});

it('restituisce all\'app la geometria pulita, in 3D, con uuid e properties invariati', function () {
    $feature = featureFromLocations(locationsWithSpikes(), ['app_id' => $this->app_->id]);
    $this->actingAs($this->user, 'api')->postJson('/api/v2/ugc/track/store', $feature)->assertStatus(201);

    $returned = $this->actingAs($this->user, 'api')
        ->getJson('/api/v2/ugc/track/index')
        ->assertStatus(200)
        ->json('features.0');

    expect($returned['geometry']['type'])->toBe('MultiLineString');
    expect($returned['geometry']['coordinates'][0])->toHaveCount(10);
    expect($returned['geometry']['coordinates'][0][0])->toHaveCount(3);
    expect($returned['properties']['uuid'])->toBe($feature['properties']['uuid']);
    expect($returned['properties']['locations'])->toHaveCount(12);
});

it('non tocca la geometria di una traccia senza locations', function () {
    $track = UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'senza locations']]);
    $before = DB::selectOne('SELECT ST_AsText(geometry::geometry) AS g FROM ugc_tracks WHERE id = ?', [$track->id])->g;

    $track->update(['properties' => ['name' => 'rinominata']]);

    $after = DB::selectOne('SELECT ST_AsText(geometry::geometry) AS g FROM ugc_tracks WHERE id = ?', [$track->id])->g;
    expect($after)->toBe($before);
});

it('lascia la geometria ricevuta se dopo il filtro resta meno di 2 punti', function () {
    $locations = [
        ['time' => 0, 'latitude' => 43.0, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0],
        ['time' => 1000, 'latitude' => 43.1, 'longitude' => 13.1, 'accuracy' => 500.0, 'altitude' => 10.0],
        ['time' => 2000, 'latitude' => 43.2, 'longitude' => 13.2, 'accuracy' => 900.0, 'altitude' => 10.0],
    ];
    $feature = featureFromLocations($locations, ['app_id' => $this->app_->id]);

    $id = $this->actingAs($this->user, 'api')->postJson('/api/v2/ugc/track/store', $feature)->assertStatus(201)->json('id');

    expect(storedPointCount($id))->toBe(3);
});
