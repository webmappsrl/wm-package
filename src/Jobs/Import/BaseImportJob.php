<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Log\Logger;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

abstract class BaseImportJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */

    /**
     * The ID of the entity to import
     */
    protected int $entityId;

    /**
     * The database connection to use for geohub
     */
    protected string $dbConnection;

    /**
     * The mapping configuration for this entity type
     */
    protected array $mapping;


    /**
     * Create a new job instance.
     */
    public function __construct(int $entityId, string $connection)
    {
        $this->entityId = $entityId;
        $this->dbConnection = $connection;
        $this->mapping = $this->getMapping();
        $this->onQueue(config('wm-geohub-import.queue.geohub-import.queue'));
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $logger = Log::channel(config('wm-geohub-import.import_log_channel', 'wm-package-failed-jobs'));
        $modelName = $this->getModelName();

        try {
            $logger->info("Started import of {$modelName} with ID {$this->entityId}");

            // Get data from geohub
            $data = $this->fetchData();

            if (empty($data)) {
                $logger->warning("{$modelName} with ID {$this->entityId} not found in geohub");
                return;
            }

            // Transform data according to mapping
            $transformedData = $this->transformData($data);

            // Import data to shard
            $this->importData($transformedData);

            $logger->info("Completed import of {$modelName} with ID {$this->entityId}");

            // Process dependencies if needed
            $this->processDependencies($data);
        } catch (\Exception $e) {
            $logger->error("Error importing {$modelName} with ID {$this->entityId}: " . $e->getMessage());
            $logger->error($e->getTraceAsString());

            // Re-throw the exception to trigger job failure
            throw $e;
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
     * Transform data according to mapping.
     */
    abstract protected function transformData(array $data): array;

    /**
     * Import data to shard.
     */
    abstract protected function importData(array $transformedData): mixed;

    /**
     * Process dependencies if needed.
     */
    abstract protected function processDependencies(array $transformedData): void;

    /**
     * Fetch data from geohub.
     */
    protected function fetchData(): ?array
    {
        $element = DB::connection($this->dbConnection)
            ->table($this->getTableName())
            ->where('id', $this->entityId)
            ->first();

        if (!$element) {
            return null;
        }

        return (array) $element;
    }

    /**
     * Helper method to apply a transformer to a value.
     */
    protected function applyTransformer(mixed $value, array $transformer): mixed
    {
        if (empty($transformer) || !isset($transformer[0]) || !isset($transformer[1])) {
            return $value;
        }

        $className = $transformer[0];
        $methodName = $transformer[1];

        if (!class_exists($className)) {
            throw new \RuntimeException("Transformer class {$className} not found");
        }

        $instance = new $className();

        if (!method_exists($instance, $methodName)) {
            throw new \RuntimeException("Method {$methodName} not found in transformer class {$className}");
        }

        return $instance->$methodName($value);
    }
}
