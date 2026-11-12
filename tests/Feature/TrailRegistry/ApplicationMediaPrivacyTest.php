<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

/**
 * La rotta pubblica dei media non deve consegnare gli allegati di un'istanza
 * (oc:8968), qualunque sia la collection, e deve continuare a servire i media
 * di tutti gli altri modelli.
 */
beforeEach(function () {
    runTrailRegistryStubs();
    config(['wm-package.features.trail_registry.enabled' => true]);

    // Stesso setup di TrailApplicationMediaTest.
    Bus::fake();
    DB::statement('ALTER SEQUENCE apps_id_seq RESTART WITH 1');
    config(['wm-package.shard_name' => 'wm_package_testing']);
    App::factory()->createQuietly();

    Storage::fake(config('media-library.disk_name', 'wmfe'));
    Storage::fake('wmfe');
    Storage::fake('public');
});

it('non consegna l url di un allegato di un istanza', function () {
    $application = TrailApplication::factory()->complete()->create();
    $media = $application->getFirstMedia('report');

    expect($media)->not->toBeNull();

    $this->getJson(route('default.api.media.geojson', $media))->assertNotFound();
});

it('non consegna nessuna collection dell istanza, galleria compresa', function () {
    $application = TrailApplication::factory()->complete()->create();
    $application->addPhoto(UploadedFile::fake()->createWithContent('vetta.jpg', 'una fotografia'));

    $media = Media::query()
        ->where('model_id', $application->getKey())
        ->get();

    // Senza questo controllo il ciclo non assertirebbe nulla.
    expect($media->pluck('collection_name'))->toContain('report', TrailApplication::PHOTOS_COLLECTION);

    foreach ($media as $item) {
        $this->getJson(route('default.api.media.geojson', $item))->assertNotFound();
    }
});

it('continua a consegnare i media degli altri modelli', function () {
    $poi = EcPoi::factory()->createQuietly();
    $media = $poi
        ->addMedia(UploadedFile::fake()->image('poi.jpg'))
        ->preservingOriginal()
        ->toMediaCollection('default');

    $this->getJson(route('default.api.media.geojson', $media))->assertOk();
});
