<?php

namespace Tests\Unit\Nova\Traits;

use PHPUnit\Framework\TestCase;
use Wm\WmPackage\Nova\Traits\HasDemClassification;

class HasDemClassificationTest extends TestCase
{
    use HasDemClassification;

    private function makeModel(
        ?array $demData,
        ?array $osmData,
        ?array $manualData,
        ?string $osmid = null
    ): object {
        return new class ($demData, $osmData, $manualData, $osmid) {
            public function __construct(
                private readonly ?array $dem,
                private readonly ?array $osm,
                private readonly ?array $manual,
                public readonly ?string $osmid,
            ) {}

            public function __get(string $name): mixed
            {
                if ($name === 'properties') {
                    return [
                        'dem_data'    => $this->dem,
                        'osm_data'    => $this->osm,
                        'manual_data' => $this->manual,
                    ];
                }

                return null;
            }
        };
    }

    /** 1. tutti vuoti → EMPTY */
    public function test_empty_when_all_sources_null(): void
    {
        $model = $this->makeModel([], [], [], null);
        $result = $this->classifyField($model, 'ascent');
        $this->assertSame('EMPTY', $result['indicator']);
        $this->assertNull($result['currentValue']);
    }

    /** 2. manual valorizzato → MANUAL */
    public function test_manual_wins(): void
    {
        $model = $this->makeModel(
            ['ascent' => 500],
            ['ascent' => 400],
            ['ascent' => 600],
            null
        );
        $result = $this->classifyField($model, 'ascent');
        $this->assertSame('MANUAL', $result['indicator']);
        $this->assertEquals(600, $result['currentValue']);
    }

    /** 3. manual stringa vuota, osmid valorizzato → OSM */
    public function test_osm_wins_when_manual_empty_string(): void
    {
        $model = $this->makeModel(
            ['ascent' => 500],
            ['ascent' => 400],
            ['ascent' => ''],
            'relation/123'
        );
        $result = $this->classifyField($model, 'ascent');
        $this->assertSame('OSM', $result['indicator']);
        $this->assertEquals(400, $result['currentValue']);
    }

    /** 4. osmid null con osm_data valorizzato → non OSM, usa DEM */
    public function test_osm_not_used_when_osmid_null(): void
    {
        $model = $this->makeModel(
            ['ascent' => 500],
            ['ascent' => 400],
            ['ascent' => ''],
            null
        );
        $result = $this->classifyField($model, 'ascent');
        $this->assertSame('DEM', $result['indicator']);
        $this->assertEquals(500, $result['currentValue']);
    }

    /** 5. solo dem_data valorizzato → DEM */
    public function test_dem_fallback(): void
    {
        $model = $this->makeModel(
            ['ascent' => 500],
            [],
            [],
            null
        );
        $result = $this->classifyField($model, 'ascent');
        $this->assertSame('DEM', $result['indicator']);
        $this->assertEquals(500, $result['currentValue']);
    }

    /** 6. manual stringa vuota, osmid null, dem valorizzato → DEM */
    public function test_dem_when_manual_empty_and_no_osmid(): void
    {
        $model = $this->makeModel(
            ['ascent' => 300],
            ['ascent' => 400],
            ['ascent' => ''],
            null
        );
        $result = $this->classifyField($model, 'ascent');
        $this->assertSame('DEM', $result['indicator']);
        $this->assertEquals(300, $result['currentValue']);
    }

    /** 7. properties null → nessun crash, EMPTY */
    public function test_no_crash_with_null_properties(): void
    {
        $model = $this->makeModel(null, null, null, null);
        $result = $this->classifyField($model, 'ascent');
        $this->assertSame('EMPTY', $result['indicator']);
        $this->assertNull($result['currentValue']);
    }

    /** 8. confronto loose == tra stringa e numero */
    public function test_loose_comparison_string_and_int(): void
    {
        $model = $this->makeModel(
            ['ascent' => 10],
            [],
            ['ascent' => ''],
            null
        );
        $result = $this->classifyField($model, 'ascent');
        $this->assertSame('DEM', $result['indicator']);
        $this->assertEquals(10, $result['currentValue']);
    }
}
