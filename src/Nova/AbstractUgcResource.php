<?php

namespace Wm\WmPackage\Nova;

use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Laravel\Nova\Card;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Actions\CopyUgc;
use Wm\WmPackage\Nova\Actions\ExportTo;
use Wm\WmPackage\Nova\Fields\PropertiesPanel;
use Wm\WmPackage\Nova\Filters\AppFilter;
use Wm\WmPackage\Nova\Filters\FormSchemaFilter;
use Wm\WmPackage\Nova\Filters\HasMediaFilter;
use Wm\WmPackage\Nova\Filters\UgcCreationDateFilter;
use Wm\WmPackage\Nova\Metrics\TopUgcCreators;

abstract class AbstractUgcResource extends AbstractGeometryResource
{
    /**
     * La Resource dell'autore, quando non e' quella dedotta dallo shard.
     *
     * @var class-string|null
     */
    protected static ?string $userResource = null;

    /**
     * Build an "index" query for the given resource.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public static function indexQuery(NovaRequest $request, $query)
    {
        $user = Auth::user();

        if ($user && ! $user->hasRole('Administrator') && ! $user->hasRole('Validator')) {
            $table = $query->getModel()->getTable();
            if (Schema::hasColumn($table, 'app_id')) {
                return $query->whereIn('app_id', $user->ownedAppIds());
            }
        }

        return $query;
    }

    /**
     * Get the fields displayed by the resource.
     *
     * @return array<int, Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make(__('App'), 'app', App::class)->filterable(),
            BelongsTo::make(__('Author'), 'author', static::userResource())->searchable()->filterable()->hideWhenUpdating()->hideWhenCreating(),
            Text::make(__('Name'), 'Name', function () {
                return data_get($this->properties, 'name')
                    ?? data_get($this->properties, 'form.title');
            })->readonly(),
            PropertiesPanel::makeWithModel(__('Form'), 'properties->form', $this, true),
            PropertiesPanel::makeWithModel(__('Nominatim Address'), 'properties->nominatim->address', $this, false)->collapsible(),
            PropertiesPanel::makeWithModel(__('Device'), 'properties->device', $this, false)->collapsible()->collapsedByDefault(),
            PropertiesPanel::makeWithModel(__('Nominatim'), 'properties->nominatim', $this, false)->collapsible()->collapsedByDefault(),
            PropertiesPanel::makeWithModel(__('Properties'), 'properties', $this, false)->collapsible()->collapsedByDefault(),
            MorphToMany::make(__('Taxonomy Wheres'), 'taxonomyWheres', TaxonomyWhere::class)
                ->display('name')
                ->collapsedByDefault(),
            Images::make(__('Image'), 'default')->hideFromIndex(),
            Text::make(__('Media'))
                ->resolveUsing(function ($value, $model) {
                    $count = $model->getMedia()->count();

                    return (string) $count;
                })
                ->onlyOnIndex(),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            ...parent::actions($request),
            new ExportTo,
            new CopyUgc,
        ];
    }

    public function filters(NovaRequest $request): array
    {
        return [
            new AppFilter,
            new FormSchemaFilter($this->model()),
            new UgcCreationDateFilter,
            new HasMediaFilter,
        ];
    }

    /**
     * Get the cards available for the resource.
     *
     * @return array<int, Card>
     */
    public function cards(NovaRequest $request): array
    {
        $ugcModelClass = get_class(static::newModel());

        return [
            new TopUgcCreators($ugcModelClass)
                ->width('full'),
        ];
    }

    /**
     * La Resource con cui si apre l'autore del contenuto.
     *
     * Qui c'era `App\\Nova\\User`, cioe' la Resource di uno shard: il package non puo' nominare
     * una classe che nei suoi test e in un altro shard non esiste. Il package non ha una Resource
     * utente concreta — `AbstractUserResource` e' astratta e Nova non puo' istanziarla — quindi
     * se lo shard non ce l'ha la relazione non si mostra, invece di far saltare la scheda.
     *
     * @return class-string|null
     */
    protected static function userResource(): ?string
    {
        return static::$userResource
            ?? (class_exists(\App\Nova\User::class) ? \App\Nova\User::class : null);
    }

    /**
     * Dichiara la Resource dell'autore senza estendere la classe: serve ai test, che altrimenti
     * dovrebbero creare un alias di classe per una Resource di uno shard.
     *
     * @param  class-string|null  $resource
     */
    public static function usingUserResource(?string $resource): void
    {
        static::$userResource = $resource;
    }

}
