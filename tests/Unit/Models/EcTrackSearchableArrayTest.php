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

    public function test_name_translations_contains_all_filled_languages()
    {
        $track = EcTrack::factory()->createQuietly([
            'osmid' => null,
            'name' => ['it' => 'V6 – Anello di Vecchiano', 'en' => 'V6 - Loop of Vecchiano', 'fr' => ''],
        ]);

        $array = $track->toSearchableArray();

        // name_translations serve all'API per mostrare il nome nella lingua dell'utente (oc:8681);
        // name resta l'italiano, su cui si ordina.
        $this->assertSame(
            ['it' => 'V6 – Anello di Vecchiano', 'en' => 'V6 - Loop of Vecchiano'],
            $array[EcTrack::SEARCH_NAME_TRANSLATIONS_FIELD]
        );
        $this->assertSame('V6 – Anello di Vecchiano', $array['name']);
    }

    public function test_name_translations_is_empty_when_the_name_is_empty()
    {
        $track = EcTrack::factory()->createQuietly(['osmid' => null, 'name' => []]);

        $this->assertSame([], $track->toSearchableArray()[EcTrack::SEARCH_NAME_TRANSLATIONS_FIELD]);
    }

    public function test_name_translations_is_empty_when_the_name_is_null()
    {
        $track = EcTrack::factory()->createQuietly(['osmid' => null]);
        $track->setRawAttributes(array_merge($track->getAttributes(), ['name' => null]));

        $this->assertSame([], $track->toSearchableArray()[EcTrack::SEARCH_NAME_TRANSLATIONS_FIELD]);
    }
}
