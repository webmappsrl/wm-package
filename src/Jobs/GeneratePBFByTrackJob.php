<?php

namespace Wm\WmPackage\Jobs;

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

class GeneratePBFByTrackJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Numero massimo di tentativi
    public $tries = 5;

    // Tempo massimo di esecuzione in secondi
    public $timeout = 900; // 10 minuti

    protected $track;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($track)
    {
        $this->track = $track;
    }

    public function handle(GeometryComputationService $geometryComputationService)
    {
        try {
            $jobs = [];
            $apps = $this->track->trackHasApps();
            $bbox = $geometryComputationService->getGeometryModelBbox($this->track);
            $author_id = $this->track->user->id;
            if ($apps) {
                foreach ($apps as $app) {
                    $min_zoom = 7;
                    $max_zoom = 14;
                    $app_id = $app->id;

                    for ($zoom = $min_zoom; $zoom <= $max_zoom; $zoom++) {
                        $tiles = $geometryComputationService->generateTiles($bbox, $zoom);
                        foreach ($tiles as $tile) {
                            [$x, $y, $z] = $tile;
                            $jobs[] = new TrackPBFJob($z, $x, $y, $app_id, $author_id);
                        }
                    }
                }
            }
            // Dispatch del batch
            Bus::batch($jobs)
                ->name("Track PBF batch: {$this->track->id}")
                ->onConnection('redis')->onQueue('pbf')->dispatch();
        } catch (Throwable $e) {
            Log::channel('pbf')->error('Errore nel Job track PBF: '.$e->getMessage());
        }
    }
}
