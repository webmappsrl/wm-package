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
         // Creiamo un mock di User usando l'helper aggiornato
         $user = $this->createMockUser(10);

         // Collection attesa
         $expectedCollection = collect([
             1 => '2025-02-10 12:00:00',
             2 => '2025-02-11 12:00:00'
         ]);

         // Per il ramo "con utente", la funzione esegue:
         // EcTrack::where('user_id', $user->id)->pluck('updated_at', 'id')
         // Creiamo un mock per il query builder che restituisce il risultato atteso
         $queryBuilderMock = Mockery::mock();
         $queryBuilderMock->shouldReceive('pluck')
             ->with('updated_at', 'id')
             ->once()
             ->andReturn($expectedCollection);

         // Impostiamo il mock statico sul modello EcTrack
         $ecTrackAlias = 'alias:' . EcTrack::class;
         Mockery::mock($ecTrackAlias)
             ->shouldReceive('where')
             ->with('user_id', $user->id)
             ->once()
             ->andReturn($queryBuilderMock);

         // Creiamo l'istanza del servizio tramite il metodo factory
         $service = EcTrackService::make();
         $result = $service->getUpdatedAtTracks($user);

         $this->assertInstanceOf(Collection::class, $result);
         $this->assertEquals($expectedCollection, $result);
    }

    public function test_getUpdatedAtTracks_without_user()
    {
         // Definiamo il risultato che la query raw dovrà restituire
         $dbResult = [
             (object)['id' => 1, 'updated_at' => '2025-02-10 12:00:00'],
             (object)['id' => 2, 'updated_at' => '2025-02-11 12:00:00'],
         ];
         $query = 'select id, updated_at from ec_tracks where user_id != 20548 and user_id != 17482';

         // Impostiamo l'aspettativa sul facade DB
         DB::shouldReceive('select')
             ->with($query)
             ->once()
             ->andReturn($dbResult);

         $service = EcTrackService::make();
         $result = $service->getUpdatedAtTracks(null);

         // La funzione converte il risultato in una collection tramite pluck
         $expectedCollection = collect($dbResult)->pluck('updated_at', 'id');

         $this->assertInstanceOf(Collection::class, $result);
         $this->assertEquals($expectedCollection, $result);
    }
}
