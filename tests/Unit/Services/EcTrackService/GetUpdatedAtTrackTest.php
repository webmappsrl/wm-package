<?php

namespace Tests\Unit\Services\EcTrackService;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Models\EcTrackService;

class GetUpdatedAtTracksTest extends AbstractEcTrackServiceTest
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('wm-package.shard_name', 'test_shard');

        // Fake S3 and WMFE disks to avoid actual AWS calls and configuration issues
        Storage::fake('s3');
        Storage::fake('wmfe');

        // Set minimal dummy S3 configuration to satisfy any direct config reads
        config([
            'filesystems.disks.s3.key'    => 'dummy_key',
            'filesystems.disks.s3.secret' => 'dummy_secret',
            'filesystems.disks.s3.region' => 'us-east-1',
            'filesystems.disks.s3.bucket' => 'dummy_bucket',
            'filesystems.disks.s3.url'    => '',
            'filesystems.disks.wmfe.driver' => 'local', // Ensure wmfe uses local for tests if faked
            'medialibrary.disk_name' => 'public', // Use a local disk for media library in tests
        ]);
    }

    public function test_get_updated_at_tracks_for_existing_user()
    {
        Queue::fake();
        $app = App::factory()->create();

        $track1 = EcTrack::factory()->create([
            'app_id' => $app->id,
        ]);
        $track2 = EcTrack::factory()->create([
            'app_id' => $app->id,
        ]);
        $updatedAtTracks = $this->ecTrackService->getUpdatedAtTracks($app->id);
        $this->assertTrue($updatedAtTracks->has($track1->id), 'Il track1 non è stato trovato nella collection.');
        $this->assertTrue($updatedAtTracks->has($track2->id), 'Il track2 non è stato trovato nella collection.');
        $this->assertEquals(
            $track1->updated_at->toDateTimeString(),
            Carbon::parse($updatedAtTracks[$track1->id])->toDateTimeString(),
            'La data di aggiornamento di track1 non corrisponde.'
        );
        $this->assertEquals(
            $track2->updated_at->toDateTimeString(),
            Carbon::parse($updatedAtTracks[$track2->id])->toDateTimeString(),
            'La data di aggiornamento di track2 non corrisponde.'
        );
    }

    public function test_get_updated_at_tracks_without_user()
    {
        $app1 = App::factory()->create();
        $app2 = App::factory()->create();

        $trackSample = [
            'name' => 'test',
            'properties' => ['excerpt' => 'test'],
            'updated_at' => Carbon::now(),
            'geometry' => 'LINESTRINGZ (0 0 0, 1 1 0)',
        ];
        $dbResult = [
            (object) ['id' => 1, 'app_id' => $app1->id,  ...$trackSample],
            (object) ['id' => 2, 'app_id' => $app2->id, ...$trackSample],
        ];

        foreach ($dbResult as $row) {
            $track = EcTrack::createQuietly((array) $row);
        }

        $query = 'select id, updated_at from ec_tracks';
        DB::shouldReceive('select')
            ->with($query)
            ->once()
            ->andReturn($dbResult);

        $result = EcTrackService::make()->getUpdatedAtTracks(null);

        $expectedCollection = collect($dbResult)->pluck('updated_at', 'id');

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertEquals($expectedCollection, $result);
    }
}
