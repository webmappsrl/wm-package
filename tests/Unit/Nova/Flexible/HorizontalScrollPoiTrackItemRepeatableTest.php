<?php

namespace Wm\WmPackage\Tests\Unit\Nova\Flexible;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use ReflectionMethod;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\Fields\PoiTrackReferenceField\src\PoiTrackReferenceField;
use Wm\WmPackage\Nova\Flexible\ConfigHome\HorizontalScrollPoiTrackItemRepeatable;
use Wm\WmPackage\Tests\TestCase;

class HorizontalScrollPoiTrackItemRepeatableTest extends TestCase
{
    use DatabaseTransactions;

    private function requestForApp(?int $appId): NovaRequest
    {
        return NovaRequest::create('/', 'GET', $appId !== null ? ['resourceId' => $appId] : []);
    }

    private function poiTrackReferenceField(array $fields): PoiTrackReferenceField
    {
        return collect($fields)->first(fn ($field) => $field instanceof PoiTrackReferenceField);
    }

    private function titleField(array $fields): Text
    {
        return collect($fields)->first(fn ($field) => $field instanceof Text && $field->attribute === 'title');
    }

    private function callPrivateMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }

    public function test_poi_options_are_scoped_to_the_current_app(): void
    {
        $app = App::factory()->createQuietly();
        $otherApp = App::factory()->createQuietly();

        $ownPoi = EcPoi::factory()->createQuietly(['app_id' => $app->id]);
        EcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

        $fields = (new HorizontalScrollPoiTrackItemRepeatable)->fields($this->requestForApp($app->id));
        $field = $this->poiTrackReferenceField($fields);

        $this->assertArrayHasKey($ownPoi->id, $field->meta['poiOptions']);
        $this->assertCount(1, $field->meta['poiOptions']);
    }

    public function test_track_options_are_scoped_to_the_current_app(): void
    {
        $app = App::factory()->createQuietly();
        $otherApp = App::factory()->createQuietly();

        $ownTrack = EcTrack::factory()->createQuietly(['app_id' => $app->id]);
        EcTrack::factory()->createQuietly(['app_id' => $otherApp->id]);

        $fields = (new HorizontalScrollPoiTrackItemRepeatable)->fields($this->requestForApp($app->id));
        $field = $this->poiTrackReferenceField($fields);

        $this->assertArrayHasKey($ownTrack->id, $field->meta['trackOptions']);
        $this->assertCount(1, $field->meta['trackOptions']);
    }

    public function test_options_are_empty_without_a_current_app_id(): void
    {
        $app = App::factory()->createQuietly();
        EcPoi::factory()->createQuietly(['app_id' => $app->id]);

        $fields = (new HorizontalScrollPoiTrackItemRepeatable)->fields($this->requestForApp(null));
        $field = $this->poiTrackReferenceField($fields);

        $this->assertSame([], $field->meta['poiOptions']);
        $this->assertSame([], $field->meta['trackOptions']);
    }

    public function test_model_field_has_updated_label(): void
    {
        $fields = (new HorizontalScrollPoiTrackItemRepeatable)->fields($this->requestForApp(null));
        $field = $this->poiTrackReferenceField($fields);

        $this->assertSame('Model', $field->name);
    }

    public function test_model_field_has_required_rule(): void
    {
        $fields = (new HorizontalScrollPoiTrackItemRepeatable)->fields($this->requestForApp(null));
        $field = $this->poiTrackReferenceField($fields);

        $this->assertContains('required', $field->rules);
    }

    public function test_title_field_is_readonly(): void
    {
        $fields = (new HorizontalScrollPoiTrackItemRepeatable)->fields($this->requestForApp(null));
        $field = $this->titleField($fields);

        $this->assertTrue($field->isReadonly(NovaRequest::create('/', 'GET')));
    }

    public function test_readonly_title_field_is_empty_for_new_item(): void
    {
        $repeatable = new HorizontalScrollPoiTrackItemRepeatable;

        $title = $this->callPrivateMethod($repeatable, 'resolveInheritedTitle', [['poi_id' => null, 'track_id' => null]]);

        $this->assertSame('', $title);
    }

    public function test_readonly_title_field_resolves_from_linked_poi(): void
    {
        $app = App::factory()->createQuietly();
        $poi = EcPoi::factory()->createQuietly(['app_id' => $app->id, 'name' => ['it' => 'Fonte Sacra', 'en' => 'Sacred Spring']]);

        $repeatable = new HorizontalScrollPoiTrackItemRepeatable;
        $title = $this->callPrivateMethod($repeatable, 'resolveInheritedTitle', [['poi_id' => $poi->id, 'track_id' => null]]);

        $this->assertSame('Fonte Sacra', $title);
    }

    public function test_readonly_title_field_falls_back_through_locales(): void
    {
        $app = App::factory()->createQuietly();
        $poi = EcPoi::factory()->createQuietly(['app_id' => $app->id, 'name' => ['fr' => 'Source Sacrée']]);

        $repeatable = new HorizontalScrollPoiTrackItemRepeatable;
        $title = $this->callPrivateMethod($repeatable, 'resolveInheritedTitle', [['poi_id' => $poi->id, 'track_id' => null]]);

        $this->assertSame('Source Sacrée', $title);
    }

    public function test_readonly_title_field_resolves_from_linked_track(): void
    {
        $app = App::factory()->createQuietly();
        $track = EcTrack::factory()->createQuietly(['app_id' => $app->id, 'name' => ['en' => 'Ridge Trail']]);

        $repeatable = new HorizontalScrollPoiTrackItemRepeatable;
        $title = $this->callPrivateMethod($repeatable, 'resolveInheritedTitle', [['poi_id' => null, 'track_id' => $track->id]]);

        $this->assertSame('Ridge Trail', $title);
    }

    public function test_model_options_label_falls_back_through_all_configured_locales(): void
    {
        $app = App::factory()->createQuietly();
        EcPoi::factory()->createQuietly(['app_id' => $app->id, 'name' => ['es' => 'Fuente Sagrada']]);

        $fields = (new HorizontalScrollPoiTrackItemRepeatable)->fields($this->requestForApp($app->id));
        $field = $this->poiTrackReferenceField($fields);

        $this->assertContains('Fuente Sagrada', $field->meta['poiOptions']);
    }
}
