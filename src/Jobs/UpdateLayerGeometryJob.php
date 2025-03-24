<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\Models\LayerService;

class UpdateLayerGeometryJob implements ShouldQueue
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
    public function handle(GeometryComputationService $geometryComputationService, LayerService $layerService)
    {
        $relatedFeaturesQuery = $layerService->getRelatedModelsQuery(EcTrack::class, $this->layer);
        $geometry = $geometryComputationService->geometryModelsToBbox($relatedFeaturesQuery);

        $saved = false;
        if ($geometry !== $this->layer->geometry) {
            $this->layer->geometry = $geometry;
            $saved = $this->layer->save();
        }

        return ['saved' => $saved];
    }
}
