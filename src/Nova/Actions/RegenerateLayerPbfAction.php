<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Services\Models\LayerService;

class RegenerateLayerPbfAction extends BasePbfAction
{
    public function name()
    {
        return __('Rigenera PBF Layer');
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $layerService = LayerService::make();
        $count = 0;
        foreach ($models as $model) {
            $layerService->updateLayersPropertyOnAllLayeredFeaturesWithJobs($model, false);
            $count++;
        }

        return Action::message("Messe in coda {$count} layer per l'aggiornamento su aws!");
    }
}

