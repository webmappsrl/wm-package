<?php

namespace Wm\WmPackage\Jobs\Pbf;

use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\Jobs\Track\BaseEcTrackJob;
use Wm\WmPackage\Services\GeometryComputationService;

class GenerateEcTrackPBFBatch extends BaseEcTrackJob
{
    // Numero massimo di tentativi
    public $tries = 5;

    // Tempo massimo di esecuzione in secondi
    public $timeout = 900; // 10 minuti

    public function handle(GeometryComputationService $geometryComputationService)
    {

        $jobs = [];
        $apps = $this->ecTrack->trackHasApps();
        $bbox = $geometryComputationService->getGeometryModelBbox($this->ecTrack);

        if ($apps) {
            foreach ($apps as $app) {
                $min_zoom = 7;
                $max_zoom = 14;
                $app_id = $app->id;

                for ($zoom = $min_zoom; $zoom <= $max_zoom; $zoom++) {
                    $tiles = $geometryComputationService->generateTiles($bbox, $zoom);
                    foreach ($tiles as $tile) {
                        [$x, $y, $z] = $tile;
                        $jobs[] = new GeneratePBFJob($z, $x, $y, $app_id);
                    }
                }
            }
        }
        // Dispatch del batch
        Bus::batch($jobs)
            ->name("Track PBF batch: {$this->ecTrack->id}")
            ->onConnection('redis')->onQueue('pbf')->dispatch();
    }
}
