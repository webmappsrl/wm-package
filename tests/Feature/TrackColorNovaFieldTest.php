<?php

declare(strict_types=1);

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Fields\TrackColor\src\TrackColor;
use Illuminate\Support\Facades\Http;

// Creare un contenuto geolocalizzato interroga osmfeatures per le aree amministrative e il DEM
// per i dati tecnici: qui non si verificano quei due servizi, si fingono perche' il test non
// esca in rete.
beforeEach(function () {
    Http::fake([
        '*osmfeatures*' => Http::response(['features' => []]),
        '*dem*' => Http::response([
            'ele' => 0,
            'geometry' => ['type' => 'LineString', 'coordinates' => []],
            'properties' => [],
        ]),
    ]);
});

function invokeProtectedTrackColor(object $object, string $method, array $args = [])
{
    $ref = new ReflectionMethod($object, $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs($object, $args);
}

it('writes selected json path when attribute is nested (properties->style->color)', function () {
    /** @var EcTrack $track */
    $track = EcTrack::factory()->create([
        'properties' => [],
    ]);

    $field = TrackColor::make('Color', 'properties->style->color');

    $request = NovaRequest::create('/', 'POST', [
        'color' => '#00ff00',
    ]);

    invokeProtectedTrackColor($field, 'fillAttributeFromRequest', [$request, 'color', $track, 'properties->style->color']);

    // Il field prepara il modello: il salvataggio lo fa Nova dopo il fill.
    $track->save();
    $track->refresh();

    expect(data_get($track->properties, 'style.color'))->toBe('#00ff00');
});

it('removes json key when value is __RESET__', function () {
    /** @var EcTrack $track */
    $track = EcTrack::factory()->create([
        'properties' => [
            'color' => '#123456',
        ],
    ]);

    $field = TrackColor::make('Color', 'properties->color');

    $request = NovaRequest::create('/', 'POST', [
        'color' => '__RESET__',
    ]);

    invokeProtectedTrackColor($field, 'fillAttributeFromRequest', [$request, 'color', $track, 'properties->color']);

    // Il field prepara il modello: il salvataggio lo fa Nova dopo il fill.
    $track->save();
    $track->refresh();

    expect(data_get($track->properties, 'color'))->toBeNull();
});

it('normalizes to inherited when enabled and incoming equals inherited', function () {
    /** @var Layer $layer */
    $layer = Layer::factory()->create([
        'rank' => 1,
        'properties' => ['color' => '#00FF00'],
    ]);

    /** @var EcTrack $track */
    $track = EcTrack::factory()->create([
        'properties' => [
            'color' => '#ABCDEF',
        ],
    ]);

    $track->layers()->attach($layer->id);

    $field = TrackColor::make('Color')->normalizeToInherited();

    $request = NovaRequest::create('/', 'POST', [
        'color' => '#00ff00', // stesso colore del layer, ma lower-case per verificare normalizzazione
    ]);

    invokeProtectedTrackColor($field, 'fillAttributeFromRequest', [$request, 'color', $track, 'properties->color']);

    // Il field prepara il modello: il salvataggio lo fa Nova dopo il fill.
    $track->save();
    $track->refresh();

    expect(data_get($track->properties, 'color'))->toBeNull();
});

it('resolve meta contains effective/inherited/stored and attribute_path', function () {
    /** @var Layer $layer */
    $layer = Layer::factory()->create([
        'rank' => 1,
        'properties' => ['color' => '#00FF00'],
    ]);

    /** @var EcTrack $track */
    $track = EcTrack::factory()->create([
        'properties' => [
            'style' => [
                'color' => 'ff0000',
            ],
        ],
    ]);

    $track->layers()->attach($layer->id);

    $field = TrackColor::make('Color', 'properties->style->color');
    $field->resolve($track);

    // `withMeta()` scrive le chiavi nella radice della serializzazione del Field,
    // non dentro una chiave `meta`.
    $meta = $field->jsonSerialize();

    expect($meta['attribute_path'])->toBe('properties->style->color')
        ->and($meta['stored_hex'])->toBe('#FF0000')
        ->and($meta['inherited_hex'])->toBe('#00FF00')
        ->and($meta['effective_hex'])->toBe('#FF0000')
        ->and($meta['source'])->toBe('custom');
});
