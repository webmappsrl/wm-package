<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

it('imports a single OSM node via CLI with auto-picked app', function () {
    App::factory()->create();

    Http::fake([
        'api.openstreetmap.org/api/0.6/node/123456.json' => Http::response([
            'elements' => [[
                'type' => 'node',
                'id' => 123_456,
                'lat' => 44.0,
                'lon' => 10.0,
                'timestamp' => '2024-01-01T00:00:00Z',
                'tags' => ['name' => 'CLI Test POI', 'amenity' => 'bench'],
            ]],
        ], 200),
    ]);

    $this->artisan('wm-package:import-ec-pois-from-osm', ['osmids' => '123456'])
        ->assertExitCode(0);
});

it('fails with a clear message when no --app is given and multiple apps exist', function () {
    App::factory()->count(2)->create();

    $this->artisan('wm-package:import-ec-pois-from-osm', ['osmids' => '123456'])
        ->expectsOutput('Pass --app=ID: more than one app exists in the database.')
        ->assertExitCode(2); // Command::INVALID
});
