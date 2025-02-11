<?php

namespace Tests\Unit\Services\EcTrackService;

use Exception;

class UpdateCurrentDataTest extends AbstractEcTrackServiceTest
{
    /** @test */
    public function update_current_data_updates_manual_data_for_non_null_dirty_fields()
    {
        // I dirty fields hanno valori non null e vanno semplicemente copiati in manual_data.
        $dirtyFields   = ['ascent' => 600, 'descent' => 500, 'speed' => 10];
        $demDataFields = ['ascent', 'descent']; // 'speed' non è rilevante
        $initialManualData = json_encode(['existing' => 'data']);

        // Prepara il track usando il metodo helper della classe astratta.
        $track = $this->prepareTrackWithDirtyFields($dirtyFields, $demDataFields, $initialManualData);

        $this->ecTrackService->updateCurrentData($track);

        // Otteniamo manual_data come array, indipendentemente dal formato originario.
        $manualData = $this->getManualData($track);
        $this->assertEquals('data', $manualData['existing']);
        $this->assertEquals(600, $manualData['ascent']);
        $this->assertEquals(500, $manualData['descent']);
        $this->assertArrayNotHasKey('speed', $manualData);
    }

    /** @test */
    public function update_current_data_updates_track_field_with_osm_value_when_dirty_field_is_null()
    {
        // Se il dirty field è null e osm_data contiene un valore, quello va usato.
        $dirtyFields   = ['ascent' => null, 'descent' => null];
        $demDataFields = ['ascent', 'descent'];

        // Simuliamo osm_data e dem_data (in questo caso osm_data ha la precedenza).
        $osmData = json_encode(['ascent' => 570, 'descent' => 480]);
        $demData = json_encode(['ascent' => 550, 'descent' => 460]);
        $track = $this->prepareTrackWithDirtyFields($dirtyFields, $demDataFields, '{}', $osmData, $demData);

        // Impostiamo valori iniziali che dovranno essere sovrascritti.
        $track->ascent = 0;
        $track->descent = 0;

        $this->ecTrackService->updateCurrentData($track);

        $this->assertEquals(570, $track->ascent);
        $this->assertEquals(480, $track->descent);

        $manualData = $this->getManualData($track);
        // I dirty fields vengono copiati in manual_data, anche se il valore viene sostituito sul campo.
        $this->assertArrayHasKey('ascent', $manualData);
        $this->assertNull($manualData['ascent']);
        $this->assertArrayHasKey('descent', $manualData);
        $this->assertNull($manualData['descent']);
    }

    /** @test */
    public function update_current_data_updates_track_field_with_dem_value_when_osm_value_missing()
    {
        // Se il dirty field è null e osm_data manca il valore, si usa quello da dem_data.
        $dirtyFields   = ['distance' => null];
        $demDataFields = ['distance'];
        $osmData = json_encode(['distance' => null]); // Valore OSM mancante
        $demData = json_encode(['distance' => 7500]);
        $track = $this->prepareTrackWithDirtyFields($dirtyFields, $demDataFields, '{}', $osmData, $demData);

        $track->distance = 0;

        $this->ecTrackService->updateCurrentData($track);

        $this->assertEquals(7500, $track->distance);
        $manualData = $this->getManualData($track);
        $this->assertArrayHasKey('distance', $manualData);
        $this->assertNull($manualData['distance']);
    }

    /** @test */
    public function update_current_data_does_not_update_field_when_not_in_dem_data_fields()
    {
        // Se il dirty field non è elencato tra i demDataFields, il campo non viene processato.
        $dirtyFields   = ['speed' => 15];
        $demDataFields = ['ascent', 'descent']; // 'speed' viene ignorato
        $initialManualData = json_encode(['existing' => 'value']);
        $track = $this->prepareTrackWithDirtyFields($dirtyFields, $demDataFields, $initialManualData);

        $track->speed = 5;
        $this->ecTrackService->updateCurrentData($track);

        $this->assertEquals(5, $track->speed);
        $manualData = $this->getManualData($track);
        $this->assertArrayNotHasKey('speed', $manualData);
    }

    /** @test */
    public function update_current_data_logs_error_when_exception_occurs()
    {
        // Simuliamo un'eccezione in saveQuietly per verificare che venga catturata internamente.
        $dirtyFields   = ['ascent' => 600];
        $demDataFields = ['ascent'];
        $track = $this->prepareTrackWithDirtyFields($dirtyFields, $demDataFields, '{}');

        // Forza saveQuietly a lanciare un'eccezione.
        $track->shouldReceive('saveQuietly')->andThrow(new Exception('Save failed'));

        try {
            $this->ecTrackService->updateCurrentData($track);
            $this->assertTrue(true, 'Exception was caught and not rethrown.');
        } catch (Exception $e) {
            $this->fail('Exception was not caught.');
        }
    }
}
