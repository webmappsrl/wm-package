<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\StoryShare\StoryImageLayout;
use Wm\WmPackage\Services\Models\StoryShare\StoryShareImageService;

// Base TestCase (Wm\WmPackage\Tests\TestCase, with RefreshDatabase) is applied globally by
// tests/Pest.php — no explicit uses() needed here.

beforeEach(function () {
    // Without this, StorageService::getShardName() gets a null `wm-package.shard_name`
    // (SHARD_NAME env unset in the test env) and throws a TypeError as soon as media
    // conversions run — same fix already used by UgcPoiControllerTest.
    config()->set('wm-package.shard_name', 'test_shard');
});

function storyShareStats(): array
{
    return [
        'duration_seconds' => 8130, // 2h 15m
        'distance_km' => 12.4,
        'ascent_meters' => 540,
    ];
}

it('composes a 1080x1920 PNG when the app has a story_frame uploaded', function () {
    Storage::fake('wmfe');

    $app = App::factory()->createQuietly();
    $app->addMedia(UploadedFile::fake()->image('frame.png', 1080, 1920))
        ->toMediaCollection('story_frame');

    $screenshot = UploadedFile::fake()->image('screenshot.png', 800, 800);

    $result = (new StoryShareImageService)->compose($app->fresh(), $screenshot, storyShareStats());

    expect($result->width())->toBe(StoryImageLayout::CANVAS_WIDTH);
    expect($result->height())->toBe(StoryImageLayout::CANVAS_HEIGHT);
    expect($result->mime())->toBe('image/png');
});

it('falls back to a padded, unbranded 1080x1920 image and logs a warning when story_frame is missing', function () {
    Storage::fake('wmfe');
    Log::spy();

    $app = App::factory()->createQuietly();
    expect($app->getFirstMedia('story_frame'))->toBeNull();

    $screenshot = UploadedFile::fake()->image('screenshot.png', 800, 800);

    $result = (new StoryShareImageService)->compose($app, $screenshot, storyShareStats());

    expect($result->width())->toBe(StoryImageLayout::CANVAS_WIDTH);
    expect($result->height())->toBe(StoryImageLayout::CANVAS_HEIGHT);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($message) => str_contains($message, 'story_frame not uploaded'));
});

it('never crops the raw screenshot in the fallback path (contain, not cover)', function () {
    Storage::fake('wmfe');
    Log::spy();

    $app = App::factory()->createQuietly();
    // A very wide (non-9:16) screenshot: if it were "cover"-cropped, most of it would be cut.
    // Under "contain" it must be fully visible, letterboxed, never larger than the canvas.
    $screenshot = UploadedFile::fake()->image('wide.png', 1600, 200);

    $result = (new StoryShareImageService)->compose($app, $screenshot, storyShareStats());

    expect($result->width())->toBe(StoryImageLayout::CANVAS_WIDTH);
    expect($result->height())->toBe(StoryImageLayout::CANVAS_HEIGHT);
});

it('throws when the screenshot is not a valid image', function () {
    Storage::fake('wmfe');

    $app = App::factory()->createQuietly();
    $corrupted = UploadedFile::fake()->create('not-an-image.png', 5, 'image/png');

    (new StoryShareImageService)->compose($app, $corrupted, storyShareStats());
})->throws(RuntimeException::class);

it('draws stats text without throwing when stats are empty', function () {
    Storage::fake('wmfe');

    $app = App::factory()->createQuietly();
    $screenshot = UploadedFile::fake()->image('screenshot.png', 800, 800);

    $result = (new StoryShareImageService)->compose($app, $screenshot, []);

    expect($result->width())->toBe(StoryImageLayout::CANVAS_WIDTH);
    expect($result->height())->toBe(StoryImageLayout::CANVAS_HEIGHT);
});
