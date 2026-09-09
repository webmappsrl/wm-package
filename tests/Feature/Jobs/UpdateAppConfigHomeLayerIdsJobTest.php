<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Jobs\UpdateAppConfigHomeLayerIdsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;

uses(DatabaseTransactions::class);

it('remaps Geohub layer ids to local ids preserving order', function () {
    $app = App::factory()->createQuietly();
    $a = Layer::factory()->createQuietly(['app_id' => $app->id, 'properties' => ['geohub_id' => 133]]);
    $b = Layer::factory()->createQuietly(['app_id' => $app->id, 'properties' => ['geohub_id' => 137]]);

    setConfigHome($app, [133, 137]);
    (new UpdateAppConfigHomeLayerIdsJob($app->id))->handle();

    expect(homeLayerIds($app))->toBe([$a->id, $b->id]);
});

it('does not remap an id that is already a local layer of this app', function () {
    $app = App::factory()->createQuietly();
    $local = Layer::factory()->createQuietly(['app_id' => $app->id, 'properties' => ['geohub_id' => 999]]);

    setConfigHome($app, [$local->id]);
    (new UpdateAppConfigHomeLayerIdsJob($app->id))->handle();

    expect(homeLayerIds($app))->toBe([$local->id]);
});

it('is idempotent when run twice', function () {
    $app = App::factory()->createQuietly();
    $a = Layer::factory()->createQuietly(['app_id' => $app->id, 'properties' => ['geohub_id' => 133]]);

    setConfigHome($app, [133]);
    (new UpdateAppConfigHomeLayerIdsJob($app->id))->handle();
    (new UpdateAppConfigHomeLayerIdsJob($app->id))->handle();

    expect(homeLayerIds($app))->toBe([$a->id]);
});

it('leaves an unresolvable id untouched instead of guessing', function () {
    $app = App::factory()->createQuietly();
    setConfigHome($app, [424242]);
    (new UpdateAppConfigHomeLayerIdsJob($app->id))->handle();

    expect(homeLayerIds($app))->toBe([424242]);
});

it('does not consult the queue nor release itself', function () {
    $source = file_get_contents(__DIR__.'/../../../src/Jobs/UpdateAppConfigHomeLayerIdsJob.php');

    expect($source)->not->toContain('isQueueEmpty')
        ->and($source)->not->toContain('release(')
        ->and($source)->not->toContain('maxAttempts');
});

function setConfigHome(App $app, array $layerIds): void
{
    $home = array_map(
        static fn (int $id) => ['box_type' => 'layer', 'layer' => $id, 'title' => ['it' => 'x']],
        $layerIds
    );

    DB::table('apps')->where('id', $app->id)->update(['config_home' => json_encode(['HOME' => $home])]);
}

function homeLayerIds(App $app): array
{
    $raw = DB::table('apps')->where('id', $app->id)->value('config_home');

    return array_column(json_decode($raw, true)['HOME'], 'layer');
}
