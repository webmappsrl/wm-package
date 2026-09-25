<?php

declare(strict_types=1);

// Wm\WmPackage\Tests\ non e' in autoload-dev del consumer (solo il composer.json del package
// stesso lo dichiara, per la sua suite standalone) — require diretto cosi' `use
// InsertsGeometryFixtures` sotto risolve indipendentemente da quale suite lancia questo file
// (stesso pattern di ImportEcPoiJobTaxonomySyncTest.php, oc:8588).
require_once __DIR__.'/../../Concerns/InsertsGeometryFixtures.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Jobs\TaxonomyWhere\ConservativeSyncTaxonomyWhereJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\TaxonomyWhereResyncService;
use Wm\WmPackage\Tests\Concerns\InsertsGeometryFixtures;

uses(TestCase::class, DatabaseTransactions::class, InsertsGeometryFixtures::class);

function resyncPoi(App $app, array $properties): int
{
    // punto in mezzo al mare, fuori da qualunque taxonomy_where locale di test
    return test()->insertEcPoiWithGeometry($app, $properties, '{"type":"Point","coordinates":[11.0,39.0,0]}');
}

function fakeOsmfeatures(array $wheres): void
{
    $client = Mockery::mock(OsmfeaturesClient::class);
    $client->shouldReceive('getWheresByGeojson')->andReturn($wheres);
    app()->instance(OsmfeaturesClient::class, $client);
}

it('writes the osmfeatures result and keeps a backup of the previous value', function () {
    $app = App::factory()->create();
    $id = resyncPoi($app, ['taxonomy_where' => ['R41241' => ['it' => 'Esperia']]]);
    fakeOsmfeatures(['R40784' => ['it' => 'Lazio', '_admin_level' => 4]]);

    $written = app(TaxonomyWhereResyncService::class)->resyncRecord(EcPoi::class, $id);

    $properties = EcPoi::find($id)->properties;
    expect($written)->toBeTrue();
    expect($properties['taxonomy_where'])->toEqual(['R40784' => ['it' => 'Lazio', '_admin_level' => 4, '_source' => 'osmfeatures']]);
    expect($properties['_taxonomy_where_backup'])->toEqual(['R41241' => ['it' => 'Esperia']]);
});

it('does not overwrite an existing backup on a second run', function () {
    $app = App::factory()->create();
    $id = resyncPoi($app, [
        'taxonomy_where' => ['R40784' => ['it' => 'Lazio', '_admin_level' => 4, '_source' => 'osmfeatures']],
        '_taxonomy_where_backup' => ['R41241' => ['it' => 'Esperia']],
    ]);
    fakeOsmfeatures(['R40784' => ['it' => 'Lazio', '_admin_level' => 4]]);

    app(TaxonomyWhereResyncService::class)->resyncRecord(EcPoi::class, $id);

    expect(EcPoi::find($id)->properties['_taxonomy_where_backup'])->toEqual(['R41241' => ['it' => 'Esperia']]);
});

it('leaves the current value untouched when nothing is found', function () {
    $app = App::factory()->create();
    $id = resyncPoi($app, ['taxonomy_where' => ['R41241' => ['it' => 'Esperia']]]);
    fakeOsmfeatures([]);

    $written = app(TaxonomyWhereResyncService::class)->resyncRecord(EcPoi::class, $id);

    $properties = EcPoi::find($id)->properties;
    expect($written)->toBeFalse();
    expect($properties['taxonomy_where'])->toEqual(['R41241' => ['it' => 'Esperia']]);
    expect($properties)->not->toHaveKey('_taxonomy_where_backup');
});

it('lets an osmfeatures failure bubble up without touching the record', function () {
    $app = App::factory()->create();
    $id = resyncPoi($app, ['taxonomy_where' => ['R41241' => ['it' => 'Esperia']]]);
    $client = Mockery::mock(OsmfeaturesClient::class);
    $client->shouldReceive('getWheresByGeojson')->andThrow(new RuntimeException('timeout'));
    app()->instance(OsmfeaturesClient::class, $client);

    expect(fn () => app(TaxonomyWhereResyncService::class)->resyncRecord(EcPoi::class, $id))
        ->toThrow(RuntimeException::class);
    expect(EcPoi::find($id)->properties['taxonomy_where'])->toEqual(['R41241' => ['it' => 'Esperia']]);
});

it('counts records by format and missing categories in the dry run, without writing', function () {
    $app = App::factory()->create(['properties' => ['taxonomy_where_display' => ['4']]]);
    resyncPoi($app, ['taxonomy_where' => ['R1' => ['it' => 'Esperia']]]);
    resyncPoi($app, ['taxonomy_where' => ['R2' => ['it' => 'Lazio', '_admin_level' => 4]]]);
    resyncPoi($app, ['taxonomy_where' => ['R3' => ['name' => ['it' => 'Comune'], 'admin_level' => 8]]]);
    $before = DB::table('ec_pois')->where('app_id', $app->id)->pluck('properties')->all();

    $report = app(TaxonomyWhereResyncService::class)->dryRunReport($app->id);

    expect($report['ec_pois'])->toMatchArray(['oldest' => 1, 'legacy' => 1, 'oc8487' => 1, 'empty' => 0, 'without_selected_categories' => 2]);
    expect(DB::table('ec_pois')->where('app_id', $app->id)->pluck('properties')->all())->toBe($before);
});

it('queues one conservative job per record, only legacy ones when asked', function () {
    Bus::fake();
    $app = App::factory()->create();
    resyncPoi($app, ['taxonomy_where' => ['R1' => ['it' => 'Esperia']]]);
    resyncPoi($app, ['taxonomy_where' => ['R2' => ['it' => 'Lazio', '_admin_level' => 4]]]);

    $count = app(TaxonomyWhereResyncService::class)->dispatch($app->id, onlyLegacy: true);

    expect($count)->toBe(1);
    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1
        && $batch->jobs->first() instanceof ConservativeSyncTaxonomyWhereJob);
});

it('runs the command in dry-run mode without queueing anything', function () {
    Bus::fake();
    $app = App::factory()->create();
    resyncPoi($app, ['taxonomy_where' => ['R1' => ['it' => 'Esperia']]]);

    $this->artisan('wm:resync-taxonomy-where', ['--app' => $app->id, '--dry-run' => true])
        ->expectsOutputToContain('ec_pois')
        ->assertSuccessful();

    Bus::assertNothingBatched();
    Bus::assertNothingDispatched();
});

it('queues the batch and the finally job on the queue passed via --queue', function () {
    Bus::fake();
    $app = App::factory()->create();
    resyncPoi($app, ['taxonomy_where' => ['R1' => ['it' => 'Esperia']]]);

    $this->artisan('wm:resync-taxonomy-where', ['--app' => $app->id, '--queue' => 'pippo'])
        ->assertSuccessful();

    Bus::assertBatched(fn ($batch) => $batch->queue() === 'pippo'
        && $batch->jobs->count() === 1
        && $batch->jobs->first() instanceof ConservativeSyncTaxonomyWhereJob);
});

it('queues only RegenerateTaxonomyWhereOutputsJob on the chosen queue with --outputs-only, no batch', function () {
    Bus::fake();
    $app = App::factory()->create();
    resyncPoi($app, ['taxonomy_where' => ['R1' => ['it' => 'Esperia']]]);

    $this->artisan('wm:resync-taxonomy-where', ['--app' => $app->id, '--outputs-only' => true, '--queue' => 'pippo'])
        ->assertSuccessful();

    Bus::assertNothingBatched();
    Bus::assertDispatched(RegenerateTaxonomyWhereOutputsJob::class, fn ($job) => $job->appId === $app->id
        && $job->queue === 'pippo');
});

it('counts taxonomy_wheres with a null geometry, globally (not per app)', function () {
    $before = app(TaxonomyWhereResyncService::class)->countWheresMissingGeometry();
    TaxonomyWhere::create(['name' => 'Senza geometria', 'properties' => []]);

    expect(app(TaxonomyWhereResyncService::class)->countWheresMissingGeometry())->toBe($before + 1);
});

it('shows the count of wheres without geometry in dry-run mode (oc:8588)', function () {
    Bus::fake();
    $app = App::factory()->create();
    resyncPoi($app, ['taxonomy_where' => ['R1' => ['it' => 'Esperia']]]);
    $before = DB::table('taxonomy_wheres')->whereNull('geometry')->count();
    TaxonomyWhere::create(['name' => 'Senza geometria', 'properties' => []]);

    $this->artisan('wm:resync-taxonomy-where', ['--app' => $app->id, '--dry-run' => true])
        ->expectsOutputToContain('Where senza geometria: '.($before + 1).'.')
        ->assertSuccessful();

    Bus::assertNothingBatched();
    Bus::assertNothingDispatched();
});

it('does not queue anything and fails when wheres are missing geometry and the confirmation is declined (oc:8588, fix round 1)', function () {
    Bus::fake();
    $app = App::factory()->create();
    resyncPoi($app, ['taxonomy_where' => ['R1' => ['it' => 'Esperia']]]);
    TaxonomyWhere::create(['name' => 'Senza geometria', 'properties' => []]);

    $this->artisan('wm:resync-taxonomy-where', ['--app' => $app->id])
        ->expectsConfirmation('Procedere comunque?', 'no')
        ->assertFailed();

    Bus::assertNothingBatched();
    Bus::assertNothingDispatched();
});

it('queues without asking when --force is passed even if wheres are missing geometry (oc:8588)', function () {
    Bus::fake();
    $app = App::factory()->create();
    resyncPoi($app, ['taxonomy_where' => ['R1' => ['it' => 'Esperia']]]);
    TaxonomyWhere::create(['name' => 'Senza geometria', 'properties' => []]);

    $this->artisan('wm:resync-taxonomy-where', ['--app' => $app->id, '--force' => true])
        ->assertSuccessful();

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1
        && $batch->jobs->first() instanceof ConservativeSyncTaxonomyWhereJob);
});

it('does not warn about missing geometry with --outputs-only (oc:8588)', function () {
    Bus::fake();
    $app = App::factory()->create();
    TaxonomyWhere::create(['name' => 'Senza geometria', 'properties' => []]);

    $this->artisan('wm:resync-taxonomy-where', ['--app' => $app->id, '--outputs-only' => true])
        ->doesntExpectOutputToContain('geometria')
        ->assertSuccessful();

    Bus::assertDispatched(RegenerateTaxonomyWhereOutputsJob::class);
});
