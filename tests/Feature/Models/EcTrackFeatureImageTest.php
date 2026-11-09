<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wm\WmPackage\Models\EcTrack;

// La copertina nell'indice di ricerca (oc:8932) deve venire da un media di tipo immagine.
// I media sono righe scritte a mano: serve solo che getMedia() le ordini e ne legga il mime_type,
// non che il file esista su disco.
function addFakeMedia(EcTrack $track, string $fileName, string $mimeType, int $order, string $collection = 'default'): void
{
    // la colonna geometry è NOT NULL: si scrive a mano con un punto qualsiasi
    DB::table('media')->insert([
        'model_type' => $track->getMorphClass(),
        'model_id' => $track->id,
        'uuid' => (string) Str::uuid(),
        'collection_name' => $collection,
        'name' => pathinfo($fileName, PATHINFO_FILENAME),
        'file_name' => $fileName,
        'mime_type' => $mimeType,
        'disk' => 'public',
        'conversions_disk' => 'public',
        'size' => 1,
        'manipulations' => '[]',
        'custom_properties' => '[]',
        'generated_conversions' => '[]',
        'responsive_images' => '[]',
        'order_column' => $order,
        'app_id' => $track->app_id,
        'geometry' => DB::raw("ST_GeogFromText('POINTZ(9 40 0)')"),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// StorageService costruisce il percorso del file dal nome dello shard, che nella suite del package è null.
beforeEach(fn () => config(['wm-package.shard_name' => 'test']));

it('non usa un documento di un\'altra collection come feature_image', function () {
    $track = EcTrack::factory()->createQuietly();
    // il documento ha l'order_column più basso di tutti: con getMedia('*') sarebbe lui il primo
    addFakeMedia($track, 'scheda-percorso.pdf', 'application/pdf', 1, 'documents');
    addFakeMedia($track, 'panorama.jpg', 'image/jpeg', 2, 'default');

    $featureImage = $track->fresh()->toSearchableArray()['feature_image'];

    expect($featureImage)->toContain('panorama')->not->toContain('.pdf');
});

it('non usa un file non immagine presente nella collection default', function () {
    $track = EcTrack::factory()->createQuietly();
    // stessa collection, order_column più basso: la collection da sola non basterebbe
    addFakeMedia($track, 'scheda-percorso.pdf', 'application/pdf', 1);
    addFakeMedia($track, 'panorama.jpg', 'image/jpeg', 2);

    $featureImage = $track->fresh()->toSearchableArray()['feature_image'];

    expect($featureImage)->toContain('panorama')->not->toContain('.pdf');
});

it('ignora anche le immagini delle altre collection', function () {
    $track = EcTrack::factory()->createQuietly();
    // un'immagine in gallery con order_column più basso: il controllo sul mime la accetterebbe,
    // solo il filtro per collection la scarta
    addFakeMedia($track, 'gallery-prima.jpg', 'image/jpeg', 1, 'gallery');
    addFakeMedia($track, 'copertina.jpg', 'image/jpeg', 2);

    $featureImage = $track->fresh()->toSearchableArray()['feature_image'];

    expect($featureImage)->toContain('copertina')->not->toContain('gallery-prima');
});

it('lascia feature_image vuota se nella collection default non ci sono media', function () {
    $track = EcTrack::factory()->createQuietly();
    addFakeMedia($track, 'scheda-percorso.pdf', 'application/pdf', 1, 'documents');
    addFakeMedia($track, 'video.mp4', 'video/mp4', 2, 'videos');

    expect($track->fresh()->toSearchableArray()['feature_image'])->toBe('');
});

it('continua a usare la prima immagine quando ci sono solo immagini', function () {
    $track = EcTrack::factory()->createQuietly();
    // inserite in ordine inverso e con nomi non alfabetici: conta l'order_column, non l'inserimento
    addFakeMedia($track, 'alfa-seconda.png', 'image/png', 2);
    addFakeMedia($track, 'zeta-prima.jpg', 'image/jpeg', 1);

    $featureImage = $track->fresh()->toSearchableArray()['feature_image'];

    expect($featureImage)->toContain('zeta-prima')->not->toContain('alfa-seconda');
});
