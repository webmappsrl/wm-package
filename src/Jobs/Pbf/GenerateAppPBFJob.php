<?php

namespace Wm\WmPackage\Jobs\Pbf;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Services\GeometryComputationService;

class GenerateAppPBFJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(protected $apps, protected $bbox) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(GeometryComputationService $geometryComputationService)
    {
        if ($this->apps) {
            foreach ($this->apps as $app) {
                $app_id = $app->id;

                $min_zoom = 5;
                $max_zoom = 9;
                // $min_zoom = $app->map_min_zoom;
                // $max_zoom = $app->map_max_zoom;

                // Iterazione attraverso i livelli di zoom
                for ($zoom = $min_zoom; $zoom <= $max_zoom; $zoom++) {
                    $tiles = $geometryComputationService->generateTiles($this->bbox, $zoom);
                    foreach ($tiles as $c => $tile) {
                        [$x, $y, $z] = $tile;
                        if ($z <= 6) {
                            GenerateLayerPBFJob::dispatch($z, $x, $y, $app_id)->onQueue('layer_pbf');
                        } else {
                            GeneratePBFJob::dispatch($z, $x, $y, $app_id);
                        }
                        //Log::info($zoom . ' ' . ++$c . '/' . count($tiles));
                    }
                }
            }
        }
    }
}
