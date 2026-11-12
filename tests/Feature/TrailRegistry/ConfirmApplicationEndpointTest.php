<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Http\Controllers\Api\TrailRegistry\ConfirmApplicationController;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

/**
 * L'endpoint di conferma della compilazione (oc:8968).
 *
 * Le rotte vivono nello shard e nel package non esistono: qui se ne registra
 * una al volo, con un nome neutro, che punta al controller.
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

    Route::post('/test-trail-registry/applications/{application}/confirm', ConfirmApplicationController::class)
        ->middleware(['auth:api', SubstituteBindings::class])
        ->name('trail-registry-test.applications.confirm');

    config(['jwt.secret' => 'segreto-di-prova-per-il-guard-api']);
    $this->actingAs(User::factory()->create(), 'api');
});

function confirmApplication(TrailApplication|int $application)
{
    $id = $application instanceof TrailApplication ? $application->getKey() : $application;

    return test()->postJson(route('trail-registry-test.applications.confirm', $id));
}

function jsonKeysAtAnyDepth(array $data): array
{
    $keys = [];
    array_walk_recursive($data, fn () => null);
    $walk = function (array $node) use (&$walk, &$keys) {
        foreach ($node as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value)) {
                $walk($value);
            }
        }
    };
    $walk($data);

    return $keys;
}

it('trasmette un fascicolo completo e risponde solo com e andata', function () {
    $application = TrailApplication::factory()->complete()->create();

    confirmApplication($application)
        ->assertOk()
        ->assertJsonPath('id', $application->getKey())
        ->assertJsonPath('status', 'under_review')
        ->assertJsonPath('already_confirmed', false);

    expect($application->refresh()->status)->toBe(TrailApplicationStatus::UnderReview);
});

it('elenca cosa manca e lascia il fascicolo in compilazione', function () {
    $application = TrailApplication::factory()->complete()->create();
    $application->clearMediaCollection('maintenance_plan');

    confirmApplication($application)
        ->assertStatus(422)
        ->assertJsonStructure(['error', 'missing' => ['fields', 'attachments']])
        ->assertJsonPath('missing.fields', [])
        ->assertJsonPath('missing.attachments.0', 'maintenance_plan');

    expect($application->refresh()->status)->toBe(TrailApplicationStatus::UnderCompilation);
});

it('tiene separati i campi mancanti dagli allegati mancanti', function () {
    $application = TrailApplication::factory()->complete()->create();
    $application->writeApiData([]);
    $application->clearMediaCollection('photos');

    confirmApplication($application)
        ->assertStatus(422)
        ->assertJsonPath('missing.fields', TrailApplication::requiredApiFields())
        ->assertJsonPath('missing.attachments', ['photos']);
});

it('alla seconda conferma risponde 200 e dice che era gia confermata', function () {
    // Chi ritenta dopo un timeout non deve credere di non aver trasmesso: la
    // risposta e' un successo come la prima, e il campo dice che non e'
    // cambiato nulla.
    $application = TrailApplication::factory()->complete()->create();

    confirmApplication($application)->assertOk()->assertJsonPath('already_confirmed', false);
    confirmApplication($application)
        ->assertOk()
        ->assertJsonPath('status', 'under_review')
        ->assertJsonPath('already_confirmed', true);
});

it('non riapre una domanda gia decisa', function (TrailApplicationStatus $decided) {
    $application = TrailApplication::factory()->complete()->create();
    $application->newQuery()->whereKey($application->getKey())->update(['status' => $decided->value]);

    confirmApplication($application)
        ->assertStatus(409)
        ->assertJsonStructure(['error']);

    expect($application->refresh()->status)->toBe($decided);
})->with([
    'respinta' => [TrailApplicationStatus::Rejected],
    'approvata' => [TrailApplicationStatus::Approved],
]);

it('non restituisce ne il numero del sentiero ne i dati della domanda', function () {
    // La conferma dice com'e' andata l'azione: stato, dati tecnici e numero si
    // leggono dall'endpoint di stato, che non cambia nulla.
    $application = TrailApplication::factory()->complete()->create();

    $json = confirmApplication($application)->assertOk()->json();

    expect(array_keys($json))->toEqualCanonicalizing(['id', 'status', 'already_confirmed'])
        ->and(jsonKeysAtAnyDepth($json))->not->toContain('code', 'trail_code', 'trail_registry_code_id');
});

it('risponde allo stesso modo su una domanda d ufficio e su un identificatore inesistente', function () {
    $office = TrailApplication::factory()->complete()->create(['source' => 'office']);
    $officeReview = TrailApplication::factory()->create(['source' => 'office']);
    $missingId = $office->getKey() + 1000;

    $onOffice = confirmApplication($office);
    $onOfficeReview = confirmApplication($officeReview);
    $onMissing = confirmApplication($missingId);

    expect($onOffice->status())->toBe(404)
        ->and($onOfficeReview->status())->toBe(404)
        ->and($onMissing->status())->toBe(404);

    // Il corpo e' lo stesso a meno dell'identificatore, quando l'handler lo cita.
    $normalize = fn ($response, $id) => str_replace((string) $id, '{id}', $response->getContent());
    expect($normalize($onOffice, $office->getKey()))->toBe($normalize($onMissing, $missingId))
        ->and($onOffice->headers->get('Content-Type'))->toBe($onMissing->headers->get('Content-Type'));

    // Una domanda d'ufficio non si trasmette dal canale.
    expect($office->refresh()->status)->toBe(TrailApplicationStatus::UnderCompilation);
});
