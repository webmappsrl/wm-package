<?php

declare(strict_types=1);

use Wm\WmPackage\Dto\OsmEcPoiPropertiesData;
use Wm\WmPackage\Dto\OsmNodePoiData;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

it('prefers information=guidepost over tourism=information for taxonomy key', function () {
    $dto = OsmNodePoiData::fromOsmNode(
        73_057_667_56,
        [
            'name' => 'Passo',
            'tourism' => 'information',
            'information' => 'guidepost',
        ],
        ['type' => 'Point', 'coordinates' => [10.1, 44.2]],
    );

    expect($dto->poiTypeOsmKey)->toBe('information')
        ->and($dto->poiTypeOsmValue)->toBe('guidepost')
        ->and($dto->poiTypeCompositeIdentifier())->toBe('information-guidepost');
});

it('normalizes composite identifiers for taxonomy matching', function () {
    expect(OsmNodePoiData::normalizeIdentifier('Tourism-Viewpoint'))->toBe('tourism-viewpoint')
        ->and(OsmNodePoiData::normalizeIdentifier('post_office'))->toBe('post-office');
});

it('rejects non-point geometry', function () {
    expect(fn () => OsmNodePoiData::fromOsmNode(
        1,
        ['name' => 'X'],
        ['type' => 'LineString', 'coordinates' => [[0, 0], [1, 1]]],
    ))->toThrow(\InvalidArgumentException::class);
});

it('maps OSM tags to properties payload', function () {
    $data = OsmEcPoiPropertiesData::fromOsmTags(
        [
            'name' => 'Poste',
            'amenity' => 'post_office',
            'phone' => '+390000',
            'addr:city' => 'Test City',
            'addr:street' => 'Via Roma',
            'addr:housenumber' => '1',
            'addr:postcode' => '41000',
            'opening_hours' => '24/7',
            'description:it' => 'Desc IT',
            'ref' => 'REF-1',
        ],
        ['osmid' => 4_769_303_114, 'type' => 'node', 'source_updated_at' => '2020-01-01 00:00:00', 'tags' => []],
    );

    $arr = $data->toArray();

    expect($arr['contact_phone'])->toBe('+390000')
        ->and($arr['opening_hours'])->toBe('24/7')
        ->and($arr['addr_locality'])->toBe('Test City')
        ->and($arr['addr_housenumber'])->toBe('1')
        ->and($arr['addr_complete'])->toContain('Via Roma')
        ->and($arr['addr_complete'])->toContain('41000')
        ->and($arr['description']['it'])->toBe('Desc IT')
        ->and($arr['out_source_feature_id'])->toBe('REF-1');
});
