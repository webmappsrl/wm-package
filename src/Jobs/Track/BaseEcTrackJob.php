<?php

namespace Wm\WmPackage\Jobs\Track;

use Illuminate\Bus\Queueable;
use Wm\WmPackage\Models\EcTrack;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;


abstract class BaseEcTrackJob implements ShouldQueue
{

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(protected EcTrack $ecTrack) {}
}
