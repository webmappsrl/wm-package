<?php

declare(strict_types=1);

use Wm\WmPackage\Dto\OsmNodePoiData;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Services\Osm\OsmTaxonomyPoiTypeResolver;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

it('creates a new TaxonomyPoiType from an unknown OSM tag pair', function () {
    $dto = OsmNodePoiData::fromOsmNode(
        1,
        ['name' => 'Test', 'amenity' => 'drinking_water'],
        ['type' => 'Point', 'coordinates' => [10.0, 44.0]],
    );

    $resolver = new OsmTaxonomyPoiTypeResolver;
    $result = $resolver->resolve($dto);

    expect($result['created'])->toBeTrue()
        ->and($result['taxonomy'])->toBeInstanceOf(TaxonomyPoiType::class)
        ->and($result['taxonomy']->identifier)->toBe('amenity-drinking-water');

    expect(TaxonomyPoiType::query()->where('identifier', 'amenity-drinking-water')->exists())->toBeTrue();
});

it('reuses an existing TaxonomyPoiType by identifier without creating a duplicate', function () {
    $existing = new TaxonomyPoiType;
    $existing->identifier = 'amenity-bench';
    $existing->setTranslation('name', 'it', 'Panchina');
    $existing->setTranslation('name', 'en', 'Bench');
    $existing->save();

    $dto = OsmNodePoiData::fromOsmNode(
        2,
        ['name' => 'Test', 'amenity' => 'bench'],
        ['type' => 'Point', 'coordinates' => [10.0, 44.0]],
    );

    $resolver = new OsmTaxonomyPoiTypeResolver;
    $result = $resolver->resolve($dto);

    expect($result['created'])->toBeFalse()
        ->and(TaxonomyPoiType::query()->where('identifier', 'amenity-bench')->count())->toBe(1);
});

it('falls back to the generic poi identifier when no classifying tag is present', function () {
    $dto = OsmNodePoiData::fromOsmNode(
        3,
        ['name' => 'Unclassified'],
        ['type' => 'Point', 'coordinates' => [10.0, 44.0]],
    );

    $resolver = new OsmTaxonomyPoiTypeResolver;
    $result = $resolver->resolve($dto);

    expect($result['taxonomy']->identifier)->toBe('poi');
});
