<?php

namespace Wm\WmPackage\Services\Import;

use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class GeohubImportService
{
    protected const MODEL_IMPORT_ORDER = [
        'app',
        'ec_poi',
        'ec_track',
        'ec_media',
    ];

    protected Connection $dbConnection;
    protected Logger $logger;
    protected array $importModels;
    protected string $dbConnectionName;

    public function __construct()
    {
        $this->dbConnectionName = config('wm-geohub-import.db_connection', 'geohub');
        $this->dbConnection = DB::connection($this->dbConnectionName);
        $this->logger = Log::channel(config('wm-geohub-import.import_log_channel', 'wm-package-failed-jobs'));
        $this->importModels = config('wm-geohub-import.import_models', []);
    }

    public function importAll(): void
    {
        $this->logger->info('Starting full import from geohub');

        // Import in the correct order to maintain dependencies. Import one batch at a time for each model type.
        foreach (self::MODEL_IMPORT_ORDER as $model) {
            $this->importAllByModel($model);
        }

        $this->logger->info('Completed full import from geohub');
    }

    public function importAllByModel(string $model): void
    {
        if (!array_key_exists($model, $this->importModels)) {
            throw new \InvalidArgumentException("Unsupported model: {$model}");
        }

        $this->logger->info("Starting import of all {$model}s");

        $ids = $this->getIdsToImport($model);

        $jobs = [];
        foreach ($ids as $id) {
            $jobClass = $this->importModels[$model]['job'];
            $jobs[] = new $jobClass($id, $this->dbConnectionName);
        }

        $batch = Bus::batch($jobs)
            ->name("Import {$model}s from geohub")
            ->allowFailures()
            ->dispatch();

        $this->logger->info("Dispatched batch {$batch->id} with " . count($jobs) . " jobs for {$model}s");
    }

    public function importSingle(string $model, int $id): void
    {
        if (!array_key_exists($model, $this->importModels)) {
            throw new \InvalidArgumentException("Unsupported model: {$model}");
        }

        $this->logger->info("Starting import of {$model} with ID {$id}");

        $jobClass = $this->importModels[$model]['job'];
        $job = new $jobClass($id, $this->dbConnectionName);

        dispatch($job);

        $this->logger->info("Dispatched job for {$model} with ID {$id}");
    }

    protected function getIdsToImport(string $model): array
    {
        switch ($model) {
            case 'app':
                // Get all app IDs
                return $this->dbConnection
                    ->table('apps')
                    ->pluck('id')
                    ->toArray();

            case 'ec_media':
            case 'ec_track':
            case 'ec_poi':
                // get all entities related to apps by user_id
                $table = str_replace('_', '_', $model) . 's'; // Convert to table name
                $userIds = $this->dbConnection
                    ->table('apps')
                    ->pluck('user_id')
                    ->unique()
                    ->toArray();

                return $this->dbConnection
                    ->table($table)
                    ->whereIn('user_id', $userIds)
                    ->pluck('id')
                    ->toArray();
            default:
                throw new \InvalidArgumentException("Unsupported model: {$model}");
        }
    }
}
