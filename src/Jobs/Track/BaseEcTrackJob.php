<?php

namespace Wm\WmPackage\Jobs\Track;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Models\EcTrack;

class BaseEcTrackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected EcTrack $ecTrack;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(EcTrack $ecTrack)
    {
        $this->ecTrack = $ecTrack;
    }

    /**
     * Get the EcTrack instance associated with the job.
     */
    public function getEcTrack(): EcTrack
    {
        return $this->ecTrack;
    }
}
