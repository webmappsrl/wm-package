<?php

namespace Wm\WmPackage\Nova;

use App\Nova\User;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
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
        'name',
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
            NovaTabTranslatable::make([Text::make('Name', 'name')]),
            BelongsTo::make('App', 'app', App::class)->filterable()->default(function () {
                $appCount = \Wm\WmPackage\Models\App::count();
                if ($appCount === 1) {
                    return \Wm\WmPackage\Models\App::first()->id;
                }

                return null;
            }),
            BelongsTo::make('User', 'user', User::class)->default(function () {
                return auth()->id();
            }),
            PropertiesPanel::makeWithModel(__('Properties'), $this->getPropertiesColumnName(), $this, true)->collapsible()->collapsedByDefault(),
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

    public function getDemTabFields(): array
    {
        return [
            Boolean::make(__('Round Trip'), 'properties->dem_data->round_trip'),
            Text::make(__('Ascent'), 'properties->dem_data->ascent'),
            Text::make(__('Descent'), 'properties->dem_data->descent'),
            Text::make(__('Distance'), 'properties->dem_data->distance'),
            Text::make(__('Maximum Elevation'), 'properties->dem_data->ele_max'),
            Text::make(__('Minimum Elevation'), 'properties->dem_data->ele_min'),
            Text::make(__('Starting Point Elevation'), 'properties->dem_data->ele_from'),
            Text::make(__('Ending Point Elevation'), 'properties->dem_data->ele_to'),
            Text::make(__('Duration Forward'), 'properties->dem_data->duration_forward'),
            Text::make(__('Duration Backward'), 'properties->dem_data->duration_backward'),
            Text::make(__('Duration Forward (bike)'), 'properties->dem_data->duration_forward_bike'),
            Text::make(__('Duration Backward (bike)'), 'properties->dem_data->duration_backward_bike'),
            Text::make(__('Duration Forward (hiking)'), 'properties->dem_data->duration_forward_hiking'),
            Text::make(__('Duration Backward (hiking)'), 'properties->dem_data->duration_backward_hiking'),
        ];
    }
}
