<?php

namespace Wm\WmPackage\Jobs\TaxonomyWhere;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\GeometryComputationService;

class SyncTaxonomyWhereJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(): void
    {
        $service = GeometryComputationService::make();

        $tracksSynced = $service->syncTaxonomyWhere(
            config('wm-package.ec_track_model', EcTrack::class)
        );
        $poisSynced = $service->syncTaxonomyWhere(
            config('wm-package.ec_poi_model', EcPoi::class)
        );

        Log::info('SyncTaxonomyWhereJob completed', [
            'tracks_synced' => $tracksSynced,
            'pois_synced' => $poisSynced,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SyncTaxonomyWhereJob failed after all retries', [
            'error' => $e->getMessage(),
        ]);
    }
}
