<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\Actions\GeneratePrintPdfAction;
use Wm\WmPackage\Nova\App as AppResource;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(DatabaseTransactions::class);

/*
 * L'opzione «Print PDF» dell'App la vede e la modifica solo l'Administrator; l'action di stampa
 * compare solo dove la stampa è accesa (oc:8982).
 */
beforeEach(function () {
    config(['wm-package.ec_track_model' => EcTrack::class]);
    Queue::fake();
});

/** Il campo «Print PDF» del tab App della Resource App. */
function printPdfField(): ?Boolean
{
    $method = new ReflectionMethod(AppResource::class, 'printPdfField');
    $method->setAccessible(true);

    return $method->invoke(new AppResource(new App));
}

/** Richiesta Nova con l'utente dato e i parametri di query dati. */
function printRequestAs(?User $user, array $query = []): NovaRequest
{
    $request = NovaRequest::create('/', 'GET', $query);
    $request->setUserResolver(fn () => $user);

    return $request;
}

/** Utente con il ruolo dato. */
function printUserWithRole(string $role): User
{
    RolesAndPermissionsService::seedDatabase();
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('mostra l\'opzione Print PDF dell\'App solo al superAdmin', function () {
    config(['wm-package.super_admin_emails' => ['superadmin@example.test']]);
    $superAdmin = printUserWithRole('Administrator');
    $superAdmin->forceFill(['email' => 'superadmin@example.test'])->saveQuietly();
    $field = printPdfField();

    expect($field)->not->toBeNull()
        ->and($field->authorizedToSee(printRequestAs($superAdmin)))->toBeTrue()
        ->and($field->authorizedToSee(printRequestAs(printUserWithRole('Administrator'))))->toBeFalse()
        ->and($field->authorizedToSee(printRequestAs(printUserWithRole('Editor'))))->toBeFalse();
});

it('mostra l\'action nel dettaglio solo se l\'App dell\'elemento ha la stampa accesa', function () {
    $on = printApp();
    $off = App::factory()->createQuietly();
    $printable = EcTrack::factory()->createQuietly(['app_id' => $on->id, 'osmid' => null]);
    $hidden = EcTrack::factory()->createQuietly(['app_id' => $off->id, 'osmid' => null]);
    $action = new GeneratePrintPdfAction(EcTrack::class);

    expect($action->authorizedToSee(printRequestAs(null, ['resourceId' => $printable->id])))->toBeTrue()
        ->and($action->authorizedToSee(printRequestAs(null, ['resourceId' => $hidden->id])))->toBeFalse();
});

it('mostra l\'action nell\'index solo se almeno un\'App ha la stampa accesa', function () {
    App::query()->get()->each(function (App $app) {
        $app->properties = array_merge((array) $app->properties, ['ec_pdf_enabled' => false]);
        $app->saveQuietly();
    });
    $action = new GeneratePrintPdfAction(EcTrack::class);

    expect($action->authorizedToSee(printRequestAs(null)))->toBeFalse();

    printApp();

    expect($action->authorizedToSee(printRequestAs(null)))->toBeTrue();
});
