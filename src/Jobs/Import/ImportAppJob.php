<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;

class ImportAppJob extends BaseImportJob
{
    protected function getModelName(): string
    {
        return 'Wm\\WmPackage\\Models\\App';
    }

    protected function getTableName(): string
    {
        return 'apps';
    }

    protected function getMapping(): array
    {
        return config('wm-geohub-import.import_mapping.app');
    }

    protected function transformData(array $data): array
    {
        // make a diff between data keys and apps columns in db
        $diff = array_diff(array_keys($data), Schema::getColumnListing('apps'));
        $transformedData = array_diff_key($data, array_flip($diff));

        // we need to check if the user related exists in db. If not, we need to create it.
        $user = $this->geohubImportService->checkUserExistence($transformedData['user_id']);
        $transformedData['user_id'] = $user->id;

        return $transformedData;
    }

    protected function processDependencies(array $data): void
    {
        $logger = Log::channel('wm-package-failed-jobs');
        $logger->info("Processing dependencies for app with ID {$this->entityId}");

        // Get user associated with the app
        $userId = $data['user_id'] ?? null;

        if ($userId) {
            $logger->info("Found user with ID {$userId} associated with app {$this->entityId}");
        }

        // Queue imports for associated entities
        // $this->queueEntityImport('layer', null, $this->entityId);
        // $this->queueEntityImport('ec_poi', $userId, $this->entityId);
        // $this->queueEntityImport('ec_track', $userId, $this->entityId);
        // $this->queueEntityImport('ec_media', $userId, $this->entityId);
    }

    /**
     * Queue imports for entities associated with this app.
     */
    protected function queueEntityImport(string $entityType, ?int $userId, int $appId): void
    {
        $logger = Log::channel('wm-package-failed-jobs');

        try {
            $tableName = str_replace('_', '_', $entityType);

            if ($tableName != 'ec_media') {
                $tableName = $tableName.'s';
            }

            $table = DB::connection(self::GEOHUB_CONNECTION)->table($tableName);

            // layer has app_id relation while ec_poi, ec_track and ec_media have user_id relation
            if ($entityType == 'layer') {
                $ids = $table->where('app_id', $appId)->pluck('id')->toArray();
            } else {
                if (! $userId) {
                    $logger->error("No user id found for app {$appId}");
                    throw new \Exception("No user id found for app {$appId}");
                }

                $ids = $table->where('user_id', $userId)->pluck('id')->toArray();
            }

            if (count($ids) > 0) {
                $logger->info('Found '.count($ids)." {$entityType}s");

                // Get the job class for this entity type
                $jobClass = config('wm-geohub-import.import_models.'.$entityType.'.job');

                // Queue jobs for each entity
                $jobs = [];
                foreach ($ids as $id) {
                    $jobs[] = new $jobClass($id, self::GEOHUB_CONNECTION, $appId);
                }

                // create a batch and add the jobs to it
                $batch = Bus::batch($jobs)->name("app-dependencies-{$entityType}-import-batch")->onQueue(config('wm-package.import_queue', 'geohub-import'));
                $batch->dispatch();
            } else {
                $logger->info("No {$entityType}s found for app {$appId}");
            }
        } catch (\Exception $e) {
            $logger->error("Error queuing {$entityType} imports for app {$appId}: ".$e->getMessage());
            throw $e;
        }
    }
}
