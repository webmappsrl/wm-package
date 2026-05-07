<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Http\Clients\DemClient;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\GeometryComputationService;

class UpdateEcPoiDemJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(protected EcPoi $ecPoi)
    {
        $this->onQueue('dem');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(DemClient $demClient, GeometryComputationService $geometryComputationService)
    {
        $coordinates = $geometryComputationService->getGeometryModelCoordinates($this->ecPoi);
        $properties = $this->ecPoi->properties;
        $properties['ele'] = $demClient->getElevation($coordinates->x, $coordinates->y);
        $this->ecPoi->properties = $properties;
        $this->ecPoi->saveQuietly();
    }
}
