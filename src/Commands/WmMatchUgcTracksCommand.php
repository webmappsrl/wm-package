<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Jobs\MatchUgcTrackToEcTracksJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

/**
 * Copertura per segmenti delle UgcTrack esistenti (oc:8773): toglie properties.layer_id e
 * layer_id_auto_resolved dalle UgcTrack e accoda il calcolo per quelle mai calcolate, fallite o
 * calcolate con parametri diversi da quelli attuali della loro App. Da lanciare dopo il backup
 * e dopo la pulizia GPS (wm:clean-ugc-track-geometry).
 */
class WmMatchUgcTracksCommand extends Command
{
    protected $signature = 'wm:match-ugc-tracks
        {--dry-run : conta senza scrivere né accodare}
        {--app= : id di una sola App}';

    protected $description = 'Calcola la corrispondenza per segmenti fra le UgcTrack esistenti e le EcTrack e toglie layer_id dalle UgcTrack';

    public function handle(UgcTrackMatchingService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyApp = $this->option('app');
        $appIds = $onlyApp ? [(int) $onlyApp] : App::query()->orderBy('id')->pluck('id')->all();

        // jsonb_exists al posto dell'operatore `?`, che PDO interpreterebbe come segnaposto.
        $withLayerId = fn () => DB::table('ugc_tracks')
            ->when($onlyApp, fn ($q) => $q->where('app_id', (int) $onlyApp))
            ->whereRaw("(jsonb_exists(properties, 'layer_id') OR jsonb_exists(properties, 'layer_id_auto_resolved'))");
        $layerIdCount = $withLayerId()->count();

        $pending = 0;
        foreach ($appIds as $appId) {
            $pending += $service->unmatchedUgcTrackIdsQuery((int) $appId)->count();
        }

        $this->info("{$pending} tracce da calcolare");
        $this->info("{$layerIdCount} tracce con layer_id da togliere");

        if ($dryRun) {
            return self::SUCCESS;
        }

        $withLayerId()->update(['properties' => DB::raw("properties - 'layer_id' - 'layer_id_auto_resolved'")]);

        foreach ($appIds as $appId) {
            // Keyset pagination: l'insieme delle tracce da calcolare si restringe man mano che i job girano.
            $service->unmatchedUgcTrackIdsQuery((int) $appId)->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    MatchUgcTrackToEcTracksJob::dispatch((int) $row->id);
                }
            }, 'g.id', 'id');
        }

        return self::SUCCESS;
    }
}
