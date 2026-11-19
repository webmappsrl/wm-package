<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Models\App;


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
        // Creare il POI interroga osmfeatures per le aree amministrative: qui non si verifica
        // quel servizio, si finge perche' il test non esca in rete.
        '*osmfeatures*' => Http::response(['features' => []]),
        // Stesso motivo: il POI prende la quota dal DEM.
        '*dem*' => Http::response(['ele' => 0]),
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
