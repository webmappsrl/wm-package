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
