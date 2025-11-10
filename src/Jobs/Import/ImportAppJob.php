<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Wm\WmPackage\Jobs\UpdateAppConfigHomeLayerIdsJob;

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
    }

    /**
     * Get the list of allowed dependencies
     */
    protected function getAllowedDependencies(): array
    {
        // All available dependencies
        $allDependencies = ['taxonomy_activity', 'taxonomy_poi_types', 'ec_poi', 'ec_track',  'layer', 'ec_media'];

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
