<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Http\Requests\ResourceDetailRequest;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackDemJob;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\Traits\DispatchesDemOnDetail;

/**
 * Una Resource finta che espone il trait: basta un oggetto con $resource.
 */
function demOnDetailResource(mixed $model): object
{
    return new class($model)
    {
        use DispatchesDemOnDetail;

        public function __construct(public mixed $resource) {}

        public function run(NovaRequest $request): void
        {
            $this->dispatchDemOnDetail($request);
        }
    };
}

beforeEach(function () {
    // Setup disco e shard: senza, la factory EcTrack fallisce su
    // StorageService::getShardName() (pattern gia' usato in
    // MultiLineStringDemTest, describe('EcTrack')).
    config(['wm-package.shard_name' => 'test_shard']);
    Storage::fake('s3');
    Storage::fake('wmfe');
    config([
        'filesystems.disks.s3.key' => 'dummy_key',
        'filesystems.disks.s3.secret' => 'dummy_secret',
        'filesystems.disks.s3.region' => 'us-east-1',
        'filesystems.disks.s3.bucket' => 'dummy_bucket',
        'filesystems.disks.s3.url' => '',
        'filesystems.disks.wmfe.driver' => 'local',
        'medialibrary.disk_name' => 'public',
    ]);

    Bus::fake();
    $this->track = EcTrack::factory()->create(['osmid' => null, 'properties' => []]);
    // Il lock del modello e quello ShouldBeUnique del job vivono in $locks di
    // ArrayStore, un array separato da quello che flush() svuota: serve un
    // driver nuovo di zecca (pattern gia' usato in MultiLineStringDemTest).
    app('cache')->forgetDriver('redis');
    Bus::fake();
});

it('sul dettaglio accoda il DEM mancante', function () {
    demOnDetailResource($this->track->fresh())->run(ResourceDetailRequest::create('/'));

    Bus::assertDispatched(UpdateEcTrackDemJob::class);
});

it('fuori dal dettaglio non accoda nulla', function () {
    demOnDetailResource($this->track->fresh())->run(NovaRequest::create('/'));

    Bus::assertNothingDispatched();
});

it('su un model che non e una MultiLineString non fa nulla', function () {
    demOnDetailResource(new EcPoi)->run(ResourceDetailRequest::create('/'));

    Bus::assertNothingDispatched();
});
