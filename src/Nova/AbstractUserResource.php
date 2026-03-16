<?php

namespace Wm\WmPackage\Nova;

use App\Nova\UgcPoi;
use App\Nova\UgcTrack;
use Illuminate\Validation\Rules;
use Laravel\Nova\Fields\FormData;
use Laravel\Nova\Fields\Gravatar;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Password;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use Spatie\Permission\Models\Permission;
use Vyuldashev\NovaPermission\PermissionBooleanGroup;
use Vyuldashev\NovaPermission\RoleBooleanGroup;
use Wm\WmPackage\Models\App as AppModel;
use Wm\WmPackage\Nova\Filters\AppFilter;

abstract class AbstractUserResource extends Resource
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

            Text::make(__('Name'), 'name')
                ->sortable()
                ->rules('required', 'max:255'),

            Text::make(__('Email'), 'email')
                ->sortable()
                ->rules('required', 'email', 'max:254')
                ->creationRules('unique:users,email')
                ->updateRules('unique:users,email,{{resourceId}}'),

            Password::make(__('Password'), 'password')
                ->onlyOnForms()
                ->creationRules('required', Rules\Password::defaults())
                ->updateRules('nullable', Rules\Password::defaults()),
            ...array_filter([$this->getAppFieldForIndex()]),
            RoleBooleanGroup::make(__('Roles'), 'roles')
                ->readonly(function () {
                    return ! auth()->user()->hasRole('Administrator') && ! auth()->user()->hasPermissionTo('manage roles and permissions');
                }),
            PermissionBooleanGroup::make(__('Permissions'), 'permissions')
                ->readonly(function () {
                    return ! auth()->user()->hasRole('Administrator') && ! auth()->user()->hasPermissionTo('manage roles and permissions');
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
            HasMany::make(__('UGC POIs'), 'ugc_pois', UgcPoi::class)
                ->onlyOnDetail()
                ->canSee(function () {
                    return optional(auth()->user())->hasRole('Administrator');
                }),
            HasMany::make(__('UGC Tracks'), 'ugc_tracks', UgcTrack::class)
                ->onlyOnDetail()
                ->canSee(function () {
                    return optional(auth()->user())->hasRole('Administrator');
                }),
        ];
    }

    /**
     * Get the filters available for the resource.
     *
     * @return array<int, Filter>
     */
    public function filters(NovaRequest $request): array
    {
        return [
            new AppFilter,
        ];
    }

    /**
     * Restituisce un array di ID delle app associate all'utente attraverso i UGC.
     * La relazione è indiretta: utente -> UGC (ugc_pois/ugc_tracks) -> app
     *
     * @return array<int>
     */
    protected function getApps(): array
    {
        $apps = collect();

        // App da ugcPois
        if ($this->ugcPois) {
            $apps = $apps->merge($this->ugcPois->pluck('app')->filter());
        }

        // App da ugcTracks
        if ($this->ugcTracks) {
            $apps = $apps->merge($this->ugcTracks->pluck('app')->filter());
        }

        // Rimuovi duplicati e restituisci array di ID
        return $apps->unique('id')->pluck('id')->toArray();
    }

    /**
     * Restituisce il campo app per l'index se esistono più app.
     * La relazione è indiretta: utente -> UGC (ugc_pois/ugc_tracks) -> app
     */
    protected function getAppFieldForIndex(): ?Text
    {
        $appCount = AppModel::count();

        if ($appCount > 1) {
            return Text::make(__('App'), function () {
                $appIds = $this->getApps();

                if (empty($appIds)) {
                    return '';
                }

                // Carica le app per ottenere i nomi
                $apps = AppModel::whereIn('id', $appIds)->get();

                // Genera link cliccabili per ogni app
                $links = $apps->map(function ($app) {
                    $url = Nova::url('/resources/apps/'.$app->id);

                    return '<a href="'.$url.'" class="link-default">'.$app->name.'</a>';
                });

                return $links->join(', ');
            })
                ->onlyOnIndex()
                ->sortable(false)
                ->asHtml();
        }

        return null;
    }
}
