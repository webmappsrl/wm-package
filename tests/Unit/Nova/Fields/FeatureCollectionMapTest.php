<?php

namespace Tests\Unit\Nova\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Models\Abstracts\MultiLineString;
use Wm\WmPackage\Models\Abstracts\Point;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\Enums\GeometryKind;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMap;

class FeatureCollectionMapTest extends TestCase
{
    protected function makeField(Model $model): FeatureCollectionMap
    {
        $field = new FeatureCollectionMap('Geometry', 'geometry');
        $field->resolve($model, 'geometry');

        return $field;
    }

    /** @test */
    public function it_detects_point_geometry_kind_from_point_model()
    {
        $model = new class extends Point {
        };

        $field = new FeatureCollectionMap('Geometry', 'geometry');
        $reflection = new \ReflectionClass($field);
        $method = $reflection->getMethod('detectGeometryKinds');
        $method->setAccessible(true);

        $kinds = $method->invoke($field, $model);

        $this->assertCount(1, $kinds);
        $this->assertSame(GeometryKind::Point, $kinds[0]);
    }

    /** @test */
    public function it_detects_multilinestring_geometry_kind_from_multilinestring_model()
    {
        $model = new class extends MultiLineString {
        };

        $field = new FeatureCollectionMap('Geometry', 'geometry');
        $reflection = new \ReflectionClass($field);
        $method = $reflection->getMethod('detectGeometryKinds');
        $method->setAccessible(true);

        $kinds = $method->invoke($field, $model);

        $this->assertCount(1, $kinds);
        $this->assertSame(GeometryKind::MultiLineString, $kinds[0]);
    }

    /** @test */
    public function it_detects_multipolygon_geometry_kind_from_polygon_model()
    {
        $model = new Layer();

        $field = new FeatureCollectionMap('Geometry', 'geometry');
        $reflection = new \ReflectionClass($field);
        $method = $reflection->getMethod('detectGeometryKinds');
        $method->setAccessible(true);

        $kinds = $method->invoke($field, $model);

        $this->assertCount(1, $kinds);
        $this->assertSame(GeometryKind::MultiPolygon, $kinds[0]);
    }

    /** @test */
    public function apply_detected_geometry_kinds_sets_meta_when_not_overridden()
    {
        $model = new class extends MultiLineString {
        };

        $field = new FeatureCollectionMap('Geometry', 'geometry');

        $reflection = new \ReflectionClass($field);
        $method = $reflection->getMethod('applyDetectedGeometryKinds');
        $method->setAccessible(true);

        $method->invoke($field, $model);

        $json = $field->jsonSerialize();

        $this->assertArrayHasKey('geometryKinds', $json);
        $this->assertSame([GeometryKind::MultiLineString->value], $json['geometryKinds']);
    }

    /** @test */
    public function for_geometry_kinds_overrides_detected_kinds_and_serialization()
    {
        $model = new class extends MultiLineString {
        };

        $field = new FeatureCollectionMap('Geometry', 'geometry');

        $field->forGeometryKinds(GeometryKind::Point, GeometryKind::MultiPolygon);

        // Dopo l'override, l'auto-detect non deve cambiare i kinds
        $reflection = new \ReflectionClass($field);
        $method = $reflection->getMethod('applyDetectedGeometryKinds');
        $method->setAccessible(true);
        $method->invoke($field, $model);

        $json = $field->jsonSerialize();

        $this->assertSame(
            [GeometryKind::Point->value, GeometryKind::MultiPolygon->value],
            $json['geometryKinds']
        );
    }

    /** @test */
    public function geojson_to_geometry_creates_point_geometry_from_feature_collection()
    {
        $featureCollection = [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [10.0, 45.0],
                    ],
                ],
            ],
        ];

        $json = json_encode($featureCollection);

        DB::shouldReceive('select')
            ->once()
            ->with(
                'SELECT ST_AsText(ST_Force2D(ST_GeomFromGeoJSON(?))) AS wkt',
                [$json]
            )
            ->andReturn([(object) ['wkt' => 'POINT(10 45)']]);

        $field = new FeatureCollectionMap('Geometry', 'geometry');
        $field->forGeometryKinds(GeometryKind::Point);

        $reflection = new \ReflectionClass($field);
        $method = $reflection->getMethod('geojsonToGeometry');
        $method->setAccessible(true);

        $wkt = $method->invoke($field, $featureCollection);

        $this->assertSame('POINT(10 45)', $wkt);
    }

    /** @test */
    public function geojson_to_geometry_creates_multilinestring_geometry()
    {
        $featureCollection = [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'MultiLineString',
                        'coordinates' => [
                            [[0, 0], [1, 1]],
                        ],
                    ],
                ],
            ],
        ];

        $json = json_encode($featureCollection);

        DB::shouldReceive('select')
            ->once()
            ->with(
                'SELECT ST_AsText(ST_LineMerge(ST_Force2D(ST_GeomFromGeoJSON(?)))) AS wkt',
                [$json]
            )
            ->andReturn([(object) ['wkt' => 'MULTILINESTRING((0 0,1 1))']]);

        $field = new FeatureCollectionMap('Geometry', 'geometry');
        $field->forGeometryKinds(GeometryKind::MultiLineString);

        $reflection = new \ReflectionClass($field);
        $method = $reflection->getMethod('geojsonToGeometry');
        $method->setAccessible(true);

        $wkt = $method->invoke($field, $featureCollection);

        $this->assertSame('MULTILINESTRING((0 0,1 1))', $wkt);
    }

    /** @test */
    public function geojson_to_geometry_creates_multipolygon_geometry()
    {
        $featureCollection = [
            'type' => 'MultiPolygon',
            'coordinates' => [
                [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]],
            ],
        ];

        $json = json_encode($featureCollection);

        DB::shouldReceive('select')
            ->once()
            ->with(
                'SELECT ST_AsText(ST_Force2D(ST_GeomFromGeoJSON(?))) AS wkt',
                [$json]
            )
            ->andReturn([(object) ['wkt' => 'MULTIPOLYGON(((0 0,1 0,1 1,0 1,0 0)))']]);

        $field = new FeatureCollectionMap('Geometry', 'geometry');
        $field->forGeometryKinds(GeometryKind::MultiPolygon);

        $reflection = new \ReflectionClass($field);
        $method = $reflection->getMethod('geojsonToGeometry');
        $method->setAccessible(true);

        $wkt = $method->invoke($field, $featureCollection);

        $this->assertSame('MULTIPOLYGON(((0 0,1 0,1 1,0 1,0 0)))', $wkt);
    }

    /** @test */
    public function fill_model_with_data_does_not_change_geometry_when_unchanged()
    {
        $model = new class extends MultiLineString {
        };

        $originalGeometry = 'MULTILINESTRING((0 0,1 1))';
        $model->geometry = $originalGeometry;

        $featureCollection = [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'MultiLineString',
                        'coordinates' => [
                            [[0, 0], [1, 1]],
                        ],
                    ],
                ],
            ],
        ];

        $json = json_encode($featureCollection);

        DB::shouldReceive('select')
            ->once()
            ->with(
                "SELECT ST_AsGeoJSON(ST_GeomFromWKB(decode(?, 'hex'))) as geojson",
                [$originalGeometry]
            )
            ->andReturn([(object) ['geojson' => $json]]);

        DB::shouldReceive('select')
            ->once()
            ->with(
                'SELECT ST_AsText(ST_LineMerge(ST_Force2D(ST_GeomFromGeoJSON(?)))) AS wkt',
                [$json]
            )
            ->andReturn([(object) ['wkt' => $originalGeometry]]);

        $field = new FeatureCollectionMap('Geometry', 'geometry');
        $field->fillModelWithData($model, $featureCollection, 'geometry');

        $this->assertSame($originalGeometry, $model->geometry);
    }

    /** @test */
    public function fill_model_with_data_updates_geometry_when_changed()
    {
        $model = new class extends MultiLineString {
        };

        $originalGeometry = 'MULTILINESTRING((0 0,1 1))';
        $model->geometry = $originalGeometry;

        $featureCollection = [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'MultiLineString',
                        'coordinates' => [
                            [[0, 0], [2, 2]],
                        ],
                    ],
                ],
            ],
        ];

        $json = json_encode($featureCollection);

        DB::shouldReceive('select')
            ->once()
            ->with(
                "SELECT ST_AsGeoJSON(ST_GeomFromWKB(decode(?, 'hex'))) as geojson",
                [$originalGeometry]
            )
            ->andReturn([(object) ['geojson' => $json]]);

        DB::shouldReceive('select')
            ->once()
            ->with(
                'SELECT ST_AsText(ST_LineMerge(ST_Force2D(ST_GeomFromGeoJSON(?)))) AS wkt',
                [$json]
            )
            ->andReturn([(object) ['wkt' => 'MULTILINESTRING((0 0,2 2))']]);

        $field = new FeatureCollectionMap('Geometry', 'geometry');
        $field->fillModelWithData($model, $featureCollection, 'geometry');

        $this->assertSame('MULTILINESTRING((0 0,2 2))', $model->geometry);
    }

    /** @test */
    public function resolve_sets_geojson_meta_using_geometry_to_geojson()
    {
        $model = new class extends MultiLineString {
        };
        $geometry = '0102000000020000000000000000000000000000000000000000000000000000F03F000000000000F03F';

        $model->geometry = $geometry;

        DB::shouldReceive('select')
            ->once()
            ->with("SELECT ST_AsGeoJSON(ST_GeomFromWKB(decode(?, 'hex'))) as geojson", [$geometry])
            ->andReturn([(object) ['geojson' => '{"type":"MultiLineString","coordinates":[[[0,0],[1,1]]]}' ]]);

        $field = new FeatureCollectionMap('Geometry', 'geometry');
        $field->resolve($model, 'geometry');

        $json = $field->jsonSerialize();

        $this->assertArrayHasKey('geojson', $json['meta']);
        $this->assertSame(
            '{"type":"MultiLineString","coordinates":[[[0,0],[1,1]]]}',
            $json['meta']['geojson']
        );
    }

    /** @test */
    public function normalize_geojson_input_handles_null_and_empty_values()
    {
        $field = new FeatureCollectionMap('Geometry', 'geometry');

        $reflection = new \ReflectionClass($field);
        $method = $reflection->getMethod('normalizeGeojsonInput');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($field, null));
        $this->assertNull($method->invoke($field, ''));
        $this->assertNull($method->invoke($field, 'null'));
    }

    /** @test */
    public function normalize_geojson_input_encodes_arrays_and_trims_strings()
    {
        $field = new FeatureCollectionMap('Geometry', 'geometry');

        $reflection = new \ReflectionClass($field);
        $method = $reflection->getMethod('normalizeGeojsonInput');
        $method->setAccessible(true);

        $arrayInput = ['type' => 'Point', 'coordinates' => [10, 45]];
        $normalizedArray = $method->invoke($field, $arrayInput);
        $this->assertSame(
            json_encode($arrayInput, JSON_UNESCAPED_UNICODE),
            $normalizedArray
        );

        $normalizedString = $method->invoke($field, '  {"type":"Point"}  ');
        $this->assertSame('{"type":"Point"}', $normalizedString);
    }

    /** @test */
    public function json_serialize_includes_extra_flags_and_geometry_kinds()
    {
        $field = new FeatureCollectionMap('Geometry', 'geometry');

        $field->withDemEnrichment(true)
            ->withPopupComponent('my-popup')
            ->enableScreenshot(true)
            ->forGeometryKinds(GeometryKind::Point, GeometryKind::MultiPolygon);

        $json = $field->jsonSerialize();

        $this->assertTrue($json['demEnrichment']);
        $this->assertSame('my-popup', $json['popupComponent']);
        $this->assertTrue($json['enableScreenshot']);
        $this->assertSame(
            [GeometryKind::Point->value, GeometryKind::MultiPolygon->value],
            $json['geometryKinds']
        );
    }
}

