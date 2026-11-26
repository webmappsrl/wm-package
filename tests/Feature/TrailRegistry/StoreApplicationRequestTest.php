<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Wm\WmPackage\Http\Requests\TrailRegistry\StoreApplicationRequest;

/**
 * L'apertura di una domanda chiede tre cose e nient'altro (oc:9056).
 *
 * Il modulo non passa di qui: si consegna con le modifiche, e la validazione
 * dei suoi campi vive in `PatchApplicationRequestTest`.
 */
beforeEach(function () {
    runTrailRegistryStubs();
    config(['wm-package.features.trail_registry.enabled' => true]);
});

function resolveStoreRequest(array $payload, bool $withGeometry = true): StoreApplicationRequest
{
    $files = $withGeometry ? ['geometry' => UploadedFile::fake()->create('traccia.gpx', 1)] : [];

    $request = StoreApplicationRequest::create('/', 'POST', $payload, [], $files);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    return $request;
}

/** @return array<string, list<string>> */
function storeErrors(array $payload, bool $withGeometry = true): array
{
    try {
        resolveStoreRequest($payload, $withGeometry);
    } catch (ValidationException $e) {
        return $e->validator->errors()->messages();
    }

    return [];
}

function minimalOpenPayload(array $overrides = []): array
{
    return array_merge([
        'reference' => 'FORES_02/2026/000123',
        'name' => 'Sentiero di prova',
    ], $overrides);
}

it('autorizza sempre: lo fa il middleware del canale', function () {
    expect((new StoreApplicationRequest)->authorize())->toBeTrue();
});

it('apre la domanda con riferimento, nome e tracciato', function () {
    config(['wm-package.features.trail_registry.api.require_reference' => true]);

    expect(storeErrors(minimalOpenPayload()))->toBe([]);
});

it('pretende il nome e il file del tracciato', function () {
    expect(storeErrors(minimalOpenPayload(['name' => null]), withGeometry: false))
        ->toHaveKeys(['name', 'geometry']);
});

it('pretende il riferimento, dove un sistema esterno c e', function () {
    config(['wm-package.features.trail_registry.api.require_reference' => true]);

    $payload = minimalOpenPayload();
    unset($payload['reference']);

    expect(storeErrors($payload))->toHaveKey('reference');
});

it('non pretende il riferimento dove nessun sistema esterno consegna', function () {
    // La chiave e' spenta di default: e' la condizione di uno shard che
    // accende il Catasto e crea le domande solo d'ufficio.
    config(['wm-package.features.trail_registry.api.require_reference' => false]);

    $payload = minimalOpenPayload();
    unset($payload['reference']);

    expect(storeErrors($payload))->toBe([]);
});

it('rifiuta i campi del modulo: si consegnano con una modifica', function () {
    // Non sono «facoltativi qui»: non appartengono a questa chiamata. Cosi'
    // ogni chiamata ha un compito solo, e chi integra non sceglie fra due
    // strade che farebbero la stessa cosa.
    $errors = storeErrors(minimalOpenPayload([
        'from' => 'Punto A',
        'distance' => 12.5,
        'activities' => ['hiking'],
    ]));

    expect($errors)->toHaveKeys(['from', 'distance', 'activities']);
});

it('non conserva niente del modulo nel payload della domanda', function () {
    config(['wm-package.features.trail_registry.api.require_reference' => true]);

    // La domanda nasce con `api_data` vuoto: ci scrive solo la versione dello
    // schema, e la mette il controller.
    expect(resolveStoreRequest(minimalOpenPayload())->apiData())->toBe([]);
});

it('rifiuta una chiave che nessuno ha dichiarato', function () {
    expect(storeErrors(minimalOpenPayload(['campo_inventato' => 'x'])))
        ->toHaveKey('campo_inventato');
});
