<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Http\Requests\NovaRequest;
use Vyuldashev\NovaPermission\PermissionBooleanGroup;
use Vyuldashev\NovaPermission\RoleBooleanGroup;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\AbstractUserResource;
use Wm\WmPackage\Services\RolesAndPermissionsService;

$makeUserResource = fn (User $user): AbstractUserResource => new class($user) extends AbstractUserResource {
    public static string $model = User::class;
};

$makeRoleField = function (NovaRequest $request, User $contextUser) use ($makeUserResource): RoleBooleanGroup {
    Auth::login($contextUser);
    $resource = $makeUserResource($contextUser);
    $fields = $resource->fields($request);

    return collect($fields)->first(fn ($f) => $f instanceof RoleBooleanGroup);
};

$makePermissionField = function (NovaRequest $request, User $contextUser) use ($makeUserResource): PermissionBooleanGroup {
    Auth::login($contextUser);
    $resource = $makeUserResource($contextUser);
    $fields = $resource->fields($request);

    return collect($fields)->first(fn ($f) => $f instanceof PermissionBooleanGroup);
};

beforeEach(function () {
    config(['wm-package.super_admin_emails' => ['superadmin@test.com']]);

    RolesAndPermissionsService::seedDatabase();
});

// --- RoleBooleanGroup ---

it('non-super-admin cannot modify roles via fillUsing', function () use ($makeRoleField) {
    $nonSuperAdmin = User::factory()->create(['email' => 'admin@test.com']);
    $nonSuperAdmin->assignRole('Administrator');

    $targetUser = User::factory()->create();
    $targetUser->assignRole('Validator');

    $request = NovaRequest::create('/', 'POST', [
        'roles' => json_encode(['Administrator' => true, 'Validator' => false]),
    ]);
    $request->setUserResolver(fn () => $nonSuperAdmin);

    $field = $makeRoleField($request, $nonSuperAdmin);

    // Protection is the early return inside fillUsing() when allowsUser() returns false
    $field->fill($request, $targetUser);
    $targetUser->refresh();

    expect($targetUser->hasRole('Validator'))->toBeTrue()
        ->and($targetUser->hasRole('Administrator'))->toBeFalse();
});

it('super-admin can assign a new role via fillUsing', function () use ($makeRoleField) {
    $superAdmin = User::factory()->create(['email' => 'superadmin@test.com']);
    $superAdmin->assignRole('Administrator');

    $targetUser = User::factory()->create();
    $targetUser->assignRole('Validator');

    $request = NovaRequest::create('/', 'POST', [
        'roles' => json_encode(['Administrator' => true, 'Validator' => false]),
    ]);
    $request->setUserResolver(fn () => $superAdmin);

    $field = $makeRoleField($request, $superAdmin);

    $field->fill($request, $targetUser);
    $targetUser->refresh();

    expect($targetUser->hasRole('Administrator'))->toBeTrue()
        ->and($targetUser->hasRole('Validator'))->toBeFalse();
});

it('super-admin cannot remove their own Administrator role (anti-self-demotion)', function () use ($makeRoleField) {
    $superAdmin = User::factory()->create(['email' => 'superadmin@test.com']);
    $superAdmin->assignRole('Administrator');

    $request = NovaRequest::create('/', 'POST', [
        'roles' => json_encode(['Administrator' => false, 'Validator' => true]),
    ]);
    $request->setUserResolver(fn () => $superAdmin);

    $field = $makeRoleField($request, $superAdmin);

    $field->fill($request, $superAdmin);
    $superAdmin->refresh();

    expect($superAdmin->hasRole('Administrator'))->toBeTrue();
});

it('super-admin can modify another users administrator role', function () use ($makeRoleField) {
    $superAdmin = User::factory()->create(['email' => 'superadmin@test.com']);
    $superAdmin->assignRole('Administrator');

    $otherAdmin = User::factory()->create();
    $otherAdmin->assignRole('Administrator');

    $request = NovaRequest::create('/', 'POST', [
        'roles' => json_encode(['Administrator' => false, 'Validator' => true]),
    ]);
    $request->setUserResolver(fn () => $superAdmin);

    $field = $makeRoleField($request, $superAdmin);

    $field->fill($request, $otherAdmin);
    $otherAdmin->refresh();

    expect($otherAdmin->hasRole('Administrator'))->toBeFalse()
        ->and($otherAdmin->hasRole('Validator'))->toBeTrue();
});

it('anti-self-demotion does not assign Administrator to super-admin who never had it', function () use ($makeRoleField) {
    $superAdmin = User::factory()->create(['email' => 'superadmin@test.com']);

    $request = NovaRequest::create('/', 'POST', [
        'roles' => json_encode(['Validator' => true]),
    ]);
    $request->setUserResolver(fn () => $superAdmin);

    $field = $makeRoleField($request, $superAdmin);

    $field->fill($request, $superAdmin);
    $superAdmin->refresh();

    expect($superAdmin->hasRole('Administrator'))->toBeFalse()
        ->and($superAdmin->hasRole('Validator'))->toBeTrue();
});

it('fillUsing returns early on invalid JSON without wiping roles', function () use ($makeRoleField) {
    $superAdmin = User::factory()->create(['email' => 'superadmin@test.com']);
    $superAdmin->assignRole('Administrator');

    $targetUser = User::factory()->create();
    $targetUser->assignRole('Validator');

    $request = NovaRequest::create('/', 'POST', [
        'roles' => 'not-valid-json',
    ]);
    $request->setUserResolver(fn () => $superAdmin);

    $field = $makeRoleField($request, $superAdmin);

    $field->fill($request, $targetUser);
    $targetUser->refresh();

    expect($targetUser->hasRole('Validator'))->toBeTrue();
});

// --- PermissionBooleanGroup ---

it('non-super-admin cannot modify permissions via fillUsing', function () use ($makePermissionField) {
    $nonSuperAdmin = User::factory()->create(['email' => 'admin@test.com']);
    $nonSuperAdmin->assignRole('Administrator');

    $targetUser = User::factory()->create();
    $targetUser->assignRole('Validator');

    $request = NovaRequest::create('/', 'POST', [
        'permissions' => json_encode(['validate ugc' => true]),
    ]);
    $request->setUserResolver(fn () => $nonSuperAdmin);

    $field = $makePermissionField($request, $nonSuperAdmin);

    $field->fill($request, $targetUser);
    $targetUser->refresh();

    expect($targetUser->hasDirectPermission('validate ugc'))->toBeFalse();
});

it('super-admin can assign permissions via fillUsing', function () use ($makePermissionField) {
    $superAdmin = User::factory()->create(['email' => 'superadmin@test.com']);
    $superAdmin->assignRole('Administrator');

    $targetUser = User::factory()->create();

    $request = NovaRequest::create('/', 'POST', [
        'permissions' => json_encode(['manage roles and permissions' => true]),
    ]);
    $request->setUserResolver(fn () => $superAdmin);

    $field = $makePermissionField($request, $superAdmin);

    $field->fill($request, $targetUser);
    $targetUser->refresh();

    expect($targetUser->hasDirectPermission('manage roles and permissions'))->toBeTrue();
});

it('super-admin cannot remove their own existing direct permissions', function () use ($makePermissionField) {
    $superAdmin = User::factory()->create(['email' => 'superadmin@test.com']);
    $superAdmin->assignRole('Administrator');
    $superAdmin->givePermissionTo('manage roles and permissions');

    $request = NovaRequest::create('/', 'POST', [
        'permissions' => json_encode(['manage roles and permissions' => false]),
    ]);
    $request->setUserResolver(fn () => $superAdmin);

    $field = $makePermissionField($request, $superAdmin);

    $field->fill($request, $superAdmin);
    $superAdmin->refresh();

    expect($superAdmin->hasDirectPermission('manage roles and permissions'))->toBeTrue();
});

it('permissions fillUsing returns early on invalid JSON without wiping permissions', function () use ($makePermissionField) {
    $superAdmin = User::factory()->create(['email' => 'superadmin@test.com']);
    $superAdmin->assignRole('Administrator');

    $targetUser = User::factory()->create();
    $targetUser->givePermissionTo('manage roles and permissions');

    $request = NovaRequest::create('/', 'POST', [
        'permissions' => 'not-valid-json',
    ]);
    $request->setUserResolver(fn () => $superAdmin);

    $field = $makePermissionField($request, $superAdmin);

    $field->fill($request, $targetUser);
    $targetUser->refresh();

    expect($targetUser->hasDirectPermission('manage roles and permissions'))->toBeTrue();
});
