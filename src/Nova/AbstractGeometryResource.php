<?php

namespace Wm\WmPackage\Nova;

use App\Nova\User;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Card;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Lenses\Lens;
use Laravel\Nova\Resource;
use Wm\WmPackage\Nova\Fields\PropertiesPanel;
use Wm\WmPackage\Nova\Traits\HasDemClassification;

abstract class AbstractGeometryResource extends Resource
{
    use HasDemClassification;

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
     * @return array<int, Field>
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
     * @return array<int, Card>
     */
    public function cards(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the filters available for the resource.
     *
     * @return array<int, Filter>
     */
    public function filters(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the lenses available for the resource.
     *
     * @return array<int, Lens>
     */
    public function lenses(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the actions available for the resource.
     *
     * @return array<int, Action>
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
        $mainFields = [
            'ascent' => __('Ascent'),
            'descent' => __('Descent'),
            'distance' => __('Distance'),
            'ele_max' => __('Maximum Elevation'),
            'ele_min' => __('Minimum Elevation'),
            'ele_from' => __('Starting Point Elevation'),
            'ele_to' => __('Ending Point Elevation'),
            'duration_forward' => __('Duration Forward'),
            'duration_backward' => __('Duration Backward'),
        ];

        $fields = [
            Boolean::make(__('Round Trip'), 'properties->dem_data->round_trip'),
        ];

        foreach ($mainFields as $fieldKey => $label) {
            $fields[] = Text::make($label, 'properties->dem_data->'.$fieldKey)
                ->onlyOnDetail()
                ->resolveUsing(function ($value, $model) use ($fieldKey) {
                    return $this->generateFieldTable($model, $fieldKey);
                })
                ->asHtml();

            $fields[] = Text::make($label, 'properties->manual_data->'.$fieldKey)
                ->onlyOnForms();
        }

        $fields[] = Text::make(__('Duration Forward (bike)'), 'properties->dem_data->duration_forward_bike');
        $fields[] = Text::make(__('Duration Backward (bike)'), 'properties->dem_data->duration_backward_bike');
        $fields[] = Text::make(__('Duration Forward (hiking)'), 'properties->dem_data->duration_forward_hiking');
        $fields[] = Text::make(__('Duration Backward (hiking)'), 'properties->dem_data->duration_backward_hiking');

        return $fields;
    }
}
