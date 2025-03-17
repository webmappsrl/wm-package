<?php

namespace Tests\Unit\Services\EcTrackService;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Models\EcTrackService;

class GetUpdatedAtTracksTest extends AbstractEcTrackServiceTest
{
    use DatabaseTransactions;

    public function test_get_updated_at_tracks_for_existing_user()
    {
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
