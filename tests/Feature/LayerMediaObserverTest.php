<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\Layer;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    // UpdateAppConfigJob::uniqueVia() hardcodes Cache::store('redis') per il suo lock
    // univoco; Bus::fake() non intercetta quell'acquisizione (gira in
    // PendingDispatch::shouldDispatch(), prima che il Dispatcher fake entri in gioco) —
    // senza questo redirect i test dipendono da una connessione Redis reale (oc:8564, review).
    config(['cache.stores.redis.driver' => 'array']);
});

function makeLayerForMediaObserverTest(): Layer
{
    App::factory()->createQuietly();

    return Layer::factory()->createQuietly();
}

function assertUpdateAppConfigJobDispatchedWithDelayFor(Layer $layer): void
{
    Bus::assertDispatched(UpdateAppConfigJob::class, function (UpdateAppConfigJob $job) use ($layer) {
        return $job->appId === $layer->app_id && $job->delay !== null;
    });
}

it('dispatches UpdateAppConfigJob with a delay when a logo media is added to a Layer', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForMediaObserverTest();

    Bus::fake();

    $layer->addMedia(UploadedFile::fake()->image('logo.png', 512, 512))
        ->toMediaCollection('logo');

    assertUpdateAppConfigJobDispatchedWithDelayFor($layer);
});

it('dispatches UpdateAppConfigJob with a delay when a logo media is deleted from a Layer', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForMediaObserverTest();

    $media = $layer->addMedia(UploadedFile::fake()->image('logo.png', 512, 512))
        ->toMediaCollection('logo');

    Bus::fake();

    $media->delete();

    assertUpdateAppConfigJobDispatchedWithDelayFor($layer);
});

it('dispatches UpdateAppConfigJob with a delay when a media is added to the default Layer collection', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForMediaObserverTest();

    Bus::fake();

    $layer->addMedia(UploadedFile::fake()->image('cover.png', 512, 512))
        ->toMediaCollection('default');

    assertUpdateAppConfigJobDispatchedWithDelayFor($layer);
});

it('dispatches UpdateAppConfigJob with a delay when a media is deleted from the default Layer collection', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForMediaObserverTest();

    $media = $layer->addMedia(UploadedFile::fake()->image('cover.png', 512, 512))
        ->toMediaCollection('default');

    Bus::fake();

    $media->delete();

    assertUpdateAppConfigJobDispatchedWithDelayFor($layer);
});

it('does not dispatch UpdateAppConfigJob when a logo-named media is added to a non-Layer model', function () {
    Storage::fake('wmfe');
    App::factory()->createQuietly();
    $poi = EcPoi::factory()->createQuietly();

    Bus::fake();

    $poi->addMedia(UploadedFile::fake()->image('poi-logo.png', 512, 512))
        ->toMediaCollection('logo');

    Bus::assertNotDispatched(UpdateAppConfigJob::class);
});
