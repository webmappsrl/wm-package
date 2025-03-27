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

class GeneratePBFByZoomJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Numero massimo di tentativi
    public $tries = 5;

    // Tempo massimo di esecuzione in secondi
    public $timeout = 900; // 10 minuti

    private $bbox;

    private $zoom;

    private $app_id;

    private $zoomTreshold = 6;

    private $no_pbf_layer = false;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($bbox, $zoom, $app_id, $no_pbf_layer = false)
    {
        $this->bbox = $bbox;
        $this->zoom = $zoom;
        $this->app_id = $app_id;
        $this->no_pbf_layer = $no_pbf_layer;
    }

    public function handle(GeometryComputationService $geometryService)
    {
        try {
            $geometryService->clearEmptyTileKeys($this->app_id, $this->zoom);
            // Genera i job figli
            $tiles = $geometryService->generateTiles($this->bbox, $this->zoom, $this->zoomTreshold, $this->app_id);
            $jobs = [];

            foreach ($tiles as $tile) {
                [$x, $y, $z] = $tile;
                if ($z <= $this->zoomTreshold && ! $this->no_pbf_layer) {
                    $jobs[] = new GenerateLayerPBFJob($z, $x, $y, $this->app_id);
                } else {
                    $jobs[] = new GeneratePBFJob($z, $x, $y, $this->app_id);
                }
            }
            // Dispatch del batch
            sleep(8); // Aspetta 5 secondi prima di avviare il batch per via del balanceCooldown
            $batch = Bus::batch($jobs)
                ->name("PBF batch: {$this->app_id}/$this->zoom")
                ->onConnection('redis')->onQueue('pbf')->dispatch();
            sleep(5); // Aspetta 5 secondi prima di avviare il batch per via del balanceCooldown
            if ($batch) {
                //   Log::info("Batch: $batch->name/$this->zoom started");
            } else {
                Log::error("Impossibile avviare il batch per il livello di zoom {$this->zoom}");
            }
        } catch (Throwable $e) {
            Log::error("Errore nel Job PBF per il livello di zoom {$this->zoom}: ".$e->getMessage());
            throw $e;
        }
    }
}
