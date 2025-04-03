<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Fields\PropertiesPanel;
use Wm\WmPackage\Nova\Traits\MultiPolygonResourceTrait;

class Layer extends AbstractGeometryResource
{
    use MultiPolygonResourceTrait {
        fields as protected fieldsTrait;
    }

    public static $with = ['ecTracks', 'manualEcPois', 'appOwner', 'associatedApps'];

    public static $model = \Wm\WmPackage\Models\Layer::class;

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Name', 'name'),
            BelongsTo::make('App', 'appOwner', App::class),
            PropertiesPanel::make('Properties', 'layer')->collapsible(),
            MorphToMany::make('Activities', 'taxonomyActivities', TaxonomyActivity::class),
            ...$this->fieldsTrait($request),
        ];
    }
}
