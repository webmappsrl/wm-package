<?php

// namespace Tests\Unit\Services\EcTrackService;

// use Illuminate\Foundation\Testing\DatabaseTransactions;
// use Illuminate\Support\Collection;
// use Illuminate\Support\Facades\DB;
// use Wm\WmPackage\Services\Models\EcTrackService;
// use Wm\WmPackage\Models\EcTrack;
// use Wm\WmPackage\Models\User;
// use Carbon\Carbon;
// class GetUpdatedAtTracksTest extends AbstractEcTrackServiceTest
// {
//     use DatabaseTransactions;
    
//     public function test_get_updated_at_tracks_for_existing_user()
//     {
//         $user = $this->createTestUser();

//         $track1 = $this->createTrackWithFields([
//             'user_id' => $user->id,
//         ]);
//         $track2 = $this->createTrackWithFields([
//             'user_id' => $user->id,
//         ]);
//         $updatedAtTracks = $this->ecTrackService->getUpdatedAtTracks($user);
//         $this->assertTrue($updatedAtTracks->has($track1->id), "Il track1 non è stato trovato nella collection.");
//         $this->assertTrue($updatedAtTracks->has($track2->id), "Il track2 non è stato trovato nella collection.");
//         $this->assertEquals(
//             $track1->updated_at->toDateTimeString(),
//             Carbon::parse($updatedAtTracks[$track1->id])->toDateTimeString(),
//             "La data di aggiornamento di track1 non corrisponde."
//         );
//         $this->assertEquals(
//             $track2->updated_at->toDateTimeString(),
//             Carbon::parse($updatedAtTracks[$track2->id])->toDateTimeString(),
//             "La data di aggiornamento di track2 non corrisponde."
//         );
//     }

//     protected function createTestUser(): User
//     {
//         return User::create([
//             'name'    => 'Test User',
//             'email'   => 'testuser@example.com',
//             'password'=> bcrypt('secret'),
//             'sku'     => 'test-sku',
//             'app_id'  => 1,
//         ]);
//     }

//     public function test_get_updated_at_tracks_without_user()
//     {
//         $dbResult = [
//             (object) ['id' => 1, 'updated_at' => '2025-02-10 12:00:00'],
//             (object) ['id' => 2, 'updated_at' => '2025-02-11 12:00:00'],
//         ];
//         $query = 'select id, updated_at from ec_tracks where user_id != 20548 and user_id != 17482';
//         DB::shouldReceive('select')
//             ->with($query)
//             ->once()
//             ->andReturn($dbResult);

//         $service = EcTrackService::make();
//         $result = $service->getUpdatedAtTracks(null);

//         $expectedCollection = collect($dbResult)->pluck('updated_at', 'id');

//         $this->assertInstanceOf(Collection::class, $result);
//         $this->assertEquals($expectedCollection, $result);
//     }
// }
