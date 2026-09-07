<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;

uses(TestCase::class, DatabaseTransactions::class);

function makeLayerForMedia(): Layer
{
    App::factory()->createQuietly();

    return Layer::factory()->createQuietly();
}

it('registers a logo media collection as single file', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForMedia();

    $layer->addMedia(UploadedFile::fake()->image('logo.png', 512, 512))
        ->toMediaCollection('logo');
    $layer->addMedia(UploadedFile::fake()->image('logo-2.png', 512, 512))
        ->toMediaCollection('logo');

    expect($layer->fresh()->getMedia('logo'))->toHaveCount(1);
});

it('exposes logo_image in toArray when a logo is attached', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForMedia();

    $layer->addMedia(UploadedFile::fake()->image('logo.png', 512, 512))
        ->toMediaCollection('logo');

    $fresh = $layer->fresh();
    expect($fresh->logo_image)->toBeString()
        ->and($fresh->toArray()['logo_image'])->toBe($fresh->logo_image);
});

it('returns null for logo_image when no logo is attached', function () {
    $layer = makeLayerForMedia();

    expect($layer->fresh()->logo_image)->toBeNull()
        ->and($layer->fresh()->toArray()['logo_image'])->toBeNull();
});
