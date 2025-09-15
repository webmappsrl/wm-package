<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\BelongsToMany;
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

    public function fields(NovaRequest $request): array
    {
        return [
            ...$this->fieldsTrait($request),
            PropertiesPanel::makeWithModel(__('Converted from UGC'), 'properties->ugc', $this, false),
            PropertiesPanel::makeWithModel('Form', 'properties->form', $this, false),
            BelongsToMany::make('EcTracks', 'ecTracks', EcTrack::class),
        ];
    }
}
