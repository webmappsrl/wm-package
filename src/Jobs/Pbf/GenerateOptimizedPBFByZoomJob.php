<?php

namespace Wm\WmPackage\Jobs\Pbf;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\PBFGeneratorService;

class GenerateOptimizedPBFByZoomJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Numero massimo di tentativi
    public $tries = 5;

    // Tempo massimo di esecuzione in secondi
    public $timeout = 900; // 15 minuti (più lungo per il clustering)

    private $app_id;
    private $zoom;
    private $zoomTreshold;
    private $no_pbf_layer = false;
    private $trackIds;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($app_id, $zoom, $no_pbf_layer = false, $trackIds = null)
    {
        $this->app_id = $app_id;
        $this->zoom = $zoom;
        $this->no_pbf_layer = $no_pbf_layer;
        $this->trackIds = $trackIds;
        $this->zoomTreshold = PBFGeneratorService::make()->getZoomTreshold();
    }

    public function handle(GeometryComputationService $geometryService, PBFGeneratorService $pbfService)
    {
        try {
            Log::info("Avvio generazione PBF ottimizzata per zoom {$this->zoom}", [
                'app_id' => $this->app_id,
                'zoom' => $this->zoom
            ]);

            // Pulisci le chiavi cache per questo zoom
            $geometryService->clearEmptyTileKeys($this->app_id, $this->zoom);

            // Usa le tracce passate dal job chain o reperiscile se non fornite
            if ($this->trackIds === null) {
                $trackIds = $pbfService->getAllTrackIds($this->app_id);
            } else {
                $trackIds = $this->trackIds;
            }
            
            if (empty($trackIds)) {
                Log::info("Nessuna traccia trovata per app_id {$this->app_id} al zoom {$this->zoom}");
                return;
            }

            Log::info("Trovate " . count($trackIds) . " tracce per zoom {$this->zoom}", [
                'app_id' => $this->app_id,
                'zoom' => $this->zoom
            ]);

            // Genera i tile ottimizzati per questo zoom
            $tiles = $pbfService->generateOptimizedTilesForZoom($trackIds, $this->zoom);
            
            if (empty($tiles)) {
                Log::info("Nessun tile da generare per zoom {$this->zoom}", [
                    'app_id' => $this->app_id,
                    'zoom' => $this->zoom
                ]);
                return;
            }

            Log::info("Generati " . count($tiles) . " tile ottimizzati per zoom {$this->zoom}", [
                'app_id' => $this->app_id,
                'zoom' => $this->zoom
            ]);

            // Crea i job per ogni tile
            $jobs = [];
            foreach ($tiles as $tile) {
                [$x, $y, $z] = $tile;
                if ($z <= $this->zoomTreshold && !$this->no_pbf_layer) {
                    $jobs[] = new GenerateLayerPBFJob($z, $x, $y, $this->app_id);
                } else {
                    $jobs[] = new GeneratePBFJob($z, $x, $y, $this->app_id);
                }
            }

            // Dispatch del batch
            if (!empty($jobs)) {
                $batch = Bus::batch($jobs)
                    ->name("Optimized PBF batch: {$this->app_id}/{$this->zoom} (" . count($jobs) . " jobs)")
                    ->onConnection('redis')
                    ->onQueue('pbf')
                    ->dispatch();

                Log::info("Batch di generazione PBF ottimizzata avviato", [
                    'app_id' => $this->app_id,
                    'zoom' => $this->zoom,
                    'total_jobs' => count($jobs),
                    'batch_id' => $batch->id ?? 'unknown'
                ]);
            }

        } catch (Throwable $e) {
            Log::error("Errore nel Job PBF ottimizzato per zoom {$this->zoom}: " . $e->getMessage(), [
                'app_id' => $this->app_id,
                'zoom' => $this->zoom,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

 

} 