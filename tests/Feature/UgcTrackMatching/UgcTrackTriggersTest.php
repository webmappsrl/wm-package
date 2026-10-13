<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Jobs\CleanUgcTrackGeometryJob;
use Wm\WmPackage\Jobs\MatchUgcTrackToEcTracksJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
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
    // Creata senza observer: il job è unico per traccia, un dispatch alla creazione nasconderebbe
    // quello dell'aggiornamento.
    $track = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => []]));

    $track->geometry = DB::raw("ST_GeomFromText('MULTILINESTRING ((11 44, 11 44.01))', 4326)");
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
    $layer = Layer::factory()->createQuietly(['app_id' => $this->app_->id]);
    $ec = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');
    matchingAttachToLayer($layer->id, $ec);

    // Punto a circa 10 m dalla EcTrack (0.0001 gradi di longitudine a 44N ~ 8 m).
    $poi = UgcPoi::factory()->create([
        'user_id' => $this->user->id,
        'app_id' => $this->app_->id,
        'properties' => ['name' => 'poi'],
        'geometry' => DB::raw("ST_GeomFromText('POINT (11.00012 44.005)', 4326)"),
    ]);

    expect($poi->refresh()->properties['layer_id'] ?? null)->toBe($layer->id);
});
