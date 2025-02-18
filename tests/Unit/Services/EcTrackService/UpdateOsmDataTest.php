<?php

namespace Tests\Unit\Services\EcTrackService;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Wm\WmPackage\Models\EcTrack;

class UpdateOsmDataTest extends AbstractEcTrackServiceTest
{
    use DatabaseTransactions;

    const OSM_ID = '123456';

    const PROPERTIES_TO_CHECK = [
        'name' => 'T123 - New Track Name',
        'ref' => 'T123',
        'duration_forward' => '150',
        'duration_backward' => '180',
        'geometry' => 'SRID=4326;LINESTRING(10.0 45.0, 10.5 45.5)',
        'ascent' => 500,
        'descent' => 400,
        'distance' => 7000,
    ];

    const ERROR_MESSAGES = [
        'should_remain_unchanged' => 'should remain unchanged',
        'should_be_updated' => 'should be updated',
        'unmatched_properties' => 'does not match expected value',
        'missing_properties' => 'Undefined array key "properties"',
        'wrong_osm_id' => 'Wrong OSM ID',
    ];

    const UNCHANGED_FIELDS = [
        'name' => 'Pre-existing Name',
        'ref' => 'Pre-existing Ref',
        'ascent' => 100,
    ];

    const UPDATED_FIELDS = [
            'geometry' => 'SRID=4326;LINESTRING(10.0 45.0, 10.5 45.5)',
            'descent' => 400,
            'distance' => 7000,
            'duration_forward' => '150',
            'duration_backward' => '180',
        ];

    protected $track;

    protected function setUp(): void
    {
        parent::setUp();
        $this->track = $this->createTrackWithFields([
            'osmid' => self::OSM_ID,
        ]);
    }
    /** @test */
    public function update_osm_data_updates_track_with_osm_data()
    {
        $this->prepareTrackWithOsmData($this->track);

        $result = $this->ecTrackService->updateOsmData($this->track);

        $this->assertTrue($result['success']);

        $this->assertFields($this->track, self::PROPERTIES_TO_CHECK, self::ERROR_MESSAGES['unmatched_properties']);
    }

    /** @test */
    public function update_osm_data_fails_when_properties_are_missing()
    {
        $this->rebindOsmClient(MockOsmClientNoProperties::class);

        $result = $this->ecTrackService->updateOsmData($this->track);

        $this->assertFalse($result['success']);
        $this->assertEquals(self::ERROR_MESSAGES['missing_properties'], $result['message']);
    }

    /** @test */
    public function update_osm_data_fails_when_geometry_is_missing()
    {
        $this->rebindOsmClient(MockOsmClientNoGeometry::class);

        $result = $this->ecTrackService->updateOsmData($this->track);

        $this->assertFalse($result['success']);
        $this->assertEquals(self::ERROR_MESSAGES['wrong_osm_id'], $result['message']);
    }

    /** @test */
    public function updates_only_null_fields_are_updated()
    {
        $this->track->name = self::UNCHANGED_FIELDS['name'];
        $this->track->ref = self::UNCHANGED_FIELDS['ref'];
        $this->track->ascent = self::UNCHANGED_FIELDS['ascent'];

        $this->track->geometry = null;
        $this->track->descent = null;
        $this->track->distance = null;
        $this->track->duration_forward = null;
        $this->track->duration_backward = null;

        $this->prepareTrackWithOsmData($this->track);

        $result = $this->ecTrackService->updateOsmData($this->track);
        $this->assertTrue($result['success']);


        $this->assertFields($this->track, self::UNCHANGED_FIELDS, self::ERROR_MESSAGES['should_remain_unchanged']);
        $this->assertFields($this->track, self::UPDATED_FIELDS, self::ERROR_MESSAGES['should_be_updated']);
    }
}
