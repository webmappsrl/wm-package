<?php

namespace Wm\WmPackage\Tests\Unit\Nova\Flexible;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\Flexible\ConfigHome\HorizontalScrollPoiTrackItemRepeatable;
use Wm\WmPackage\Nova\Flexible\Resolvers\ConfigHomeResolver;
use Wm\WmPackage\Tests\TestCase;

class ConfigHomeResolverPoiTrackTest extends TestCase
{
    use DatabaseTransactions;

    private function callPrivateMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }

    private function repeaterRow(array $fields): array
    {
        return [
            'type' => HorizontalScrollPoiTrackItemRepeatable::key(),
            'fields' => $fields,
        ];
    }

    private function baseFields(array $overrides = []): array
    {
        return array_merge([
            'poi_id' => null,
            'track_id' => null,
            'title' => [],
            'image_url' => '',
        ], $overrides);
    }

    public function test_valid_poi_reference_is_kept(): void
    {
        $app = App::factory()->createQuietly();
        $poi = EcPoi::factory()->createQuietly(['app_id' => $app->id]);

        $result = $this->callPrivateMethod(new ConfigHomeResolver, 'fromPoiTrackRepeaterItems', [
            [$this->repeaterRow($this->baseFields(['poi_id' => $poi->id, 'title' => ['it' => 'Custom Poi'], 'image_url' => 'http://example.com/a.jpg']))],
            $app,
        ]);

        $this->assertCount(1, $result);
        $this->assertSame($poi->id, $result[0]['poi_id']);
        $this->assertArrayNotHasKey('track_id', $result[0]);
        $this->assertSame('Custom Poi', $result[0]['title']['it']);
        $this->assertSame('http://example.com/a.jpg', $result[0]['image_url']);
    }

    public function test_valid_track_reference_is_kept(): void
    {
        $app = App::factory()->createQuietly();
        $track = EcTrack::factory()->createQuietly(['app_id' => $app->id]);

        $result = $this->callPrivateMethod(new ConfigHomeResolver, 'fromPoiTrackRepeaterItems', [
            [$this->repeaterRow($this->baseFields(['track_id' => $track->id]))],
            $app,
        ]);

        $this->assertCount(1, $result);
        $this->assertSame($track->id, $result[0]['track_id']);
        $this->assertArrayNotHasKey('poi_id', $result[0]);
    }

    public function test_title_is_inherited_from_poi_name_when_not_overridden(): void
    {
        app()->setLocale('it');

        $app = App::factory()->createQuietly();
        $poi = EcPoi::factory()->createQuietly(['app_id' => $app->id, 'name' => ['it' => 'Fonte Sacra', 'en' => 'Sacred Spring']]);

        $result = $this->callPrivateMethod(new ConfigHomeResolver, 'fromPoiTrackRepeaterItems', [
            [$this->repeaterRow($this->baseFields(['poi_id' => $poi->id]))],
            $app,
        ]);

        $this->assertSame('Fonte Sacra', $result[0]['title']['it']);
        $this->assertSame('Sacred Spring', $result[0]['title']['en']);
    }

    public function test_title_override_takes_precedence_over_inherited_poi_name(): void
    {
        $app = App::factory()->createQuietly();
        $poi = EcPoi::factory()->createQuietly(['app_id' => $app->id, 'name' => ['it' => 'Fonte Sacra', 'en' => 'Sacred Spring']]);

        $result = $this->callPrivateMethod(new ConfigHomeResolver, 'fromPoiTrackRepeaterItems', [
            [$this->repeaterRow($this->baseFields(['poi_id' => $poi->id, 'title' => ['it' => 'Custom Poi']]))],
            $app,
        ]);

        $this->assertSame('Custom Poi', $result[0]['title']['it']);
    }

    public function test_image_url_is_empty_when_poi_has_no_media_and_no_override(): void
    {
        $app = App::factory()->createQuietly();
        $poi = EcPoi::factory()->createQuietly(['app_id' => $app->id]);

        $result = $this->callPrivateMethod(new ConfigHomeResolver, 'fromPoiTrackRepeaterItems', [
            [$this->repeaterRow($this->baseFields(['poi_id' => $poi->id]))],
            $app,
        ]);

        $this->assertSame('', $result[0]['image_url']);
    }

    public function test_image_url_override_is_kept_when_provided(): void
    {
        $app = App::factory()->createQuietly();
        $poi = EcPoi::factory()->createQuietly(['app_id' => $app->id]);

        $result = $this->callPrivateMethod(new ConfigHomeResolver, 'fromPoiTrackRepeaterItems', [
            [$this->repeaterRow($this->baseFields(['poi_id' => $poi->id, 'image_url' => 'http://example.com/custom.jpg']))],
            $app,
        ]);

        $this->assertSame('http://example.com/custom.jpg', $result[0]['image_url']);
    }

    public function test_merge_item_image_prefers_override_over_default(): void
    {
        $result = $this->callPrivateMethod(new ConfigHomeResolver, 'mergeItemImage', [
            ['image_url' => 'http://example.com/custom.jpg'],
            'http://example.com/default.jpg',
        ]);

        $this->assertSame('http://example.com/custom.jpg', $result);
    }

    public function test_merge_item_image_falls_back_to_default_when_no_override(): void
    {
        $result = $this->callPrivateMethod(new ConfigHomeResolver, 'mergeItemImage', [
            ['image_url' => ''],
            'http://example.com/default.jpg',
        ]);

        $this->assertSame('http://example.com/default.jpg', $result);
    }

    public function test_title_is_inherited_from_track_name_when_not_overridden(): void
    {
        app()->setLocale('it');

        $app = App::factory()->createQuietly();
        $track = EcTrack::factory()->createQuietly(['app_id' => $app->id, 'name' => ['it' => 'Sentiero del Monte', 'en' => 'Mountain Path']]);

        $result = $this->callPrivateMethod(new ConfigHomeResolver, 'fromPoiTrackRepeaterItems', [
            [$this->repeaterRow($this->baseFields(['track_id' => $track->id]))],
            $app,
        ]);

        $this->assertSame('Sentiero del Monte', $result[0]['title']['it']);
        $this->assertSame('Mountain Path', $result[0]['title']['en']);
    }

    public function test_item_with_neither_field_populated_is_discarded(): void
    {
        $app = App::factory()->createQuietly();

        $result = $this->callPrivateMethod(new ConfigHomeResolver, 'fromPoiTrackRepeaterItems', [
            [$this->repeaterRow($this->baseFields())],
            $app,
        ]);

        $this->assertSame([], $result);
    }

    public function test_item_with_both_fields_populated_is_discarded(): void
    {
        $app = App::factory()->createQuietly();
        $poi = EcPoi::factory()->createQuietly(['app_id' => $app->id]);
        $track = EcTrack::factory()->createQuietly(['app_id' => $app->id]);

        $result = $this->callPrivateMethod(new ConfigHomeResolver, 'fromPoiTrackRepeaterItems', [
            [$this->repeaterRow($this->baseFields(['poi_id' => $poi->id, 'track_id' => $track->id]))],
            $app,
        ]);

        $this->assertSame([], $result);
    }

    public function test_orphan_poi_reference_is_discarded(): void
    {
        $app = App::factory()->createQuietly();

        $result = $this->callPrivateMethod(new ConfigHomeResolver, 'fromPoiTrackRepeaterItems', [
            [$this->repeaterRow($this->baseFields(['poi_id' => 999999999]))],
            $app,
        ]);

        $this->assertSame([], $result);
    }

    public function test_poi_belonging_to_another_app_is_discarded(): void
    {
        $app = App::factory()->createQuietly();
        $otherApp = App::factory()->createQuietly();
        $poiFromOtherApp = EcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

        $result = $this->callPrivateMethod(new ConfigHomeResolver, 'fromPoiTrackRepeaterItems', [
            [$this->repeaterRow($this->baseFields(['poi_id' => $poiFromOtherApp->id]))],
            $app,
        ]);

        $this->assertSame([], $result);
    }

    public function test_to_poi_track_repeater_items_shapes_rows_for_nova_hydration(): void
    {
        $result = $this->callPrivateMethod(new ConfigHomeResolver, 'toPoiTrackRepeaterItems', [
            [
                ['poi_id' => 5, 'track_id' => null, 'title' => ['it' => 'A'], 'image_url' => 'u'],
            ],
        ]);

        $this->assertSame(HorizontalScrollPoiTrackItemRepeatable::key(), $result[0]['type']);
        $this->assertSame(5, $result[0]['fields']['poi_id']);
        $this->assertNull($result[0]['fields']['track_id']);
        $this->assertSame('A', $result[0]['fields']['title']['it']);
    }

    public function test_previous_poi_track_items_for_group_returns_saved_items_when_box_type_matches(): void
    {
        $app = App::factory()->createQuietly();
        $poi = EcPoi::factory()->createQuietly(['app_id' => $app->id]);

        $savedPayload = json_encode([
            'HOME' => [
                [
                    'box_type' => 'horizontal_scroll_geo',
                    'items' => [
                        ['title' => ['it' => 'Saved'], 'image_url' => '', 'poi_id' => $poi->id],
                    ],
                ],
            ],
        ]);

        DB::table('apps')->where('id', $app->id)->update(['config_home' => $savedPayload]);
        $app->refresh();

        $result = $this->callPrivateMethod(new ConfigHomeResolver, 'previousPoiTrackItemsForGroup', [$app, 'config_home', 0]);

        $this->assertNotNull($result);
        $this->assertSame($poi->id, $result[0]['poi_id']);
    }

    public function test_previous_poi_track_items_for_group_returns_null_for_different_box_type(): void
    {
        $app = App::factory()->createQuietly();

        $savedPayload = json_encode([
            'HOME' => [
                ['box_type' => 'horizontal_scroll_taxonomy', 'items' => []],
            ],
        ]);

        DB::table('apps')->where('id', $app->id)->update(['config_home' => $savedPayload]);
        $app->refresh();

        $result = $this->callPrivateMethod(new ConfigHomeResolver, 'previousPoiTrackItemsForGroup', [$app, 'config_home', 0]);

        $this->assertNull($result);
    }
}
