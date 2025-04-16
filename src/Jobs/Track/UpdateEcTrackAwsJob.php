<?php

namespace Wm\WmPackage\Jobs\Track;

use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\StorageService;
use Wm\WmPackage\Http\Resources\EcTrackResource;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class UpdateEcTrackAwsJob extends BaseEcTrackJob
{
    public function __construct(protected EcTrack $ecTrack)
    {
        parent::__construct($ecTrack);
        $this->onQueue('aws');
    }

    /**
     * Definisci i middleware per il job.
     *
     * @return array
     */
    public function middleware()
    {
        return [
            // Applica WithoutOverlapping con una chiave unica per limitare la concorrenza
            new WithoutOverlapping($this->getLockKey()),
        ];
    }

    /**
     * Genera una chiave unica per il lock di WithoutOverlapping.
     *
     * @return string
     */
    protected function getLockKey()
    {
        // Utilizza un identificatore unico per ogni tile
        return 'upload-ectrack-to-aws-' . $this->ecTrack->id;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(StorageService $cloudStorageService)
    {

        $resource = new EcTrackResource($this->ecTrack);

        // save on aws
        $cloudStorageService->storeTrack($this->ecTrack->id, $resource->toJson());
    }
}
