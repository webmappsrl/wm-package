<?php

namespace Tests\Unit\Imports\Processors;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Imports\Processors\EcTrackRowProcessor;
use Wm\WmPackage\Tests\TestCase;

class EcTrackRowProcessorTest extends TestCase
{
    /** @test */
    public function apply_writes_valid_headers_into_properties_and_sets_skip_geomixer_tech(): void
    {
        $model = new class extends Model
        {
            protected $guarded = [];

            public $timestamps = false;
        };

        $model->setAttribute('properties', ['existing' => 'keep']);

        $data = [
            'id' => 123,
            'from' => 'A',
            'to' => 'B',
            'ele_from' => 10,
            'distance' => '1,5 km',
            'unknown' => 'skip',
            'duration_forward' => '',
        ];

        (new EcTrackRowProcessor)->apply($model, $data);

        $properties = $model->getAttribute('properties');

        $this->assertSame('keep', $properties['existing']);
        $this->assertSame('A', $properties['from']);
        $this->assertSame('B', $properties['to']);
        $this->assertSame(10, $properties['ele_from']);
        $this->assertSame('1.5', $properties['distance']);
        $this->assertArrayNotHasKey('unknown', $properties);
        $this->assertArrayNotHasKey('duration_forward', $properties);
        $this->assertTrue($properties['skip_geomixer_tech']);
    }
}
