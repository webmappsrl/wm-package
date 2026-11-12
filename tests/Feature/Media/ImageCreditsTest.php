<?php

declare(strict_types=1);

use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Http\Resources\MediaResource;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Nova\EcPoi as EcPoiResource;
use Wm\WmPackage\Nova\Fields\ImageCredits;
use Wm\WmPackage\Services\GeoJsonService;

/**
 * Didascalia, autore e credits delle foto (oc:8959): si scrivono in Nova sulla foto e escono con la
 * foto, in `feature_image` e `image_gallery`. Stanno nelle custom properties del media.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    config(['wm-package.shard_name' => 'test-shard']);
    Storage::fake('wmfe');
    Storage::fake('public');
    config(['wm-media-library.disk_name' => 'public', 'media-library.disk_name' => 'public']);
});

function creditsPoi(): EcPoi
{
    return EcPoi::factory()->createQuietly(['app_id' => App::factory()->createQuietly()->id]);
}

function creditsPhoto(EcPoi $poi, array $customProperties = [])
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

    return $poi->addMediaFromString($png)->usingFileName('foto.png')
        ->withCustomProperties($customProperties)
        ->toMediaCollection('default');
}

it('porta didascalia, autore e credits nella foto del dettaglio', function () {
    $photo = creditsPhoto(creditsPoi(), [
        'caption' => 'Il Monte Corrasi',
        'author' => 'Matteo Cara',
        'credits' => 'Comunità Montana',
    ]);

    expect((new MediaResource($photo))->resolve())->toMatchArray([
        'id' => $photo->id,
        'caption' => 'Il Monte Corrasi',
        'author' => 'Matteo Cara',
        'credits' => 'Comunità Montana',
    ]);
});

it('mette a null i dati che la foto non ha, anche se salvati vuoti', function () {
    $photo = creditsPhoto(creditsPoi(), ['author' => '']);

    expect((new MediaResource($photo))->resolve())->toMatchArray([
        'caption' => null,
        'author' => null,
        'credits' => null,
    ]);
});

it('porta autore e credits anche nelle foto del GeoJSON, accanto alla didascalia', function () {
    $poi = creditsPoi();
    creditsPhoto($poi, ['caption' => 'Vista', 'author' => 'Gianluca Schirru', 'credits' => 'Agenzia Forestas']);

    $properties = GeoJsonService::make()->getModelAsGeojson($poi->fresh())['properties'];

    expect($properties['feature_image'])->toMatchArray(['caption' => 'Vista', 'author' => 'Gianluca Schirru', 'credits' => 'Agenzia Forestas'])
        ->and($properties['image_gallery'][0])->toMatchArray(['caption' => 'Vista', 'author' => 'Gianluca Schirru', 'credits' => 'Agenzia Forestas']);
});

it('fa scrivere didascalia, autore e credits su ogni foto in Nova', function () {
    $images = collect((new EcPoiResource(new EcPoi))->fields(NovaRequest::create('/')))
        ->first(fn ($field) => $field instanceof Images && $field->attribute === 'default');

    expect($images)->not->toBeNull()
        ->and(collect($images->meta['customPropertiesFields'])->pluck('attribute')->all())
        ->toBe([ImageCredits::CAPTION, ImageCredits::AUTHOR, ImageCredits::CREDITS]);
});
