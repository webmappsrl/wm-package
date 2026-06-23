<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Http\Requests\NovaRequest;
use Vyuldashev\NovaPermission\RoleBooleanGroup;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\AbstractUserResource;
use Wm\WmPackage\Services\RolesAndPermissionsService;

// Minimal concrete implementation for testing
function makeUserResource(User $user): AbstractUserResource
{
    return new class($user) extends AbstractUserResource
    {
        public static string $model = User::class;
    };
}

function makeRoleField(NovaRequest $request, User $contextUser): RoleBooleanGroup
{
    Auth::login($contextUser);
    $resource = makeUserResource($contextUser);
    $fields = $resource->fields($request);

    return collect($fields)->first(fn ($f) => $f instanceof RoleBooleanGroup);
}

beforeEach(function () {
    config(['wm-package.super_admin_emails' => ['superadmin@test.com']]);

    RolesAndPermissionsService::seedDatabase();
});

it('non-super-admin cannot modify roles via fillUsing (field is readonly)', function () {
    $nonSuperAdmin = User::factory()->create(['email' => 'admin@test.com']);
    $nonSuperAdmin->assignRole('Administrator');

    $targetUser = User::factory()->create();
    $targetUser->assignRole('Validator');

    $request = NovaRequest::create('/', 'POST', [
        'roles' => json_encode(['Administrator' => true, 'Validator' => false]),
    ]);
    $request->setUserResolver(fn () => $nonSuperAdmin);

    Auth::login($nonSuperAdmin);
    $field = makeRoleField($request, $nonSuperAdmin);

    // fill() returns early when isReadonly() is true for non-super-admin
    $field->fill($request, $targetUser);
    $targetUser->refresh();

    expect($targetUser->hasRole('Validator'))->toBeTrue()
        ->and($targetUser->hasRole('Administrator'))->toBeFalse();
});

it('super-admin can assign a new role via fillUsing', function () {
    $superAdmin = User::factory()->create(['email' => 'superadmin@test.com']);
    $superAdmin->assignRole('Administrator');

    $targetUser = User::factory()->create();
    $targetUser->assignRole('Validator');

    $request = NovaRequest::create('/', 'POST', [
        'roles' => json_encode(['Administrator' => true, 'Validator' => false]),
    ]);
    $request->setUserResolver(fn () => $superAdmin);

    Auth::login($superAdmin);
    $field = makeRoleField($request, $superAdmin);

    $field->fill($request, $targetUser);
    $targetUser->refresh();

    expect($targetUser->hasRole('Administrator'))->toBeTrue()
        ->and($targetUser->hasRole('Validator'))->toBeFalse();
});

it('super-admin cannot remove their own Administrator role (anti-self-demotion)', function () {
    $superAdmin = User::factory()->create(['email' => 'superadmin@test.com']);
    $superAdmin->assignRole('Administrator');

    // Super-admin tries to remove their own Administrator role
    $request = NovaRequest::create('/', 'POST', [
        'roles' => json_encode(['Administrator' => false, 'Validator' => true]),
    ]);
    $request->setUserResolver(fn () => $superAdmin);

    Auth::login($superAdmin);
    $field = makeRoleField($request, $superAdmin);

    $field->fill($request, $superAdmin);
    $superAdmin->refresh();

    expect($superAdmin->hasRole('Administrator'))->toBeTrue();
});

it('super-admin can modify another users administrator role', function () {
    $superAdmin = User::factory()->create(['email' => 'superadmin@test.com']);
    $superAdmin->assignRole('Administrator');

    $otherAdmin = User::factory()->create();
    $otherAdmin->assignRole('Administrator');

    // Super-admin removes Administrator from another user — allowed
    $request = NovaRequest::create('/', 'POST', [
        'roles' => json_encode(['Administrator' => false, 'Validator' => true]),
    ]);
    $request->setUserResolver(fn () => $superAdmin);

    Auth::login($superAdmin);
    $field = makeRoleField($request, $superAdmin);

    $field->fill($request, $otherAdmin);
    $otherAdmin->refresh();

    expect($otherAdmin->hasRole('Administrator'))->toBeFalse()
        ->and($otherAdmin->hasRole('Validator'))->toBeTrue();
});
