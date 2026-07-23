<?php

namespace Wm\WmPackage\Tests\Unit\Nova\Flexible;

use Laravel\Nova\Support\Fluent;
use Wm\WmPackage\Nova\Flexible\ConfigHome\HorizontalScrollPoiTrackItemRepeatable;
use Wm\WmPackage\Nova\Flexible\ConfigHome\HorizontalScrollPoiTrackRepeaterJsonPreset;
use Wm\WmPackage\Tests\TestCase;

class HorizontalScrollPoiTrackRepeaterJsonPresetTest extends TestCase
{
    public function test_extract_raw_items_reads_from_plain_array_model(): void
    {
        $items = [['type' => HorizontalScrollPoiTrackItemRepeatable::key(), 'fields' => ['poi_id' => 5]]];

        $result = $this->callPrivateMethod(new HorizontalScrollPoiTrackRepeaterJsonPreset, 'extractRawItems', [
            ['items' => $items],
            'items',
        ]);

        $this->assertSame($items, $result);
    }

    public function test_extract_raw_items_returns_null_for_missing_key_in_array_model(): void
    {
        $result = $this->callPrivateMethod(new HorizontalScrollPoiTrackRepeaterJsonPreset, 'extractRawItems', [
            ['title' => []],
            'items',
        ]);

        $this->assertNull($result);
    }

    public function test_extract_raw_items_reads_from_object_model(): void
    {
        $items = [['type' => HorizontalScrollPoiTrackItemRepeatable::key(), 'fields' => ['poi_id' => 5]]];
        $model = new Fluent(['items' => $items]);

        $result = $this->callPrivateMethod(new HorizontalScrollPoiTrackRepeaterJsonPreset, 'extractRawItems', [
            $model,
            'items',
        ]);

        $this->assertSame($items, $result);
    }

    public function test_extract_raw_items_returns_null_for_non_array_non_object_model(): void
    {
        $result = $this->callPrivateMethod(new HorizontalScrollPoiTrackRepeaterJsonPreset, 'extractRawItems', [
            null,
            'items',
        ]);

        $this->assertNull($result);
    }
}
