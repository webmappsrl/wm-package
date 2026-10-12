<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\Jobs\UpdateUgcTrackDemStatsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\EcTrackService;

beforeEach(function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 40.0);
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
    Bus::fake([UpdateUgcTrackDemStatsJob::class]);
    $this->track = UgcTrack::factory()->create([
        'user_id' => $this->user->id, 'app_id' => $this->app_->id,
        'properties' => ['name' => 'dem', 'locations' => array_map(fn ($i) => [
            'time' => $i * 10_000, 'latitude' => 43.0 + $i * 0.00009, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 100.0, 'speed' => 4.0,
        ], range(0, 10))],
    ]);
});

function fakeDem(?array $properties, bool $fails = false): void
{
    $mock = Mockery::mock(EcTrackService::class);
    $expectation = $mock->shouldReceive('fetchDemTechData')->once();
    $fails ? $expectation->andThrow(new Exception('DEM giù')) : $expectation->andReturn(['properties' => $properties]);
    app()->instance(EcTrackService::class, $mock);
}

it('scrive solo le chiavi DEM di stats', function () {
    $before = $this->track->fresh()->properties['stats'];
    fakeDem(['ascent' => 313, 'descent' => 610, 'ele_min' => 935, 'ele_max' => 1456, 'ele_from' => 1303, 'ele_to' => 994, 'distance' => 99]);

    (new UpdateUgcTrackDemStatsJob($this->track->id, $before['computed_at']))->handle(app(EcTrackService::class));

    $after = $this->track->fresh()->properties['stats'];
    expect($after['ascent'])->toBe(313);
    expect($after['ele_to'])->toBe(994);
    expect($after['distance'])->toBe($before['distance']);
});

it('non scrive se nel frattempo i punti sono cambiati (computed_at diverso)', function () {
    // Con computed_at diverso il job esce prima di chiamare il servizio DEM.
    $mock = Mockery::mock(EcTrackService::class);
    $mock->shouldNotReceive('fetchDemTechData');
    app()->instance(EcTrackService::class, $mock);

    (new UpdateUgcTrackDemStatsJob($this->track->id, '2000-01-01T00:00:00Z'))->handle(app(EcTrackService::class));

    expect($this->track->fresh()->properties['stats']['ascent'])->toBeNull();
});

it('lascia le chiavi DEM a null e rilancia l\'eccezione se il servizio fallisce', function () {
    fakeDem(null, fails: true);
    $computedAt = $this->track->fresh()->properties['stats']['computed_at'];

    expect(fn () => (new UpdateUgcTrackDemStatsJob($this->track->id, $computedAt))->handle(app(EcTrackService::class)))
        ->toThrow(Exception::class);
    expect($this->track->fresh()->properties['stats']['ascent'])->toBeNull();
});
