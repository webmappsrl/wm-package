<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\EcTrack;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Wm\WmPackage\Services\Models\LayerService;
use Wm\WmPackage\Services\GeometryComputationService;

class UpdateLayerGeometryJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(protected Layer $layer) {}


    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->layer->id;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(LayerService $layerService)
    {
        $saved = $layerService->updateLayerGeometry($this->layer);
        return ['saved' => $saved];
    }
}
