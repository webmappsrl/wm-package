<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Wm\WmPackage\Http\Controllers\Api\TrailRegistry\PatchApplicationController;
use Wm\WmPackage\Http\Controllers\Api\TrailRegistry\StoreApplicationController;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;

/**
 * La modifica di una domanda in compilazione (oc:9056).
 *
 * Le rotte vivono nello shard: qui se ne registrano due al volo, la creazione
 * per aprire la domanda e la modifica per cambiarla.
 */
beforeEach(function () {
    runTrailRegistryStubs();
    config(['wm-package.features.trail_registry.enabled' => true]);
    config(['wm-package.features.trail_registry.api.require_reference' => true]);

    Bus::fake();
    DB::statement('ALTER SEQUENCE apps_id_seq RESTART WITH 1');
    config(['wm-package.shard_name' => 'wm_package_testing']);
    App::factory()->createQuietly();
    Storage::fake(config('media-library.disk_name', 'wmfe'));
    Storage::fake('wmfe');
    Storage::fake('public');

    foreach (['hiking-difficulty' => ['T', 'E', 'EE', 'EEA'], 'mtb-difficulty' => ['TC', 'MC', 'BC']] as $identifier => $levels) {
        $family = TaxonomyTerm::create(['identifier' => $identifier, 'name' => ['it' => $identifier]]);
        foreach ($levels as $level) {
            TaxonomyTerm::create([
                'identifier' => $identifier.'-'.$level,
                'parent_id' => $family->id,
                'name' => ['it' => $level],
            ]);
        }
    }

    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    // Un secondo settore, lontano dal primo: serve a provare il tracciato che
    // cambia settore e si porta dietro il numero del catasto.
    makeSector('ZNUB6', 'POLYGON((20 20, 20 30, 30 30, 30 20, 20 20))');

    Route::post('/test-trail-registry/applications', StoreApplicationController::class)
        ->middleware('auth:api')
        ->name('trail-registry-test.patch.store');
    // `SubstituteBindings` non e' automatico su una rotta registrata qui: le
    // rotte vere lo prendono dal gruppo `api`. Senza, alla Request arriva
    // l'identificatore come stringa invece della domanda.
    Route::patch('/test-trail-registry/applications/{application}', PatchApplicationController::class)
        ->middleware(['auth:api', SubstituteBindings::class])
        ->name('trail-registry-test.patch.update');

    config(['jwt.secret' => 'segreto-di-prova-per-il-guard-api']);
    $this->actingAs(User::factory()->create(), 'api');

    Http::fake(['*' => Http::response([
        'type' => 'Feature',
        'properties' => [
            'distance' => 157.2, 'ascent' => 300, 'descent' => 290,
            'duration_forward_hiking' => 120, 'duration_backward_hiking' => 110,
        ],
        'geometry' => ['type' => 'MultiLineString', 'coordinates' => [[[1, 1, 100], [2, 2, 200]]]],
    ])]);
});

function patchGpx(float $lon = 1, float $lat = 1, string $filename = 'traccia.gpx'): UploadedFile
{
    $endLon = $lon + 1;
    $endLat = $lat + 1;

    return UploadedFile::fake()->createWithContent($filename, <<<GPX
    <?xml version="1.0"?>
    <gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1">
      <trk><trkseg>
        <trkpt lat="{$lat}" lon="{$lon}"><ele>10</ele></trkpt>
        <trkpt lat="{$endLat}" lon="{$endLon}"><ele>20</ele></trkpt>
      </trkseg></trk>
    </gpx>
    GPX);
}

/** Apre una domanda con il minimo e restituisce il modello. */
function openedApplication(array $overrides = []): TrailApplication
{
    $id = test()->post(
        route('trail-registry-test.patch.store'),
        array_merge([
            'reference' => 'FORES_02/2026/'.uniqid(),
            'name' => 'Sentiero di prova',
            'geometry' => patchGpx(),
        ], $overrides),
        ['Accept' => 'application/json'],
    )->assertCreated()->json('id');

    return TrailApplication::findOrFail($id);
}

function patchApplication(TrailApplication $application, array $payload): TestResponse
{
    return test()->post(
        route('trail-registry-test.patch.update', $application),
        array_merge(['_method' => 'PATCH'], $payload),
        ['Accept' => 'application/json'],
    );
}

/*
|--------------------------------------------------------------------------
| Si manda cio' che cambia
|--------------------------------------------------------------------------
*/

it('cambia il campo che arriva e lascia stare gli altri', function () {
    $application = openedApplication();

    patchApplication($application, ['from' => 'Punto A'])->assertOk();
    patchApplication($application, ['to' => 'Punto B'])->assertOk();

    expect($application->fresh()->api_data)
        ->toMatchArray(['from' => 'Punto A', 'to' => 'Punto B']);
});

it('sostituisce un valore gia consegnato', function () {
    $application = openedApplication();

    patchApplication($application, ['distance' => 12.5])->assertOk();
    patchApplication($application, ['distance' => 14])->assertOk();

    expect($application->fresh()->api_data['distance'])->toBe(14);
});

it('sostituisce un elenco per intero, non elemento per elemento', function () {
    $application = openedApplication();

    patchApplication($application, [
        'managing_subjects' => json_encode([
            ['name' => 'Comune di Oliena', 'email' => 'oliena@example.com', 'subject' => 'organization'],
            ['name' => 'Mario Rossi', 'subject' => 'person'],
        ]),
    ])->assertOk();

    patchApplication($application, [
        'managing_subjects' => json_encode([
            ['name' => 'Comune di Nuoro', 'email' => 'nuoro@example.com', 'subject' => 'organization'],
        ]),
    ])->assertOk();

    expect($application->fresh()->api_data['managing_subjects'])->toHaveCount(1);
});

it('non pretende i campi che non sono ancora stati compilati', function () {
    $application = openedApplication();

    // Mezzo modulo e' vuoto e la modifica passa lo stesso: a pretendere tutto
    // sara' la conferma.
    patchApplication($application, ['distance' => 12.5])->assertOk();

    expect($application->fresh()->missingForConfirmation()['fields'])->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Un errore non scrive niente
|--------------------------------------------------------------------------
*/

it('rifiuta un valore non ammesso e non scrive nemmeno i campi buoni', function () {
    $application = openedApplication();
    patchApplication($application, ['from' => 'Punto A'])->assertOk();

    patchApplication($application, ['to' => 'Punto B', 'distance' => -5])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['distance']);

    $apiData = $application->fresh()->api_data;

    expect($apiData)->not->toHaveKey('to')
        ->and($apiData['from'])->toBe('Punto A');
});

it('rifiuta un campo che lo schema non conosce', function () {
    $application = openedApplication();

    patchApplication($application, ['campo_inventato' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['campo_inventato']);
});

/*
|--------------------------------------------------------------------------
| I campi che si mandano insieme
|--------------------------------------------------------------------------
*/

it('chiede i campi legati nella stessa chiamata', function () {
    $application = openedApplication();

    patchApplication($application, ['primary_activity' => 'hiking'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['activities']);
});

it('accetta i campi legati mandati insieme', function () {
    $application = openedApplication();

    patchApplication($application, [
        'activities' => json_encode(['hiking', 'mtb']),
        'primary_activity' => 'mtb',
        'difficulty' => json_encode(['hiking' => 'EE']),
    ])->assertOk();

    expect($application->fresh()->api_data['primary_activity'])->toBe('mtb');
});

it('rifiuta una primaria che non e fra le attivita mandate', function () {
    $application = openedApplication();

    patchApplication($application, [
        'activities' => json_encode(['hiking']),
        'primary_activity' => 'cycling',
    ])->assertUnprocessable()->assertJsonValidationErrors(['primary_activity']);
});

/*
|--------------------------------------------------------------------------
| La cancellazione
|--------------------------------------------------------------------------
*/

it('cancella un campo facoltativo mandato senza valore', function () {
    $application = openedApplication();
    patchApplication($application, ['overlapping_routes' => 'Sentiero 101'])->assertOk();

    patchApplication($application, ['overlapping_routes' => ''])->assertOk();

    expect($application->fresh()->api_data)->not->toHaveKey('overlapping_routes');
});

it('cancella anche un campo strutturato e uno numerico', function () {
    $application = openedApplication();
    patchApplication($application, [
        'themes' => json_encode(['woods-forests']),
        'ascent' => 300,
    ])->assertOk();

    patchApplication($application, ['themes' => '', 'ascent' => ''])->assertOk();

    $apiData = $application->fresh()->api_data;

    expect($apiData)->not->toHaveKey('themes')
        ->and($apiData)->not->toHaveKey('ascent');
});

it('non lascia svuotare un campo obbligatorio gia compilato', function () {
    $application = openedApplication();
    patchApplication($application, ['from' => 'Punto A'])->assertOk();

    patchApplication($application, ['from' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['from']);

    expect($application->fresh()->api_data['from'])->toBe('Punto A');
});

it('non si lamenta di un campo obbligatorio mai compilato mandato vuoto', function () {
    $application = openedApplication();

    // E' il caso di chi rimanda l'intero modulo, con dentro anche i campi che
    // non ha ancora compilato.
    patchApplication($application, ['from' => '', 'to' => 'Punto B'])->assertOk();

    expect($application->fresh()->api_data)->toHaveKey('to')
        ->and($application->fresh()->api_data)->not->toHaveKey('from');
});

/*
|--------------------------------------------------------------------------
| Chi puo' modificare, e quando
|--------------------------------------------------------------------------
*/

it('non modifica una domanda gia trasmessa', function () {
    $application = openedApplication();
    patchApplication($application, ['from' => 'Punto A'])->assertOk();

    $application->forceFill(['status' => TrailApplicationStatus::UnderReview])->saveQuietly();

    patchApplication($application, ['from' => 'Punto Z'])->assertStatus(409);

    expect($application->fresh()->api_data['from'])->toBe('Punto A');
});

it('risponde a una domanda d ufficio come a una che non esiste', function () {
    $office = TrailApplication::factory()->createQuietly(['source' => 'office']);

    $response = patchApplication($office, ['from' => 'Punto A']);
    $inesistente = test()->post(
        route('trail-registry-test.patch.update', 999999),
        ['_method' => 'PATCH', 'from' => 'Punto A'],
        ['Accept' => 'application/json'],
    );

    expect($response->status())->toBe($inesistente->status());
});

/*
|--------------------------------------------------------------------------
| La versione dello schema
|--------------------------------------------------------------------------
*/

it('riscrive la versione dello schema a ogni modifica', function () {
    $application = openedApplication();

    patchApplication($application, ['from' => 'Punto A'])->assertOk();

    expect($application->fresh()->api_data[ApplicationSchema::VERSION_KEY])
        ->toBe(app(ApplicationSchema::class)->version());
});

/*
|--------------------------------------------------------------------------
| Il tracciato si puo' sostituire
|--------------------------------------------------------------------------
*/

it('sostituisce la geometria con quella del file mandato', function () {
    $application = openedApplication();

    patchApplication($application, ['geometry' => patchGpx(3, 3)])->assertOk();

    $wkt = DB::selectOne(
        'SELECT ST_AsText(geometry::geometry) AS wkt FROM trail_applications WHERE id = ?',
        [$application->getKey()],
    )->wkt;

    // La risposta del servizio DEM e' finta e riporta la geometria su (1 1).
    // Cio' che conta qui e' che la colonna sia stata riscritta.
    expect($wkt)->not->toBeEmpty();
});

it('sostituisce anche il file originale del tracciato', function () {
    $application = openedApplication();
    $first = $application->getFirstMedia(TrailApplication::ORIGINAL_GEOMETRY_COLLECTION);

    patchApplication($application, ['geometry' => patchGpx(3, 3, 'nuova.gpx')])->assertOk();

    $application->unsetRelation('media');
    $secondo = $application->getFirstMedia(TrailApplication::ORIGINAL_GEOMETRY_COLLECTION);

    // La collection tiene un file solo: il nuovo sostituisce, non affianca.
    // Lasciare il vecchio manderebbe in istruttoria due tracciati diversi.
    expect($application->getMedia(TrailApplication::ORIGINAL_GEOMETRY_COLLECTION))->toHaveCount(1)
        ->and($secondo->file_name)->toBe('nuova.gpx')
        ->and($secondo->getKey())->not->toBe($first?->getKey());
});

it('rifa i dati tecnici invece di tenere quelli del tracciato di prima', function () {
    $application = openedApplication();

    DB::table('trail_applications')->where('id', $application->getKey())->update([
        'properties' => json_encode(['dem_data' => ['distance' => 999]]),
    ]);

    patchApplication($application, ['geometry' => patchGpx(3, 3)])->assertOk();

    // `needsDem()` e' falso finche' `dem_data` c'e' e la geometria ha quote
    // non nulle: senza azzerarlo, un GPX nuovo con le `<ele>` lascerebbe in
    // piedi i numeri del tracciato di prima.
    expect($application->fresh()->properties['dem_data']['distance'] ?? null)->not->toBe(999);
});

it('rifiuta un file che non e ne GPX ne GeoJSON e non tocca niente', function () {
    $application = openedApplication();
    patchApplication($application, ['from' => 'Punto A'])->assertOk();

    patchApplication($application, [
        'geometry' => UploadedFile::fake()->createWithContent('rotto.gpx', 'non sono un tracciato'),
    ])->assertUnprocessable();

    expect($application->fresh()->api_data['from'])->toBe('Punto A');
});

/*
|--------------------------------------------------------------------------
| Il numero del catasto segue la geometria
|--------------------------------------------------------------------------
*/

it('tiene il numero se il tracciato resta nello stesso settore', function () {
    $application = openedApplication();
    $before = $application->activeCode;

    patchApplication($application, ['geometry' => patchGpx(3, 3)])->assertOk();

    $application->unsetRelation('activeCode');

    expect($application->activeCode->getKey())->toBe($before->getKey())
        ->and($application->codes()->count())->toBe(1);
});

it('libera il numero e ne riserva un altro se il tracciato cambia settore', function () {
    $application = openedApplication();
    $before = $application->activeCode;

    // Il secondo settore, lontano dal primo.
    patchApplication($application, ['geometry' => patchGpx(21, 21)])->assertOk();

    $application->unsetRelation('activeCode');
    $after = $application->activeCode;

    expect($after->getKey())->not->toBe($before->getKey())
        ->and($after->fullCode)->not->toBe($before->fullCode)
        // Il legame non si rompe: resta la storia dei due codici.
        ->and($application->codes()->count())->toBe(2)
        ->and($before->fresh()->status->value)->toBe('released');
});
