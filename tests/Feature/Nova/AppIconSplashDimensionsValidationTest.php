<?php

declare(strict_types=1);

use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Nova\App as AppResource;

uses(TestCase::class, DatabaseTransactions::class);

/**
 * Extracts the Images field with the given attribute from the App Nova resource.
 */
function appImagesField(App $model, string $attribute): Images
{
    $request = NovaRequest::create('/');
    $resource = new AppResource($model);

    foreach ($resource->fields($request) as $item) {
        $fields = $item instanceof Panel ? collect($item->data) : collect([$item]);
        $found = $fields->first(fn ($f) => $f instanceof Images && $f->attribute === $attribute);
        if ($found) {
            return $found;
        }
    }

    throw new RuntimeException("Images field '{$attribute}' not found in App resource");
}

/**
 * Builds a NovaRequest carrying a single new upload for the given media collection,
 * matching the nested `__media__.<collection>` shape the Ebess field expects.
 */
function mediaUploadRequest(string $collection, UploadedFile $file): NovaRequest
{
    $symfonyRequest = HttpRequest::create('/nova-api/apps/1', 'PUT');
    $symfonyRequest->files->set('__media__', [$collection => [$file]]);

    return NovaRequest::createFrom($symfonyRequest, new NovaRequest);
}

function makeAppForMedia(): App
{
    return App::factory()->createQuietly();
}

// ── icon ─────────────────────────────────────────────────────────────────────

it('rejects an icon smaller than 1024x1024', function () {
    Storage::fake('wmfe');
    $app = makeAppForMedia();
    $field = appImagesField($app, 'icon');
    $request = mediaUploadRequest('icon', UploadedFile::fake()->image('icon.png', 512, 512));

    expect(fn () => $field->fill($request, $app))
        ->toThrow(ValidationException::class, 'The icon must be at least 1024×1024px and square (1:1 ratio).');
});

it('rejects a non-square icon', function () {
    Storage::fake('wmfe');
    $app = makeAppForMedia();
    $field = appImagesField($app, 'icon');
    $request = mediaUploadRequest('icon', UploadedFile::fake()->image('icon.png', 1024, 2048));

    expect(fn () => $field->fill($request, $app))->toThrow(ValidationException::class);
});

it('accepts a valid 1024x1024 icon', function () {
    Storage::fake('wmfe');
    $app = makeAppForMedia();
    $field = appImagesField($app, 'icon');
    $request = mediaUploadRequest('icon', UploadedFile::fake()->image('icon.png', 1024, 1024));

    $callback = $field->fill($request, $app);
    if (is_callable($callback)) {
        $callback();
    }

    expect($app->fresh()->getFirstMedia('icon'))->not->toBeNull();
});

// ── splash ───────────────────────────────────────────────────────────────────

it('rejects a splash screen smaller than 2732x2732', function () {
    Storage::fake('wmfe');
    $app = makeAppForMedia();
    $field = appImagesField($app, 'splash');
    // 1920x1920 is the threshold historically cited in the oc:8246 root cause — explicit regression case.
    $request = mediaUploadRequest('splash', UploadedFile::fake()->image('splash.png', 1920, 1920));

    expect(fn () => $field->fill($request, $app))
        ->toThrow(ValidationException::class, 'The splash screen must be at least 2732×2732px and square (1:1 ratio).');
});

it('accepts a valid 2732x2732 splash screen', function () {
    Storage::fake('wmfe');
    $app = makeAppForMedia();
    $field = appImagesField($app, 'splash');
    $request = mediaUploadRequest('splash', UploadedFile::fake()->image('splash.png', 2732, 2732));

    $callback = $field->fill($request, $app);
    if (is_callable($callback)) {
        $callback();
    }

    expect($app->fresh()->getFirstMedia('splash'))->not->toBeNull();
});

// ── icon_small ───────────────────────────────────────────────────────────────

it('does not validate dimensions on icon_small', function () {
    Storage::fake('wmfe');
    $app = makeAppForMedia();
    $field = appImagesField($app, 'icon_small');
    $request = mediaUploadRequest('icon_small', UploadedFile::fake()->image('icon_small.png', 100, 100));

    $callback = $field->fill($request, $app);
    if (is_callable($callback)) {
        $callback();
    }

    expect($app->fresh()->getFirstMedia('icon_small'))->not->toBeNull();
});

// ── singleFile() ─────────────────────────────────────────────────────────────

it('replaces the previous icon when a new valid one is uploaded', function () {
    Storage::fake('wmfe');
    $app = makeAppForMedia();
    $app->addMedia(UploadedFile::fake()->image('icon.png', 1024, 1024))->toMediaCollection('icon');
    expect($app->fresh()->getMedia('icon'))->toHaveCount(1);

    $field = appImagesField($app, 'icon');
    $request = mediaUploadRequest('icon', UploadedFile::fake()->image('icon-v2.png', 1024, 1024));

    $callback = $field->fill($request, $app);
    if (is_callable($callback)) {
        $callback();
    }

    expect($app->fresh()->getMedia('icon'))->toHaveCount(1);
});

// ── save without touching the field (critical regression, see overview §Rischi) ──

it('does not fail when saving the form without touching an existing non-compliant icon', function () {
    Storage::fake('wmfe');
    $app = makeAppForMedia();
    // Legacy/non-compliant media already attached, e.g. from before this validation existed.
    $existingMedia = $app->addMedia(UploadedFile::fake()->image('icon.png', 500, 500))->toMediaCollection('icon');

    $symfonyRequest = HttpRequest::create('/nova-api/apps/1', 'PUT');
    $symfonyRequest->request->set('__media__', ['icon' => [$existingMedia->id]]);
    $request = NovaRequest::createFrom($symfonyRequest, new NovaRequest);

    $field = appImagesField($app, 'icon');

    $callback = $field->fill($request, $app);
    if (is_callable($callback)) {
        $callback();
    }

    expect($app->fresh()->getMedia('icon'))->toHaveCount(1);
    expect($app->fresh()->getFirstMedia('icon')->id)->toBe($existingMedia->id);
});

// ── locale ───────────────────────────────────────────────────────────────────

it('shows the italian custom message when locale is it', function () {
    Storage::fake('wmfe');
    app()->setLocale('it');
    $app = makeAppForMedia();
    $field = appImagesField($app, 'icon');
    $request = mediaUploadRequest('icon', UploadedFile::fake()->image('icon.png', 512, 512));

    expect(fn () => $field->fill($request, $app))
        ->toThrow(ValidationException::class, "L'icona deve essere almeno 1024×1024px e quadrata (proporzioni 1:1).");

    app()->setLocale('en');
});
