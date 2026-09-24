<?php

namespace Wm\WmPackage\Tests\Unit\Models;

use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Tests\TestCase;

class EcTrackSearchableArrayTest extends TestCase
{
    public function test_ascent_is_the_manual_value_when_present()
    {
        $track = EcTrack::factory()->createQuietly([
            'properties' => ['manual_data' => ['ascent' => '450'], 'dem_data' => ['ascent' => 300]],
        ]);

        $this->assertSame(450, $track->toSearchableArray()['ascent']);
    }

    public function test_ascent_falls_back_to_the_dem_value()
    {
        $track = EcTrack::factory()->createQuietly([
            'properties' => ['dem_data' => ['ascent' => 300]],
        ]);

        $this->assertSame(300, $track->toSearchableArray()['ascent']);
    }

    public function test_ascent_ignores_the_legacy_first_level_value()
    {
        // Il primo livello di properties è un'eredità di GeoHub (oc:8642): non deve più vincere.
        $track = EcTrack::factory()->createQuietly([
            'properties' => ['ascent' => 999, 'dem_data' => ['ascent' => 300]],
        ]);

        $this->assertSame(300, $track->toSearchableArray()['ascent']);
    }
}
