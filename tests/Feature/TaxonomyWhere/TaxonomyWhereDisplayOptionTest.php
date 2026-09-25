<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\TaxonomyWhereDisplayService;

uses(TestCase::class, DatabaseTransactions::class);

it('lists the categories present in the app data plus the already selected ones', function () {
    $app = App::factory()->create();
    DB::table('ec_pois')->insert([
        'name' => json_encode(['it' => 'Poi']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[13.7,41.4,0]}')"),
        'properties' => json_encode(['taxonomy_where' => [
            'R1' => ['it' => 'Lazio', '_admin_level' => 4],
            'R2' => ['name' => ['it' => 'Esperia'], 'admin_level' => '8'],
            'G1' => ['it' => 'Parco', '_source' => 'geohub'],
            'OLD' => ['it' => 'Ausonia'],
        ]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $options = app(TaxonomyWhereDisplayService::class)->availableCategories($app->id, ['6']);

    expect(array_keys($options))->toEqualCanonicalizing(['4', '8', 'source:geohub', '6']);
    expect($options['4'])->toBe(__('Region'));
    expect($options['source:geohub'])->toBe(__('Source: :source', ['source' => 'geohub']));
});

it('queues the outputs regeneration when the option changes', function () {
    Bus::fake();
    $app = App::factory()->create();

    $properties = $app->properties ?? [];
    $properties['taxonomy_where_display'] = ['4'];
    $app->properties = $properties;
    $app->save();

    Bus::assertDispatched(RegenerateTaxonomyWhereOutputsJob::class, fn ($job) => $job->appId === $app->id);
});

it('does not queue the regeneration when the option is unchanged', function () {
    $app = App::factory()->create(['properties' => ['taxonomy_where_display' => ['4']]]);
    Bus::fake();

    $app->name = $app->name.' bis';
    $app->save();

    Bus::assertNotDispatched(RegenerateTaxonomyWhereOutputsJob::class);
});
