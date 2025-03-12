<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Database\Eloquent\Model;
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

    protected array $data;

    /**
     * The Geohub import service
     */
    protected GeohubImportService $geohubImportService;

    /**
     * Create a new job instance.
     */
    public function __construct(int $entityId, array $data = [])
    {
        $this->entityId = $entityId;
        $this->data = $data;
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

        try {
            $data = $this->geohubImportService->fetchData($this->entityId, $this->getTableName());
            $transformedData = $this->transformData($data);

            $model = $this->geohubImportService->importData($transformedData, $modelName, $this->entityId);

            //using $data instead of $transformedData for referenced keys in geohub database
            $this->processDependencies($data, $model);

            $logger->info("Completed import of {$modelName} with ID {$this->entityId}");
        } catch (\Exception $e) {
            $logger->error("Failed to import {$modelName} with ID {$this->entityId}: {$e->getMessage()}", [
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Get the model name for this job.
     */
    protected function getModelName(): string
    {
        return config('wm-geohub-import.import_mapping.' . $this->getModelKey() . '.namespace');
    }

    /**
     * Get the table name for this job.
     */
    protected function getTableName(): string
    {
        return (new ($this->getModelName()))->getTable();
    }

    /**
     * Get the mapping configuration for this entity type.
     */
    protected function getMapping(): array
    {
        return config('wm-geohub-import.mappings.' . $this->getModelKey());
    }

    /**
     * Get the relations for this entity type.
     */
    protected function getRelations(): array
    {
        return config('wm-geohub-import.import_mapping.' . $this->getModelKey() . '.relations');
    }

    /**
     * Get the model key for this job.
     */
    abstract protected function getModelKey(): string;

    /**
     * Transform the data.
     */
    abstract protected function transformData(array $data): array;

    /**
     * Process dependencies if needed.
     */
    abstract protected function processDependencies(array $transformedData, Model $model): void;
}
