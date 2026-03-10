<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Actions\ExecuteEcPoiDataChainAction;
use Wm\WmPackage\Nova\Actions\TranslateModelAction;
use Wm\WmPackage\Nova\Fields\PropertiesPanel;
use Wm\WmPackage\Nova\Filters\GlobalEcPoiFilter;
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
                ->help(__('Tipologie di POI associate a questo punto di interesse')),
            PropertiesPanel::makeWithModel(__('Converted from UGC'), 'properties->ugc', $this, false),
            PropertiesPanel::makeWithModel('Form', 'properties->form', $this, false),
            BelongsToMany::make('EcTracks', 'ecTracks', EcTrack::class),
            Boolean::make(__('Global'), 'global')
                ->default(true)
                ->help(__('Indicates if the POI is global and visible always on the map')),
        ];
    }

    /**
     * Azioni disponibili sul resource EcPoi.
     *
     * @return array<int, Action>
     */
    public function actions(NovaRequest $request): array
    {
        return [
            new ExecuteEcPoiDataChainAction,
            new TranslateModelAction,
        ];
    }

    /**
     * Filtri disponibili sul resource EcPoi.
     */
    public function filters(NovaRequest $request): array
    {
        return [
            ...parent::filters($request),
            new GlobalEcPoiFilter,
        ];
    }
}
