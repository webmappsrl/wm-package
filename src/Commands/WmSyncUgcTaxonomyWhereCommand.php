<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;

class WmSyncUgcTaxonomyWhereCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'wm:sync-ugc-taxonomy-where
                            {--type=all : Tipo UGC: all, poi, track}
                            {--app-id= : Limita ai record con questo app_id}
                            {--queue=geometric-computations : Coda su cui accodare i job}
                            {--chunk=500 : Dimensione chunk per la query}';

    /**
     * @var string
     */
    protected $description = 'Accoda i job per ricalcolare properties.taxonomy_where (Osmfeatures) su UgcPoi e/o UgcTrack con geometria.';

    public function handle(): int
    {
        $type = strtolower((string) $this->option('type'));
        if (! in_array($type, ['all', 'poi', 'track', 'pois', 'tracks'], true)) {
            $this->error('Opzione --type non valida: usare all, poi o track.');

            return self::FAILURE;
        }

        $includePoi = in_array($type, ['all', 'poi', 'pois'], true);
        $includeTrack = in_array($type, ['all', 'track', 'tracks'], true);

        $queue = (string) $this->option('queue');
        $chunk = max(1, (int) $this->option('chunk'));
        $appId = $this->option('app-id');

        $poiQuery = UgcPoi::query()->whereNotNull('geometry');
        $trackQuery = UgcTrack::query()->whereNotNull('geometry');

        if ($appId !== null && $appId !== '') {
            $poiQuery->where('app_id', $appId);
            $trackQuery->where('app_id', $appId);
        }

        $poiCount = $includePoi ? (clone $poiQuery)->count() : 0;
        $trackCount = $includeTrack ? (clone $trackQuery)->count() : 0;
        $total = $poiCount + $trackCount;

        if ($total === 0) {
            $this->warn('Nessun record UGC con geometria da elaborare.');

            return self::SUCCESS;
        }

        $this->info("Record da accodare: {$total} (UgcPoi: {$poiCount}, UgcTrack: {$trackCount}). Coda: {$queue}");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $dispatch = function ($model) use ($queue, $bar): void {
            $pending = new UpdateModelWithGeometryTaxonomyWhere($model);
            if ($queue !== '') {
                dispatch($pending)->onQueue($queue);
            } else {
                dispatch($pending);
            }
            $bar->advance();
        };

        if ($includePoi) {
            $poiQuery->orderBy('id')->chunkById($chunk, function ($rows) use ($dispatch): void {
                foreach ($rows as $poi) {
                    $dispatch($poi);
                }
            });
        }

        if ($includeTrack) {
            $trackQuery->orderBy('id')->chunkById($chunk, function ($rows) use ($dispatch): void {
                foreach ($rows as $track) {
                    $dispatch($track);
                }
            });
        }

        $bar->finish();
        $this->newLine();
        $this->info("Completato: {$total} job accodati.");

        return self::SUCCESS;
    }
}
