<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Services\Models\LayerService;

class UpdateLayerPbfAction extends Action
{
    use InteractsWithQueue, Queueable;

    public function name()
    {
        return __('Update Layer pbf');
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $layerService = LayerService::make();
        $count = 0;
        foreach ($models as $model) {
            $layerService->updateLayersPropertyOnAllLayeredFeaturesWithJobs($model);
            $count++;
        }

        return Action::message("Messe in coda {$count} layer per l'aggiornamento su aws!");
    }

    private function writeOnAws($tracks)
    {
        foreach ($tracks as $track) {
            UpdateEcTrackAwsJob::dispatch($track);
        }
    }
}
