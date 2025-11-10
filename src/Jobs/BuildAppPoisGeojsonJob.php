<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\App;

/**
 * Job per generare il file pois.geojson per una specifica app.
 *
 * Questo job implementa ShouldBeUnique per prevenire l'esecuzione di job duplicati
 * per la stessa app durante il periodo di uniqueFor (30 minuti).
 */
class BuildAppPoisGeojsonJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * The maximum number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 1700;

    /**
     * The number of seconds after which the job's unique lock will be released.
     * This prevents duplicate jobs from being dispatched for the same app.
     *
     * @var int
     */
    public $uniqueFor = 1800; // 30 minuti

    /**
     * Create a new job instance.
     */
    public function __construct(protected int $appId) {}

    /**
     * Get the unique ID for the job.
     * Questo previene l'esecuzione di job duplicati per la stessa app.
     */
    public function uniqueId(): string
    {
        return 'build_pois_geojson_'.$this->appId;
    }

    /**
     * Get the cache store that should be used to acquire the job lock.
     * Uses Redis for better performance and reliability.
     *
     * @return \Illuminate\Contracts\Cache\Store
     */
    public function uniqueVia()
    {
        return Cache::store('redis');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("Iniziando generazione pois.geojson per App ID: {$this->appId}");

        // Trova l'app
        $app = App::find($this->appId);
        if (! $app) {
            Log::error("App con ID {$this->appId} non trovata!");

            return;
        }

        Log::info("App trovata: {$app->name}");

        try {
            // Genera il geojson usando il metodo dell'App
            $geojson = $app->BuildPoisGeojson();

            Log::info("✅ File pois.geojson generato con successo per App {$this->appId}");
            Log::info('📊 Features generate: '.count($geojson['features']));
        } catch (\Exception $e) {
            Log::error("Errore durante la generazione del pois.geojson per App {$this->appId}: ".$e->getMessage());
            throw $e; // Rilancia l'eccezione per far fallire il job
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Job BuildAppPoisGeojsonJob fallito per App ID {$this->appId}: ".$exception->getMessage());
    }
}
