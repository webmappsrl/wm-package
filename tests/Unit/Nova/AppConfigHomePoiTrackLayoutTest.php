<?php

namespace Wm\WmPackage\Tests\Unit\Nova;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Fields\Repeater;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Nova\App as NovaApp;
use Wm\WmPackage\Tests\TestCase;

class AppConfigHomePoiTrackLayoutTest extends TestCase
{
    use DatabaseTransactions;

    public function test_poi_track_layout_has_no_title_field(): void
    {
        $app = App::factory()->createQuietly();
        $novaApp = new NovaApp($app);

        $fields = $this->callPrivateMethod($novaApp, 'horizontal_scroll_poi_track_layout');

        $this->assertCount(1, $fields);
        $this->assertInstanceOf(Repeater::class, $fields[0]);
        $this->assertSame('items', $fields[0]->attribute);
    }

    public function test_poi_track_items_repeater_is_shown_on_detail(): void
    {
        $app = App::factory()->createQuietly();
        $novaApp = new NovaApp($app);

        $repeater = $this->callPrivateMethod($novaApp, 'horizontalScrollPoiTrackItemsRepeater');

        $this->assertTrue($repeater->isShownOnDetail(NovaRequest::create('/', 'GET'), $app));
    }
}
