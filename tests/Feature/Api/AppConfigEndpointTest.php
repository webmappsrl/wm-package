<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppConfigService;
use Wm\WmPackage\Services\StorageService;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config(['wm-package.shard_name' => 'test_shard']);

    Storage::fake('wmfe');
    Storage::fake('conf');
});

it('responds with a JSON object, not a JSON string', function () {
    $app = App::factory()->createQuietly(['api' => 'webmapp']);
    (new AppConfigService($app))->writeAppConfigOnAws();

    $response = $this->getJson("/api/app/webmapp/{$app->id}/config.json");

    $response->assertOk();
    expect($response->json())->toBeArray()
        ->and($response->json('APP.name'))->not->toBeNull();
});

it('recomputes and stores the config once when storage is empty, instead of throwing 500', function () {
    $app = App::factory()->createQuietly(['api' => 'webmapp']);

    $response = $this->getJson("/api/app/webmapp/{$app->id}/config.json");

    $response->assertOk();
    expect($response->json())->toBeArray();
});

it('does not serve a 200 with a null body when the stored file is corrupted', function () {
    $app = App::factory()->createQuietly(['api' => 'webmapp']);
    StorageService::make()->storeAppConfig($app->id, '{"APP": tronc');

    $response = $this->getJson("/api/app/webmapp/{$app->id}/config.json");

    expect($response->json())->not->toBeNull();
});
