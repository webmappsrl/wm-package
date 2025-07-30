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

class GenerateOptimizedPBFChainJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Numero massimo di tentativi
    public $tries = 3;

    // Tempo massimo di esecuzione in secondi
    public $timeout = 1800; // 30 minuti

    private $app_id;
    private $startZoom;
    private $minZoom;
    private $no_pbf_layer = false;
    private $maxClusterDistance;
    private $trackIds;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($app_id, $startZoom, $minZoom, $no_pbf_layer = false, $maxClusterDistance = 10000, $trackIds = null)
    {
        $this->app_id = $app_id;
        $this->startZoom = $startZoom;
        $this->minZoom = $minZoom;
        $this->no_pbf_layer = $no_pbf_layer;
        $this->maxClusterDistance = $maxClusterDistance;
        $this->trackIds = $trackIds;
    }

    public function handle(GeometryComputationService $geometryService, PBFGeneratorService $pbfService)
    {
        try {
            Log::info("Avvio catena di generazione PBF ottimizzata", [
                'app_id' => $this->app_id,
                'start_zoom' => $this->startZoom,
                'min_zoom' => $this->minZoom
            ]);

            // Usa le track IDs passate come parametro o recuperale se non fornite
            $trackIds = $this->trackIds ?? $pbfService->getAllTrackIds($this->app_id);
            
            if (empty($trackIds)) {
                Log::info("Nessuna traccia trovata per app_id {$this->app_id}");
                return;
            }

            Log::info("Trovate " . count($trackIds) . " tracce per la generazione ottimizzata", [
                'app_id' => $this->app_id
            ]);

            // Genera la catena di job per ogni livello di zoom (dal più alto al più basso)
            $jobs = [];
            
            for ($zoom = $this->startZoom; $zoom >= $this->minZoom; $zoom--) {
                $jobs[] = new GenerateOptimizedPBFByZoomJob(
                    $this->app_id,
                    $zoom,
                    $this->no_pbf_layer,
                    $this->maxClusterDistance,
                    $trackIds  // Passa le tracce già reperite
                );
            }

            // Dispatch della catena di job
            Bus::chain($jobs)
                ->onConnection('redis')
                ->onQueue('pbf')
                ->dispatch();

            Log::info("Catena di generazione PBF ottimizzata avviata", [
                'app_id' => $this->app_id,
                'total_zoom_levels' => count($jobs),
                'zoom_range' => "{$this->startZoom} → {$this->minZoom}"
            ]);

        } catch (Throwable $e) {
            Log::error("Errore nella catena di generazione PBF ottimizzata: " . $e->getMessage(), [
                'app_id' => $this->app_id,
                'start_zoom' => $this->startZoom,
                'min_zoom' => $this->minZoom,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }


} 