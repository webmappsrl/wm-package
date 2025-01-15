<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\CloudStorageService;

class UpdateEcTrackAwsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;



    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(protected EcTrack $ecTrack) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(CloudStorageService $cloudStorageService)
    {
        $geojson = $this->ecTrack->getGeojson();
        $trackUri = $this->ecTrack->id . '.json';
        $cloudStorageService->storeTrack($trackUri, json_encode($geojson));
    }
}
