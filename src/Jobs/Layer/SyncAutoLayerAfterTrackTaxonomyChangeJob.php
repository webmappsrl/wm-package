<?php

namespace Wm\WmPackage\Jobs\Layer;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\LayerService;
use Wm\WmPackage\Services\PBFGeneratorService;

class SyncAutoLayerAfterTrackTaxonomyChangeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 600;

    public function __construct(
        public int $layerId,
        public ?int $trackId = null
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'sync-auto-layer-track-taxonomy-'.$this->layerId;
    }

    public function handle(
        LayerService $layerService,
        PBFGeneratorService $pbfGeneratorService
    ): void {
        $layer = Layer::find($this->layerId);
        if (! $layer || ! $layer->isAutoTrackMode()) {
            return;
        }

        $layerService->assignTracksByTaxonomy($layer);
        $layerService->updateLayersPropertyOnAllLayeredFeaturesWithJobs($layer);
        $pbfGeneratorService->regeneratePbfsForLayer($layer);

        // Reindex solo dopo il sync del pivot, così `layers` in indice è coerente.
        if ($this->trackId) {
            $trackModelClass = config('wm-package.ec_track_model', EcTrack::class);
            $track = $trackModelClass::find($this->trackId);
            if ($track) {
                $isAttachedToLayer = $layer->ecTracks()
                    ->where($track->getTable().'.id', $track->id)
                    ->exists();

                // Aggiorna immediatamente properties.layers della track corrente
                // per garantire coerenza prima della reindicizzazione.
                $layerService->updateLayerIdsPropertyOnLayeredFeature(
                    $track,
                    [$layer->id],
                    $isAttachedToLayer
                );

                $track->searchable();
                Log::info('Track reindexed after auto layer sync', [
                    'track_id' => $this->trackId,
                    'layer_id' => $this->layerId,
                    'attached_to_layer' => $isAttachedToLayer,
                ]);
            }
        }
    }
}
