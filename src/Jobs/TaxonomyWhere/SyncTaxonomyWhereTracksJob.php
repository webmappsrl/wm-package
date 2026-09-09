<?php

namespace Wm\WmPackage\Jobs\TaxonomyWhere;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\GeometryComputationService;

class SyncTaxonomyWhereTracksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(): void
    {
        $tracksSynced = GeometryComputationService::make()->syncTracksTaxonomyWhere(
            config('wm-package.ec_track_model', EcTrack::class)
        );

        Log::info('SyncTaxonomyWhereTracksJob completed', ['tracks_synced' => $tracksSynced]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SyncTaxonomyWhereTracksJob failed after all retries', [
            'error' => $e->getMessage(),
        ]);
    }
}
