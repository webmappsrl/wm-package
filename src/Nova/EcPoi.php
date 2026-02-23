<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Fields\PropertiesPanel;
use Wm\WmPackage\Nova\Traits\PointResourceTrait;

class EcPoi extends AbstractEcResource
{
    use PointResourceTrait {
        fields as protected fieldsTrait;
    }

    public static function label(): string
    {
        return __('Poi');
    }

    public static $model = \Wm\WmPackage\Models\EcPoi::class;

    public static $title = 'name';

    public function title()
    {
        return $this->getTranslation('name', 'it') ?: $this->id;
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ...$this->fieldsTrait($request),
            MorphToMany::make(__('Taxonomy Poi Types'), 'taxonomyPoiTypes', TaxonomyPoiType::class)
                ->display('name')
                ->help('Tipologie di POI associate a questo punto di interesse'),
            PropertiesPanel::makeWithModel(__('Converted from UGC'), 'properties->ugc', $this, false),
            PropertiesPanel::makeWithModel('Form', 'properties->form', $this, false),
            BelongsToMany::make('EcTracks', 'ecTracks', EcTrack::class),
        ];
    }
}
