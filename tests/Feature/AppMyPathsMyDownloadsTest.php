<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppConfigService;

uses(TestCase::class, DatabaseTransactions::class);

// ── config.json ────────────────────────────────────────────────────────────────

it('config.json does not include my_paths when no image is uploaded', function () {
    $app = App::factory()->createQuietly();

    $config = (new AppConfigService($app))->config();

    expect($config['APP'])->not->toHaveKey('myPaths');
});

it('config.json does not include my_downloads when no image is uploaded', function () {
    $app = App::factory()->createQuietly();

    $config = (new AppConfigService($app))->config();

    expect($config['APP'])->not->toHaveKey('myDownloads');
});

it('config.json includes my_paths when image is uploaded', function () {
    Storage::fake('wmfe');

    $app = App::factory()->createQuietly();
    $app->addMedia(UploadedFile::fake()->image('my_paths.png'))
        ->toMediaCollection('my_paths');

    $config = (new AppConfigService($app))->config();

    expect($config['APP'])->toHaveKey('myPaths');
    expect($config['APP']['myPaths'])->toBeString()->not->toBeEmpty();
});

it('config.json includes my_downloads when image is uploaded', function () {
    Storage::fake('wmfe');

    $app = App::factory()->createQuietly();
    $app->addMedia(UploadedFile::fake()->image('my_downloads.png'))
        ->toMediaCollection('my_downloads');

    $config = (new AppConfigService($app))->config();

    expect($config['APP'])->toHaveKey('myDownloads');
    expect($config['APP']['myDownloads'])->toBeString()->not->toBeEmpty();
});

// ── routes ─────────────────────────────────────────────────────────────────────

it('GET elbrus my_paths.png returns 404 when no image is uploaded', function () {
    $app = App::factory()->createQuietly();

    $this->get("/api/app/elbrus/{$app->id}/resources/my_paths.png")
        ->assertStatus(404);
});

it('GET elbrus my_downloads.png returns 404 when no image is uploaded', function () {
    $app = App::factory()->createQuietly();

    $this->get("/api/app/elbrus/{$app->id}/resources/my_downloads.png")
        ->assertStatus(404);
});

it('GET webmapp my_paths.png returns 404 when no image is uploaded', function () {
    $app = App::factory()->createQuietly();

    $this->get("/api/app/webmapp/{$app->id}/resources/my_paths.png")
        ->assertStatus(404);
});

it('GET webmapp my_downloads.png returns 404 when no image is uploaded', function () {
    $app = App::factory()->createQuietly();

    $this->get("/api/app/webmapp/{$app->id}/resources/my_downloads.png")
        ->assertStatus(404);
});

it('GET elbrus my_paths.png returns 200 when image is uploaded', function () {
    Storage::fake('wmfe');

    $app = App::factory()->createQuietly();
    $app->addMedia(UploadedFile::fake()->image('my_paths.png', 2214, 1013))
        ->toMediaCollection('my_paths');

    $this->get("/api/app/elbrus/{$app->id}/resources/my_paths.png")
        ->assertStatus(200);
});

it('GET elbrus my_downloads.png returns 200 when image is uploaded', function () {
    Storage::fake('wmfe');

    $app = App::factory()->createQuietly();
    $app->addMedia(UploadedFile::fake()->image('my_downloads.png', 2214, 1013))
        ->toMediaCollection('my_downloads');

    $this->get("/api/app/elbrus/{$app->id}/resources/my_downloads.png")
        ->assertStatus(200);
});
