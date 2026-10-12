<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Jobs\CleanUgcTrackGeometryJob;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\Models\UgcTrackCleanupService;
use Wm\WmPackage\Services\Models\UgcTrackStatsService;

class WmCleanUgcTrackGeometryCommand extends Command
{
    protected $signature = 'wm:clean-ugc-track-geometry
                            {--dry-run : Elenca le tracce che cambierebbero, senza scrivere nulla}
                            {--app-id= : Limita ai record con questo app_id}
                            {--queue=default : Coda su cui accodare i job}';

    protected $description = 'Ricostruisce la geometria delle UgcTrack da properties.locations e ne calcola properties.stats (oc:8719, oc:8742).';

    public function handle(UgcTrackCleanupService $cleanup, UgcTrackStatsService $statsService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $queue = (string) $this->option('queue');
        $appId = $this->option('app-id');

        $query = UgcTrack::query()->whereRaw("jsonb_typeof(properties::jsonb->'locations') = 'array'");
        if ($appId !== null && $appId !== '') {
            $query->where('app_id', $appId);
        }

        $rows = [];
        $total = 0;
        $withStats = 0;
        $query->chunkById(200, function ($tracks) use ($cleanup, $statsService, $dryRun, $queue, &$rows, &$total, &$withStats) {
            foreach ($tracks as $track) {
                if ($cleanup->wouldChange($track)) {
                    $summary = $cleanup->summary($cleanup->locationsOf($track) ?? []);
                    $rows[] = [
                        $track->id,
                        $summary['discarded'].'/'.$summary['total'],
                        // «Prima» è la geometria salvata, non i locations: le tracce col punto (0,0)
                        // solo nella geometria devono mostrare la differenza reale.
                        sprintf('%.1f', $this->storedLengthKm($track)),
                        sprintf('%.1f', $summary['length_after_km']),
                    ];
                }
                $total++;

                // Solo nel dry-run: le tracce con meno di 2 punti tenuti non ricevono stats (il job lo toglie).
                if ($dryRun && $statsService->localStats($cleanup->locationsOf($track) ?? []) !== null) {
                    $withStats++;
                }

                if (! $dryRun) {
                    CleanUgcTrackGeometryJob::dispatch($track->id)->onQueue($queue);
                }
            }
        });

        $this->table(['id', 'punti scartati/totale', 'km prima', 'km dopo'], $rows);
        $this->info($dryRun
            ? count($rows).' tracce cambierebbero geometria; '.$withStats.' riceverebbero stats, '
                .($total - $withStats).' con meno di 2 punti tenuti resterebbero senza (dry-run, nessuna scrittura).'
            : $total.' job accodati sulla coda '.$queue.' ('.count($rows).' con geometria da ripulire).');
        $this->warn('Prima del run reale rivedi l\'elenco del dry-run su ogni progetto.');

        return self::SUCCESS;
    }

    private function storedLengthKm(UgcTrack $track): float
    {
        $row = DB::selectOne(
            "SELECT COALESCE(ST_Length(ST_Force2D(geometry::geometry)::geography), 0) / 1000 AS km FROM {$track->getTable()} WHERE id = ?",
            [$track->id]
        );

        return (float) ($row->km ?? 0);
    }
}
