<?php

namespace Tests\Unit\Services\EcTrackService;

use Mockery;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Models\EcTrackService;
use Wm\WmPackage\Http\Clients\OsmClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class UpdateOsmDataTest extends AbstractEcTrackServiceTest
{
    use DatabaseTransactions;

    const OSM_ID = '123456';
    const PROPERTIES_TO_CHECK = [
        'name'              => 'T123 - New Track Name',
        'ref'               => 'T123',
        'duration_forward'  => '150',
        'duration_backward' => '180',
        'geometry'          => 'SRID=4326;LINESTRING(10.0 45.0, 10.5 45.5)',
        'ascent'            => 500,
        'descent'           => 400,
        'distance'          => 7000,
    ];
    const ERROR_MESSAGES = [
        'missing_properties' => 'Undefined array key "properties"',
        'wrong_osm_id'       => 'Wrong OSM ID',
    ];

    /** @test */
    public function update_osm_data_updates_track_with_osm_data()
    {
        $track = Mockery::mock(EcTrack::class)->makePartial();
        $track->osmid = self::OSM_ID;
        $this->prepareTrackWithOsmData($track);

        $result = $this->ecTrackService->updateOsmData($track);

        $this->assertTrue($result['success']);
        foreach (self::PROPERTIES_TO_CHECK as $property => $expectedValue) {
            $this->assertEquals(
                $expectedValue,
                $track->$property,
                "Failed asserting that {$property} matches expected value."
            );
        }
    }

    /** @test */
    public function update_osm_data_fails_when_properties_are_missing()
    {
        $this->rebindOsmClient(MockOsmClientNoProperties::class);

        $track = Mockery::mock(EcTrack::class)->makePartial();
        $track->osmid = self::OSM_ID;

        $result = $this->ecTrackService->updateOsmData($track);

        $this->assertFalse($result['success']);
        $this->assertEquals(self::ERROR_MESSAGES['missing_properties'], $result['message']);
    }

    /**
     * Caso 3: Se manca la geometria.
     * Aspettativa: Viene lanciato l'errore "Wrong OSM ID".
     */
    /** @test */
    public function update_osm_data_fails_when_geometry_is_missing()
    {
        $this->rebindOsmClient(MockOsmClientNoGeometry::class);

        $track = Mockery::mock(EcTrack::class)->makePartial();
        $track->osmid = self::OSM_ID;

        $result = $this->ecTrackService->updateOsmData($track);

        $this->assertFalse($result['success']);
        $this->assertEquals(self::ERROR_MESSAGES['wrong_osm_id'], $result['message']);
    }

    /**
     * Caso 4: I campi vengono aggiornati solo se sono null o non valorizzati.
     * I campi già impostati (es. name, ref, ascent) non vengono sovrascritti,
     * mentre quelli null vengono aggiornati con i dati OSM.
     */
    /** @test */
    public function updates_only_null_fields_are_updated()
    {
        $track = Mockery::mock(EcTrack::class)->makePartial();
        $track->osmid = self::OSM_ID;
        
        // Imposta i campi preesistenti
        $track->name = 'Pre-existing Name';
        $track->ref = 'Pre-existing Ref';
        $track->ascent = 100;
        
        // Imposta i campi che sono null e dovrebbero essere aggiornati
        $track->geometry = null;
        $track->descent = null;
        $track->distance = null;
        $track->duration_forward = null;
        $track->duration_backward = null;

        $this->prepareTrackWithOsmData($track);

        $result = $this->ecTrackService->updateOsmData($track);
        $this->assertTrue($result['success']);

        // Campi che non devono essere sovrascritti
        $unchangedFields = [
            'name'   => 'Pre-existing Name',
            'ref'    => 'Pre-existing Ref',
            'ascent' => 100,
        ];
        // Campi che devono essere aggiornati con i valori dai dati OSM
        $updatedFields = [
            'geometry'          => 'SRID=4326;LINESTRING(10.0 45.0, 10.5 45.5)',
            'descent'           => 400,
            'distance'          => 7000,
            'duration_forward'  => '150',
            'duration_backward' => '180',
        ];

        $this->assertFields($track, $unchangedFields, 'should remain unchanged');
        $this->assertFields($track, $updatedFields, 'should be updated');
    }

    /**
     * Helper method per asserire che i campi del track abbiano i valori attesi.
     */
    


}
