<?php

namespace Wm\WmPackage\Nova;

use Illuminate\Validation\Rules;
use Laravel\Nova\Fields\FormData;
use Laravel\Nova\Fields\Gravatar;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Password;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Spatie\Permission\Models\Permission;
use Vyuldashev\NovaPermission\PermissionBooleanGroup;
use Vyuldashev\NovaPermission\RoleBooleanGroup;

abstract class AbstractUser extends Resource
{
    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'name';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'id',
        'name',
        'email',
    ];

    /**
     * Get the fields displayed by the resource.
     *
     * @return array
     */
    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),

            Gravatar::make()->maxWidth(50),

            Text::make('Name')
                ->sortable()
                ->rules('required', 'max:255'),

            Text::make('Email')
                ->sortable()
                ->rules('required', 'email', 'max:254')
                ->creationRules('unique:users,email')
                ->updateRules('unique:users,email,{{resourceId}}'),

            Password::make('Password')
                ->onlyOnForms()
                ->creationRules('required', Rules\Password::defaults())
                ->updateRules('nullable', Rules\Password::defaults()),

            RoleBooleanGroup::make('Roles', 'roles')->canSee(function () {
                return auth()->user()->hasRole('Administrator') || auth()->user()->hasPermissionTo('manage roles and permissions');
            }),
            PermissionBooleanGroup::make('Permissions', 'permissions')
                ->canSee(function () {
                    return auth()->user()->hasRole('Administrator') || auth()->user()->hasPermissionTo('manage roles and permissions');
                })
                ->dependsOn('roles', function (PermissionBooleanGroup $field, NovaRequest $request, FormData $formData) {
                    $roles = $formData->get('roles');
                    $rolesArray = json_decode($roles, true);

                    $permissions = collect();

                    if (isset($rolesArray['Administrator']) && $rolesArray['Administrator'] === true) {
                        $permissions = $permissions->merge(Permission::where('name', 'manage roles and permissions')->pluck('name', 'name'));
                    }

                    if (isset($rolesArray['Validator']) && $rolesArray['Validator'] === true) {
                        $permissions = $permissions->merge(Permission::where('name', 'like', 'validate %')->pluck('name', 'name'));
                    }

                    $field->options(function () use ($permissions) {
                        return $permissions->toArray();
                    });
                }),
        ];
    }
}
