<?php

namespace Wm\WmPackage\Jobs\Track;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Models\EcTrack;

class ReindexEcTrackSearchableJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public int $trackId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'reindex-ectrack-searchable-'.$this->trackId;
    }

    public function handle(): void
    {
        $trackModelClass = config('wm-package.ec_track_model', EcTrack::class);
        $track = $trackModelClass::find($this->trackId);
        if (! $track) {
            return;
        }

        $track->searchable();
    }
}
