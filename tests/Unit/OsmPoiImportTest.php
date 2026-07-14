<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Exceptions\OsmClientExceptionNoTags;
use Wm\WmPackage\Http\Clients\OsmClient;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Services\Osm\OsmPoiImporter;
use Wm\WmPackage\Services\Osm\OsmTaxonomyPoiTypeResolver;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    \Mockery::close();
});

describe('OsmClient with Http::fake (no real OSM calls)', function () {
    it('parses a mocked node JSON response like production OSM API', function () {
        $payload = [
            'elements' => [
                [
                    'type' => 'node',
                    'id' => 99_900_001,
                    'lat' => 44.5,
                    'lon' => 10.25,
                    'timestamp' => '2024-06-01T10:00:00Z',
                    'tags' => [
                        'name' => 'Fake Bench',
                        'amenity' => 'bench',
                    ],
                ],
            ],
        ];

        Http::fake([
            'api.openstreetmap.org/api/0.6/node/99900001.json' => Http::response($payload, 200),
        ]);

        $client = new OsmClient;
        [$properties, $geometry] = $client->getPropertiesAndGeometry('node/99900001');

        expect($geometry['type'])->toBe('Point')
            ->and($geometry['coordinates'])->toBe([10.25, 44.5])
            ->and($properties['name'])->toBe('Fake Bench')
            ->and($properties['amenity'])->toBe('bench')
            ->and($properties)->toHaveKey('_updated_at');
    });
});

describe('OsmPoiImporter dry-run (mocked OSM + taxonomy, no DB writes)', function () {
    it('returns expected outcome without persisting', function () {
        $properties = [
            'name' => 'Test POI',
            'amenity' => 'bench',
            '_updated_at' => '2024-01-01 12:00:00',
        ];
        $geometry = ['type' => 'Point', 'coordinates' => [9.0, 45.0]];

        $osmClient = \Mockery::mock(OsmClient::class);
        $osmClient->shouldReceive('getPropertiesAndGeometry')
            ->once()
            ->with('node/1001')
            ->andReturn([$properties, $geometry]);

        $taxonomy = new TaxonomyPoiType;
        $taxonomy->forceFill([
            'id' => 42,
            'identifier' => 'amenity-bench',
        ]);

        $resolver = \Mockery::mock(OsmTaxonomyPoiTypeResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->andReturn(['taxonomy' => $taxonomy, 'created' => false]);

        $importer = new class ($osmClient, $resolver) extends OsmPoiImporter {
            protected function findExistingEcPoiByOsmid(int $osmid, int $appId): ?\Wm\WmPackage\Models\EcPoi
            {
                return null;
            }
        };

        $report = $importer->importNodes([1001], appId: 1, userId: null, dryRun: true, global: true);

        expect($report->dryRun)->toBeTrue()
            ->and($report->outcomes())->toHaveCount(1)
            ->and($report->outcomes()[0]['action'])->toBe('created')
            ->and($report->outcomes()[0]['osmid'])->toBe(1001)
            ->and($report->outcomes()[0]['ec_poi_id'])->toBeNull()
            ->and($report->outcomes()[0]['taxonomy_identifier'])->toBe('amenity-bench')
            ->and($report->failuresCount())->toBe(0);
    });

    it('records failures when OSM client throws', function () {
        $osmClient = \Mockery::mock(OsmClient::class);
        $osmClient->shouldReceive('getPropertiesAndGeometry')
            ->andThrow(new OsmClientExceptionNoTags('no tags', 1));

        $resolver = \Mockery::mock(OsmTaxonomyPoiTypeResolver::class);
        $resolver->shouldReceive('resolve')->never();

        $importer = new class ($osmClient, $resolver) extends OsmPoiImporter {
            protected function findExistingEcPoiByOsmid(int $osmid, int $appId): ?\Wm\WmPackage\Models\EcPoi
            {
                return null;
            }
        };

        $report = $importer->importNodes([2002], 1, null, true, true);

        expect($report->outcomes())->toBeEmpty()
            ->and($report->failuresCount())->toBe(1)
            ->and($report->failures()[0]['category'])->toBe('no_tags')
            ->and($report->failures()[0]['osmid'])->toBe(2002);
    });
});

describe('Simulated import pipeline (DTO only, no persistence)', function () {
    it('builds EcPoi attributes from mocked OSM node payload', function () {
        Http::fake([
            'api.openstreetmap.org/api/0.6/node/88800001.json' => Http::response([
                'elements' => [
                    [
                        'type' => 'node',
                        'id' => 88_800_001,
                        'lat' => 46.0,
                        'lon' => 11.0,
                        'timestamp' => '2023-05-05T08:00:00Z',
                        'tags' => [
                            'name' => 'Summit',
                            'natural' => 'peak',
                            'ele' => '2000',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = new OsmClient;
        [$props, $geom] = $client->getPropertiesAndGeometry('node/88800001');
        $dto = \Wm\WmPackage\Dto\OsmNodePoiData::fromOsmNode(88_800_001, $props, $geom);
        $attrs = $dto->toEcPoiAttributes(appId: 5, userId: null);

        expect($attrs['app_id'])->toBe(5)
            ->and($attrs['osmid'])->toBe(88_800_001)
            ->and($attrs['properties']['osmid'])->toBe(88_800_001)
            ->and($attrs['properties']['osm_data']['tags']['natural'])->toBe('peak')
            ->and($dto->poiTypeOsmKey)->toBe('natural')
            ->and($dto->poiTypeOsmValue)->toBe('peak');
    });
});
