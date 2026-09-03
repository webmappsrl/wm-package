<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Jobs\TaxonomyWhere\FetchTaxonomyWhereGeometryJob;
use Wm\WmPackage\Models\TaxonomyWhere;

beforeEach(function () {
    config()->set('wm-package.clients.osmfeatures.host', 'https://osmfeatures.test');
});

it('does not fall back to an arbitrary language when it and en are missing', function () {
    Http::fake([
        '*/admin-areas/list*' => Http::response([
            'data' => [
                ['id' => 'R19621461', 'name' => ['ko' => '올리아스트라'], 'updated_at' => null],
            ],
        ]),
    ]);

    $items = app(OsmfeaturesClient::class)->getAdminAreasIds('9,39,10,41', 6);

    expect($items[0]['name'])->toBeNull();
});

it('keeps the italian name when available', function () {
    Http::fake([
        '*/admin-areas/list*' => Http::response([
            'data' => [
                ['id' => 'R276369', 'name' => ['it' => 'Cagliari', 'ko' => '칼리아리'], 'updated_at' => null],
            ],
        ]),
    ]);

    $items = app(OsmfeaturesClient::class)->getAdminAreasIds('9,39,10,41', 6);

    expect($items[0]['name'])->toBe('Cagliari');
});

it('replaces the placeholder id with the base name from the detail endpoint', function () {
    Http::fake([
        '*/admin-areas/R19621461' => Http::response([
            'type' => 'Feature',
            'properties' => ['name' => 'Ogliastra', 'admin_level' => 6],
            'geometry' => null,
        ]),
    ]);

    $where = TaxonomyWhere::create([
        'name' => 'R19621461',
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R19621461'],
    ]);

    (new FetchTaxonomyWhereGeometryJob($where->id))->handle(app(OsmfeaturesClient::class));

    expect($where->fresh()->getTranslation('name', 'it'))->toBe('Ogliastra');
});

it('does not overwrite a reliable italian name with the base name', function () {
    Http::fake([
        '*/admin-areas/R276369' => Http::response([
            'type' => 'Feature',
            'properties' => ['name' => 'Cagliari (OSM)', 'admin_level' => 6],
            'geometry' => null,
        ]),
    ]);

    $where = TaxonomyWhere::create([
        'name' => ['it' => 'Cagliari'],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R276369'],
    ]);

    (new FetchTaxonomyWhereGeometryJob($where->id))->handle(app(OsmfeaturesClient::class));

    expect($where->fresh()->getTranslation('name', 'it'))->toBe('Cagliari');
});
