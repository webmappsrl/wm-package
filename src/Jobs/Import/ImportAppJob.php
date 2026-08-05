<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Wm\WmPackage\Jobs\UpdateAppConfigHomeLayerIdsJob;
use Wm\WmPackage\Services\Import\GeohubImportService;

class ImportAppJob extends BaseImportJob
{
    protected function getModelKey(): string
    {
        return 'app';
    }

    protected function transformData(array $data): array
    {

        // make a diff between data keys and apps columns in database
        $diff = array_diff(array_keys($data), Schema::getColumnListing('apps'));
        $transformedData = array_diff_key($data, array_flip($diff));

        // add geohub_id and geohub_synced_at to the transformed data
        $transformedData['properties']['geohub_id'] = $data['id'];
        $transformedData['properties']['geohub_synced_at'] = now();

        // we need to check if the user related exists in db. If not, we need to create it.
        $user = $this->geohubImportService->checkUserExistence($transformedData['user_id']);
        $transformedData['user_id'] = $user->id;
        unset($transformedData['id']);

        return $transformedData;
    }

    protected function processDependencies(array $data, Model $model): void
    {
        // Get the list of allowed dependencies from configuration or job data
        $allowedDependencies = $this->getAllowedDependencies();

        // foreach ($this->getRelations() as $modelKey => $relationData) {
        //     $this->queueEntityImport($modelKey, $userId, $relationData['foreign_key']);
        // }

        // Import only allowed dependencies
        if (in_array('taxonomy_activity', $allowedDependencies)) {
            $this->queueEntityImport('taxonomy_activity', $data['user_id'], 'user_id', $model->id);
        }

        if (in_array('taxonomy_poi_types', $allowedDependencies)) {
            $this->queueEntityImport('taxonomy_poi_types', $data['user_id'], 'user_id', $model->id);
        }

        if (in_array('taxonomy_theme', $allowedDependencies)) {
            $this->queueEntityImport('taxonomy_theme', $data['user_id'], 'user_id', $model->id);
        }

        if (in_array('ec_poi', $allowedDependencies)) {
            $this->queueEntityImport('ec_poi', $data['user_id'], 'user_id', $model->id);
        }

        if (in_array('ec_track', $allowedDependencies)) {
            $this->queueEntityImport('ec_track', $data['user_id'], 'user_id', $model->id);
        }

        if (in_array('layer', $allowedDependencies)) {
            $this->queueEntityImport('layer', $data['user_id'], 'app_id', $model->id);
        }

        if (in_array('ec_media', $allowedDependencies)) {
            $this->queueEntityImport('ec_media', $data['user_id'], 'user_id', $model->id);
        }

        $this->queueUgcDependencies($allowedDependencies, $model->id);
    }

    /**
     * ugc_poi/ugc_track/ugc_media are opt-in (see wm-geohub-import.php) and filtered by
     * Geohub app_id, not user_id — unlike EC content, UGC authors are end users, not the
     * app owner. Media is dispatched only after the poi/track batch finishes, since syncing
     * its pivots requires their local rows to already exist.
     */
    protected function queueUgcDependencies(array $allowedDependencies, int $localAppId): void
    {
        $wantsPoi = in_array('ugc_poi', $allowedDependencies);
        $wantsTrack = in_array('ugc_track', $allowedDependencies);
        $wantsMedia = in_array('ugc_media', $allowedDependencies);

        if (! $wantsPoi && ! $wantsTrack && ! $wantsMedia) {
            return;
        }

        $whereCondition = ['app_id' => (string) $this->entityId];
        $data = ['app_id' => $localAppId];
        $queue = config('wm-geohub-import.queue.queue', 'geohub-import');

        $jobs = [];

        if ($wantsPoi) {
            foreach ($this->geohubImportService->getGeohubIdsToImport('ugc_poi', $whereCondition) as $id) {
                $jobs[] = $this->geohubImportService->createJob('ugc_poi', $id, $data);
            }
        }

        if ($wantsTrack) {
            foreach ($this->geohubImportService->getGeohubIdsToImport('ugc_track', $whereCondition) as $id) {
                $jobs[] = $this->geohubImportService->createJob('ugc_track', $id, $data);
            }
        }

        if (count($jobs) > 0) {
            // The `then()` callback is serialized into job_batches.options at dispatch time
            // (batches always persist through a repository, even on the sync queue) — it must
            // stay `static` and only close over plain arrays/scalars. Closing over $this would
            // drag in $this->geohubImportService, which holds a live DB connection/PDO handle
            // that PHP cannot serialize, crashing every dispatch that has poi/track jobs.
            Bus::batch($jobs)
                ->name('app-dependencies-ugc_poi_track-import-batch')
                ->onQueue($queue)
                ->allowFailures()
                ->then(static function () use ($wantsMedia, $whereCondition, $data, $queue) {
                    if ($wantsMedia) {
                        self::dispatchUgcMediaBatch($whereCondition, $data, $queue);
                    }
                })
                ->dispatch();
        } elseif ($wantsMedia) {
            // Nothing to wait for (poi/track not requested, or none found) — dispatch media right away.
            self::dispatchUgcMediaBatch($whereCondition, $data, $queue);
        }
    }

    /**
     * Static so it never closes over a job instance — always resolves a fresh
     * GeohubImportService, since this can run from inside a serialized batch callback.
     */
    protected static function dispatchUgcMediaBatch(array $whereCondition, array $data, string $queue): void
    {
        $geohubImportService = app(GeohubImportService::class);

        $mediaJobs = [];
        foreach ($geohubImportService->getGeohubIdsToImport('ugc_media', $whereCondition) as $id) {
            $mediaJobs[] = $geohubImportService->createJob('ugc_media', $id, $data);
        }

        if (count($mediaJobs) > 0) {
            Bus::batch($mediaJobs)
                ->name('app-dependencies-ugc_media-import-batch')
                ->onQueue($queue)
                ->allowFailures()
                ->dispatch();
        }
    }

    /**
     * Get the list of allowed dependencies
     */
    protected function getAllowedDependencies(): array
    {
        // All available dependencies
        $allDependencies = ['taxonomy_activity', 'taxonomy_poi_types', 'taxonomy_theme', 'ec_poi', 'ec_track', 'layer', 'ec_media'];

        // First check if allowed_dependencies is passed in job data
        if (isset($this->data['allowed_dependencies']) && is_array($this->data['allowed_dependencies'])) {
            return $this->data['allowed_dependencies'];
        }

        // Fallback to configuration
        $configDependencies = config('wm-geohub-import.default_dependencies.app', $allDependencies);

        return is_array($configDependencies) ? $configDependencies : $allDependencies;
    }

    /**
     * Queue imports for entities associated with this app.
     */
    protected function queueEntityImport(string $entityModelKey, ?int $userId, string $entityForeignKey, int $appId): void
    {
        $logger = Log::channel('wm-package-failed-jobs');

        try {
            $whereCondition = null;
            $data = [];

            switch ($entityModelKey) {
                case 'layer':
                    $whereCondition = [$entityForeignKey => $this->entityId];
                    $data = ['app_id' => $appId];
                    break;
                case 'ec_media':
                    // Per ec_media, importiamo i media associati ai track dell'app, non solo quelli dell'utente
                    $whereCondition = null; // Gestiremo i media tramite relazioni
                    $data = ['app_id' => $appId, 'app_user_id' => $userId];
                    break;
                case strpos($entityModelKey, 'taxonomy') !== false: // import all taxonomy entities
                    $whereCondition = null;
                    break;
                default:
                    $whereCondition = [$entityForeignKey => $userId];
                    $data = ['app_id' => $appId];
                    break;
            }
            $ids = $this->geohubImportService->getGeohubIdsToImport($entityModelKey, $whereCondition, $data);

            if (count($ids) > 0) {
                $jobs = [];
                foreach ($ids as $id) {
                    $jobs[] = $this->geohubImportService->createJob($entityModelKey, $id, $data);
                }
                // create a batch and add the jobs to it
                $batch = Bus::batch($jobs)->name("app-dependencies-{$entityModelKey}-import-batch")->onQueue(config('wm-geohub-import.queue.queue', 'geohub-import'));
                $batch->dispatch();

                // Dopo aver dispatchato tutti i job dei layer, lancia l'aggiornamento di config_home
                if ($entityModelKey === 'layer') {
                    dispatch((new UpdateAppConfigHomeLayerIdsJob($appId))
                        ->onQueue(config('wm-geohub-import.queue.queue', 'geohub-import')));
                }
            }
        } catch (\Exception $e) {
            $logger->error("Error queuing {$entityModelKey} imports for app {$this->entityId}: ".$e->getMessage());
            throw $e;
        }
    }
}
