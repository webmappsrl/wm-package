<?php

namespace Tests\Unit\Services\EcTrackService;

use Exception;
use Wm\WmPackage\Models\EcTrack;
class UpdateManualDataTest extends AbstractEcTrackServiceTest
{
    const ASCENT_FIELD_LABEL = 'ascent';
    const DESCENT_FIELD_LABEL = 'descent';
    const DISTANCE_FIELD_LABEL = 'distance';
    const OSM_DATA_FIELDS = [
        self::ASCENT_FIELD_LABEL => 500,
        self::DESCENT_FIELD_LABEL => 400,
        self::DISTANCE_FIELD_LABEL => 7000,
    ];
    const DEM_DATA_FIELDS = [
        self::ASCENT_FIELD_LABEL => 300,
        self::DESCENT_FIELD_LABEL => 200,
        self::DISTANCE_FIELD_LABEL => 5000,
    ];

    const DIRTY_FIELDS = [
        self::ASCENT_FIELD_LABEL => 550,
        self::DESCENT_FIELD_LABEL => 210,
        self::DISTANCE_FIELD_LABEL => 7100,
    ];
    private EcTrack $track;
    public function setUp(): void
    {
        parent::setUp();
        $osmData = json_encode(self::OSM_DATA_FIELDS);
        $demData = json_encode(self::DEM_DATA_FIELDS);
        $this->track = $this->prepareTrackWithDirtyFields([], [], '{}', $osmData, $demData);
    }
    /** @test */
    public function update_manual_data_updates_field_when_track_value_differs_from_osm_and_dem()
    {
        $this->track->ascent = self::DIRTY_FIELDS[self::ASCENT_FIELD_LABEL];   // diverso da 500 (OSM) e 300 (DEM) → deve essere salvato in manual_data
        $this->track->descent = self::OSM_DATA_FIELDS[self::DESCENT_FIELD_LABEL];  // uguale a OSM, quindi non va registrato
        $this->track->distance = self::OSM_DATA_FIELDS[self::DISTANCE_FIELD_LABEL]; // uguale a OSM, quindi non va registrato

        $this->ecTrackService->updateManualData($this->track);

        $this->assertEquals([
            self::ASCENT_FIELD_LABEL => self::DIRTY_FIELDS[self::ASCENT_FIELD_LABEL]
        ], $this->getManualData($this->track));
    }

    /** @test */
    public function update_manual_data_does_not_update_field_if_track_value_equals_osm_or_dem()
    {
        // Impostiamo i valori dei campi uguali a quelli di OSM o DEM:
        $this->track->ascent = self::OSM_DATA_FIELDS[self::ASCENT_FIELD_LABEL];   // uguale a OSM
        $this->track->descent = self::DEM_DATA_FIELDS[self::DESCENT_FIELD_LABEL];  // uguale a DEM
        $this->track->distance = null; // null non viene considerato

        $this->ecTrackService->updateManualData($this->track);

        // In questo caso nessun campo è diverso, quindi manual_data deve rimanere null.
        $this->assertNull($this->track->manual_data);
    }

    /** @test */
    public function update_manual_data_updates_multiple_fields_correctly()
    {
        $this->track->ascent = self::DIRTY_FIELDS[self::ASCENT_FIELD_LABEL]; // 660
        $this->track->descent = self::DIRTY_FIELDS[self::DESCENT_FIELD_LABEL]; // 210
        $this->track->distance = self::DIRTY_FIELDS[self::DISTANCE_FIELD_LABEL]; // 7100

        $this->ecTrackService->updateManualData($this->track);

        $this->assertEquals([
            self::ASCENT_FIELD_LABEL => self::DIRTY_FIELDS[self::ASCENT_FIELD_LABEL],
            self::DESCENT_FIELD_LABEL => self::DIRTY_FIELDS[self::DESCENT_FIELD_LABEL],
            self::DISTANCE_FIELD_LABEL => self::DIRTY_FIELDS[self::DISTANCE_FIELD_LABEL]
        ], $this->getManualData($this->track));
    }
}
