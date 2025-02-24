<?php

namespace Tests\Unit\OsmFeatures;

use Mockery;
use Mockery\MockInterface;
use Wm\WmPackage\Tests\TestCase;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;

class OsmfeaturesClientTest extends TestCase
{
    private OsmfeaturesClient|MockInterface $client;

    const ERROR_MESSAGES = [
        'Failing_retrieval' => 'Failing during retrieving admin areas (wheres) from osmfeatures: Error 500: Error message',
        'No_intersections' => 'No intersections found for the given geojson',
        'No_default_names' => 'No default names found for the given geojson',
        'No_all_translations' => 'No all translations found for the given geojson',
    ];

    const EXAMPLE_GEOJSON_REQUEST = [
        'type' => 'Point',
        'coordinates' => [0, 0]
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Mockery::mock(OsmfeaturesClient::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
    }

    public function test_get_wheres_with_no_intersections(): void
    {
        $this->client->shouldReceive('getAdminAreasIntersected')
            ->once()
            ->with(self::EXAMPLE_GEOJSON_REQUEST)
            ->andReturn([
                'features' => []
            ]);

        $result = $this->client->getWheresByGeojson(self::EXAMPLE_GEOJSON_REQUEST);

        $this->assertEquals([], $result);
    }

    public function test_get_wheres_with_only_default_names(): void
    {
        $this->client->shouldReceive('getAdminAreasIntersected')
            ->once()
            ->with(self::EXAMPLE_GEOJSON_REQUEST)
            ->andReturn([
                'features' => [
                    [
                        'osm_type' => 'way',
                        'osm_id' => '123',
                        'tags' => [
                            'name' => 'First Place'
                        ]
                    ],
                    [
                        'osm_type' => 'node',
                        'osm_id' => '456',
                        'tags' => [
                            'name' => 'Second Place'
                        ]
                    ]
                ]
            ]);

        $result = $this->client->getWheresByGeojson(self::EXAMPLE_GEOJSON_REQUEST);

        $this->assertEquals([
            'way123' => [
                'it' => 'First Place',
                'en' => 'First Place'
            ],
            'node456' => [
                'it' => 'Second Place',
                'en' => 'Second Place'
            ]
        ], $result);
    }

    public function test_get_wheres_with_all_translations(): void
    {
        $this->client->shouldReceive('getAdminAreasIntersected')
            ->once()
            ->with(self::EXAMPLE_GEOJSON_REQUEST)
            ->andReturn([
                'features' => [
                    [
                        'osm_type' => 'way',
                        'osm_id' => '123',
                        'tags' => [
                            'name' => 'First Place',
                            'name:it' => 'Primo Posto',
                            'name:en' => 'First Place'
                        ]
                    ],
                    [
                        'osm_type' => 'node',
                        'osm_id' => '456',
                        'tags' => [
                            'name' => 'Second Place',
                            'name:it' => 'Secondo Posto',
                            'name:en' => 'Second Place'
                        ]
                    ]
                ]
            ]);

        $result = $this->client->getWheresByGeojson(self::EXAMPLE_GEOJSON_REQUEST);

        $this->assertEquals([
            'way123' => [
                'it' => 'Primo Posto',
                'en' => 'First Place'
            ],
            'node456' => [
                'it' => 'Secondo Posto',
                'en' => 'Second Place'
            ]
        ], $result);
    }

    public function test_get_wheres_with_failed_response(): void
    {
        $this->client->shouldReceive('getAdminAreasIntersected')
            ->once()
            ->andThrow(new \Exception(self::ERROR_MESSAGES['Failing_retrieval']));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(self::ERROR_MESSAGES['Failing_retrieval']);

        $this->client->getWheresByGeojson(self::EXAMPLE_GEOJSON_REQUEST);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}