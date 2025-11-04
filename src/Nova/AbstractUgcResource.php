<?php

namespace Wm\WmPackage\Nova;

use App\Nova\User;
use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Actions\CopyUgc;
use Wm\WmPackage\Nova\Actions\ExportTo;
use Wm\WmPackage\Nova\Fields\PropertiesPanel;
use Wm\WmPackage\Nova\Filters\AppFilter;
use Wm\WmPackage\Nova\Filters\FormSchemaFilter;
use Wm\WmPackage\Nova\Filters\UgcCreationDateFilter;
use Wm\WmPackage\Nova\Metrics\TopUgcCreators;

abstract class AbstractUgcResource extends AbstractGeometryResource
{
    /**
     * Get the fields displayed by the resource.
     *
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make('App', 'app', App::class)->filterable(),
            BelongsTo::make('Author', 'author', User::class)->searchable()->filterable()->hideWhenUpdating()->hideWhenCreating(),
            Text::make('Name', 'properties->name'),
            PropertiesPanel::makeWithModel('Form', 'properties->form', $this, true),
            PropertiesPanel::makeWithModel('Nominatim Address', 'properties->nominatim->address', $this, false)->collapsible(),
            PropertiesPanel::makeWithModel('Device', 'properties->device', $this, false)->collapsible()->collapsedByDefault(),
            PropertiesPanel::makeWithModel('Nominatim', 'properties->nominatim', $this, false)->collapsible()->collapsedByDefault(),
            PropertiesPanel::makeWithModel('Properties', 'properties', $this, false)->collapsible()->collapsedByDefault(),
            Images::make('Image', 'default')->hideFromIndex(),
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
        ];
    }

    /**
     * Get the cards available for the resource.
     *
     * @return array<int, \Laravel\Nova\Card>
     */
    public function cards(NovaRequest $request): array
    {
        $ugcModelClass = get_class(static::newModel());

        return [
            new TopUgcCreators($ugcModelClass)
                ->width('full'),
        ];
    }
}
