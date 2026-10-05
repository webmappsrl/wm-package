<?php

use Illuminate\Support\Facades\Gate;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\Policies\TrailRegistryPolicy;

/**
 * Il Catasto lo vede e lo usa solo chi lo gestisce (oc:8700): Administrator
 * ed Editor. Senza una policy registrata Nova non controlla detail, modifica e
 * Action aperti per URL: per questo la regola sta qui e non solo nelle Resource.
 */
beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

function utenteConRuolo(?string $role): User
{
    $user = User::factory()->create();
    if ($role !== null) {
        $user->assignRole($role);
    }

    return $user;
}

it('allows vale solo per Administrator ed Editor', function (?string $role, bool $expected) {
    expect(TrailRegistryPolicy::allows(utenteConRuolo($role)))->toBe($expected);
})->with([
    'Administrator' => ['Administrator', true],
    'Editor' => ['Editor', true],
    'Validator' => ['Validator', false],
    'Contributor' => ['Contributor', false],
    'senza ruolo' => [null, false],
]);

it('allows nega senza utente', function () {
    expect(TrailRegistryPolicy::allows(null))->toBeFalse();
});

it('la policy e registrata per i tre modelli del catasto', function () {
    foreach ([TrailApplication::class, TrailRegistryCode::class, TrailRegistryAnomaly::class] as $model) {
        expect(Gate::getPolicyFor($model))->toBeInstanceOf(TrailRegistryPolicy::class);
    }
});

it('codici e anomalie restano in sola lettura anche per Administrator', function () {
    $admin = utenteConRuolo('Administrator');

    foreach ([new TrailRegistryCode, new TrailRegistryAnomaly] as $model) {
        expect($admin->can('view', $model))->toBeTrue()
            ->and($admin->can('update', $model))->toBeFalse()
            ->and($admin->can('delete', $model))->toBeFalse()
            ->and($admin->can('create', $model::class))->toBeFalse();
    }
});

it('un istanza si modifica solo in istruttoria, e solo da Administrator ed Editor', function () {
    $editor = utenteConRuolo('Editor');
    $validator = utenteConRuolo('Validator');
    $inIstruttoria = (new TrailApplication)->forceFill(['status' => TrailApplicationStatus::UnderReview]);
    $approvata = (new TrailApplication)->forceFill(['status' => TrailApplicationStatus::Approved]);

    expect($editor->can('update', $inIstruttoria))->toBeTrue()
        ->and($editor->can('update', $approvata))->toBeFalse()
        ->and($validator->can('update', $inIstruttoria))->toBeFalse()
        ->and($editor->can('create', TrailApplication::class))->toBeTrue()
        ->and($validator->can('create', TrailApplication::class))->toBeFalse()
        ->and($editor->can('delete', $inIstruttoria))->toBeFalse();
});

class PolicyShardCodeModel extends TrailRegistryCode {}

it('la policy vale anche per il modello configurato dallo shard', function () {
    expect(Gate::getPolicyFor(PolicyShardCodeModel::class))->toBeInstanceOf(TrailRegistryPolicy::class);
    expect(utenteConRuolo('Validator')->can('view', new PolicyShardCodeModel))->toBeFalse();
    expect(utenteConRuolo('Editor')->can('view', new PolicyShardCodeModel))->toBeTrue();
});
