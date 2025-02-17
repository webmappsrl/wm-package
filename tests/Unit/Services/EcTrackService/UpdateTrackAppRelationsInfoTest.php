<?php

namespace Tests\Unit\Services\EcTrackService;

use Illuminate\Support\Collection;
use Mockery;
use Wm\WmPackage\Models\EcTrack;

class UpdateTrackAppRelationsInfoTest extends AbstractEcTrackServiceTest
{
    protected $track;

    protected function setUp(): void
    {
        parent::setUp();
        $this->track = Mockery::mock(EcTrack::class)->makePartial();
    }

    /** @test */
    public function update_track_app_relations_info_does_not_call_update_when_no_layers()
    {
        // Prepara un track senza layers associati.
        $this->track->associatedLayers = new Collection; // Collection vuota

        // Ci aspettiamo che update non venga chiamato.
        $this->track->shouldNotReceive('update');

        // Chiamata al metodo da testare.
        $this->ecTrackService->updateTrackAppRelationsInfo($this->track);
    }

    /** @test */
    public function update_track_app_relations_info_calls_update_with_correct_updates()
    {
        // Prepara un track con due layers associati.
        // Creiamo due layer fittizi come stdClass con proprietà app_id e id.
        $layer1 = (object) ['app_id' => 'app1', 'id' => 123];
        $layer2 = (object) ['app_id' => 'app2', 'id' => 456];
        $this->track->associatedLayers = new Collection([$layer1, $layer2]);

        // Imposta le proprietà relative al taxonomy sul track.
        $this->track->taxonomyActivities = 'activities_field';
        $this->track->taxonomyThemes = 'themes_field';

        // Simula i metodi getTaxonomyArray e getSearchableString.
        // Ci aspettiamo che vengano chiamati per ogni layer.
        $this->track->shouldReceive('getTaxonomyArray')
            ->with('activities_field')
            ->andReturn('activities_data')
            ->twice();
        $this->track->shouldReceive('getTaxonomyArray')
            ->with('themes_field')
            ->andReturn('themes_data')
            ->twice();
        $this->track->shouldReceive('getSearchableString')
            ->with('app1')
            ->andReturn('searchable_app1')
            ->once();
        $this->track->shouldReceive('getSearchableString')
            ->with('app2')
            ->andReturn('searchable_app2')
            ->once();

        // Costruiamo l'array atteso.
        $expectedUpdates = [
            'layers' => [
                'app1' => 123,
                'app2' => 456,
            ],
            'activities' => [
                'app1' => 'activities_data',
                'app2' => 'activities_data',
            ],
            'themes' => [
                'app1' => 'themes_data',
                'app2' => 'themes_data',
            ],
            'searchable' => [
                'app1' => 'searchable_app1',
                'app2' => 'searchable_app2',
            ],
        ];

        // Aspettiamo che, all'interno di EcTrack::withoutEvents,
        // il metodo update venga chiamato una volta con l'array atteso.
        $this->track->shouldReceive('update')
            ->once()
            ->with($expectedUpdates);

        // Eseguiamo il metodo da testare.
        $this->ecTrackService->updateTrackAppRelationsInfo($this->track);
    }
}
