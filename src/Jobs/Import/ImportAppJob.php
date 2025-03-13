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
        // make a diff between data keys and apps columns in db
        $diff = array_diff(array_keys($data), Schema::getColumnListing('apps'));
        $transformedData = array_diff_key($data, array_flip($diff));

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
        $this->queueEntityImport('ec_poi', $data['user_id'], 'user_id', $model->id);
        $this->queueEntityImport('ec_track', $data['user_id'], 'user_id', $model->id);
    }

    /**
     * Queue imports for entities associated with this app.
     */
    protected function queueEntityImport(string $entityModelKey, ?int $userId, string $entityForeignKey, int $appId): void
    {
        $logger = Log::channel('wm-package-failed-jobs');

        try {
            if ($entityModelKey == 'layer') {
                $ids = $this->geohubImportService->getGeohubIdsToImport($entityModelKey, [$entityForeignKey => $this->entityId]);
            } else {
                if (! $userId) {
                    $logger->error("No user id found for app {$this->entityId}");
                    throw new \Exception("No user id found for app {$this->entityId}");
                }
                $ids = $this->geohubImportService->getGeohubIdsToImport($entityModelKey, [$entityForeignKey => $userId]);
            }

            if (count($ids) > 0) {
                $jobs = [];
                foreach ($ids as $id) {
                    $jobs[] = $this->geohubImportService->createJob($entityModelKey, $id, ['app_id' => $appId]);
                }
                // create a batch and add the jobs to it
                $batch = Bus::batch($jobs)->name("app-dependencies-{$entityModelKey}-import-batch")->onQueue(config('geohub-import.queue', 'geohub-import'));
                $batch->dispatch();
            }
        } catch (\Exception $e) {
            $logger->error("Error queuing {$entityModelKey} imports for app {$this->entityId}: " . $e->getMessage());
            throw $e;
        }
    }
}
