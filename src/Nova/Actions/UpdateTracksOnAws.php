<?php

namespace Wm\WmPackage\Nova\Actions;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Models\App;

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
        $modelClass = config('wm-package.ec_track_model');

        foreach ($models as $model) {
            if ($model instanceof App) {
                try {

                    // Process tracks in chunks to avoid memory issues
                    $chunkSize = 500;
                    $processedCount = 0;

                    $modelClass::where('app_id', $model->id)->chunk($chunkSize, function ($tracks) use (&$processedCount) {
                        $this->writeOnAws($tracks);
                        $processedCount += $tracks->count();
                    });

                    $count += $processedCount;

                } catch (Exception $e) {
                    Log::error('Error processing App', ['app_id' => $model->id, 'error' => $e->getMessage()]);
                }
            } elseif (is_a($model, $modelClass)) {
                $this->writeOnAws([$model]);
                $count++;
            }
        }

        return Action::message("Messe in coda {$count} tracce per l'aggiornamento su aws!");
    }

    private function writeOnAws($tracks)
    {

        // Process tracks in batches of 100 to avoid memory issues and timeouts
        $batchSize = 100;
        $totalTracks = count($tracks);
        $processedCount = 0;

        foreach ($tracks->chunk($batchSize) as $batch) {
            foreach ($batch as $track) {
                try {
                    UpdateEcTrackAwsJob::dispatch($track);
                    $processedCount++;

                } catch (Exception $e) {
                    Log::error('Failed to dispatch job', [
                        'track_id' => $track->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Small delay between batches to prevent overwhelming the queue
            usleep(100000); // 0.1 second delay
        }
    }
}
