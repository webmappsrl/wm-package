<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Models\Layer;

class UpdateLayerTracksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(protected Layer $layer) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Esegui la logica per aggiornare le tracce del layer
        $trackIds = $this->layer->getTracks();
        $this->layer->ecTracks()->sync($trackIds);
    }
}
