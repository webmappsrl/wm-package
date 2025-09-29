<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Models\App;

class WmBuildAppPoisGeojsonCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wm:build-pois-geojson {app_id : The ID of the app}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build pois.geojson file for a specific app';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $appId = $this->argument('app_id');

        $this->info("Generando pois.geojson per App ID: {$appId}");

        // Trova l'app
        $app = App::find($appId);
        if (! $app) {
            $this->error("App con ID {$appId} non trovata!");

            return 1;
        }

        $this->info("App trovata: {$app->name}");

        // Genera il geojson usando il metodo dell'App
        BuildAppPoisGeojsonJob::dispatch($app->id);

        $this->info("✅ Job per la generazione del file pois.geojson lanciato con successo per App {$appId}");

        return 0;
    }
}
