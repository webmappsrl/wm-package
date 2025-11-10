<?php

namespace Wm\WmPackage;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Wm\WmPackage\Http\Controllers\GlobalFileController;

class GlobalFileServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Registra l'helper
        $this->app->singleton('global-file-helper', function ($app) {
            return new \Wm\WmPackage\Helpers\GlobalFileHelper;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Carica le viste del package
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'wm-package');

        // Pubblica le viste se necessario
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/wm-package'),
        ], 'wm-package-views');

        // Pubblica la configurazione
        $this->publishes([
            __DIR__.'/../config/global-files.php' => config_path('global-files.php'),
        ], 'wm-package-config');

        // Registra le routes del package
        $this->registerRoutes();
    }

    /**
     * Registra le routes del package
     */
    protected function registerRoutes(): void
    {
        $config = config('global-files', require __DIR__.'/../config/global-files.php');
        $middleware = $config['middleware'] ?? ['auth', 'nova'];

        Route::middleware($middleware)->group(function () use ($config) {
            foreach ($config['file_types'] as $fileType => $settings) {
                $routePrefix = $settings['route_prefix'];
                $filename = $settings['filename'];

                // Route per visualizzare l'interfaccia
                Route::get("/{$routePrefix}", [GlobalFileController::class, 'show'])
                    ->name("{$fileType}.upload.show")
                    ->defaults('fileType', $fileType)
                    ->defaults('filename', $filename);

                // Route per caricare il file
                Route::post("/{$routePrefix}", [GlobalFileController::class, 'upload'])
                    ->name("{$fileType}.upload.store")
                    ->defaults('fileType', $fileType)
                    ->defaults('filename', $filename);

                // Route per visualizzare il file
                Route::get("/{$routePrefix}/view/{filename}", [GlobalFileController::class, 'view'])
                    ->name("{$fileType}.upload.view")
                    ->defaults('fileType', $fileType);

                // Route per scaricare il file
                Route::get("/{$routePrefix}/download/{filename}", [GlobalFileController::class, 'download'])
                    ->name("{$fileType}.upload.download")
                    ->defaults('fileType', $fileType);

                // Route per eliminare il file
                Route::delete("/{$routePrefix}/{filename}", [GlobalFileController::class, 'delete'])
                    ->name("{$fileType}.upload.delete")
                    ->defaults('fileType', $fileType);
            }
        });
    }
}
