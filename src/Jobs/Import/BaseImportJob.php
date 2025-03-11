<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Wm\WmPackage\Services\Import\GeohubImportService;

abstract class BaseImportJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The ID of the entity to import
     */
    protected int $entityId;

    /**
     * The Geohub import service
     */
    protected GeohubImportService $geohubImportService;

    /**
     * Create a new job instance.
     */
    public function __construct(int $entityId)
    {
        $this->entityId = $entityId;
        $this->onQueue('geohub-import');
    }

    /**
     * Execute the job.
     */
    public function handle(GeohubImportService $importService): void
    {
        $this->geohubImportService = $importService;

        $logger = Log::channel(config('wm-geohub-import.import_log_channel', 'wm-package-failed-jobs'));
        $modelName = $this->getModelName();
        $tableName = $this->getTableName();

        try {
            $data = $this->geohubImportService->fetchData($this->entityId, $tableName);
            $transformedData = $this->transformData($data);

            $this->geohubImportService->importData($transformedData, $modelName, $this->entityId);

            $this->processDependencies($transformedData);

            $logger->info("Completed import of {$modelName} with ID {$this->entityId}", [
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            $logger->error("Failed to import {$modelName} with ID {$this->entityId}: {$e->getMessage()}", [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Get the model name for this job.
     */
    abstract protected function getModelName(): string;

    /**
     * Get the table name for this job.
     */
    abstract protected function getTableName(): string;

    /**
     * Get the mapping configuration for this entity type.
     */
    abstract protected function getMapping(): array;

    /**
     * Transform the data.
     */
    abstract protected function transformData(array $data): array;

    /**
     * Process dependencies if needed.
     */
    abstract protected function processDependencies(array $transformedData): void;
}
