<?php

namespace Wm\WmPackage\Jobs\Layer;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\LayerService;

class SyncAutoLayerAfterPoiTaxonomyChangeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 600;

    public function __construct(
        public int $layerId,
        public ?int $poiId = null
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'sync-auto-layer-poi-taxonomy-'.$this->layerId;
    }

    public function handle(LayerService $layerService): void
    {
        $layer = Layer::find($this->layerId);
        if (! $layer || ! $layer->isAutoPoiMode()) {
            return;
        }

        $layerService->assignPoisByTaxonomy($layer);
        $layerService->updateLayersPropertyOnAllLayeredFeaturesWithJobs($layer);

        // Reindex solo dopo il sync del pivot, così `layers` in indice è coerente.
        if ($this->poiId) {
            $poiModelClass = config('wm-package.ec_poi_model', EcPoi::class);
            $poi = $poiModelClass::find($this->poiId);
            if ($poi) {
                $isAttachedToLayer = $layer->ecPois()
                    ->where($poi->getTable().'.id', $poi->id)
                    ->exists();

                // Aggiorna immediatamente properties.layers del POI corrente
                // per garantire coerenza prima della reindicizzazione.
                $layerService->updateLayerIdsPropertyOnLayeredFeature(
                    $poi,
                    [$layer->id],
                    $isAttachedToLayer
                );

                if ($poi->app_id) {
                    BuildAppPoisGeojsonJob::dispatch($poi->app_id);
                }
                Log::info('Poi reindexed after auto layer sync', [
                    'poi_id' => $this->poiId,
                    'layer_id' => $this->layerId,
                    'attached_to_layer' => $isAttachedToLayer,
                ]);
            }
        }
    }
}
