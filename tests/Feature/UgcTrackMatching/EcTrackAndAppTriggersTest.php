<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Facades\OsmClient;
use Wm\WmPackage\Jobs\MatchEcTrackToUgcTracksJob;
use Wm\WmPackage\Jobs\RematchAppUgcTracksJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Models\EcTrackService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    // AppObserver::saved() scrive la config su AWS: serve uno shard e storage finti.
    config(['wm-package.shard_name' => 'test_shard']);
    Storage::fake('pois');
    Storage::fake('wmfe');
    Storage::fake('conf');
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

    // La factory valorizza osmid: reverse() rifiuta le tracce OSM.
    EcTrack::withoutEvents(fn () => EcTrack::whereKey($id)->update(['osmid' => null, 'properties' => []]));

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

it('updateOsmData accoda il ricalcolo quando la geometria cambia', function () {
    $id = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');
    EcTrack::withoutEvents(fn () => EcTrack::whereKey($id)->update(['properties' => json_encode(['osmid' => 123])]));

    OsmClient::shouldReceive('getGeojson')->once()->andReturn(json_encode([
        'type' => 'Feature',
        'properties' => ['name' => 'Rel', 'ref' => 'R1'],
        'geometry' => ['type' => 'LineString', 'coordinates' => [[12, 44], [12, 44.02]]],
    ]));

    $result = app(EcTrackService::class)->updateOsmData(EcTrack::find($id));

    expect($result['success'])->toBeTrue($result['message']);
    Bus::assertDispatched(MatchEcTrackToUgcTracksJob::class, fn ($j) => $j->ecTrackId === $id);
});
