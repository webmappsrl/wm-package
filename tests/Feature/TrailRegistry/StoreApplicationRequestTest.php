<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Wm\WmPackage\Http\Requests\TrailRegistry\StoreApplicationRequest;
use Wm\WmPackage\Http\Requests\TrailRegistry\StoreAttachmentRequest;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Exceptions\MissingDifficultyFamilyException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

// Le Form Request si esercitano direttamente, senza chiamata HTTP: le rotte
// vivono nello shard. I test sull'endpoint arrivano con il Task 7.

/**
 * Un payload completo e valido.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function completeApplicationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sentiero di prova',
        'from' => 'Punto A',
        'to' => 'Punto B',
        'distance' => 12.5,
        'ascent' => 300,
        'duration_forward' => 240,
        'duration_backward' => 200,
        'activities' => ['hiking', 'mtb'],
        'difficulty' => ['hiking' => 'EE'],
        'existing_signage' => 'good_condition',
        'proposed_managing_municipalities' => ['Cagliari'],
        'municipality_email' => 'comune@example.com',
        'soggetto_gestore' => 'forestas',
        'soggetto_rilevatore' => 'applicant',
        'soggetto_manutentore' => 'forestas',
        'soggetto_custode_presente' => false,
        'last_survey_date' => '2026-01-15',
        'maintenance_frequency_months' => 12,
    ], $overrides);
}

/**
 * Costruisce la Form Request con il payload dato e la valida come farebbe il
 * container prima di un controller. Lancia ValidationException se fallisce.
 *
 * @param  array<string, mixed>  $payload
 */
function resolveApplicationRequest(array $payload, bool $withGeometry = true, array $query = [], array $extraFiles = []): StoreApplicationRequest
{
    $files = ($withGeometry ? ['geometry' => UploadedFile::fake()->create('traccia.gpx', 1)] : []) + $extraFiles;

    $request = StoreApplicationRequest::create('/?'.http_build_query($query), 'POST', $payload, [], $files);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    return $request;
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, list<string>>
 */
function applicationErrors(array $payload, bool $withGeometry = true): array
{
    try {
        resolveApplicationRequest($payload, $withGeometry);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

beforeEach(function () {
    foreach (['hiking-difficulty' => ['T', 'E', 'EE', 'EEA'], 'mtb-difficulty' => ['TC', 'MC', 'BC']] as $identifier => $levels) {
        $family = TaxonomyTerm::create(['identifier' => $identifier, 'name' => ['it' => $identifier]]);
        foreach ($levels as $level) {
            TaxonomyTerm::create([
                'identifier' => 'test:'.$identifier.'-'.$level,
                'parent_id' => $family->id,
                'name' => ['it' => $level],
            ]);
        }
    }
});

it('autorizza sempre: lo fa il middleware del canale', function () {
    expect((new StoreApplicationRequest)->authorize())->toBeTrue();
    expect((new StoreAttachmentRequest)->authorize())->toBeTrue();
});

it('accetta una consegna completa', function () {
    expect(applicationErrors(completeApplicationPayload()))->toBe([]);
});

it('dice campo per campo quale regola ha fallito', function () {
    $errors = applicationErrors(completeApplicationPayload(['municipality_email' => 'non-una-email']));

    expect($errors)->toHaveKey('municipality_email');
    expect($errors['municipality_email'][0])->toContain('email');
    expect($errors)->toHaveCount(1);
});

it('pretende nome e file della geometria', function () {
    $errors = applicationErrors(completeApplicationPayload(['name' => null]), withGeometry: false);

    expect($errors)->toHaveKeys(['name', 'geometry']);
});

it('pretende i campi obbligatori dello schema', function () {
    $payload = completeApplicationPayload();
    unset($payload['from'], $payload['last_survey_date']);

    expect(applicationErrors($payload))->toHaveKeys(['from', 'last_survey_date']);
});

it('pretende il soggetto gestore terzo solo quando la select lo prevede', function () {
    expect(applicationErrors(completeApplicationPayload([
        'soggetto_gestore' => 'third_party',
        'soggetto_gestore_other' => null,
    ])))->toHaveKey('soggetto_gestore_other');

    expect(applicationErrors(completeApplicationPayload([
        'soggetto_gestore' => 'forestas',
        'soggetto_gestore_other' => null,
    ])))->toBe([]);
});

it('rifiuta una lunghezza implausibile, non solo un valore non numerico', function () {
    expect(applicationErrors(completeApplicationPayload(['distance' => 12000])))->toHaveKey('distance');
    expect(applicationErrors(completeApplicationPayload(['distance' => 'lunga'])))->toHaveKey('distance');
    expect(applicationErrors(completeApplicationPayload(['duration_forward' => 999999])))->toHaveKey('duration_forward');
});

it('accetta e conserva una chiave che il format non prevede', function () {
    $payload = completeApplicationPayload(['campo_nuovo_del_format' => 'x']);

    expect(applicationErrors($payload))->toBe([]);

    $apiData = resolveApplicationRequest($payload)->apiData();

    expect($apiData)->toHaveKey('campo_nuovo_del_format', 'x');
    expect($apiData)->toHaveKey('from');
    expect($apiData)->not->toHaveKeys(['name', 'geometry']);
});

it('rifiuta una difficolta dichiarata per un attivita non indicata, dicendo quale', function () {
    $errors = applicationErrors(completeApplicationPayload([
        'activities' => ['hiking'],
        'difficulty' => ['hiking' => 'EE', 'mtb' => 'BC'],
    ]));

    expect($errors)->toHaveKey('difficulty.mtb');
    expect($errors['difficulty.mtb'][0])->toContain('mtb');
    expect($errors)->not->toHaveKey('difficulty.hiking');
});

it('accetta una difficolta solo per alcune delle attivita indicate', function () {
    expect(applicationErrors(completeApplicationPayload([
        'activities' => ['hiking', 'mtb'],
        'difficulty' => ['mtb' => 'BC'],
    ])))->toBe([]);
});

it('rifiuta un livello che non appartiene alla scala dell attivita', function () {
    expect(applicationErrors(completeApplicationPayload(['difficulty' => ['mtb' => 'EEA']])))
        ->toHaveKey('difficulty');
});

it('non maschera la mancanza della scala di difficolta: l eccezione risale', function () {
    TaxonomyTerm::query()->where('identifier', 'mtb-difficulty')->delete();

    resolveApplicationRequest(completeApplicationPayload([
        'activities' => ['mtb'],
        'difficulty' => ['mtb' => 'BC'],
    ]));
})->throws(MissingDifficultyFamilyException::class);

it('accetta le cinque collection ammesse e un file', function () {
    expect(StoreAttachmentRequest::collections())->toHaveCount(5);

    foreach (StoreAttachmentRequest::collections() as $collection) {
        $validator = Validator::make(
            ['collection' => $collection, 'file' => UploadedFile::fake()->create('allegato.pdf', 1)],
            (new StoreAttachmentRequest)->rules()
        );

        expect($validator->passes())->toBeTrue($collection);
    }
});

it('rifiuta una collection non registrata o un file mancante', function () {
    $rules = (new StoreAttachmentRequest)->rules();

    $unknown = Validator::make(['collection' => 'altro', 'file' => UploadedFile::fake()->create('a.pdf', 1)], $rules);
    expect($unknown->errors()->keys())->toBe(['collection']);

    $noFile = Validator::make(['collection' => TrailApplication::PHOTOS_COLLECTION], $rules);
    expect($noFile->errors()->keys())->toBe(['file']);
});

it('esclude original_geometry dalle collection dell upload', function () {
    expect(StoreAttachmentRequest::collections())->not->toContain(TrailApplication::ORIGINAL_GEOMETRY_COLLECTION);

    $validator = Validator::make(
        ['collection' => TrailApplication::ORIGINAL_GEOMETRY_COLLECTION, 'file' => UploadedFile::fake()->create('a.gpx', 1)],
        (new StoreAttachmentRequest)->rules()
    );

    expect($validator->errors()->keys())->toBe(['collection']);
});

it('decodifica i campi strutturati e i booleani che arrivano come stringhe in multipart', function () {
    $payload = completeApplicationPayload([
        'activities' => '["hiking","mtb"]',
        'difficulty' => '{"hiking":"EE"}',
        'proposed_managing_municipalities' => '["Cagliari"]',
        'soggetto_custode_presente' => 'false',
    ]);

    expect(applicationErrors($payload))->toBe([]);

    $apiData = resolveApplicationRequest($payload)->apiData();

    expect($apiData['activities'])->toBe(['hiking', 'mtb']);
    expect($apiData['difficulty'])->toBe(['hiking' => 'EE']);
    expect($apiData['soggetto_custode_presente'])->toBeFalse();
});

it('applica la regola condizionale al booleano decodificato da stringa', function () {
    $missing = completeApplicationPayload(['soggetto_custode_presente' => 'true', 'soggetto_custode' => null]);
    expect(applicationErrors($missing))->toHaveKey('soggetto_custode');

    $given = completeApplicationPayload(['soggetto_custode_presente' => 'true', 'soggetto_custode' => 'Mario']);
    expect(resolveApplicationRequest($given)->apiData()['soggetto_custode_presente'])->toBeTrue();
});

it('non legge come false una stringa vuota sul booleano', function () {
    expect(applicationErrors(completeApplicationPayload(['soggetto_custode_presente' => ''])))
        ->toHaveKey('soggetto_custode_presente');
});

it('accetta la forma nativa dei form: activities[] e booleani 1 e 0', function () {
    $payload = completeApplicationPayload(['activities' => ['hiking'], 'soggetto_custode_presente' => '1', 'soggetto_custode' => 'Mario']);
    $apiData = resolveApplicationRequest($payload)->apiData();
    expect($apiData['soggetto_custode_presente'])->toBeTrue();
    expect($apiData['activities'])->toBe(['hiking']);

    $zero = resolveApplicationRequest(completeApplicationPayload(['soggetto_custode_presente' => '0']))->apiData();
    expect($zero['soggetto_custode_presente'])->toBeFalse();
});

it('con difficulty presente chiede activities se manca o e vuoto, senza crash', function () {
    $missing = completeApplicationPayload(['difficulty' => ['hiking' => 'EE']]);
    unset($missing['activities']);
    expect(applicationErrors($missing))->toHaveKey('activities');

    expect(applicationErrors(completeApplicationPayload(['activities' => [], 'difficulty' => ['hiking' => 'EE']])))
        ->toHaveKey('activities');
});

it('non conserva in api_data ne i file extra ne la query string, ma le chiavi ignote del corpo si', function () {
    $apiData = resolveApplicationRequest(
        completeApplicationPayload(['campo_nuovo_del_format' => 'x']),
        query: ['_token' => 'abc', 'altro' => '1'],
        extraFiles: ['allegato_strano' => UploadedFile::fake()->create('x.pdf', 1)],
    )->apiData();

    expect($apiData)->toHaveKey('campo_nuovo_del_format', 'x');
    expect($apiData)->not->toHaveKeys(['_token', 'altro', 'allegato_strano', 'geometry', 'name']);
});

it('trasforma un JSON malformato in un errore di validazione, non in un crash', function () {
    $errors = applicationErrors(completeApplicationPayload([
        'activities' => '["hiking",',
        'difficulty' => '{non json',
    ]));

    expect($errors)->toHaveKeys(['activities', 'difficulty']);
    expect($errors['activities'][0])->toContain('array');
});

it('decodifica il campo 54 arrivato come JSON in stringa', function () {
    $payload = completeApplicationPayload([
        'soggetto_manutentore' => 'other_legal_entity',
        'soggetto_manutentore_other' => '["Ente A"]',
        'soggetto_manutentore_cf' => '["12345678901"]',
    ]);

    expect(applicationErrors($payload))->toBe([]);
    expect(resolveApplicationRequest($payload)->apiData()['soggetto_manutentore_other'])->toBe(['Ente A']);
});
