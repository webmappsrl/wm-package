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
        foreach ($models as $model) {
            if ($model instanceof App) {
                $tracks = EcTrack::where('app_id', $model->id)->get();
                $this->writeOnAws($tracks);
            } elseif ($model instanceof EcTrack) {
                $this->writeOnAws([$model]);
            }
        }

        return Action::message('Update jobs have been dispatched!');
    }

    private function writeOnAws($tracks)
    {
        foreach ($tracks as $track) {
            UpdateEcTrackAwsJob::dispatch($track);
        }
    }
}
