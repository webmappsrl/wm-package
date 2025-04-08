<?php

namespace Wm\WmPackage\Nova\Actions;

use Wm\WmPackage\Models\App;
use Illuminate\Bus\Queueable;
use Laravel\Nova\Actions\Action;
use Wm\WmPackage\Models\EcTrack;
use Illuminate\Support\Collection;
use Laravel\Nova\Fields\ActionFields;
use Illuminate\Queue\InteractsWithQueue;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;

class UpdateTracksOnAws extends Action
{
    use InteractsWithQueue, Queueable;

    public function name()
    {
        return __('Update Tracks on AWS');
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $count = 0;
        foreach ($models as $model) {
            if ($model instanceof App) {
                $tracks = EcTrack::where('app_id', $model->id)->get();
                $count++;
                $this->writeOnAws($tracks);
            } elseif ($model instanceof EcTrack) {
                $this->writeOnAws([$model]);
                $count++;
            }
        }

        return Action::message("Messe in coda {$count} tracce per l'aggiornamento su aws!");
    }

    private function writeOnAws($tracks)
    {
        foreach ($tracks as $track) {
            UpdateEcTrackAwsJob::dispatch($track);
        }
    }
}
