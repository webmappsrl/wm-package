<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Http\Controllers\Api\TrailRegistry\StoreAttachmentController;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Tests\Feature\TrailRegistry\Support\ShardTrailApplication;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

/**
 * L'endpoint di upload di un allegato (oc:8968).
 *
 * Le rotte vivono nello shard e nel package non esistono: qui se ne registra
 * una al volo, con un nome neutro, che punta al controller.
 */
beforeEach(function () {
    runTrailRegistryStubs();
    config(['wm-package.features.trail_registry.enabled' => true]);

    // Stesso setup degli altri test sugli allegati (vedi TrailApplicationMediaTest).
    Bus::fake();
    DB::statement('ALTER SEQUENCE apps_id_seq RESTART WITH 1');
    config(['wm-package.shard_name' => 'wm_package_testing']);
    App::factory()->createQuietly();
    Storage::fake(config('media-library.disk_name', 'wmfe'));
    Storage::fake('wmfe');
    Storage::fake('public');

    Route::post('/test-trail-registry/applications/{application}/attachments', StoreAttachmentController::class)
        ->middleware(['auth:api', SubstituteBindings::class])
        ->name('trail-registry-test.attachments.store');

    config(['jwt.secret' => 'segreto-di-prova-per-il-guard-api']);
    $this->actingAs(User::factory()->create(), 'api');
});

function attachmentApplication(array $state = []): TrailApplication
{
    return TrailApplication::factory()->create(array_merge(
        ['status' => TrailApplicationStatus::UnderCompilation],
        $state
    ));
}

function postAttachment(TrailApplication|int $application, array $data)
{
    $id = $application instanceof TrailApplication ? $application->getKey() : $application;

    return test()->postJson(route('trail-registry-test.attachments.store', $id), $data);
}

function distinctPhoto(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name.'.jpg', 'contenuto-'.$name);
}

it('aggiunge l allegato alla collection indicata', function () {
    $application = attachmentApplication();

    postAttachment($application, [
        'collection' => 'report',
        'file' => UploadedFile::fake()->create('relazione.pdf'),
    ])->assertCreated()->assertExactJson(['collection' => 'report', 'count' => 1]);

    expect($application->fresh()->getMedia('report'))->toHaveCount(1);
});

it('sostituisce il documento rimandato in una collection a file singolo', function () {
    $application = attachmentApplication();

    postAttachment($application, ['collection' => 'report', 'file' => UploadedFile::fake()->createWithContent('a.pdf', 'uno')])->assertCreated();
    postAttachment($application, ['collection' => 'report', 'file' => UploadedFile::fake()->createWithContent('b.pdf', 'due')])
        ->assertCreated()->assertJson(['count' => 1]);

    $media = $application->fresh()->getMedia('report');
    expect($media)->toHaveCount(1)->and($media->first()->file_name)->toBe('b.pdf');
});

it('non conta due volte la stessa fotografia rimandata dopo un timeout', function () {
    $application = attachmentApplication();

    // Due richieste, due file temporanei: stessi byte, come un retry vero.
    postAttachment($application, ['collection' => 'photos', 'file' => distinctPhoto('sentiero')])->assertCreated();
    postAttachment($application, ['collection' => 'photos', 'file' => distinctPhoto('sentiero')])
        ->assertCreated()->assertExactJson(['collection' => 'photos', 'count' => 1]);

    expect($application->fresh()->getMedia('photos'))->toHaveCount(1);
});

it('accetta cinque fotografie distinte e rifiuta la sesta', function () {
    $application = attachmentApplication();

    foreach (range(1, 5) as $n) {
        postAttachment($application, ['collection' => 'photos', 'file' => distinctPhoto("f{$n}")])
            ->assertCreated()->assertJson(['count' => $n]);
    }

    postAttachment($application, ['collection' => 'photos', 'file' => distinctPhoto('sesta')])
        ->assertStatus(422)->assertJsonStructure(['error']);

    expect($application->fresh()->getMedia('photos'))->toHaveCount(5);
});

it('lascia passare il rinvio della quinta fotografia quando il limite e raggiunto', function () {
    $application = attachmentApplication();

    foreach (range(1, 5) as $n) {
        postAttachment($application, ['collection' => 'photos', 'file' => distinctPhoto("f{$n}")])->assertCreated();
    }

    // Il retry della quinta dopo un timeout: conta dopo la deduplicazione.
    postAttachment($application, ['collection' => 'photos', 'file' => distinctPhoto('f5')])
        ->assertCreated()->assertJson(['count' => 5]);

    expect($application->fresh()->getMedia('photos'))->toHaveCount(5);
});

it('non accetta allegati per un istanza gia trasmessa', function () {
    $application = attachmentApplication(['status' => TrailApplicationStatus::UnderReview]);

    postAttachment($application, [
        'collection' => 'report',
        'file' => UploadedFile::fake()->create('relazione.pdf'),
    ])->assertStatus(409)->assertJsonStructure(['error']);

    expect($application->fresh()->getMedia('report'))->toHaveCount(0);
});

it('risponde come a una domanda inesistente per un istanza nata d ufficio', function () {
    $office = attachmentApplication(['source' => 'office']);
    // Anche trasmessa: lo stato non deve far trapelare che esiste.
    $officeSubmitted = attachmentApplication(['source' => 'office', 'status' => TrailApplicationStatus::UnderReview]);

    $payload = ['collection' => 'report', 'file' => UploadedFile::fake()->create('relazione.pdf')];

    postAttachment($office, $payload)->assertStatus(404);
    postAttachment($officeSubmitted, $payload)->assertStatus(404);
    postAttachment(999999, $payload)->assertStatus(404);

    expect($office->fresh()->getMedia('report'))->toHaveCount(0);
});

it('rifiuta la traccia del sentiero come collection', function () {
    $application = attachmentApplication();

    postAttachment($application, [
        'collection' => 'original_geometry',
        'file' => UploadedFile::fake()->create('traccia.gpx'),
    ])->assertStatus(422)->assertJsonValidationErrors('collection');
});

it('rifiuta una richiesta senza file', function () {
    postAttachment(attachmentApplication(), ['collection' => 'report'])
        ->assertStatus(422)->assertJsonValidationErrors('file');
});

// Una domanda d'ufficio e un identificatore sconosciuto devono essere
// indistinguibili anche con un payload non valido: la validazione non deve
// far trapelare l'esistenza.
it('risponde allo stesso modo a un payload non valido su domanda d ufficio e su identificatore inesistente', function () {
    $office = attachmentApplication(['source' => 'office']);
    $missingId = $office->getKey() + 1000;

    $onOffice = postAttachment($office, []);
    $onMissing = postAttachment($missingId, []);

    $onOffice->assertStatus(404);
    $onMissing->assertStatus(404);

    // Il corpo e' lo stesso a meno dell'identificatore, quando l'handler lo cita.
    $normalize = fn ($response, $id) => str_replace((string) $id, '{id}', $response->getContent());
    expect($normalize($onOffice, $office->getKey()))->toBe($normalize($onMissing, $missingId));
    expect($onOffice->headers->get('Content-Type'))->toBe($onMissing->headers->get('Content-Type'));
});

it('risponde allo stesso modo con un payload valido su domanda d ufficio e su identificatore inesistente', function () {
    $office = attachmentApplication(['source' => 'office']);
    $missingId = $office->getKey() + 1000;
    $payload = fn () => ['collection' => 'report', 'file' => UploadedFile::fake()->create('relazione.pdf')];

    $onOffice = postAttachment($office, $payload());
    $onMissing = postAttachment($missingId, $payload());

    $normalize = fn ($response, $id) => str_replace((string) $id, '{id}', $response->getContent());
    expect($onOffice->status())->toBe(404)->and($onMissing->status())->toBe(404);
    expect($normalize($onOffice, $office->getKey()))->toBe($normalize($onMissing, $missingId));
});

it('rifiuta l allegato se la domanda e stata trasmessa dopo il controllo iniziale', function () {
    $application = attachmentApplication();

    // La domanda viene trasmessa fra il caricamento del modello e il lock:
    // la transazione deve rileggere lo stato sotto lock e rifiutare.
    TrailApplication::retrieved(function (TrailApplication $loaded) use ($application) {
        static $done = false;
        if (! $done && $loaded->getKey() === $application->getKey()) {
            $done = true;
            DB::table($loaded->getTable())
                ->where('id', $loaded->getKey())
                ->update(['status' => TrailApplicationStatus::UnderReview->value]);
        }
    });

    postAttachment($application, [
        'collection' => 'report',
        'file' => UploadedFile::fake()->create('relazione.pdf'),
    ])->assertStatus(409);

    expect(TrailApplication::find($application->getKey())->getMedia('report'))->toHaveCount(0);
});

it('rifiuta la fotografia se la domanda e stata trasmessa dopo il controllo iniziale', function () {
    $application = attachmentApplication();

    TrailApplication::retrieved(function (TrailApplication $loaded) use ($application) {
        static $done = false;
        if (! $done && $loaded->getKey() === $application->getKey()) {
            $done = true;
            DB::table($loaded->getTable())
                ->where('id', $loaded->getKey())
                ->update(['status' => TrailApplicationStatus::UnderReview->value]);
        }
    });

    postAttachment($application, ['collection' => 'photos', 'file' => distinctPhoto('tardiva')])->assertStatus(409);

    expect(TrailApplication::find($application->getKey())->getMedia('photos'))->toHaveCount(0);
});

it('registra l allegato sotto la classe che lo shard ha configurato', function () {
    // Il binding della rotta consegna la classe base: senza conversione
    // l'allegato finirebbe sotto quel nome, e Nova, che lavora con la
    // sottoclasse, non lo troverebbe.
    $application = attachmentApplication();
    config(['wm-package.features.trail_registry.models.application' => ShardTrailApplication::class]);

    postAttachment($application, [
        'collection' => 'report',
        'file' => UploadedFile::fake()->create('relazione.pdf', 10, 'application/pdf'),
    ])->assertCreated();

    expect(DB::table('media')->where('model_id', $application->getKey())->where('collection_name', 'report')->value('model_type'))
        ->toBe(ShardTrailApplication::class)
        ->and(ShardTrailApplication::find($application->getKey())->getMedia('report'))->toHaveCount(1);
});
