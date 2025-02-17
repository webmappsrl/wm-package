<?php

namespace Tests\Unit\Services\EcTrackService;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\EcTrackService;
use Mockery;

class GetUpdatedAtTracksTest extends AbstractEcTrackServiceTest
{
    public function test_getUpdatedAtTracks_with_user()
    {
         $user = $this->createMockUser(10);
         $fakeResult = [
             (object)['id' => 1, 'updated_at' => '2025-02-10 12:00:00'],
             (object)['id' => 2, 'updated_at' => '2025-02-11 12:00:00']
         ];
         $expectedCollection = collect($fakeResult)->pluck('updated_at', 'id');
         $service = EcTrackService::make();
         $result = $service->getUpdatedAtTracks($user);
         $this->assertInstanceOf(Collection::class, $result);
         $this->assertEquals($expectedCollection, $result);
    }

    public function test_getUpdatedAtTracks_without_user()
    {
         $dbResult = [
             (object)['id' => 1, 'updated_at' => '2025-02-10 12:00:00'],
             (object)['id' => 2, 'updated_at' => '2025-02-11 12:00:00'],
         ];
         $query = 'select id, updated_at from ec_tracks where user_id != 20548 and user_id != 17482';
         DB::shouldReceive('select')
             ->with($query)
             ->once()
             ->andReturn($dbResult);

         $service = EcTrackService::make();
         $result = $service->getUpdatedAtTracks(null);

         $expectedCollection = collect($dbResult)->pluck('updated_at', 'id');

         $this->assertInstanceOf(Collection::class, $result);
         $this->assertEquals($expectedCollection, $result);
    }
}
