<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\UgcMediaHashService;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

/**
 * Le sei collection dell'istanza (oc:8960). Le cinque documentali tengono un
 * file solo, perche' il protocollo concordato con lo sportello prevede i retry
 * e un reinvio deve sostituire, non affiancare. La galleria fotografica no: ne
 * contiene fino a cinque, e si difende dai reinvii riconoscendo il contenuto.
 */
beforeEach(function () {
    runTrailRegistryStubs();
    config(['wm-package.features.trail_registry.enabled' => true]);

    // Stesso setup di ApproveTrailApplicationTest, per gli stessi motivi:
    // Bus::fake() tiene fuori i job che chiamano servizi esterni e l'observer
    // dell'App; la sequenza riparte da 1 perche' MediaObserver, quando il
    // modello padre non ha `app_id` — e trail_applications non ce l'ha —
    // ripiega sull'app 1; lo shard serve alle conversioni di GeometryModel.
    Bus::fake();
    DB::statement('ALTER SEQUENCE apps_id_seq RESTART WITH 1');
    config(['wm-package.shard_name' => 'wm_package_testing']);
    App::factory()->createQuietly();

    // Il disco dei media del package e' `wmfe` (wm-media-library.disk_name,
    // MEDIA_DISK): senza il fake su quello si tenta davvero S3.
    Storage::fake(config('media-library.disk_name', 'wmfe'));
    Storage::fake('wmfe');
    Storage::fake('public');
});

it('registra le sei collection dell istanza', function () {
    $application = TrailApplication::factory()->create();

    $names = collect($application->getRegisteredMediaCollections())->pluck('name')->all();

    expect($names)->toContain(
        'report',
        'original_geometry',
        'trail_data_sheet',
        'photos',
        'authorizations',
        'maintenance_plan',
    );
});

it('tiene un solo file nelle collection documentali, piu di uno nella galleria', function () {
    $application = TrailApplication::factory()->create();
    $documentali = ['report', 'original_geometry', 'trail_data_sheet', 'authorizations', 'maintenance_plan'];
    $nostre = array_merge($documentali, ['photos']);

    $trovate = collect($application->getRegisteredMediaCollections())
        ->filter(fn ($collection) => in_array($collection->name, $nostre, true));

    // Senza questa riga il ciclo sotto non assertirebbe nulla finche' le
    // collection non esistono, e il test passerebbe per vuoto.
    expect($trovate)->toHaveCount(count($nostre));

    foreach ($trovate as $collection) {
        expect($collection->singleFile)->toBe(in_array($collection->name, $documentali, true));
    }
});

it('non aggiunge due volte la stessa foto reinviata', function () {
    $application = TrailApplication::factory()->create();

    // Stessi byte, nome diverso: e' cosi' che arriva un reinvio dopo un
    // timeout — lo stesso che fa l'app UGC con i suoi retry, dove i nomi sono
    // posizionali (oc:8718). Un nome diverso non ne fa una fotografia nuova.
    $application->addPhoto(UploadedFile::fake()->createWithContent('vetta.jpg', 'i byte della fotografia'));
    $application->addPhoto(UploadedFile::fake()->createWithContent('image_0.jpg', 'i byte della fotografia'));

    expect($application->fresh()->getMedia(TrailApplication::PHOTOS_COLLECTION))->toHaveCount(1);
});

it('aggiunge una fotografia che non c era', function () {
    $application = TrailApplication::factory()->create();

    $application->addPhoto(UploadedFile::fake()->createWithContent('vetta.jpg', 'la prima'));
    $application->addPhoto(UploadedFile::fake()->createWithContent('valle.jpg', 'la seconda'));

    expect($application->fresh()->getMedia(TrailApplication::PHOTOS_COLLECTION))->toHaveCount(2);
});

it('scrive l hash della foto, cosi che il confronto dopo non riapra il file', function () {
    $application = TrailApplication::factory()->create();
    $file = UploadedFile::fake()->createWithContent('vetta.jpg', 'i byte della fotografia');
    $atteso = UgcMediaHashService::make()->hashOfFile($file->getRealPath());

    $media = $application->addPhoto($file);

    expect($media->getCustomProperty(UgcMediaHashService::HASH_PROPERTY))->toBe($atteso);
});
