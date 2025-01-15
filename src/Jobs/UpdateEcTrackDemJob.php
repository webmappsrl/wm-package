<?php

namespace Wm\WmPackage\Jobs;

use App\Traits\HandlesData;
use Illuminate\Bus\Queueable;
use Wm\WmPackage\Models\EcTrack;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Wm\WmPackage\Services\EcTrackService;

class UpdateEcTrackDemJob implements ShouldQueue
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
    public function handle(EcTrackService $ecTrackService)
    {
        $ecTrackService->updateDemData($this->ecTrack);
    }
}
