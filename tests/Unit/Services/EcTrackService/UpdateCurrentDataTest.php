<?php

namespace Tests\Unit\Services\EcTrackService;

use Exception;

class UpdateCurrentDataTest extends AbstractEcTrackServiceTest
{
    const ASCENT_FIELD_LABEL = 'ascent';

    const DESCENT_FIELD_LABEL = 'descent';

    const SPEED_FIELD_LABEL = 'speed';

    const DISTANCE_FIELD_LABEL = 'distance';

    const OSM_DATA_FIELDS = [
        self::ASCENT_FIELD_LABEL => 500,
        self::DESCENT_FIELD_LABEL => 400,
        self::DISTANCE_FIELD_LABEL => 7000,
    ];

    const DEM_DATA_FIELDS = [
        self::ASCENT_FIELD_LABEL => 550,
        self::DESCENT_FIELD_LABEL => 460,
        self::DISTANCE_FIELD_LABEL => 7500,
    ];

    const DIRTY_DATA_FIELDS = [
        self::ASCENT_FIELD_LABEL => 600,
        self::DESCENT_FIELD_LABEL => 500,
        self::DISTANCE_FIELD_LABEL => 10,
        self::SPEED_FIELD_LABEL => 15,
    ];

    const EXCEPTION_MESSAGES = [
        'save_failed' => 'Save failed',
        'caught_exception' => 'Exception was caught and not rethrown.',
        'exception_not_caught' => 'Exception was not caught.',
    ];

    /** @test */
    public function update_current_data_updates_manual_data_for_non_null_dirty_fields()
    {
        // I dirty fields hanno valori non null e vanno semplicemente copiati in manual_data.
        $dirtyFields = [
            self::ASCENT_FIELD_LABEL => self::DIRTY_DATA_FIELDS[self::ASCENT_FIELD_LABEL],
            self::DESCENT_FIELD_LABEL => self::DIRTY_DATA_FIELDS[self::DESCENT_FIELD_LABEL],
            self::SPEED_FIELD_LABEL => self::DIRTY_DATA_FIELDS[self::SPEED_FIELD_LABEL],
        ];
        $demDataFields = [self::ASCENT_FIELD_LABEL, self::DESCENT_FIELD_LABEL]; // 'speed' non è rilevante
        $initialManualData = json_encode(['existing' => 'data']);

        // Prepara il track usando il metodo helper della classe astratta.
        $track = $this->prepareTrackWithDirtyFields($dirtyFields, $demDataFields, $initialManualData);

        $this->ecTrackService->updateCurrentData($track);

        // Otteniamo manual_data come array, indipendentemente dal formato originario.
        $manualData = $this->getManualData($track);
        $this->assertEquals('data', $manualData['existing']);
        $this->assertEquals(self::DIRTY_DATA_FIELDS[self::ASCENT_FIELD_LABEL], $manualData['ascent']);
        $this->assertEquals(self::DIRTY_DATA_FIELDS[self::DESCENT_FIELD_LABEL], $manualData['descent']);
        $this->assertArrayNotHasKey('speed', $manualData);
    }

    /** @test */
    public function update_current_data_updates_track_field_with_osm_value_when_dirty_field_is_null()
    {
        // Se il dirty field è null e osm_data contiene un valore, quello va usato.
        $dirtyFields = [self::ASCENT_FIELD_LABEL => null, self::DESCENT_FIELD_LABEL => null];
        $demDataFields = [self::ASCENT_FIELD_LABEL, self::DESCENT_FIELD_LABEL];

        // Simuliamo osm_data e dem_data (in questo caso osm_data ha la precedenza).
        $osmData = json_encode([self::ASCENT_FIELD_LABEL => self::OSM_DATA_FIELDS[self::ASCENT_FIELD_LABEL], self::DESCENT_FIELD_LABEL => self::OSM_DATA_FIELDS[self::DESCENT_FIELD_LABEL]]);
        $demData = json_encode([self::ASCENT_FIELD_LABEL => self::DEM_DATA_FIELDS[self::ASCENT_FIELD_LABEL], self::DESCENT_FIELD_LABEL => self::DEM_DATA_FIELDS[self::DESCENT_FIELD_LABEL]]);
        $track = $this->prepareTrackWithDirtyFields($dirtyFields, $demDataFields, '{}', $osmData, $demData);

        // Impostiamo valori iniziali che dovranno essere sovrascritti.
        $track->ascent = 0;
        $track->descent = 0;

        $this->ecTrackService->updateCurrentData($track);

        $this->assertEquals(self::OSM_DATA_FIELDS[self::ASCENT_FIELD_LABEL], $track->ascent);
        $this->assertEquals(self::OSM_DATA_FIELDS[self::DESCENT_FIELD_LABEL], $track->descent);

        $manualData = $this->getManualData($track);
        // I dirty fields vengono copiati in manual_data, anche se il valore viene sostituito sul campo.
        $this->assertArrayHasKey(self::ASCENT_FIELD_LABEL, $manualData);
        $this->assertNull($manualData[self::ASCENT_FIELD_LABEL]);
        $this->assertArrayHasKey(self::DESCENT_FIELD_LABEL, $manualData);
        $this->assertNull($manualData[self::DESCENT_FIELD_LABEL]);
    }

    /** @test */
    public function update_current_data_updates_track_field_with_dem_value_when_osm_value_missing()
    {
        // Se il dirty field è null e osm_data manca il valore, si usa quello da dem_data.
        $dirtyFields = [self::DISTANCE_FIELD_LABEL => null];
        $demDataFields = [self::DISTANCE_FIELD_LABEL];
        $osmData = json_encode([self::DISTANCE_FIELD_LABEL => null]); // Valore OSM mancante
        $demData = json_encode([self::DISTANCE_FIELD_LABEL => self::DEM_DATA_FIELDS[self::DISTANCE_FIELD_LABEL]]);
        $track = $this->prepareTrackWithDirtyFields($dirtyFields, $demDataFields, '{}', $osmData, $demData);

        $track->distance = 0;

        $this->ecTrackService->updateCurrentData($track);

        $this->assertEquals(self::DEM_DATA_FIELDS[self::DISTANCE_FIELD_LABEL], $track->distance);
        $manualData = $this->getManualData($track);
        $this->assertArrayHasKey(self::DISTANCE_FIELD_LABEL, $manualData);
        $this->assertNull($manualData[self::DISTANCE_FIELD_LABEL]);
    }

    /** @test */
    public function update_current_data_does_not_update_field_when_not_in_dem_data_fields()
    {
        // Se il dirty field non è elencato tra i demDataFields, il campo non viene processato.
        $dirtyFields = [self::SPEED_FIELD_LABEL => self::DIRTY_DATA_FIELDS[self::SPEED_FIELD_LABEL]];
        $demDataFields = [self::ASCENT_FIELD_LABEL, self::DESCENT_FIELD_LABEL]; // 'speed' viene ignorato
        $initialManualData = json_encode(['existing' => 'value']);
        $track = $this->prepareTrackWithDirtyFields($dirtyFields, $demDataFields, $initialManualData);

        $track->speed = 5;
        $this->ecTrackService->updateCurrentData($track);

        $this->assertEquals(5, $track->speed);
        $manualData = $this->getManualData($track);
        $this->assertArrayNotHasKey(self::SPEED_FIELD_LABEL, $manualData);
    }

    /** @test */
    public function update_current_data_logs_error_when_exception_occurs()
    {
        // Simuliamo un'eccezione in saveQuietly per verificare che venga catturata internamente.
        $dirtyFields = [self::ASCENT_FIELD_LABEL => self::DIRTY_DATA_FIELDS[self::ASCENT_FIELD_LABEL]];
        $demDataFields = [self::ASCENT_FIELD_LABEL];
        $track = $this->prepareTrackWithDirtyFields($dirtyFields, $demDataFields, '{}');

        // Forza saveQuietly a lanciare un'eccezione.
        $track->shouldReceive('saveQuietly')->andThrow(new Exception(self::EXCEPTION_MESSAGES['save_failed']));

        try {
            $this->ecTrackService->updateCurrentData($track);
            $this->assertTrue(true, self::EXCEPTION_MESSAGES['caught_exception']);
        } catch (Exception $e) {
            $this->fail(self::EXCEPTION_MESSAGES['exception_not_caught']);
        }
    }
}
