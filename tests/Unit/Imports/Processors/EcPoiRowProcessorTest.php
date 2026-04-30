<?php

namespace Tests\Unit\Imports\Processors;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Imports\Processors\EcPoiRowProcessor;
use Wm\WmPackage\Tests\TestCase;

class EcPoiRowProcessorTest extends TestCase
{
    /** @test */
    public function validate_returns_error_when_required_fields_missing(): void
    {
        $processor = new EcPoiRowProcessor;

        $this->assertStringContainsString('name_it', $processor->validate(['name_it' => '', 'poi_type' => 'bar', 'lat' => 1, 'lng' => 1]) ?? '');
        $this->assertStringContainsString('poi_type', $processor->validate(['name_it' => 'x', 'poi_type' => '', 'lat' => 1, 'lng' => 1]) ?? '');
        $this->assertStringContainsString('lat and lng are required', $processor->validate(['name_it' => 'x', 'poi_type' => 'bar', 'lat' => '', 'lng' => '']) ?? '');
        $this->assertStringContainsString('must be numeric', $processor->validate(['name_it' => 'x', 'poi_type' => 'bar', 'lat' => 'a', 'lng' => 'b']) ?? '');
    }

    /** @test */
    public function apply_builds_point_geometry_from_lat_lng_and_writes_properties(): void
    {
        $model = new class extends Model
        {
            protected $guarded = [];

            public $timestamps = false;

            public function setTranslation(string $key, string $locale, string $value): void
            {
                $map = $this->getAttribute($key) ?? [];
                $map = is_array($map) ? $map : [];
                $map[$locale] = $value;
                $this->setAttribute($key, $map);
            }
        };

        $data = [
            'id' => null,
            'name_it' => 'Rifugio',
            'name_en' => 'Refuge',
            'description_it' => 'desc it',
            'poi_type' => 'rifugio',
            'lat' => '45,5',
            'lng' => '10,25',
            'addr_complete' => 'Via Roma 1',
            'capacity' => '20',
            'contact_email' => 'a@b.c',
            'related_url' => 'https://example.com',
            'errors' => 'ignored',
            'unknown_column' => 'skip',
        ];

        (new EcPoiRowProcessor)->apply($model, $data);

        $this->assertSame('POINT Z (10.25 45.5 0)', $model->getAttribute('geometry'));

        $properties = $model->getAttribute('properties');
        $this->assertSame('Rifugio', $model->getAttribute('name')['it'] ?? null);
        $this->assertSame('Refuge', $model->getAttribute('name')['en'] ?? null);
        $this->assertSame(['it' => 'desc it'], $properties['description']);
        $this->assertSame('rifugio', $properties['poi_type']);
        $this->assertSame('Via Roma 1', $properties['addr_complete']);
        $this->assertSame('20', $properties['capacity']);
        $this->assertSame('a@b.c', $properties['contact_email']);
        $this->assertSame(['https://example.com' => 'https://example.com'], $properties['related_url']);
        $this->assertArrayNotHasKey('errors', $properties);
        $this->assertArrayNotHasKey('unknown_column', $properties);
    }
}
