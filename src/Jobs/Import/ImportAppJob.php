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
        // Get the list of allowed dependencies from configuration or job data
        $allowedDependencies = $this->getAllowedDependencies();

        // Collect all batch jobs to be executed sequentially
        $batchJobs = [];

        // Import only allowed dependencies


        if (in_array('taxonomy_activity', $allowedDependencies)) {
            $batchJobs[] = $this->createBatchJob('taxonomy_activity', $data['user_id'], 'user_id', $model->id);
        }

        if (in_array('taxonomy_poi_types', $allowedDependencies)) {
            $batchJobs[] = $this->createBatchJob('taxonomy_poi_types', $data['user_id'], 'user_id', $model->id);
        }

        if (in_array('ec_poi', $allowedDependencies)) {
            $batchJobs[] = $this->createBatchJob('ec_poi', $data['user_id'], 'user_id', $model->id);
        }

        if (in_array('ec_track', $allowedDependencies)) {
            $batchJobs[] = $this->createBatchJob('ec_track', $data['user_id'], 'user_id', $model->id);
        }

        if (in_array('layer', $allowedDependencies)) {
            $batchJobs[] = $this->createBatchJob('layer', $data['user_id'], 'app_id', $model->id);
        }

        if (in_array('ec_media', $allowedDependencies)) {
            $batchJobs[] = $this->createBatchJob('ec_media', $data['user_id'], 'user_id', $model->id);
        }

        // Filter out null batches and execute all batches sequentially with resilience
        $validBatchJobs = array_filter($batchJobs, function($batch) {
            return $batch !== null;
        });
        
        if (!empty($validBatchJobs)) {
            $this->executeSequentialBatches($validBatchJobs);
        }
    }

    /**
     * Get the list of allowed dependencies
     */
    protected function getAllowedDependencies(): array
    {
        // All available dependencies
        $allDependencies = ['taxonomy_activity', 'taxonomy_poi_types','ec_poi', 'ec_track',  'layer', 'ec_media'];

        // First check if allowed_dependencies is passed in job data
        if (isset($this->data['allowed_dependencies']) && is_array($this->data['allowed_dependencies'])) {
            return $this->data['allowed_dependencies'];
        }

        // Fallback to configuration
        $configDependencies = config('wm-geohub-import.default_dependencies.app', $allDependencies);

        return is_array($configDependencies) ? $configDependencies : $allDependencies;
    }

    /**
     * Execute batches sequentially with resilience - if one fails, continue with the next.
     */
    protected function executeSequentialBatches(array $batches): void
    {
        $logger = Log::channel('wm-package-failed-jobs');
        
        foreach ($batches as $index => $batch) {
            try {
                $logger->info("Starting sequential batch " . ($index + 1) . " of " . count($batches));
                
                // Dispatch the batch and get the batch instance
                $dispatchedBatch = $batch->dispatch();
                
                // Wait for the batch to complete
                $this->waitForBatchCompletion($dispatchedBatch);
                
                $logger->info("Completed sequential batch " . ($index + 1) . " of " . count($batches));
            } catch (\Exception $e) {
                $logger->error("Sequential batch " . ($index + 1) . " failed, continuing with next batch: " . $e->getMessage());
                // Continue with the next batch even if this one failed
            }
        }
    }

    /**
     * Wait for a batch to complete by polling its status.
     */
    protected function waitForBatchCompletion(\Illuminate\Bus\Batch $batch): void
    {
        $maxWaitTime = 300; // 5 minutes max wait
        $pollInterval = 5; // Check every 5 seconds
        $elapsed = 0;
        
        while ($elapsed < $maxWaitTime) {
            $batchStatus = $batch->fresh();
            
            if ($batchStatus && $batchStatus->finished()) {
                return;
            }
            
            sleep($pollInterval);
            $elapsed += $pollInterval;
        }
        
        Log::warning("Batch did not complete within {$maxWaitTime} seconds, continuing with next batch");
    }

    /**
     * Create a batch job for entities associated with this app.
     * Returns a batch that can be chained with other batches.
     */
    protected function createBatchJob(string $entityModelKey, ?int $userId, string $entityForeignKey, int $appId): ?\Illuminate\Bus\PendingBatch
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
                // create a batch and return it (don't dispatch yet)
                return Bus::batch($jobs)->name("app-dependencies-{$entityModelKey}-import-batch")->onQueue(config('wm-geohub-import.queue.queue', 'geohub-import'));
            }
            
            return null;
        } catch (\Exception $e) {
            $logger->error("Error creating batch for {$entityModelKey} imports for app {$this->entityId}: ".$e->getMessage());
            throw $e;
        }
    }
}
