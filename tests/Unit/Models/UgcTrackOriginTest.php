<?php

namespace Wm\WmPackage\Tests\Unit\Models;

use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Tests\TestCase;

/**
 * Origine di una traccia UGC (oc:8747, rivista in oc:8165).
 *
 * Nessuno di questi casi tocca il database: `origin()` guarda solo `properties`.
 */
class UgcTrackOriginTest extends TestCase
{
    private function trackWith(array $properties): UgcTrack
    {
        $track = new UgcTrack;
        $track->properties = $properties;

        return $track;
    }

    public function test_a_track_with_properties_import_is_imported()
    {
        // Campo esplicito scritto dall'app dalla oc:8764 in poi (wm-core).
        $track = $this->trackWith(['import' => ['format' => 'gpx', 'file_name' => 'giro.gpx']]);

        $this->assertSame('imported', $track->origin());
    }

    public function test_properties_import_wins_even_with_locations()
    {
        // Un GeoJSON esportato da una registrazione e ricaricato porta ancora
        // locations: il campo esplicito deve avere la precedenza.
        $track = $this->trackWith([
            'import' => ['format' => 'geojson'],
            'locations' => [['latitude' => 44.1, 'longitude' => 10.3]],
        ]);

        $this->assertSame('imported', $track->origin());
    }

    public function test_a_track_with_gpx_type_is_imported()
    {
        // _gpxType lo aggiunge @tmcw/togeojson convertendo un GPX caricato.
        $track = $this->trackWith(['_gpxType' => 'trk']);

        $this->assertSame('imported', $track->origin());
    }

    public function test_a_track_with_non_empty_locations_is_recorded()
    {
        $track = $this->trackWith(['locations' => [
            ['latitude' => 44.1487, 'longitude' => 10.3678],
            ['latitude' => 44.1488, 'longitude' => 10.3679],
        ]]);

        $this->assertSame('recorded', $track->origin());
    }

    public function test_a_track_with_empty_locations_is_imported()
    {
        $track = $this->trackWith(['locations' => []]);

        $this->assertSame('imported', $track->origin());
    }

    public function test_distance_filter_alone_no_longer_means_recorded()
    {
        // Cambiato in oc:8165: distanceFilter è un'impostazione del GPS e resta
        // valorizzata anche su un dato importato, quindi non prova una registrazione.
        $track = $this->trackWith(['distanceFilter' => 10]);

        $this->assertSame('imported', $track->origin());
    }

    public function test_a_track_without_any_hint_is_imported()
    {
        $track = $this->trackWith([]);

        $this->assertSame('imported', $track->origin());
    }
}
