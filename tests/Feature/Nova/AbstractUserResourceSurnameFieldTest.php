<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\AbstractUserResource;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

function surnameField(User $user): Text
{
    $viewer = \App\Models\User::factory()->create();
    Auth::login($viewer);

    $request = NovaRequest::create('/');
    $request->setUserResolver(fn () => $viewer);

    $resource = new class($user) extends AbstractUserResource
    {
        public static string $model = User::class;
    };

    $fields = $resource->fields($request);

    return collect($fields)->first(fn ($field) => $field->attribute === 'surname');
}

it('exposes the surname field and shows it on the index', function () {
    $user = User::factory()->create(['surname' => 'Rossi']);

    $field = surnameField($user);

    expect($field)->not->toBeNull()
        ->and($field->showOnIndex)->toBeTrue();

    $field->resolveForDisplay($user);
    expect($field->value)->toBe('Rossi');
});
