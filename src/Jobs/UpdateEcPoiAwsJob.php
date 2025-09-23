<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\StorageService;

class UpdateEcPoiAwsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(protected EcPoi $ecPoi)
    {
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
        // Utilizza un identificatore unico per ogni POI
        return 'upload-ecpoi-to-aws-'.$this->ecPoi->id;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(StorageService $cloudStorageService)
    {
        // Ottieni il modello dalla configurazione per essere consistente
        $ecPoiModelClass = config('wm-package.ec_poi_model', EcPoi::class);

        // Ricarica il modello usando la classe configurata per assicurarsi che sia della classe corretta
        $ecPoi = $ecPoiModelClass::find($this->ecPoi->id);

        if (! $ecPoi) {
            throw new \Exception("EcPoi with ID {$this->ecPoi->id} not found");
        }

        // Recupera l'App associata all'EcPoi
        $app = $ecPoi->app;

        if (! $app) {
            throw new \Exception("App not found for EcPoi with ID {$ecPoi->id}");
        }

        // Genera il GeoJSON di tutti i POI dell'app e salvalo su AWS
        $app->BuildPoisGeojson();
    }
}
