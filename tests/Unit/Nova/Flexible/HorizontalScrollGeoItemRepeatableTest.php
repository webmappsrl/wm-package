<?php

namespace Wm\WmPackage\Tests\Unit\Nova\Flexible;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\Fields\GeoReferenceField\src\GeoReferenceField;
use Wm\WmPackage\Nova\Flexible\ConfigHome\HorizontalScrollGeoItemRepeatable;
use Wm\WmPackage\Tests\TestCase;

class HorizontalScrollGeoItemRepeatableTest extends TestCase
{
    use DatabaseTransactions;

    private function requestForApp(?int $appId): NovaRequest
    {
        return NovaRequest::create('/', 'GET', $appId !== null ? ['resourceId' => $appId] : []);
    }

    private function geoReferenceField(array $fields): GeoReferenceField
    {
        return collect($fields)->first(fn ($field) => $field instanceof GeoReferenceField);
    }

    public function test_poi_options_are_scoped_to_the_current_app(): void
    {
        $app = App::factory()->createQuietly();
        $otherApp = App::factory()->createQuietly();

        $ownPoi = EcPoi::factory()->createQuietly(['app_id' => $app->id]);
        EcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

        $fields = (new HorizontalScrollGeoItemRepeatable)->fields($this->requestForApp($app->id));
        $field = $this->geoReferenceField($fields);

        $this->assertArrayHasKey($ownPoi->id, $field->meta['poiOptions']);
        $this->assertCount(1, $field->meta['poiOptions']);
    }

    public function test_track_options_are_scoped_to_the_current_app(): void
    {
        $app = App::factory()->createQuietly();
        $otherApp = App::factory()->createQuietly();

        $ownTrack = EcTrack::factory()->createQuietly(['app_id' => $app->id]);
        EcTrack::factory()->createQuietly(['app_id' => $otherApp->id]);

        $fields = (new HorizontalScrollGeoItemRepeatable)->fields($this->requestForApp($app->id));
        $field = $this->geoReferenceField($fields);

        $this->assertArrayHasKey($ownTrack->id, $field->meta['trackOptions']);
        $this->assertCount(1, $field->meta['trackOptions']);
    }

    public function test_options_are_empty_without_a_current_app_id(): void
    {
        $app = App::factory()->createQuietly();
        EcPoi::factory()->createQuietly(['app_id' => $app->id]);

        $fields = (new HorizontalScrollGeoItemRepeatable)->fields($this->requestForApp(null));
        $field = $this->geoReferenceField($fields);

        $this->assertSame([], $field->meta['poiOptions']);
        $this->assertSame([], $field->meta['trackOptions']);
    }
}
