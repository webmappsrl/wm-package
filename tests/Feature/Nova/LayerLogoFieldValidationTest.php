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
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Layer as LayerResource;

uses(TestCase::class, DatabaseTransactions::class);

function layerImagesField(Layer $model, string $attribute): Images
{
    $request = NovaRequest::create('/');
    $resource = new LayerResource($model);

    foreach ($resource->fields($request) as $item) {
        $fields = $item instanceof Panel ? collect($item->data) : collect([$item]);
        $found = $fields->first(fn ($f) => $f instanceof Images && $f->attribute === $attribute);
        if ($found) {
            return $found;
        }
    }

    throw new RuntimeException("Images field '{$attribute}' not found in Layer resource");
}

function layerMediaUploadRequest(int $layerId, string $collection, UploadedFile $file): NovaRequest
{
    $symfonyRequest = HttpRequest::create("/nova-api/layers/{$layerId}", 'PUT');
    $symfonyRequest->files->set('__media__', [$collection => [$file]]);

    return NovaRequest::createFrom($symfonyRequest, new NovaRequest);
}

function makeLayerForNovaMedia(): Layer
{
    App::factory()->createQuietly();

    return Layer::factory()->createQuietly();
}

it('rejects a non-square logo', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForNovaMedia();
    $field = layerImagesField($layer, 'logo');
    $request = layerMediaUploadRequest($layer->id, 'logo', UploadedFile::fake()->image('logo.png', 1024, 512));

    expect(fn () => $field->fill($request, $layer))->toThrow(ValidationException::class);
});

it('rejects a non png/webp logo', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForNovaMedia();
    $field = layerImagesField($layer, 'logo');
    $request = layerMediaUploadRequest($layer->id, 'logo', UploadedFile::fake()->create('logo.jpg', 100, 'image/jpeg'));

    expect(fn () => $field->fill($request, $layer))->toThrow(ValidationException::class);
});

it('rejects an svg logo', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForNovaMedia();
    $field = layerImagesField($layer, 'logo');
    $svgContent = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><rect width="200" height="200"/></svg>';
    $tmpFile = tempnam(sys_get_temp_dir(), 'svg').'.svg';
    file_put_contents($tmpFile, $svgContent);
    $file = new UploadedFile($tmpFile, 'logo.svg', 'image/svg+xml', null, true);
    $request = layerMediaUploadRequest($layer->id, 'logo', $file);

    expect(fn () => $field->fill($request, $layer))->toThrow(ValidationException::class);
});

it('accepts a valid square png logo', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForNovaMedia();
    $field = layerImagesField($layer, 'logo');
    $request = layerMediaUploadRequest($layer->id, 'logo', UploadedFile::fake()->image('logo.png', 512, 512));

    $callback = $field->fill($request, $layer);
    if (is_callable($callback)) {
        $callback();
    }

    expect($layer->fresh()->getFirstMedia('logo'))->not->toBeNull();
});

it('accepts a valid square webp logo', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForNovaMedia();
    $field = layerImagesField($layer, 'logo');
    $request = layerMediaUploadRequest($layer->id, 'logo', UploadedFile::fake()->image('logo.webp', 300, 300));

    $callback = $field->fill($request, $layer);
    if (is_callable($callback)) {
        $callback();
    }

    expect($layer->fresh()->getFirstMedia('logo'))->not->toBeNull();
});
