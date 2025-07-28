<?php

namespace Wm\WmPackage\Jobs\Track;

use Illuminate\Queue\Middleware\WithoutOverlapping;
use Wm\WmPackage\Http\Resources\EcTrackResource;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\StorageService;

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
        return 'upload-ectrack-to-aws-'.$this->ecTrack->id;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(StorageService $cloudStorageService)
    {
        // Ottieni il modello dalla configurazione per essere consistente
        $ecTrackModelClass = config('wm-package.ec_track_model', \Wm\WmPackage\Models\EcTrack::class);

        // Ricarica il modello usando la classe configurata per assicurarsi che sia della classe corretta
        $ecTrack = $ecTrackModelClass::find($this->ecTrack->id);

        if (! $ecTrack) {
            throw new \Exception("EcTrack with ID {$this->ecTrack->id} not found");
        }

        $resource = new EcTrackResource($ecTrack);

        // save on aws
        $cloudStorageService->storeTrack($ecTrack->id, $resource->toJson());
    }
}
