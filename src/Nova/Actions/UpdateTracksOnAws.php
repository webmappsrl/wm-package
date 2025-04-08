<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;

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
