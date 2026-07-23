<?php

namespace Wm\WmPackage\Tests\Unit\Nova\Fields;

use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Support\Fluent;
use Wm\WmPackage\Nova\Fields\PoiTrackReferenceField\src\PoiTrackReferenceField;
use Wm\WmPackage\Tests\TestCase;

class PoiTrackReferenceFieldTest extends TestCase
{
    public function test_resolve_attribute_returns_track_type_when_track_id_is_set(): void
    {
        $field = PoiTrackReferenceField::make('Reference', 'model_ref');

        $result = $this->callPrivateMethod($field, 'resolveAttribute', [
            ['poi_id' => null, 'track_id' => 7],
            'model_ref',
        ]);

        $this->assertSame(['type' => 'track', 'id' => 7], $result);
    }

    public function test_resolve_attribute_returns_poi_type_when_poi_id_is_set(): void
    {
        $field = PoiTrackReferenceField::make('Reference', 'model_ref');

        $result = $this->callPrivateMethod($field, 'resolveAttribute', [
            ['poi_id' => 12, 'track_id' => null],
            'model_ref',
        ]);

        $this->assertSame(['type' => 'poi', 'id' => 12], $result);
    }

    public function test_resolve_attribute_returns_null_type_when_neither_is_set(): void
    {
        $field = PoiTrackReferenceField::make('Reference', 'model_ref');

        $result = $this->callPrivateMethod($field, 'resolveAttribute', [
            ['poi_id' => null, 'track_id' => null],
            'model_ref',
        ]);

        $this->assertSame(['type' => null, 'id' => null], $result);
    }

    public function test_fill_writes_poi_id_when_type_is_poi(): void
    {
        $field = PoiTrackReferenceField::make('Reference', 'model_ref');
        $model = new Fluent;

        $request = NovaRequest::create('/', 'POST', [
            'model_ref' => json_encode(['type' => 'poi', 'id' => 9]),
        ]);

        $this->callPrivateMethod($field, 'fillAttributeFromRequest', [$request, 'model_ref', $model, 'model_ref']);

        $this->assertSame(9, $model->poi_id);
        $this->assertNull($model->track_id);
    }

    public function test_fill_writes_track_id_when_type_is_track(): void
    {
        $field = PoiTrackReferenceField::make('Reference', 'model_ref');
        $model = new Fluent;

        $request = NovaRequest::create('/', 'POST', [
            'model_ref' => json_encode(['type' => 'track', 'id' => 4]),
        ]);

        $this->callPrivateMethod($field, 'fillAttributeFromRequest', [$request, 'model_ref', $model, 'model_ref']);

        $this->assertSame(4, $model->track_id);
        $this->assertNull($model->poi_id);
    }

    public function test_fill_writes_both_null_when_id_is_missing(): void
    {
        $field = PoiTrackReferenceField::make('Reference', 'model_ref');
        $model = new Fluent;

        $request = NovaRequest::create('/', 'POST', [
            'model_ref' => json_encode(['type' => null, 'id' => null]),
        ]);

        $this->callPrivateMethod($field, 'fillAttributeFromRequest', [$request, 'model_ref', $model, 'model_ref']);

        $this->assertNull($model->poi_id);
        $this->assertNull($model->track_id);
    }
}
