<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Nova\FeatureCollection as FeatureCollectionResource;
use Wm\WmPackage\Services\StorageService;

uses(TestCase::class, DatabaseTransactions::class);

function makeApp(): App
{
    return App::factory()->createQuietly(['overlays_label' => 'Layers']);
}

/**
 * Extracts the File field with attribute 'file_path' from the FeatureCollection Nova resource.
 */
function featureCollectionFileField(FeatureCollection $model): File
{
    $request = NovaRequest::create('/');
    $resource = new FeatureCollectionResource($model);

    foreach ($resource->fields($request) as $item) {
        $fields = $item instanceof Panel ? collect($item->data) : collect([$item]);
        $found = $fields->first(fn ($f) => $f instanceof File && $f->attribute === 'file_path');
        if ($found) {
            return $found;
        }
    }

    throw new RuntimeException('file_path field not found in FeatureCollection resource');
}

it('store callback returns true when mode is not upload, preserving existing file_path', function () {
    Storage::fake('wmfe');

    $app = makeApp();
    $fc = FeatureCollection::factory()->createQuietly(['app_id' => $app->id, 'mode' => 'upload']);
    $existingPath = '/'.config('wm-package.shard_name', 'webmapp').'/'.$app->id.'/feature-collection/'.$fc->id.'.geojson';
    $fc->updateQuietly(['file_path' => $existingPath]);

    $request = NovaRequest::create('/nova-api/feature-collections/'.$fc->id, 'PUT', ['mode' => 'generated']);
    $callback = featureCollectionFileField($fc)->storageCallback;

    $result = $callback($request, $fc, 'file_path', 'file_path', null, null);

    expect($result)->toBeTrue();
    expect($fc->fresh()->file_path)->toBe($existingPath);
});

it('store callback returns true when mode is upload but no file is attached', function () {
    Storage::fake('wmfe');

    $app = makeApp();
    $fc = FeatureCollection::factory()->createQuietly(['app_id' => $app->id, 'mode' => 'upload']);
    $existingPath = '/'.config('wm-package.shard_name', 'webmapp').'/'.$app->id.'/feature-collection/'.$fc->id.'.geojson';
    $fc->updateQuietly(['file_path' => $existingPath]);

    $request = NovaRequest::create('/nova-api/feature-collections/'.$fc->id, 'PUT', ['mode' => 'upload']);
    $callback = featureCollectionFileField($fc)->storageCallback;

    $result = $callback($request, $fc, 'file_path', 'file_path', null, null);

    expect($result)->toBeTrue();
});

it('store callback stores the file and returns its path when mode is upload with a file', function () {
    Storage::fake('wmfe');

    $app = makeApp();
    $fc = FeatureCollection::factory()->createQuietly([
        'app_id' => $app->id,
        'mode'   => 'upload',
    ]);

    $file = UploadedFile::fake()->createWithContent('map.geojson', '{"type":"FeatureCollection","features":[]}');
    $request = NovaRequest::create(
        '/nova-api/feature-collections/'.$fc->id,
        'PUT',
        ['mode' => 'upload'],
        [],
        ['file_path' => $file]
    );
    $callback = featureCollectionFileField($fc)->storageCallback;

    $result = $callback($request, $fc, 'file_path', 'file_path', null, null);

    expect($result)->toBeString();
    expect($result)->toContain('feature-collection');
    expect(Storage::disk('wmfe')->exists($result))->toBeTrue();
});

it('afterCreate stores the file and updates file_path in the database', function () {
    Storage::fake('wmfe');

    $app = makeApp();
    $fc = FeatureCollection::factory()->createQuietly([
        'app_id'    => $app->id,
        'mode'      => 'upload',
        'file_path' => null,
    ]);

    $file = UploadedFile::fake()->createWithContent('map.geojson', '{"type":"FeatureCollection","features":[]}');
    $request = NovaRequest::create(
        '/nova-api/feature-collections',
        'POST',
        ['mode' => 'upload'],
        [],
        ['file_path' => $file]
    );

    FeatureCollectionResource::afterCreate($request, $fc);

    $shard = config('wm-package.shard_name', 'webmapp');
    $expectedPath = '/'.$shard.'/'.$app->id.'/feature-collection/'.$fc->id.'.geojson';

    expect($fc->fresh()->file_path)->toBe($expectedPath);
    expect(Storage::disk('wmfe')->exists($expectedPath))->toBeTrue();
});

it('afterCreate throws RuntimeException when storage fails', function () {
    Storage::fake('wmfe');

    $app = makeApp();
    $fc = FeatureCollection::factory()->createQuietly([
        'app_id'    => $app->id,
        'mode'      => 'upload',
        'file_path' => null,
    ]);

    $mock = Mockery::mock(StorageService::class);
    $mock->shouldReceive('storeFeatureCollection')->andReturn(false);
    app()->instance(StorageService::class, $mock);

    $file = UploadedFile::fake()->createWithContent('map.geojson', '{"type":"FeatureCollection","features":[]}');
    $request = NovaRequest::create(
        '/nova-api/feature-collections',
        'POST',
        ['mode' => 'upload'],
        [],
        ['file_path' => $file]
    );

    expect(fn () => FeatureCollectionResource::afterCreate($request, $fc))
        ->toThrow(RuntimeException::class);
});
