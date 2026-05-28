<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;

class TaxonomyPoiType extends AbstractTaxonomyResource
{
    public static $model = \Wm\WmPackage\Models\TaxonomyPoiType::class;

    public static $title = 'name';

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            Select::make(__('Map icon display'), 'properties->use_image_as_icon')
                ->options([
                    '0' => __('Use category icon (default)'),
                    '1' => __('Use image as icon'),
                ])
                ->default('0')
                ->displayUsingLabels()
                ->help(__('Default behaviour for all related POIs of this type when displayed on a track map: show the category icon or the feature image.')),
            MorphToMany::make('POI Associati', 'ecPois', EcPoi::class)
                ->display('name')
                ->help('Punti di interesse associati a questa tassonomia'),
        ];
    }
}
