<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use Wm\WmPackage\Nova\Fields\PropertiesPanel;

abstract class AbstractGeometryResource extends Resource
{
    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'id';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'id',
    ];

    /**
     * Get the fields displayed by the resource.
     *
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Name', 'name'),
            BelongsTo::make('App', 'app', App::class),
            PropertiesPanel::make(ucwords($this->getPropertiesColumnName()), $this->getPropertiesModelKey())->collapsible(),
        ];
    }

    /**
     * Get the model key for properties configuration based on the concrete class name.
     */
    protected function getPropertiesModelKey(): string
    {
        // Get the class basename (e.g., "EcPoi" from "Wm\WmPackage\Nova\EcPoi")
        $className = class_basename(get_class($this));

        // Convert to snake case (e.g., "EcPoi" to "ec_poi")
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className));
    }

    /**
     * Get the cards available for the resource.
     *
     * @return array<int, \Laravel\Nova\Card>
     */
    public function cards(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the filters available for the resource.
     *
     * @return array<int, \Laravel\Nova\Filters\Filter>
     */
    public function filters(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the lenses available for the resource.
     *
     * @return array<int, \Laravel\Nova\Lenses\Lens>
     */
    public function lenses(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the actions available for the resource.
     *
     * @return array<int, \Laravel\Nova\Actions\Action>
     */
    public function actions(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the name of the properties column based on model type.
     *
     * @return string The name of the properties column
     */
    protected function getPropertiesColumnName(): string
    {
        if ($this instanceof Media) {
            return 'custom_properties';
        }

        return 'properties';
    }
}
