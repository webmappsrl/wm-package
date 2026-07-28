<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\Osm\OsmPoiImporter;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

it('does not let two different apps overwrite each other on the same osmid', function () {
    $appA = App::factory()->create();
    $appB = App::factory()->create();

    Http::fake([
        'api.openstreetmap.org/api/0.6/node/555000111.json' => Http::response([
            'elements' => [[
                'type' => 'node',
                'id' => 555_000_111,
                'lat' => 44.0,
                'lon' => 10.0,
                'timestamp' => '2024-01-01T00:00:00Z',
                'tags' => ['name' => 'Shared OSM node', 'amenity' => 'bench'],
            ]],
        ], 200),
    ]);

    $importer = app(OsmPoiImporter::class);

    $importer->importNodes([555_000_111], (int) $appA->id, null, false, true);
    $importer->importNodes([555_000_111], (int) $appB->id, null, false, true);

    $poiA = EcPoi::query()->where('app_id', $appA->id)->where('osmid', 555_000_111)->first();
    $poiB = EcPoi::query()->where('app_id', $appB->id)->where('osmid', 555_000_111)->first();

    expect($poiA)->not->toBeNull()
        ->and($poiB)->not->toBeNull()
        ->and($poiA->id)->not->toBe($poiB->id);
});
