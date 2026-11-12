<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Exceptions\ApplicationIncompleteException;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailApplicationTransitionException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * La chiusura della compilazione (oc:8968). Stesso setup dei test sugli
 * allegati: i media dell'istanza hanno bisogno di un'app di riferimento, di un
 * disco finto e dei job tenuti fuori dalla coda.
 */
beforeEach(function () {
    runTrailRegistryStubs();
    config(['wm-package.features.trail_registry.enabled' => true]);

    Bus::fake();
    DB::statement('ALTER SEQUENCE apps_id_seq RESTART WITH 1');
    config(['wm-package.shard_name' => 'wm_package_testing']);
    App::factory()->createQuietly();

    Storage::fake(config('media-library.disk_name', 'wmfe'));
    Storage::fake('wmfe');
    Storage::fake('public');
});

it('porta in istruttoria un fascicolo completo', function () {
    $application = TrailApplication::factory()->complete()->create();

    app(TrailRegistryService::class)->confirmCompilation($application);

    expect($application->refresh()->status)->toBe(TrailApplicationStatus::UnderReview);
});

it('rifiuta e dice cosa manca, lasciando il fascicolo in compilazione', function () {
    $application = TrailApplication::factory()->complete()->create();
    $application->clearMediaCollection('report');

    try {
        app(TrailRegistryService::class)->confirmCompilation($application);
        $this->fail('doveva sollevare');
    } catch (ApplicationIncompleteException $e) {
        expect($e->missing()['attachments'])->toContain('report');
    }

    expect($application->refresh()->status)->toBe(TrailApplicationStatus::UnderCompilation);
});

it('tiene separati i campi e gli allegati mancanti', function () {
    $application = TrailApplication::factory()->complete()->create();
    $application->writeApiData([]);
    $application->clearMediaCollection('photos');

    try {
        app(TrailRegistryService::class)->confirmCompilation($application);
        $this->fail('doveva sollevare');
    } catch (ApplicationIncompleteException $e) {
        expect($e->missing()['fields'])->toBe(TrailApplication::requiredApiFields())
            ->and($e->missing()['attachments'])->toBe(['photos']);
    }
});

it('e idempotente su un fascicolo gia in istruttoria', function () {
    $application = TrailApplication::factory()->complete()->create();
    $service = app(TrailRegistryService::class);

    // Il ritentativo di un client andato in timeout: stesso esito, niente
    // errore. Il valore restituito dice se la transizione e' avvenuta adesso:
    // e' cio' che permette al canale di rispondere «era gia' confermata».
    expect($service->confirmCompilation($application))->toBeTrue()
        ->and($service->confirmCompilation($application))->toBeFalse();

    expect($application->refresh()->status)->toBe(TrailApplicationStatus::UnderReview);
});

it('non ricontrolla il fascicolo di chi e gia in istruttoria', function () {
    $application = TrailApplication::factory()->complete()->create();
    $service = app(TrailRegistryService::class);
    $service->confirmCompilation($application);
    $application->clearMediaCollection('report');

    $service->confirmCompilation($application);

    expect($application->refresh()->status)->toBe(TrailApplicationStatus::UnderReview);
});

it('non riapre un fascicolo respinto o approvato', function (TrailApplicationStatus $status) {
    $application = TrailApplication::factory()->complete()->create(['status' => $status]);

    expect(fn () => app(TrailRegistryService::class)->confirmCompilation($application))
        ->toThrow(InvalidTrailApplicationTransitionException::class);

    expect($application->refresh()->status)->toBe($status);
})->with([
    'respinto' => TrailApplicationStatus::Rejected,
    'approvato' => TrailApplicationStatus::Approved,
]);

it('non riserva un numero', function () {
    $application = TrailApplication::factory()->complete()->create();
    $before = DB::table('trail_registry_codes')->count();

    app(TrailRegistryService::class)->confirmCompilation($application);

    expect(DB::table('trail_registry_codes')->count())->toBe($before);
});

it('decide sullo stato del database, non su quello del modello stantio', function (TrailApplicationStatus $inDatabase) {
    $application = TrailApplication::factory()->complete()->create();
    $stantio = TrailApplication::find($application->id);
    expect($stantio->status)->toBe(TrailApplicationStatus::UnderCompilation);

    DB::table('trail_applications')->where('id', $application->id)->update(['status' => $inDatabase->value]);

    $chiamata = fn () => app(TrailRegistryService::class)->confirmCompilation($stantio);

    if ($inDatabase === TrailApplicationStatus::UnderReview) {
        $chiamata();
    } else {
        expect($chiamata)->toThrow(InvalidTrailApplicationTransitionException::class);
    }

    expect(DB::table('trail_applications')->where('id', $application->id)->value('status'))->toBe($inDatabase->value);
})->with([
    'gia trasmesso' => TrailApplicationStatus::UnderReview,
    'respinto' => TrailApplicationStatus::Rejected,
]);

it('valuta la completezza su cio che e nel database, non sul modello stantio', function () {
    $application = TrailApplication::factory()->complete()->create();
    $application->clearMediaCollection('report');

    // Il modello del chiamante e' stato caricato quando il report mancava...
    $stantio = TrailApplication::find($application->id);
    expect($stantio->missingForConfirmation()['attachments'])->toContain('report');

    // ...e subito dopo il report e' arrivato.
    $application->addMedia(UploadedFile::fake()->createWithContent('report.pdf', 'report'))
        ->toMediaCollection('report');

    app(TrailRegistryService::class)->confirmCompilation($stantio);

    expect($stantio->status)->toBe(TrailApplicationStatus::UnderReview)
        ->and(TrailApplication::find($application->id)->status)->toBe(TrailApplicationStatus::UnderReview);
});

it('solleva un errore di dominio se l istanza e stata cancellata', function () {
    $application = TrailApplication::factory()->complete()->create();
    $stantio = TrailApplication::find($application->id);
    DB::table('trail_applications')->where('id', $application->id)->delete();

    expect(fn () => app(TrailRegistryService::class)->confirmCompilation($stantio))
        ->toThrow(ModelNotFoundException::class);
});
