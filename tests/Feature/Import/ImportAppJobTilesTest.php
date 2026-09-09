<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Jobs\Import\ImportAppJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Tile;
use Wm\WmPackage\Services\Import\GeohubImportService;

uses(DatabaseTransactions::class);

it('parses the real double-encoded Geohub tiles column', function () {
    $raw = '["{\"webmapp\":\"https:\/\/api.webmapp.it\/tiles\/{z}\/{x}\/{y}.png\"}"]';

    expect(parseTilesForTest($raw))->toBe([
        ['attribution' => 'webmapp', 'server_xyz' => 'https://api.webmapp.it/tiles/{z}/{x}/{y}.png'],
    ]);
});

it('also parses a plain array of objects, in case Geohub stops double-encoding', function () {
    $raw = '[{"satellite":"https://example.test/{z}/{x}/{y}.jpg"}]';

    expect(parseTilesForTest($raw))->toBe([
        ['attribution' => 'satellite', 'server_xyz' => 'https://example.test/{z}/{x}/{y}.jpg'],
    ]);
});

it('returns an empty array for null, empty string or malformed json', function () {
    expect(parseTilesForTest(null))->toBe([])
        ->and(parseTilesForTest(''))->toBe([])
        ->and(parseTilesForTest('not json'))->toBe([]);
});

it('attaches existing tiles to the pivot preserving Geohub order', function () {
    $app = App::factory()->createQuietly();
    Tile::firstOrCreate(['attribution' => 'webmapp'], tileAttrs('webmapp'));
    Tile::firstOrCreate(['attribution' => 'satellite'], tileAttrs('satellite'));

    syncTilesForTest($app, [
        ['attribution' => 'satellite', 'server_xyz' => 'x'],
        ['attribution' => 'webmapp', 'server_xyz' => 'y'],
    ]);

    expect($app->fresh()->tiles()->pluck('attribution')->all())->toBe(['satellite', 'webmapp']);
});

it('creates a missing Tile with a translations array label, never a bare string', function () {
    $app = App::factory()->createQuietly();

    syncTilesForTest($app, [
        ['attribution' => 'GOMBITELLI', 'server_xyz' => 'https://example.test/g/{z}/{x}/{y}.png'],
    ]);

    $tile = Tile::where('attribution', 'GOMBITELLI')->firstOrFail();

    // label è json NOT NULL con HasTranslations: una stringa nuda produrrebbe JSON invalido
    expect($tile->getTranslations('label'))->toBe(['it' => 'GOMBITELLI', 'en' => 'GOMBITELLI'])
        ->and($tile->server_xyz)->toBe('https://example.test/g/{z}/{x}/{y}.png')
        ->and($tile->icon)->toBeNull();
});

it('never modifies an existing Tile, even when Geohub disagrees on the url', function () {
    $app = App::factory()->createQuietly();
    $existing = Tile::firstOrCreate(['attribution' => 'webmapp'], tileAttrs('webmapp'));
    $originalUrl = $existing->server_xyz;
    $originalUpdatedAt = $existing->updated_at;

    syncTilesForTest($app, [
        ['attribution' => 'webmapp', 'server_xyz' => 'https://a-different-server.test/{z}/{x}/{y}.png'],
    ]);

    $existing->refresh();

    // TileObserver::saved() dispatcha UpdateAppConfigJob per OGNI app collegata al tile:
    // modificare un tile condiviso durante l'import di una app riscriverebbe il config
    // di app non correlate. La creazione è sicura (apps() vuota -> early return).
    expect($existing->server_xyz)->toBe($originalUrl)
        ->and($existing->updated_at->eq($originalUpdatedAt))->toBeTrue();
});

it('is idempotent across a re-import', function () {
    $app = App::factory()->createQuietly();
    Tile::firstOrCreate(['attribution' => 'webmapp'], tileAttrs('webmapp'));

    $parsed = [['attribution' => 'webmapp', 'server_xyz' => 'y']];
    syncTilesForTest($app, $parsed);
    syncTilesForTest($app, $parsed);

    expect($app->fresh()->tiles()->count())->toBe(1);
});

function tileAttrs(string $attribution): array
{
    return [
        'label' => ['it' => $attribution, 'en' => $attribution],
        'server_xyz' => "https://original.test/{$attribution}/{z}/{x}/{y}.png",
    ];
}

function parseTilesForTest(mixed $raw): array
{
    $job = new ImportAppJob(999, []);
    $m = new ReflectionMethod($job, 'parseGeohubTiles');
    $m->setAccessible(true);

    return $m->invoke($job, $raw);
}

function syncTilesForTest(App $app, array $parsed): void
{
    $job = new ImportAppJob(999, []);
    $prop = new ReflectionProperty($job, 'geohubImportService');
    $prop->setAccessible(true);
    $prop->setValue($job, app(GeohubImportService::class));

    $m = new ReflectionMethod($job, 'syncTiles');
    $m->setAccessible(true);
    $m->invoke($job, $app, $parsed);
}
