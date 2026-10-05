<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Nova;
use Laravel\Nova\NovaCoreServiceProvider;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication as TrailApplicationModel;
use Wm\WmPackage\TrailRegistry\Nova\Actions\ApproveTrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\TrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * La regola dei ruoli del Catasto (oc:8700) sugli endpoint veri di Nova:
 * index, detail, modifica e Action. Chiamare authorizedTo*() direttamente non
 * basta: il detail e il PUT passano da authorizeTo*(), le Action con canRun()
 * saltano la Resource.
 */
beforeEach(function () {
    runTrailRegistryStubs();
    RolesAndPermissionsService::seedDatabase();
    config(['wm-package.features.trail_registry.enabled' => true]);
    app()->register(NovaCoreServiceProvider::class);
    Nova::auth(fn () => true);
    Nova::resources([TrailApplication::class, TrailRegistryCode::class, TrailRegistryAnomaly::class]);
});

function utenteHttp(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function istanzaHttp(TrailApplicationStatus $status = TrailApplicationStatus::UnderReview): TrailApplicationModel
{
    return TrailApplicationModel::factory()->create(['status' => $status]);
}

it('Administrator ed Editor aprono index e detail del registro dei codici', function (string $role) {
    $id = makeCode();

    $this->actingAs(utenteHttp($role))->getJson('/nova-api/trail-registry-codes')->assertOk();
    $this->actingAs(utenteHttp($role))->getJson("/nova-api/trail-registry-codes/{$id}")->assertOk();
})->with(['Administrator', 'Editor']);

it('Validator e Contributor ricevono 403 sull index delle tre Resource', function (string $role, string $uriKey) {
    $this->actingAs(utenteHttp($role))->getJson("/nova-api/{$uriKey}")->assertForbidden();
})->with(['Validator', 'Contributor'])
    ->with(['trail-applications', 'trail-registry-codes', 'trail-registry-anomalies']);

it('Validator e Contributor ricevono 403 sul detail di un codice aperto per URL', function (string $role) {
    $id = makeCode();

    $this->actingAs(utenteHttp($role))->getJson("/nova-api/trail-registry-codes/{$id}")->assertForbidden();
})->with(['Validator', 'Contributor']);

it('un Validator non puo modificare un istanza in istruttoria', function () {
    $application = istanzaHttp();

    $this->actingAs(utenteHttp('Validator'))
        ->putJson("/nova-api/trail-applications/{$application->id}", [])
        ->assertForbidden();
});

it('un Editor non puo modificare un istanza approvata', function () {
    $application = istanzaHttp(TrailApplicationStatus::Approved);

    $this->actingAs(utenteHttp('Editor'))
        ->putJson("/nova-api/trail-applications/{$application->id}", [])
        ->assertForbidden();
});

it('un Editor modifica un istanza in istruttoria', function () {
    // Nova registra ogni modifica in action_events, che non fa parte delle
    // migrazioni del package: senza, l'update risponderebbe 500 per la
    // tabella mancante e non per l'autorizzazione.
    foreach (['2018_01_01_000000_create_action_events_table' => 'CreateActionEventsTable', '2019_05_10_000000_add_fields_to_action_events_table' => 'AddFieldsToActionEventsTable'] as $file => $class) {
        require_once __DIR__."/../../../vendor/laravel/nova/database/migrations/{$file}.php";
        (new $class)->up();
    }
    $application = istanzaHttp();

    $this->actingAs(utenteHttp('Editor'))
        ->putJson("/nova-api/trail-applications/{$application->id}", [])
        ->assertOk();
});

it('un Validator non puo eseguire l Action Approva', function () {
    // Stesso impianto di ApproveTrailApplicationTest: con questo setup
    // l'Action, se eseguita, approverebbe davvero l'istanza. Senza, il buco
    // sull'autorizzazione si maschererebbe dietro un errore del service.
    // Nova registra ogni esecuzione in action_events: la tabella non fa parte
    // delle migrazioni del package.
    // Sono classi con nome, non migrazioni anonime: require_once e new.
    foreach (['2018_01_01_000000_create_action_events_table' => 'CreateActionEventsTable', '2019_05_10_000000_add_fields_to_action_events_table' => 'AddFieldsToActionEventsTable'] as $file => $class) {
        require_once __DIR__."/../../../vendor/laravel/nova/database/migrations/{$file}.php";
        (new $class)->up();
    }
    Bus::fake();
    DB::statement('ALTER SEQUENCE apps_id_seq RESTART WITH 1');
    config(['wm-package.shard_name' => 'wm_package_testing']);
    App::factory()->createQuietly();
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $application = istanzaHttp();
    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z ((1 1 0, 2 2 0))', $application->id]
    );
    app(TrailRegistryService::class)->reserve($application->refresh());

    $response = $this->actingAs(utenteHttp('Validator'))
        ->postJson('/nova-api/trail-applications/action?action='.(new ApproveTrailApplication)->uriKey(), [
            'resources' => (string) $application->id,
        ]);

    // canSee nasconde l'Action al Validator: ActionRequest::action() risponde
    // 403. Il canRun e' coperto in TrailRegistryNovaResourcesTest.
    expect($response->status())->toBe(403);
    expect($application->fresh()->status)->toBe(TrailApplicationStatus::UnderReview);
});

it('il form di creazione e per Editor e non per Validator', function () {
    $this->actingAs(utenteHttp('Editor'))
        ->getJson('/nova-api/trail-applications/creation-fields')
        ->assertOk();

    $this->actingAs(utenteHttp('Validator'))
        ->getJson('/nova-api/trail-applications/creation-fields')
        ->assertForbidden();
});

it('a dominio spento nemmeno un Administrator apre il registro dei codici', function () {
    config(['wm-package.features.trail_registry.enabled' => false]);

    $this->actingAs(utenteHttp('Administrator'))
        ->getJson('/nova-api/trail-registry-codes')
        ->assertForbidden();
});
