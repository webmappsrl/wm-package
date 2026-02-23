<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\AppIconsService;
use Wm\WmPackage\Services\StorageService;

class WmGenerateIconsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wm:generate-icons {app_id? : The ID of the app to generate icons for} {--all : Generate icons for all apps}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate icons.json file for a specific app or all apps using the same logic as TaxonomyPoiTypeablesObserver';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(AppIconsService $appIconsService, StorageService $storageService)
    {
        $appId = $this->argument('app_id');
        $allApps = $this->option('all');

        if ($appId && $allApps) {
            $this->error('Cannot use both app_id and --all options together.');

            return 1;
        }

        if ($allApps) {
            $apps = App::all();
            $this->info('Generando icons.json per tutte le app...');
            $bar = $this->output->createProgressBar($apps->count());
            $bar->start();

            foreach ($apps as $app) {
                $this->generateIconsForApp($app, $appIconsService, $storageService);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
            $this->output->success('Generazione icons.json completata per tutte le app!');
        } elseif ($appId) {
            $app = App::find($appId);
            if (! $app) {
                $this->error("App con ID {$appId} non trovata!");

                return 1;
            }
            $this->generateIconsForApp($app, $appIconsService, $storageService);
            $this->output->success("✅ File icons.json generato con successo per App {$appId}!");
        } else {
            $this->error('Please specify an app_id or use the --all option.');

            return 1;
        }

        return 0;
    }

    /**
     * Generate icons.json for a specific app
     *
     * @param  App  $app
     * @param  AppIconsService  $appIconsService
     * @param  StorageService  $storageService
     * @return void
     */
    private function generateIconsForApp(App $app, AppIconsService $appIconsService, StorageService $storageService)
    {
        $this->info("Generando icons.json per App ID: {$app->id}...");
        $this->info("App trovata: {$app->name}");

        try {
            // Usa la stessa logica dell'observer TaxonomyPoiTypeablesObserver (riga 39)
            $icons = $appIconsService->writeIconsOnAws($app->id);
            $path = $storageService->getShardBasePath($app->id).'icons.json';
            $this->info("📊 Numero di icone generate: ".count($icons));
            $this->info("📁 Percorso: {$path}");
        } catch (\Exception $e) {
            $this->error("Errore durante la generazione del icons.json per App {$app->id}: ".$e->getMessage());
            Log::error("WmGenerateIconsCommand: Error for App ID {$app->id}: ".$e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
