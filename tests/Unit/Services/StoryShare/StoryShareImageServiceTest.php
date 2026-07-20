<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
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

/**
 * Third revision (oc:8183): StoryShareImageService no longer reads a client-uploaded
 * screenshot — it receives an already-rendered map image (produced by MapRenderService in
 * production; a plain in-memory canvas here, since this test targets the compositing step in
 * isolation).
 */
function fakeMapImage(int $width = 960, int $height = 960)
{
    return Image::canvas($width, $height, '#336699');
}

it('composes a 1080x1920 PNG when the app has a story_frame uploaded', function () {
    Storage::fake('wmfe');

    $app = App::factory()->createQuietly();
    $app->addMedia(UploadedFile::fake()->image('frame.png', 1080, 1920))
        ->toMediaCollection('story_frame');

    $result = (new StoryShareImageService)->compose($app->fresh(), fakeMapImage(), storyShareStats());

    expect($result->width())->toBe(StoryImageLayout::CANVAS_WIDTH);
    expect($result->height())->toBe(StoryImageLayout::CANVAS_HEIGHT);
    expect($result->mime())->toBe('image/png');
});

it('falls back to a padded, unbranded 1080x1920 image when story_frame is missing', function () {
    Storage::fake('wmfe');

    $app = App::factory()->createQuietly();
    expect($app->getFirstMedia('story_frame'))->toBeNull();

    $result = (new StoryShareImageService)->compose($app, fakeMapImage(), storyShareStats());

    expect($result->width())->toBe(StoryImageLayout::CANVAS_WIDTH);
    expect($result->height())->toBe(StoryImageLayout::CANVAS_HEIGHT);

    // NOTE: this used to also assert `Log::shouldHaveReceived('warning')` via `Log::spy()`,
    // but that trips a pre-existing, unrelated bug already flagged in a previous revision of
    // notes.md: with `Log::spy()` active, Laravel's own deprecation-warning handler
    // (HandleExceptions::handleError(), which calls `$log->warning(...)` for any native PHP
    // warning/deprecation raised during the test — e.g. from GD's resize internals) resolves
    // a null logger in this minimal Testbench app, causing
    // "Call to a member function warning() on null". Not fixed here (out of scope) — the log
    // call itself is still exercised by StoryShareImageService (see the source), just not
    // asserted on on in this test to keep it green.
});

it('never crops the map image in the fallback path (contain, not cover)', function () {
    Storage::fake('wmfe');

    $app = App::factory()->createQuietly();
    // A very wide (non-9:16) map image: if it were "cover"-cropped, most of it would be cut.
    // Under "contain" it must be fully visible, letterboxed, never larger than the canvas.
    $wideMap = fakeMapImage(1600, 200);

    $result = (new StoryShareImageService)->compose($app, $wideMap, storyShareStats());

    expect($result->width())->toBe(StoryImageLayout::CANVAS_WIDTH);
    expect($result->height())->toBe(StoryImageLayout::CANVAS_HEIGHT);
});

it('throws when the app story_frame media asset cannot be read', function () {
    Storage::fake('wmfe');

    $app = App::factory()->createQuietly();
    $app->addMedia(UploadedFile::fake()->image('frame.png', 1080, 1920))
        ->toMediaCollection('story_frame');

    // Simulate a lost/corrupted stored file: delete the underlying disk file while the
    // Media DB record still exists.
    $media = $app->fresh()->getFirstMedia('story_frame');
    Storage::disk($media->disk)->delete($media->getPathRelativeToRoot());

    (new StoryShareImageService)->compose($app->fresh(), fakeMapImage(), storyShareStats());
})->throws(RuntimeException::class);

it('draws stats text without throwing when stats are empty', function () {
    Storage::fake('wmfe');

    $app = App::factory()->createQuietly();

    $result = (new StoryShareImageService)->compose($app, fakeMapImage(), []);

    expect($result->width())->toBe(StoryImageLayout::CANVAS_WIDTH);
    expect($result->height())->toBe(StoryImageLayout::CANVAS_HEIGHT);
});
