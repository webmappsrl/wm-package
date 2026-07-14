<?php

namespace Wm\WmPackage\Tests\Unit\Nova\Fields;

use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Support\Fluent;
use ReflectionMethod;
use Wm\WmPackage\Nova\Fields\GeoReferenceField\src\GeoReferenceField;
use Wm\WmPackage\Tests\TestCase;

class GeoReferenceFieldTest extends TestCase
{
    private function callProtectedMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }

    public function test_resolve_attribute_returns_track_type_when_track_id_is_set(): void
    {
        $field = GeoReferenceField::make('Reference', 'geo_ref');

        $result = $this->callProtectedMethod($field, 'resolveAttribute', [
            ['poi_id' => null, 'track_id' => 7],
            'geo_ref',
        ]);

        $this->assertSame(['type' => 'track', 'id' => 7], $result);
    }

    public function test_resolve_attribute_returns_poi_type_when_poi_id_is_set(): void
    {
        $field = GeoReferenceField::make('Reference', 'geo_ref');

        $result = $this->callProtectedMethod($field, 'resolveAttribute', [
            ['poi_id' => 12, 'track_id' => null],
            'geo_ref',
        ]);

        $this->assertSame(['type' => 'poi', 'id' => 12], $result);
    }

    public function test_resolve_attribute_returns_null_type_when_neither_is_set(): void
    {
        $field = GeoReferenceField::make('Reference', 'geo_ref');

        $result = $this->callProtectedMethod($field, 'resolveAttribute', [
            ['poi_id' => null, 'track_id' => null],
            'geo_ref',
        ]);

        $this->assertSame(['type' => null, 'id' => null], $result);
    }

    public function test_fill_writes_poi_id_when_type_is_poi(): void
    {
        $field = GeoReferenceField::make('Reference', 'geo_ref');
        $model = new Fluent;

        $request = NovaRequest::create('/', 'POST', [
            'geo_ref' => json_encode(['type' => 'poi', 'id' => 9]),
        ]);

        $this->callProtectedMethod($field, 'fillAttributeFromRequest', [$request, 'geo_ref', $model, 'geo_ref']);

        $this->assertSame(9, $model->poi_id);
        $this->assertNull($model->track_id);
    }

    public function test_fill_writes_track_id_when_type_is_track(): void
    {
        $field = GeoReferenceField::make('Reference', 'geo_ref');
        $model = new Fluent;

        $request = NovaRequest::create('/', 'POST', [
            'geo_ref' => json_encode(['type' => 'track', 'id' => 4]),
        ]);

        $this->callProtectedMethod($field, 'fillAttributeFromRequest', [$request, 'geo_ref', $model, 'geo_ref']);

        $this->assertSame(4, $model->track_id);
        $this->assertNull($model->poi_id);
    }

    public function test_fill_writes_both_null_when_id_is_missing(): void
    {
        $field = GeoReferenceField::make('Reference', 'geo_ref');
        $model = new Fluent;

        $request = NovaRequest::create('/', 'POST', [
            'geo_ref' => json_encode(['type' => null, 'id' => null]),
        ]);

        $this->callProtectedMethod($field, 'fillAttributeFromRequest', [$request, 'geo_ref', $model, 'geo_ref']);

        $this->assertNull($model->poi_id);
        $this->assertNull($model->track_id);
    }
}
