<?php

namespace Wm\WmPackage\Services\Import;

use Illuminate\Log\Logger;
use Wm\WmPackage\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Service for importing data from Geohub to the local database
 * 
 * This service handles the import process for various entity types
 * from the Geohub database to the local application database.
 */
class GeohubImportService
{
    /**
     * The order in which models should be imported to maintain dependencies
     */
    protected const MODEL_IMPORT_ORDER = [
        'app',
        'layer',
        'ec_poi',
        'ec_track',
        'ec_media',
    ];

    /**
     * The database connection to Geohub
     */
    protected Connection $dbConnection;

    /**
     * Logger instance for recording import operations
     */
    protected Logger $logger;

    /**
     * Configuration for import models
     */
    protected array $importModels;

    /**
     * Initialize the import service
     */
    public function __construct()
    {
        $this->dbConnection = DB::connection('geohub');
        $this->logger = Log::channel('wm-package-failed-jobs');
        $this->importModels = config('wm-geohub-import.import_models', []);
    }

    //------------------------------------------------------------------
    // Public Import Methods
    //------------------------------------------------------------------

    /**
     * Import all entities from Geohub
     */
    public function importAll(): void
    {
        $this->logger->info('Starting full import from geohub');

        // Import in the correct order to maintain dependencies. Import one batch at a time for each model type.
        foreach (self::MODEL_IMPORT_ORDER as $model) {
            $this->importAllByModel($model);
        }

        $this->logger->info('Completed full import from geohub');
    }

    /**
     * Import all entities of a specific model type
     * 
     * @param string $model The model type to import
     */
    public function importAllByModel(string $model): void
    {
        $this->validateModelExists($model);

        $this->logger->info("Starting import of all {$model}s");

        $ids = $this->getIdsToImport($model);

        $jobs = [];
        foreach ($ids as $id) {
            $jobClass = $this->importModels[$model]['job'];
            $jobs[] = new $jobClass($id);
        }

        $batch = Bus::batch($jobs)
            ->name("Import {$model}s from geohub")
            ->allowFailures()
            ->dispatch();

        $this->logger->info("Dispatched batch {$batch->id} with " . count($jobs) . " jobs for {$model}s");
    }

    /**
     * Import a single entity from Geohub
     * 
     * @param string $model The model type to import
     * @param int $id The ID of the entity to import
     */
    public function importSingle(string $model, int $id): void
    {
        $this->validateModelExists($model);

        $this->logger->info("Starting import of {$model} with ID {$id}");

        $jobClass = $this->importModels[$model]['job'];
        $job = new $jobClass($id);

        dispatch($job);

        $this->logger->info("Dispatched job for {$model} with ID {$id}");
    }

    //------------------------------------------------------------------
    // Data Fetching and Processing Methods
    //------------------------------------------------------------------

    /**
     * Fetch data from Geohub for a specific entity
     * 
     * @param int $entityId The ID of the entity to fetch
     * @param string $tableName The table name to fetch from
     * @return array|null The fetched data as an array
     * @throws \Exception If the entity is not found
     */
    public function fetchData(int $entityId, string $tableName): ?array
    {
        $element = DB::connection('geohub')
            ->table($tableName)
            ->where('id', $entityId)
            ->first();

        if (! $element) {
            $this->logger->warning("{$tableName} with ID {$entityId} not found in geohub");
            throw new \Exception("{$tableName} with ID {$entityId} not found in geohub");
        }

        return (array) $element;
    }

    /**
     * Import the transformed data into the database
     * 
     * @param array $transformedData The data to import
     * @param string $modelName The model class name
     * @param int $entityId The ID of the entity to import
     * @throws \Exception If import fails
     */
    public function importData(array $transformedData, string $modelName, int $entityId): void
    {
        try {
            if (! class_exists($modelName)) {
                throw new \RuntimeException("App model class {$modelName} not found or not configured");
            }

            $identifiers = $this->getIdentifiers($transformedData, $entityId);

            // Create or update the app
            $app = $modelName::updateOrCreate($identifiers, $transformedData);

            $this->logger->info("{$modelName} with ID {$entityId} imported successfully. Local ID: {$app->id}");
        } catch (\Exception $e) {
            $this->logger->error("Error importing {$modelName} with ID {$entityId}: " . $e->getMessage());
            throw $e;
        }
    }

    //------------------------------------------------------------------
    // Helper Methods
    //------------------------------------------------------------------

    /**
     * Validate that the given model exists in the import models configuration
     * 
     * @param string $model The model to validate
     * @throws \InvalidArgumentException If the model is not supported
     */
    protected function validateModelExists(string $model): void
    {
        if (! array_key_exists($model, $this->importModels)) {
            throw new \InvalidArgumentException("Unsupported model: {$model}");
        }
    }

    /**
     * Get the IDs of entities to import for a specific model
     * 
     * @param string $model The model type
     * @return array The IDs to import
     * @throws \InvalidArgumentException If the model is not supported
     */
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

    /**
     * Get the identifiers for the updateOrCreate method.
     * 
     * @param array $data The data to extract identifiers from
     * @param int $entityId The ID of the entity to import
     * @return array The identifiers
     */
    protected function getIdentifiers(array $data, int $entityId): array
    {
        // Extract identifier fields for updateOrCreate
        $identifiers = [];
        foreach ($this->mapping['identifiers'] ?? [] as $field) {
            if (isset($data[$field])) {
                $identifiers[$field] = $data[$field];
            }
        }

        if (empty($identifiers)) {
            // If no identifiers are found, use custom properties with geohub_id
            $identifiers = [
                'properties->geohub_id' => $entityId,
            ];
        }

        return $identifiers;
    }

    /**
     * Helper method to apply a transformer to a value.
     * 
     * @param mixed $value The value to transform
     * @param array $transformer The transformer configuration
     * @return mixed The transformed value
     * @throws \RuntimeException If the transformer class or method is not found
     */
    protected function applyTransformer(mixed $value, array $transformer): mixed
    {
        if (empty($transformer) || ! isset($transformer[0]) || ! isset($transformer[1])) {
            return $value;
        }

        $className = $transformer[0];
        $methodName = $transformer[1];

        if (! class_exists($className)) {
            throw new \RuntimeException("Transformer class {$className} not found");
        }

        $instance = new $className;

        if (! method_exists($instance, $methodName)) {
            throw new \RuntimeException("Method {$methodName} not found in transformer class {$className}");
        }

        return $instance->$methodName($value);
    }

    public function checkUserExistence(int $userId): User
    {

        $geohubUser = $this->dbConnection->table('users')->where('id', $userId)->first();
        $shardUser = User::where('email', $geohubUser->email)->first();
        if (! $shardUser) {
            // make a diff between geohubUser and User model
            $diff = array_diff(array_keys((array) $geohubUser), Schema::getColumnListing('users'));
            $transformedData = array_diff_key((array) $geohubUser, array_flip($diff));
            $shardUser = User::create($transformedData);
        }

        return $shardUser;
    }
}
