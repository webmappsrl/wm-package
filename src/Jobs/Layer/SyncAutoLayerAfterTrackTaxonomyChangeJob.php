<?php

namespace Wm\WmPackage\Jobs\Layer;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Il lock di unicità si prende su Redis, non sul database (oc:8910).
     *
     * Questo job si accoda dentro la transazione del salvataggio Nova di un contenuto. Con il
     * lock su `cache_locks`, `DatabaseLock::acquire()` tenta un INSERT e, se la riga esiste
     * già, PostgreSQL aborta la transazione: da lì in poi ogni query del salvataggio muore con
     * «current transaction is aborted» e il form non si salva. Stesso rimedio di
     * `BuildAppPoisGeojsonJob`.
     *
     * @return Store
     */
    public function uniqueVia()
    {
        return Cache::store('redis');
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

        $trackModelClass = config('wm-package.ec_track_model', EcTrack::class);
        $trackTable = (new $trackModelClass)->getTable();

        // Chi c'era prima: serve per reindicizzare anche le tracce che il nuovo filtro lascia
        // fuori. Senza, restano in indice con il layer addosso e l'app continua a mostrarle
        // dentro (oc:8910).
        $before = $layer->ecTracks()->pluck($trackTable.'.id')->all();

        $layerService->assignTracksByTaxonomy($layer);
        $layerService->updateLayersPropertyOnAllLayeredFeaturesWithJobs($layer);
        $pbfGeneratorService->regeneratePbfsForLayer($layer);

        $layer->unsetRelation('ecTracks');
        $after = $layer->ecTracks()->pluck($trackTable.'.id')->all();
        $this->reindexChangedTracks($layerService, $layer, $before, $after, $trackModelClass);

        // Reindex solo dopo il sync del pivot, così `layers` in indice è coerente.
        if ($this->trackId) {
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

    /**
     * Rimanda all'indice le tracce entrate e quelle uscite dal layer.
     *
     * Le uscite sono la metà che mancava: `updateLayersPropertyOnAllLayeredFeaturesWithJobs()`
     * tocca solo le tracce ancora collegate, quindi chi esce conserva in indice il layer di
     * prima e l'app continua a mostrarlo lì. Si reindicizza solo ciò che è cambiato: su un
     * layer con centinaia di tracce rifare tutto a ogni salvataggio costerebbe troppo.
     *
     * @param  list<int>  $before
     * @param  list<int>  $after
     * @param  class-string  $trackModelClass
     */
    private function reindexChangedTracks(
        LayerService $layerService,
        Layer $layer,
        array $before,
        array $after,
        string $trackModelClass
    ): void {
        $left = array_values(array_diff($before, $after));
        $entered = array_values(array_diff($after, $before));

        if ($left === [] && $entered === []) {
            return;
        }

        // Chi è uscito non è più nel pivot: `properties.layers` va corretto a mano, perché il
        // riallineamento di poco fa guarda solo le tracce collegate.
        foreach ($trackModelClass::query()->whereIn('id', $left)->cursor() as $track) {
            $layerService->updateLayerIdsPropertyOnLayeredFeature($track, [$layer->id], false);
            $track->searchable();
        }

        foreach ($trackModelClass::query()->whereIn('id', $entered)->cursor() as $track) {
            $track->searchable();
        }

        Log::info('Tracks reindexed after layer filters changed', [
            'layer_id' => $layer->id,
            'left' => count($left),
            'entered' => count($entered),
        ]);
    }
}
