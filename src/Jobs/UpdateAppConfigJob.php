<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppConfigService;

/**
 * ShouldBeUnique necessario perché uniqueId() sia effettivo: Laravel lo consulta solo
 * con questa interfaccia. Senza, il dispatch dal finally() del batch layer si sommava a
 * quelli di TileObserver/LayerObserver/FeatureCollection*, N ricalcoli concorrenti che
 * scrivono la stessa chiave S3 senza lock. Vedi oc:8488.
 */
class UpdateAppConfigJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $appId
    ) {}

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return "update-app-config-{$this->appId}";
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(): void
    {
        try {
            $app = App::find($this->appId);

            if (! $app) {
                Log::warning('App not found for config update', ['app_id' => $this->appId]);

                return;
            }

            (new AppConfigService($app))->writeAppConfigOnAws();
        } catch (\Exception $e) {
            Log::error('Error updating app config in job', [
                'app_id' => $this->appId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
