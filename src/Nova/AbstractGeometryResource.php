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
use Laravel\Nova\Panel;
use Laravel\Nova\Resource;
use Wm\WmPackage\Nova\Fields\PropertiesPanel;
use Wm\WmPackage\Nova\Traits\HasDemClassification;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends resource<TModel>
 */
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
     * @return array<int, Field|Panel>
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
        // Struttura unica per campo: label, unita' di misura mostrata come
        // help del valore manuale, e regole di validazione decise dal dev
        // per quel campo (oc:8571). Le quote di elevazione ammettono valori
        // negativi, le durate sono minuti interi restituiti dal DEM.
        $mainFields = [
            'ascent' => [__('Ascent'), __('In metri'), ['nullable', 'numeric', 'min:0']],
            'descent' => [__('Descent'), __('In metri'), ['nullable', 'numeric', 'min:0']],
            'distance' => [__('Distance'), __('In km'), ['nullable', 'numeric', 'min:0']],
            'ele_max' => [__('Maximum Elevation'), __('In metri'), ['nullable', 'numeric']],
            'ele_min' => [__('Minimum Elevation'), __('In metri'), ['nullable', 'numeric']],
            'ele_from' => [__('Starting Point Elevation'), __('In metri'), ['nullable', 'numeric']],
            'ele_to' => [__('Ending Point Elevation'), __('In metri'), ['nullable', 'numeric']],
            'duration_forward' => [__('Duration Forward'), __('In minuti'), ['nullable', 'integer', 'min:0']],
            'duration_backward' => [__('Duration Backward'), __('In minuti'), ['nullable', 'integer', 'min:0']],
        ];

        $fields = [
            Boolean::make(__('Round Trip'), 'properties->dem_data->round_trip'),
        ];

        foreach ($mainFields as $fieldKey => [$label, $unit, $rules]) {
            $fields[] = Text::make($label, 'properties->dem_data->'.$fieldKey)
                ->onlyOnDetail()
                ->resolveUsing(function ($value, $model) use ($fieldKey) {
                    return $this->generateFieldTable($model, $fieldKey);
                })
                ->asHtml();

            $fields[] = Text::make($label, 'properties->manual_data->'.$fieldKey)
                ->onlyOnForms()
                ->rules(...$rules)
                ->help($unit);
        }

        $fields[] = Text::make(__('Duration Forward (bike)'), 'properties->dem_data->duration_forward_bike');
        $fields[] = Text::make(__('Duration Backward (bike)'), 'properties->dem_data->duration_backward_bike');
        $fields[] = Text::make(__('Duration Forward (hiking)'), 'properties->dem_data->duration_forward_hiking');
        $fields[] = Text::make(__('Duration Backward (hiking)'), 'properties->dem_data->duration_backward_hiking');

        return $fields;
    }
}
