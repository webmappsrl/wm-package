<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Wm\WmPackage\Models\TaxonomyWhere;

it('derives the identifier from source and osmfeatures id', function () {
    $where = TaxonomyWhere::create([
        'name' => ['it' => 'Cagliari'],
        'properties' => [
            'source' => 'osmfeatures',
            'osmfeatures_id' => 'R276369',
            'admin_level' => 6,
        ],
    ]);

    expect($where->identifier)->toBe('osmfeatures-r276369');
});

it('ignores the name when a source id is available', function () {
    $where = TaxonomyWhere::create([
        'name' => ['it' => '올리아스트라'],
        'properties' => [
            'source' => 'osmfeatures',
            'osmfeatures_id' => 'R19621461',
        ],
    ]);

    expect($where->identifier)->toBe('osmfeatures-r19621461');
});

it('derives the identifier from source and osm2cai id', function () {
    $where = TaxonomyWhere::create([
        'name' => ['it' => 'Settore Gennargentu'],
        'properties' => ['source' => 'osm2cai', 'osm2cai_id' => 142],
    ]);

    expect($where->identifier)->toBe('osm2cai-142');
});

it('falls back to the slugged name for legacy records without a source id', function () {
    $where = TaxonomyWhere::create([
        'name' => ['it' => 'Area F (Nord)'],
        'properties' => ['source' => 'geohub_conf_32'],
    ]);

    expect($where->identifier)->toBe('geohub-conf-32-area-f-nord');
});

it('stamps the platform name as source on manually created records', function () {
    config()->set('app.name', 'Forestas');

    $where = TaxonomyWhere::create(['name' => ['it' => 'Area Nuova']]);

    expect($where->fresh()->properties['source'])->toBe('forestas');
});

it('uses a clean identifier for the first manually created record', function () {
    config()->set('app.name', 'Forestas');

    $where = TaxonomyWhere::create(['name' => ['it' => 'Area Nuova']]);

    expect($where->fresh()->identifier)->toBe('forestas-area-nuova');
});

it('appends a progressive counter to homonymous manually created records', function () {
    config()->set('app.name', 'Forestas');

    $first = TaxonomyWhere::create(['name' => ['it' => 'Area Nuova']]);
    $second = TaxonomyWhere::create(['name' => ['it' => 'Area Nuova']]);
    $third = TaxonomyWhere::create(['name' => ['it' => 'Area Nuova']]);

    expect($first->fresh()->identifier)->toBe('forestas-area-nuova')
        ->and($second->fresh()->identifier)->toBe('forestas-area-nuova-2')
        ->and($third->fresh()->identifier)->toBe('forestas-area-nuova-3');
});

it('does not change the identifier when the source name is corrected', function () {
    $where = TaxonomyWhere::create([
        'name' => ['it' => '올리아스트라'],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R19621461'],
    ]);

    $where->update(['name' => ['it' => 'Ogliastra']]);

    expect($where->fresh()->identifier)->toBe('osmfeatures-r19621461');
});

it('still syncs the translated name into properties', function () {
    $where = TaxonomyWhere::create([
        'name' => ['it' => 'Cagliari', 'en' => 'Cagliari'],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R276369'],
    ]);

    expect($where->fresh()->properties['name'])->toEqual(['it' => 'Cagliari', 'en' => 'Cagliari']);
});

it('rejects a duplicate identifier with a validation error', function () {
    TaxonomyWhere::create([
        'name' => ['it' => 'Cagliari'],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R276369'],
    ]);

    expect(fn () => TaxonomyWhere::create([
        'name' => ['it' => 'Altro nome'],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R276369'],
    ]))->toThrow(ValidationException::class);
});
