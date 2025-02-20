<?php

namespace Tests\Unit\Services\EcTrackService;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Models\EcTrack;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Services\Models\EcTrackService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class GetUpdatedAtTracksTest extends AbstractEcTrackServiceTest
{
    use DatabaseTransactions;

    public function test_get_updated_at_tracks_for_existing_user()
    {
        $user = $this->createTestUser();

        $track1 = $this->createTrackWithFields([
            'user_id' => $user->id,
        ]);
        $track2 = $this->createTrackWithFields([
            'user_id' => $user->id,
        ]);
        $updatedAtTracks = $this->ecTrackService->getUpdatedAtTracks($user);
        $this->assertTrue($updatedAtTracks->has($track1->id), "Il track1 non è stato trovato nella collection.");
        $this->assertTrue($updatedAtTracks->has($track2->id), "Il track2 non è stato trovato nella collection.");
        $this->assertEquals(
            $track1->updated_at->toDateTimeString(),
            Carbon::parse($updatedAtTracks[$track1->id])->toDateTimeString(),
            "La data di aggiornamento di track1 non corrisponde."
        );
        $this->assertEquals(
            $track2->updated_at->toDateTimeString(),
            Carbon::parse($updatedAtTracks[$track2->id])->toDateTimeString(),
            "La data di aggiornamento di track2 non corrisponde."
        );
    }

    protected function createTestUser(): User
    {
        return User::create([
            'name'    => 'Test User',
            'email'   => 'testuser' . Str::random(4) . '@example.com',
            'password' => bcrypt('secret'),
            'app_id'  => 1,
        ]);
    }

    public function test_get_updated_at_tracks_without_user()
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $this->createTestUser();

        $trackSample = [
            'name' => 'test',
            'properties' => ['excerpt' => 'test'],
            'updated_at' => Carbon::now(),
            'geometry' => "LINESTRING (0 0, 1 1)",
        ];
        $dbResult = [
            (object) ['id' => 1, 'user_id' => $user1->id,  ...$trackSample],
            (object) ['id' => 2, 'user_id' => $user2->id, ...$trackSample],
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
