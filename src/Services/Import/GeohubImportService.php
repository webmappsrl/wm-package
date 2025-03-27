<?php

namespace Wm\WmPackage\Services\Import;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Log\Logger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use stdClass;
use Wm\WmPackage\Jobs\Import\BaseImportJob;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\StorageService;

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
        'ec_poi',
        'ec_track',
        'taxonomy_activity',
        'layer',
        'ec_media',
    ];

    protected const GEOHUB_URL = 'https://geohub.webmapp.it/';

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

        $ids = $this->getGeohubIdsToImport($modelKey, null);

        $jobs = $this->createJobsForIds($modelKey, $ids);

        $batch = Bus::batch($jobs)
            ->name("Import {$modelKey}s from geohub")
            ->allowFailures()
            ->dispatch();

        $this->logger->info("Dispatched batch {$batch->id} with ".count($jobs)." jobs for {$modelKey}s");
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
        // handle media table
        if ($tableName === 'media') {
            $tableName = 'ec_media';
        }

        $element = $this->dbConnection
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
     * @param  string  $modelKey  The model key
     * @param  string  $modelName  The model class name
     * @param  int  $entityId  The ID of the entity to import
     * @return Model The imported model
     *
     * @throws \Exception If import fails
     */
    public function importData(array $transformedData, string $modelKey, string $modelName, int $entityId): Model
    {
        try {
            if (! class_exists($modelName)) {
                throw new \RuntimeException("App model class {$modelName} not found or not configured");
            }

            $identifier = $this->getIdentifier($modelKey, $entityId);

            // Create or update the app
            $model = $modelName::updateOrCreate($identifier, $transformedData);

            $this->logger->info("{$modelName} with ID {$entityId} imported successfully. Local ID: {$model->id}");

            return $model;
        } catch (\Exception $e) {
            $this->logger->error("Error importing {$modelName} with ID {$entityId}: ".$e->getMessage());
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Helper Methods
    // ------------------------------------------------------------------

    /**
     * Create a job instance for the given model and ID
     *
     * @param  string  $modelKey  The model key
     * @param  int  $id  The ID of the entity
     * @param  array  $data  Additional data to pass to the job
     * @return BaseImportJob The job instance
     */
    public function createJob(string $modelKey, int $id, array $data = []): BaseImportJob
    {
        $jobClass = $this->importMapping[$modelKey]['job'];

        return new $jobClass($id, $data);
    }

    /**
     * Create multiple jobs for a list of IDs
     *
     * @param  string  $modelKey  The model key
     * @param  array  $ids  The IDs to create jobs for
     * @param  array  $data  Additional data to pass to the jobs
     * @return array Array of job instances
     */
    protected function createJobsForIds(string $modelKey, array $ids, array $data = []): array
    {
        $jobs = [];
        foreach ($ids as $id) {
            $jobs[] = $this->createJob($modelKey, $id, $data);
        }

        return $jobs;
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
     * @param  ?array  $wheres  The where conditions to apply
     * @return array The IDs to import
     *
     * @throws \InvalidArgumentException If the model is not supported
     */
    public function getGeohubIdsToImport(string $modelKey, ?array $wheres): array
    {
        $connection = $this->dbConnection->table($this->importMapping[$modelKey]['geohub_table']);

        if (! $wheres) {
            return $connection->pluck('id')->toArray();
        }

        foreach ($wheres as $column => $value) {
            $connection->where($column, $value);
        }

        return $connection->pluck('id')->toArray();
    }

    /**
     * Get the identifier for the updateOrCreate method.
     *
     * @param  string  $modelKey  The model key
     * @param  int  $entityId  The ID of the entity to import
     * @return array The identifier
     */
    protected function getIdentifier(string $modelKey, int $entityId): array
    {
        return [
            $this->importMapping[$modelKey]['identifier'] ?? 'properties->geohub_id' => $entityId,
        ];
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
     * Transform data fields using mapping configuration
     *
     * @param  array  $data  The data to transform
     * @param  array  $fieldMapping  The field mapping configuration
     * @return array The transformed data
     */
    protected function transformMappedFields(array $data, array $fieldMapping): array
    {
        $transformed = [];

        foreach ($fieldMapping as $target => $source) {
            if (is_array($source) && isset($source['field']) && isset($source['transformer'])) {
                $value = $data[$source['field']] ?? null;
                $transformed[$target] = $this->applyTransformer($value, $source['transformer']);
            } elseif (is_string($source)) {
                $transformed[$target] = $data[$source] ?? null;
            }
        }

        return $transformed;
    }

    /**
     * Transform the fields of the data using mapping configuration
     *
     * @param  array  $data  The data to transform
     * @param  string  $modelKey  The model key
     * @return array The transformed data
     */
    public function transformFields(array $data, string $modelKey): array
    {
        return $this->transformMappedFields(
            $data,
            $this->importMapping[$modelKey]['fields'] ?? []
        );
    }

    /**
     * Transform the properties of the data
     *
     * @param  array  $data  The data to transform
     * @param  string  $modelKey  The model key
     * @return array The transformed data
     */
    public function transformProperties(array $data, string $modelKey): array
    {
        $transformedProperties = [];

        if (isset($this->importMapping[$modelKey]['properties']['mapping'])) {
            $transformedProperties = $this->transformMappedFields(
                $data,
                $this->importMapping[$modelKey]['properties']['mapping']
            );
        }

        // Add standard properties
        $transformedProperties['geohub_id'] = $data['id'] ?? null;
        $transformedProperties['geohub_synced_at'] = Carbon::now()->toIso8601String();

        return $transformedProperties;
    }

    /**
     * Associate ec_pois with the given model
     *
     * @param  string  $modelKey  The model key
     * @param  int  $modelId  The ID of the model
     * @return array The IDs of the associated ec_pois
     */
    /**
     * Get associated EcPoi IDs with order information
     *
     * @param  string  $modelKey  The model key
     * @param  int  $modelId  The ID of the model
     * @return array The IDs of the associated ec_pois with order values
     */
    public function getAssociatedEcPoisIDs(string $modelKey, int $modelId): array
    {
        $ecPoiRelation = $this->importMapping[$modelKey]['relations']['ec_pois'];
        $pivotData = $this->dbConnection->table($ecPoiRelation['pivot_table'])
            ->where($ecPoiRelation['foreign_key'], $modelId)
            ->select('ec_poi_id', 'order')
            ->get();

        $ecPoiGeohubIds = $pivotData->pluck('ec_poi_id')->toArray();
        $orderMapping = $pivotData->pluck('order', 'ec_poi_id')->toArray();

        $ecPois = EcPoi::whereIn('properties->geohub_id', $ecPoiGeohubIds)->get();

        $result = [];
        foreach ($ecPois as $ecPoi) {
            $geohubId = $ecPoi->properties['geohub_id'];
            $result[$ecPoi->id] = $orderMapping[$geohubId] ?? 0;
        }

        return $result;
    }

    /**
     * Get the records to import for a taxonomy morphable model
     *
     * @param  string  $modelKey  The model key
     * @param  int  $modelId  The ID of the model
     * @return Collection The records to import
     */
    public function getTaxonomyMorphableRecords(string $modelKey, int $modelId): Collection
    {
        $relations = $this->importMapping[$modelKey]['relations'];
        $morphableTable = $relations['morphable_table'];
        $foreignKey = $relations['foreign_key'];
        $morphableIdKey = $relations['morphable_id'];
        $morphableTypeKey = $relations['morphable_type'];
        $morphableModels = $relations['morphable_models'];
        $pivotColumns = $relations['pivot_columns'] ?? [];

        $morphableRecords = $this->dbConnection->table($morphableTable)
            ->where($foreignKey, $modelId)
            ->get();

        return $morphableRecords->map(function ($record) use ($morphableModels, $morphableTypeKey, $morphableIdKey, $pivotColumns) {
            $modelName = Str::snake(class_basename($record->{$morphableTypeKey}));

            if (! isset($morphableModels[$modelName])) {
                return null;
            }

            $modelClass = $morphableModels[$modelName];
            $morphableId = $record->{$morphableIdKey};
            $whereCondition = str_contains($modelName, 'media')
                ? ['custom_properties->geohub_id' => $morphableId]
                : ['properties->geohub_id' => $morphableId];

            $model = $modelClass::where($whereCondition)->first();

            if ($model && ! empty($pivotColumns)) {
                $model->pivot_data = $this->extractPivotData($record, $pivotColumns);
            }

            return $model;
        })
            ->filter()
            ->values();
    }

    /**
     * Associate layers with taxonomy activities based on relationships in the Geohub database
     *
     * @param  string  $taxonomyKey  The taxonomy key (e.g. 'taxonomy_activity')
     * @param  Model  $model  The layer model to associate taxonomies with
     */
    public function associateLayersWithTaxonomy(string $taxonomyKey, Model $model): void
    {
        $config = $this->getRelationConfig('layer', $taxonomyKey);
        $relationTable = $config['pivot_table'];
        $foreignKey = $config['foreign_key'];
        $morphableTypeKey = $config['morphable_type']['key'];
        $morphableTypeValue = $config['morphable_type']['value'];
        $pivotColumns = $config['pivot_columns'] ?? [];

        $taxonomyRelations = $this->dbConnection->table($relationTable)
            ->where($foreignKey, $model->properties['geohub_id'])
            ->where($morphableTypeKey, $morphableTypeValue)
            ->get();

        foreach ($taxonomyRelations as $relation) {
            $pivotData = $this->extractPivotData($relation, $pivotColumns);
            $relationExists = $model->taxonomyActivities()
                ->where('properties->geohub_id', $relation->taxonomy_activity_id)
                ->exists();

            if (! $relationExists) {
                $taxonomyActivity = TaxonomyActivity::where('properties->geohub_id', $relation->taxonomy_activity_id)->first();
                if ($taxonomyActivity) {
                    $model->taxonomyActivities()->attach($taxonomyActivity->id, $pivotData);
                }
            } else {
                $model->taxonomyActivities()->updateExistingPivot($model->id, $pivotData);
            }
        }
    }

    /**
     * Associate layers with ec_track through taxonomy
     *
     * @param  string  $taxonomyKey  The taxonomy key (e.g. 'taxonomy_theme')
     * @param  Model  $model  The layer model to associate with ec_track
     */
    public function associateLayersWithEcTrack(string $taxonomyKey, Model $model): void
    {
        $config = $this->getRelationConfig('layer', $taxonomyKey);
        $relationTable = $config['pivot_table'];
        $key = $config['key'];
        $foreignKey = $config['foreign_key'];
        $morphableTypeKey = $config['morphable_type']['key'];
        $morphableTypeValue = $config['morphable_type']['value'];
        $layerTaxonomyRelations = $this->dbConnection->table($relationTable)
            ->where($foreignKey, $model->properties['geohub_id'])
            ->where($morphableTypeKey, $morphableTypeValue)
            ->get();

        foreach ($layerTaxonomyRelations as $relation) {
            $trackTaxonomyRelations = $this->dbConnection->table($relationTable)
                ->where($key, $relation->{$key})
                ->where($morphableTypeKey, 'App\\Models\\EcTrack')
                ->get();

            foreach ($trackTaxonomyRelations as $relation) {
                $ecTrack = EcTrack::where('properties->geohub_id', $relation->{$foreignKey})->first();
                if ($ecTrack && ! $model->ecTracks()->where('layerable_type', 'Wm\\WmPackage\\Models\\EcTrack')->where('layerable_id', $ecTrack->id)->exists()) {
                    $model->ecTracks()->attach($ecTrack->id, ['created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    public function handleOverlayLayers(Model $model): void
    {
        $config = $this->getRelationConfig('layer', 'overlay_layers');

        // Get all overlay layers from the database connection
        $overlayLayers = $this->dbConnection
            ->table('overlay_layers')
            ->select('*')
            ->get();

        foreach ($overlayLayers as $overlayLayer) {
            $association = $this->dbConnection
                ->table($config['pivot_table'])
                ->where($config['key'], $model->properties['geohub_id'])
                ->where($config['foreign_key'], $overlayLayer->id)
                ->where($config['morphable_type']['key'], $config['morphable_type']['value'])
                ->first();

            if ($association) {
                $this->mergeLayerWithOverlayLayer($model, $overlayLayer);
            } else {
                // TODO: If no association is found, create a new layer from the overlay layer. !IMPORTANT: handle the missing geometry in overlay_layers
                // $this->createNewLayerFromOverlayLayer($model, $overlayLayer);
            }
        }
    }

    protected function mergeLayerWithOverlayLayer(Model $model, stdClass $overlayLayer): void
    {
        $updateData = [];

        if (! empty($overlayLayer->feature_collection)) {
            $featureCollection = $overlayLayer->feature_collection;

            // If the path is an external url, keep it as is
            if (filter_var($featureCollection, FILTER_VALIDATE_URL)) {
                $updateData['feature_collection'] = $featureCollection;
            }
            // Otherwise we need to download and upload to AWS
            else {
                $fileUrl = self::GEOHUB_URL.'storage/'.$featureCollection;
                $fileContent = $this->downloadFileContent($fileUrl);

                if ($fileContent !== false) {
                    $this->storeFeatureCollectionOnAws($model, $fileContent);
                }
            }
        }

        if (! empty($overlayLayer->configuration)) {
            $updateData['configuration'] = $overlayLayer->configuration;
        }

        // Update the layer with the merged data if there's anything to update
        if (! empty($updateData)) {
            $model->update($updateData);
            \Log::info("Layer {$model->id} updated with overlay layer {$overlayLayer->id} data");
        }
    }

    /**
     * Download a file from Geohub
     *
     * @param  string  $url  The URL of the file to download
     * @return string|false The file content or false if the file doesn't exist
     */
    protected function downloadFileContent(string $url): string|false
    {
        $contents = @file_get_contents($url);

        return $contents !== false ? $contents : false;
    }

    /**
     * Store a feature collection on AWS
     *
     * @param  Model  $model  The model to store the feature collection for
     * @param  string  $fileContent  The file content to store
     */
    protected function storeFeatureCollectionOnAws(Model $model, string $fileContent): void
    {
        $appId = $model->app_id ?? null;

        $storageService = StorageService::make();

        try {
            $path = $storageService->storeLayerFeatureCollection(
                $appId,
                $model->id,
                $fileContent
            );
        } catch (\Exception $e) {
            $this->logger->error('Error storing layer feature collection: '.$e->getMessage());
        }

        if ($path) {
            $model->feature_collection = $path;
            $model->save();
        }
    }

    /**
     * Extract pivot data from a relation object
     *
     * @param  object  $relation  The relation object
     * @param  array  $pivotColumns  The pivot columns to extract
     * @return array The extracted pivot data
     */
    protected function extractPivotData(object $relation, array $pivotColumns): array
    {
        $pivotData = [];
        foreach ($pivotColumns as $column) {
            if (property_exists($relation, $column)) {
                $pivotData[$column] = $relation->{$column};
            }
        }

        return $pivotData;
    }

    /**
     * Get configuration for a relation from import mapping
     *
     * @param  string  $modelKey  The model key
     * @param  string  $relationKey  The relation key
     * @return array The relation configuration
     */
    protected function getRelationConfig(string $modelKey, string $relationKey): array
    {
        return $this->importMapping[$modelKey]['relations'][$relationKey];
    }
}
