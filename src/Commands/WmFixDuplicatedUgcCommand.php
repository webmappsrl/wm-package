<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\Models\UgcDuplicatesService;

class WmFixDuplicatedUgcCommand extends Command
{
    /** Valori di --type e modello corrispondente. */
    private const TYPES = ['tracks' => UgcTrack::class, 'pois' => UgcPoi::class];

    protected $signature = 'wm:fix-duplicated-ugc
                            {--type= : tracks oppure pois; senza, entrambi}
                            {--execute : Applica le modifiche; senza, produce solo il report}
                            {--app-id= : Limita ai record con questo app_id}
                            {--max-distance=1 : Oltre questa distanza in metri fra le geometrie il gruppo non viene toccato}';

    protected $description = 'Unisce gli UGC (tracce e POI) duplicati per properties.uuid nel più vecchio, archiviando le copie (oc:8718).';

    public function handle(UgcDuplicatesService $service): int
    {
        $type = $this->option('type');
        if ($type !== null && ! isset(self::TYPES[$type])) {
            $this->error('--type deve essere tracks oppure pois.');

            return self::FAILURE;
        }
        $types = $type !== null ? [$type => self::TYPES[$type]] : self::TYPES;
        $execute = (bool) $this->option('execute');
        $appId = $this->option('app-id') !== null && $this->option('app-id') !== '' ? (int) $this->option('app-id') : null;
        $maxDistance = (float) $this->option('max-distance');

        $rows = [];
        foreach ($types as $typeName => $modelClass) {
            foreach ($service->groups($modelClass, $appId) as $group) {
                $skip = $group['max_distance_m'] > $maxDistance;
                if ($execute && ! $skip) {
                    $discarded = $service->mergeGroup($modelClass, $group);
                    foreach ($discarded as $media) {
                        // Dopo il commit: cancella riga e file del media doppione.
                        $media->delete();
                    }
                }
                $esito = $skip ? 'da verificare' : ($execute ? 'unito' : 'da unire');
                $rows[] = [$typeName, $group['uuid'], $group['parent_id'], implode(' ', $group['copy_ids']), sprintf('%.2f', $group['max_distance_m']), $esito];
                Log::channel('duplicated-ugc')->info('Gruppo UGC duplicato', [
                    'tipo' => $typeName,
                    'uuid' => $group['uuid'],
                    'padre' => $group['parent_id'],
                    'copie' => $group['copy_ids'],
                    'distanza_max_m' => round($group['max_distance_m'], 2),
                    'esito' => $esito,
                ]);
            }
        }

        Log::channel('duplicated-ugc')->info('wm:fix-duplicated-ugc completato', [
            'modalita' => $execute ? 'execute' : 'report',
            'tipi' => array_keys($types),
            'app_id' => $appId,
            'max_distance_m' => $maxDistance,
            'gruppi' => count($rows),
        ]);
        $this->table(['tipo', 'uuid', 'padre', 'copie', 'distanza max (m)', 'esito'], $rows);
        $this->info(count($rows).' gruppi trovati'.($execute ? '.' : ' (report, nessuna scrittura).'));
        $this->warn($execute
            ? 'Fatto. Le copie sono in ugc_duplicates_archive; il rollback si fa dal backup del DB.'
            : 'Report: prima di --execute fai il backup del DB.');

        return self::SUCCESS;
    }
}
