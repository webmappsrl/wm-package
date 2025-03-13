<?php

namespace Wm\WmPackage\Services\Import;

use Illuminate\Log\Logger;
use Wm\WmPackage\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Jobs\Import\BaseImportJob;
use Wm\WmPackage\Models\EcPoi;

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
    protected array $importMapping;

    /**
     * Initialize the import service
     */
    public function __construct()
    {
        $this->dbConnection = DB::connection('geohub');
        $this->logger = Log::channel('wm-package-failed-jobs');
        $this->importMapping = config('wm-geohub-import.import_mapping', []);
    }

    // ------------------------------------------------------------------
    // Public Import Methods
    // ------------------------------------------------------------------

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
     * @param  string  $model  The model type to import
     */
    public function importAllByModel(string $modelKey): void
    {
        $this->validateModelExists($modelKey);

        $this->logger->info("Starting import of all {$modelKey}s");

        $ids = $this->dbConnection
            ->table($this->importMapping[$modelKey]['geohub_table'])
            ->pluck('id')
            ->toArray();

        $jobs = [];
        foreach ($ids as $id) {
            $jobClass = $this->importMapping[$modelKey]['job'];
            $jobs[] = new $jobClass($id);
        }

        $batch = Bus::batch($jobs)
            ->name("Import {$modelKey}s from geohub")
            ->allowFailures()
            ->dispatch();

        $this->logger->info("Dispatched batch {$batch->id} with " . count($jobs) . " jobs for {$model}s");
    }

    /**
     * Import a single entity from Geohub
     *
     * @param  string  $modelKey  The model type to import
     * @param  int  $id  The ID of the entity to import
     * @param  array  $data  Additional data to pass to the job
     */
    public function importSingle(string $modelKey, int $id, array $data = []): void
    {
        $this->validateModelExists($modelKey);

        $this->logger->info("Starting import of {$modelKey} with ID {$id}");

        $job = $this->createJob($modelKey, $id, $data);

        dispatch($job);

        $this->logger->info("Dispatched job for {$modelKey} with ID {$id}");
    }

    // ------------------------------------------------------------------
    // Data Fetching and Processing Methods
    // ------------------------------------------------------------------

    /**
     * Fetch data from Geohub for a specific entity
     *
     * @param  int  $entityId  The ID of the entity to fetch
     * @param  string  $tableName  The table name to fetch from
     * @return array|null The fetched data as an array
     *
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
     * @param  array  $transformedData  The data to import
     * @param  string  $modelName  The model class name
     * @param  int  $entityId  The ID of the entity to import
     * @return Model The imported model
     *
     * @throws \Exception If import fails
     */
    public function importData(array $transformedData, string $modelName, int $entityId): Model
    {
        try {
            if (! class_exists($modelName)) {
                throw new \RuntimeException("App model class {$modelName} not found or not configured");
            }

            $identifiers = $this->getIdentifiers($transformedData, $entityId);

            // Create or update the app
            $model = $modelName::updateOrCreate($identifiers, $transformedData);

            $this->logger->info("{$modelName} with ID {$entityId} imported successfully. Local ID: {$model->id}");

            return $model;
        } catch (\Exception $e) {
            $this->logger->error("Error importing {$modelName} with ID {$entityId}: " . $e->getMessage());
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Helper Methods
    // ------------------------------------------------------------------


    public function createJob(string $modelKey, int $id, array $data = []): BaseImportJob
    {
        $jobClass = $this->importMapping[$modelKey]['job'];
        $job = new $jobClass($id, $data);

        return $job;
    }
    /**
     * Validate that the given model exists in the import models configuration
     *
     * @param  string  $model  The model to validate
     *
     * @throws \InvalidArgumentException If the model is not supported
     */
    protected function validateModelExists(string $model): void
    {
        if (! array_key_exists($model, $this->importMapping)) {
            throw new \InvalidArgumentException("Unsupported model: {$model}");
        }
    }

    /**
     * Get the IDs of entities to import for a specific model
     *
     * @param  string  $model  The model type
     * @return array The IDs to import
     *
     * @throws \InvalidArgumentException If the model is not supported
     */
    public function getGeohubIdsToImport(string $modelKey, array $wheres = []): array
    {

        $connection = $this->dbConnection->table($this->importMapping[$modelKey]['geohub_table']);

        foreach ($wheres as $column => $value) {
            $connection->where($column, $value);
        }

        return $connection->pluck('id')->toArray();
    }

    /**
     * Get the identifiers for the updateOrCreate method.
     *
     * @param  array  $data  The data to extract identifiers from
     * @param  int  $entityId  The ID of the entity to import
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
     * @param  mixed  $value  The value to transform
     * @param  array  $transformer  The transformer configuration
     * @return mixed The transformed value
     *
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

    /**
     * Check if a user exists in the local database and create it if it doesn't
     *
     * @param  int  $userId  The ID of the user to check
     * @return User The user object
     */
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

    /**
     * Transform the fields of the data using mapping configuration
     *
     * @param  array  $data  The data to transform
     * @return array The transformed data
     */
    public function transformFields(array $data, string $modelKey): array
    {
        $transformedData = [];
        foreach ($this->importMapping[$modelKey]['fields'] ?? [] as $target => $source) {
            if (is_array($source) && isset($source['field']) && isset($source['transformer'])) {
                $value = $data[$source['field']] ?? null;
                $transformedData[$target] = $this->applyTransformer($value, $source['transformer']);
            } elseif (is_string($source)) {
                $transformedData[$target] = $data[$source] ?? null;
            }
        }

        return $transformedData;
    }

    /**
     * Transform the properties of the data
     *
     * @param  array  $data  The data to transform
     * @return array The transformed data
     */
    public function transformProperties(array $data, string $modelKey): array
    {
        $transformedProperties = [];
        if (isset($this->importMapping[$modelKey]['properties'])) {
            $properties = [];

            foreach ($this->importMapping[$modelKey]['properties'] as $target => $source) {
                if (is_array($source) && isset($source['field']) && isset($source['transformer'])) {
                    $value = $data[$source['field']] ?? null;
                    $properties[$target] = $this->applyTransformer($value, $source['transformer']);
                } elseif (is_string($source)) {
                    $properties[$target] = $data[$source] ?? null;
                }
            }

            $properties['geohub_id'] = $data['id'] ?? null;
            $properties['geohub_synced_at'] = Carbon::now()->toIso8601String();

            $transformedProperties = $properties;
        }

        return $transformedProperties;
    }

    /**
     * Associate ec_pois with the given model
     *
     * @param  string  $modelKey  The model key
     * @param  int  $modelId  The ID of the model
     * @return array The IDs of the associated ec_pois
     */
    public function getAssociatedEcPoisIDs(string $modelKey, int $modelId): array
    {
        $ecPoiRelation = $this->importMapping[$modelKey]['relations']['ec_pois'];
        $ecPoiGeohubIds = $this->dbConnection->table($ecPoiRelation['pivot_table'])->where($ecPoiRelation['foreign_key'], $modelId)->pluck('ec_poi_id')->toArray();

        //query current DB looking the match in properties->geohub_id
        $ecPoiIds = EcPoi::whereIn('properties->geohub_id', $ecPoiGeohubIds)->pluck('id')->toArray();

        return $ecPoiIds;
    }
}
