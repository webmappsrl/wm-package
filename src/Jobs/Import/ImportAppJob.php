<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
        // foreach ($this->getRelations() as $modelKey => $relationData) {
        //     $this->queueEntityImport($modelKey, $userId, $relationData['foreign_key']);
        // }
        // $this->queueEntityImport('ec_poi', $data['user_id'], 'user_id', $model->id); //TODO: da riabilitare a chiusura feature
        // $this->queueEntityImport('ec_track', $data['user_id'], 'user_id', $model->id);//TODO: da riabilitare a chiusura feature
        $this->queueEntityImport('taxonomy_activity', $data['user_id'], 'user_id', $model->id);
        //  $this->queueEntityImport('layer', $data['user_id'], 'app_id', $model->id);
        // $this->queueEntityImport('ec_media', $data['user_id'], 'user_id', $model->id);//TODO: da riabilitare a chiusura feature
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
                case strpos($entityModelKey, 'taxonomy') !== false: // import all taxonomy entities
                    $whereCondition = null;
                    break;
                default:
                    $whereCondition = [$entityForeignKey => $userId];
                    $data = ['app_id' => $appId];
                    break;
            }
            $ids = $this->geohubImportService->getGeohubIdsToImport($entityModelKey, $whereCondition);

            if (count($ids) > 0) {
                $jobs = [];
                foreach ($ids as $id) {
                    $jobs[] = $this->geohubImportService->createJob($entityModelKey, $id, $data);
                }
                // create a batch and add the jobs to it
                $batch = Bus::batch($jobs)->name("app-dependencies-{$entityModelKey}-import-batch")->onQueue(config('wm-geohub-import.queue.queue', 'geohub-import'));
                $batch->dispatch();
            }
        } catch (\Exception $e) {
            $logger->error("Error queuing {$entityModelKey} imports for app {$this->entityId}: ".$e->getMessage());
            throw $e;
        }
    }
}
