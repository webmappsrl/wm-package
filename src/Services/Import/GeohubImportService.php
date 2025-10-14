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
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use stdClass;
use Wm\WmPackage\Jobs\Import\BaseImportJob;
use Wm\WmPackage\Jobs\UpdateAppConfigHomeLayerIdsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;
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
        'taxonomy_activity',
        'taxonomy_poi_types',
        'ec_poi',
        'ec_track',
        'layer',
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

    /**
     * Get the database connection to Geohub
     */
    public function getDbConnection(): Connection
    {
        return $this->dbConnection;
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
     * @param  string  $modelKey  The model type to import
     * @param  array  $data  Additional data to pass to the jobs
     */
    public function importAllByModel(string $modelKey, array $data = []): void
    {
        $this->validateModelExists($modelKey);

        $this->logger->info("Starting import of all {$modelKey}s");

        $geohubModelIds = $this->getGeohubIdsToImport($modelKey, null);

        $jobs = $this->createJobsForIds($modelKey, $geohubModelIds, $data);

        $batch = Bus::batch($jobs)
            ->name("Import {$modelKey}s from geohub")
            ->allowFailures()
            ->dispatch();

        $this->logger->info("Dispatched batch {$batch->id} with " . count($jobs) . " jobs for {$modelKey}s");
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

        $data = (array) $element;

        // handle translatable attributes
        $this->handleTranslatableAttributes($this->getModelInstance($tableName), $data);

        if (! $element) {
            $this->logger->warning("{$tableName} with ID {$entityId} not found in geohub");
            throw new \Exception("{$tableName} with ID {$entityId} not found in geohub");
        }

        return $data;
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

            // Find existing model or create new one
            $model = $modelName::where($identifier)->first();

            if ($model) {
                // Update existing model
                $model->fill($transformedData);
            } else {
                // Create new model
                $model = new $modelName($transformedData);
            }

            // Temporarily disable observers during import to avoid triggering PBF jobs
            $model::unsetEventDispatcher();

            // Save quietly to avoid triggering observers during Geohub import
            $model->saveQuietly();

            // Re-enable observers after save
            $model::setEventDispatcher(app('events'));

            $this->logger->info("{$modelName} with ID {$entityId} imported successfully. Local ID: {$model->id} {$model->name}");

            return $model;
        } catch (\Exception $e) {
            $this->logger->error("Error importing {$modelName} with ID {$entityId}: " . $e->getMessage());
            throw $e;
        }
    }

    public function getModelInstance(string $tableName): Model
    {
        $mapping = collect($this->importMapping)->firstWhere('geohub_table', $tableName);

        if (! $mapping) {
            throw new \InvalidArgumentException("No model mapping found for table: {$tableName}");
        }

        $modelClass = $mapping['namespace'];

        return new $modelClass;
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
    public function createJob(string $modelKey, int $geohubModelId, array $data = []): BaseImportJob
    {
        $jobClass = $this->importMapping[$modelKey]['job'];

        return new $jobClass($geohubModelId, $data);
    }

    /**
     * Create multiple jobs for a list of IDs
     *
     * @param  string  $modelKey  The model key
     * @param  array  $ids  The IDs to create jobs for
     * @param  array  $data  Additional data to pass to the jobs
     * @return array Array of job instances
     */
    protected function createJobsForIds(string $modelKey, array $geohubModelIds, array $data = []): array
    {
        $jobs = [];
        foreach ($geohubModelIds as $geohubModelId) {
            $jobs[] = $this->createJob($modelKey, $geohubModelId, $data);
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
     * @param  ?array  $data  Additional data (e.g., app_user_id for ec_media)
     * @return array The IDs to import
     *
     * @throws \InvalidArgumentException If the model is not supported
     */
    public function getGeohubIdsToImport(string $modelKey, ?array $wheres, ?array $data = null): array
    {
        $this->logger->info("🔍 getGeohubIdsToImport called for model: {$modelKey}, wheres: " . json_encode($wheres) . ", data: " . json_encode($data));

        $connection = $this->dbConnection->table($this->importMapping[$modelKey]['geohub_table']);

        if (! $wheres) {
            // Caso speciale per ec_media: importiamo solo i media associati ai track dell'app
            if ($modelKey === 'ec_media' && isset($data['app_user_id'])) {
                $this->logger->info("🔍 Calling getEcMediaIdsForApp with app_user_id: {$data['app_user_id']}");
                return $this->getEcMediaIdsForApp($data['app_user_id']);
            }
            return $connection->pluck('id')->toArray();
        }

        foreach ($wheres as $column => $value) {
            $connection->where($column, $value);
        }

        return $connection->pluck('id')->toArray();
    }

    /**
     * Get the IDs of ec_media associated with tracks of the current app
     *
     * @param  int  $appUserId  The user ID of the app
     * @return array The IDs of ec_media to import
     */
    private function getEcMediaIdsForApp(int $appUserId): array
    {
        // Prima trova l'app nel database geohub per ottenere l'user_id corretto
        $app = $this->dbConnection->table('apps')->where('user_id', $appUserId)->first();
        if (!$app) {
            $this->logger->info("App not found for user {$appUserId} in geohub database");
            return [];
        }

        $this->logger->info("Found app {$app->id} with geohub user_id: {$appUserId}");

        // Trova tutti i track dell'app corrente (sia quelli dell'user dell'app che quelli associati all'app)
        $appTracks = $this->dbConnection->table('ec_tracks')
            ->where('user_id', $appUserId)
            ->whereNotNull('feature_image')
            ->pluck('feature_image')
            ->toArray();

        // Trova anche i track associati all'app tramite la tabella di relazione
        $appTracksViaRelation = $this->dbConnection->table('ec_track_layer')
            ->join('layers', 'ec_track_layer.layer_id', '=', 'layers.id')
            ->where('layers.app_id', $app->id)
            ->join('ec_tracks', 'ec_track_layer.ec_track_id', '=', 'ec_tracks.id')
            ->whereNotNull('ec_tracks.feature_image')
            ->pluck('ec_tracks.feature_image')
            ->toArray();

        // Combina i due array
        $allAppTracks = array_unique(array_merge($appTracks, $appTracksViaRelation));

        if (empty($allAppTracks)) {
            $this->logger->info("No tracks with feature_image found for app user {$appUserId}");
            return [];
        }

        // Trova tutti i media associati a questi track (feature_image)
        $mediaIds = $this->dbConnection->table('ec_media')
            ->whereIn('id', $allAppTracks)
            ->pluck('id')
            ->toArray();

        // Trova anche i media associati tramite la tabella di relazione ec_media_ec_track
        $mediaViaRelation = $this->dbConnection->table('ec_media_ec_track')
            ->whereIn('ec_track_id', $this->getTrackIdsFromMediaIds($allAppTracks))
            ->pluck('ec_media_id')
            ->toArray();

        // Trova anche i media che sono feature_image dei track associati all'app
        // (indipendentemente dal loro user_id)
        $trackIds = $this->dbConnection->table('ec_track_layer')
            ->join('layers', 'ec_track_layer.layer_id', '=', 'layers.id')
            ->where('layers.app_id', $app->id)
            ->pluck('ec_track_layer.ec_track_id')
            ->toArray();

        $featureImageMedia = $this->dbConnection->table('ec_tracks')
            ->whereIn('id', $trackIds)
            ->whereNotNull('feature_image')
            ->pluck('feature_image')
            ->toArray();

        // Combina tutti i media
        $allMediaIds = array_unique(array_merge($mediaIds, $mediaViaRelation, $featureImageMedia));

        $this->logger->info("Found " . count($allMediaIds) . " ec_media associated with app tracks (geohub user_id: {$appUserId})");

        return $allMediaIds;
    }

    /**
     * Get app ID from user ID
     */
    private function getAppIdFromUserId(int $userId): ?int
    {
        $app = $this->dbConnection->table('apps')->where('user_id', $userId)->first();
        return $app ? $app->id : null;
    }

    /**
     * Get track IDs from media IDs (feature_image)
     */
    private function getTrackIdsFromMediaIds(array $mediaIds): array
    {
        return $this->dbConnection->table('ec_tracks')
            ->whereIn('feature_image', $mediaIds)
            ->pluck('id')
            ->toArray();
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

        $this->assignAdministratorRole($shardUser);

        return $shardUser;
    }

    /**
     * Assign the Administrator role to the user
     *
     * @param  User  $user  The user to assign the role to
     */
    protected function assignAdministratorRole(User $user): void
    {
        $role = Role::where('name', 'Administrator')->first();
        if (! $role) {
            RolesAndPermissionsService::seedDatabase();
            $role = Role::where('name', 'Administrator')->first();
        }

        $user->assignRole($role);
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

        if ($morphableRecords->isEmpty()) {
            return collect();
        }

        // Group records by model type for batch queries
        $groupedRecords = $morphableRecords->groupBy($morphableTypeKey);
        $results = collect();

        foreach ($groupedRecords as $modelType => $records) {
            $modelName = Str::snake(class_basename($modelType));

            if (! isset($morphableModels[$modelName])) {
                continue;
            }

            $modelClass = $morphableModels[$modelName];
            $morphableIds = $records->pluck($morphableIdKey)->toArray();

            // Batch query: get all models at once
            $whereCondition = str_contains($modelName, 'media')
                ? ['custom_properties->geohub_id' => $morphableIds]
                : ['properties->geohub_id' => $morphableIds];

            $models = $modelClass::whereIn(
                str_contains($modelName, 'media') ? 'custom_properties->geohub_id' : 'properties->geohub_id',
                $morphableIds
            )->get();

            // Map records to their corresponding models
            foreach ($records as $record) {
                $morphableId = $record->{$morphableIdKey};
                $model = $models->first(function ($m) use ($morphableId, $modelName) {
                    $geohubId = $modelName === 'ec_media'
                        ? $m->custom_properties['geohub_id'] ?? null
                        : $m->properties['geohub_id'] ?? null;
                    return $geohubId == $morphableId;
                });

                if ($model && ! empty($pivotColumns)) {
                    $model->pivot_data = $this->extractPivotData($record, $pivotColumns);
                }

                if ($model) {
                    $results->push($model);
                }
            }
        }

        return $results;
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

    public function associateLayersWithMedia(Model $model): void
    {
        Log::info("🖼️ ASSOCIATE LAYERS WITH MEDIA - Layer ID: {$model->id}, Geohub ID: {$model->properties['geohub_id']}");

        // 1. Controlla se il layer ha già una feature_image associata nel database Geohub
        $layerFeatureImage = $this->dbConnection->table('layers')
            ->where('id', $model->properties['geohub_id'])
            ->whereNotNull('feature_image')
            ->first();

        if ($layerFeatureImage && $layerFeatureImage->feature_image) {
            Log::info("✅ Layer already has feature_image in Geohub: {$layerFeatureImage->feature_image}");

            return;
        }

        // 2. Se non ha feature_image, controlla taxonomy_activity con feature_image
        $featureImageMedia = $this->getFeatureImageFromTaxonomy($model, 'taxonomy_activity');

        // 3. Se nemmeno taxonomy_activity ha feature_image, controlla taxonomy_theme
        if (! $featureImageMedia) {
            $featureImageMedia = $this->getFeatureImageFromTaxonomy($model, 'taxonomy_theme');
        }
    }

    /**
     * Get feature image from taxonomy associated with the layer
     */
    private function getFeatureImageFromTaxonomy(Model $model, string $taxonomyKey): ?object
    {
        Log::info("🔍 Checking {$taxonomyKey} for feature_image");

        $taxonomyConfig = $this->getRelationConfig('layer', $taxonomyKey);
        $relationTable = $taxonomyConfig['pivot_table'];
        $primaryKey = $taxonomyConfig['key'];
        $foreignKey = $taxonomyConfig['foreign_key'];
        $morphableTypeKey = $taxonomyConfig['morphable_type']['key'];
        $morphableTypeValue = $taxonomyConfig['morphable_type']['value'];

        // Ottieni le relazioni taxonomy per questo layer
        $taxonomyRelations = $this->dbConnection->table($relationTable)
            ->where($foreignKey, $model->properties['geohub_id'])
            ->where($morphableTypeKey, $morphableTypeValue)
            ->get();

        foreach ($taxonomyRelations as $relation) {
            // Ottieni il nome della tabella e della colonna ID dalla configurazione
            $taxonomyTable = Str::plural($taxonomyKey);

            // Cerca taxonomy con feature_image nel database Geohub
            $taxonomy = $this->dbConnection->table($taxonomyTable)
                ->where('id', $relation->{$primaryKey})
                ->whereNotNull('feature_image')
                ->first();

            if ($taxonomy && $taxonomy->feature_image) {
                $ecMedia = $this->dbConnection->table('ec_media')
                    ->where('id', $taxonomy->feature_image)
                    ->first();

                if ($ecMedia && $ecMedia->url) {
                    return $this->addMediaFromUrlToLayer($model, $ecMedia);
                }
            }
        }

        return null;
    }

    /**
     * Add media from URL to layer using Spatie Media Library
     */
    private function addMediaFromUrlToLayer(Model $model, object $ecMedia): ?object
    {
        try {
            // Usa addMediaFromUrl di Spatie per aggiungere il media direttamente al layer
            $media = $model->addMediaFromUrl($ecMedia->url)
                ->usingName($ecMedia->name ?? 'Feature Image')
                ->withCustomProperties([
                    'geohub_id' => $ecMedia->id,
                ])
                ->toMediaCollection('default');

            Log::info("✅ Added media from URL to layer: {$media->id}");

            return $media;
        } catch (\Exception $e) {
            Log::error("❌ Failed to add media from URL {$ecMedia->url}: " . $e->getMessage());

            return null;
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
        $this->logger->info("🔍 ASSOCIATE LAYERS WITH EC TRACK - Layer ID: {$model->id}, Geohub ID: {$model->properties['geohub_id']}, Taxonomy: {$taxonomyKey}");

        $config = $this->getRelationConfig('layer', $taxonomyKey);
        $relationTable = $config['pivot_table'];
        $key = $config['key'];
        $foreignKey = $config['foreign_key'];
        $morphableTypeKey = $config['morphable_type']['key'];
        $morphableTypeValue = $config['morphable_type']['value'];

        $this->logger->info("📊 Using relation table: {$relationTable}, key: {$key}, foreignKey: {$foreignKey}");

        $layerTaxonomyRelations = $this->dbConnection->table($relationTable)
            ->where($foreignKey, $model->properties['geohub_id'])
            ->where($morphableTypeKey, $morphableTypeValue)
            ->get();

        $this->logger->info('📋 Layer taxonomy relations found: ' . $layerTaxonomyRelations->count());

        $totalTracksAssigned = 0;
        $totalTracksAlreadyAssigned = 0;
        $totalTracksNotFound = 0;

        foreach ($layerTaxonomyRelations as $relation) {
            $this->logger->info("🔗 Processing taxonomy relation: {$relation->{$key}}");

            $trackTaxonomyRelations = $this->dbConnection->table($relationTable)
                ->where($key, $relation->{$key})
                ->where($morphableTypeKey, 'App\\Models\\EcTrack')
                ->get();

            $this->logger->info("📋 Track taxonomy relations for {$relation->{$key}}: " . $trackTaxonomyRelations->count());

            foreach ($trackTaxonomyRelations as $trackRelation) {
                $ecTrackModelClass = config('wm-package.ec_track_model', 'App\Models\EcTrack');
                $ecTrack = $ecTrackModelClass::where('properties->geohub_id', $trackRelation->{$foreignKey})->first();

                if ($ecTrack) {
                    $alreadyExists = $model->ecTracks()->where('layerable_type', $ecTrackModelClass)->where('layerable_id', $ecTrack->id)->exists();

                    if (!$alreadyExists) {
                        $model->ecTracks()->attach($ecTrack->id, ['created_at' => now(), 'updated_at' => now()]);
                        $totalTracksAssigned++;
                        $this->logger->info("✅ Track assigned: Geohub ID {$trackRelation->{$foreignKey}} -> Local ID {$ecTrack->id}");
                    } else {
                        $totalTracksAlreadyAssigned++;
                        $this->logger->info("⚠️ Track already assigned: Geohub ID {$trackRelation->{$foreignKey}} -> Local ID {$ecTrack->id}");
                    }
                } else {
                    $totalTracksNotFound++;
                    $this->logger->warning("❌ Track not found locally: Geohub ID {$trackRelation->{$foreignKey}}");
                }
            }
        }

        $this->logger->info("📊 ASSOCIATION SUMMARY for Layer {$model->id} (Taxonomy: {$taxonomyKey}):");
        $this->logger->info("   • Tracks assigned: {$totalTracksAssigned}");
        $this->logger->info("   • Tracks already assigned: {$totalTracksAlreadyAssigned}");
        $this->logger->info("   • Tracks not found locally: {$totalTracksNotFound}");
        $this->logger->info('   • Final layer track count: ' . $model->ecTracks()->count());
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

    protected function handleTranslatableAttributes(Model $model, array &$data): void
    {
        // Check if the model has the getTranslatableAttributes method
        if (!method_exists($model, 'getTranslatableAttributes')) {
            return;
        }

        $translatableAttributes = $model->getTranslatableAttributes();

        if (empty($translatableAttributes)) {
            return;
        }

        foreach ($translatableAttributes as $attribute) {
            if (isset($data[$attribute]) && is_string($data[$attribute])) {
                $decoded = json_decode($data[$attribute], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data[$attribute] = $decoded;
                }
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
                $fileUrl = config('wm-package.clients.geohub.host') . '/storage/' . $featureCollection;
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
            Log::info("Layer {$model->id} updated with overlay layer {$overlayLayer->id} data");
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
            $this->logger->error('Error storing layer feature collection: ' . $e->getMessage());
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

    /**
     * Controlla se una coda specifica è vuota
     * Verifica sia Redis che Horizon per assicurarsi che non ci siano job in attesa o in esecuzione
     * 
     * @param string $queueName Nome della coda da controllare
     * @return bool True se la coda è vuota, false altrimenti
     */
    public function isQueueEmpty(string $queueName): bool
    {
        try {
            $appName = config('app.name', 'wm-package');
            $redis = Redis::connection();

            $queueKeyPrefix = "{$appName}_database_queues:{$queueName}";
            $horizonPrefix = "{$appName}_horizon:";

            $checkQueues = function () use ($redis, $queueKeyPrefix, $horizonPrefix, $queueName) {
                // Code Redis: attesa, delayed, reserved
                $pendingSize = (int) $redis->llen($queueKeyPrefix);
                $delayedSize = (int) $redis->zcard($queueKeyPrefix . ':delayed');
                $reservedSize = (int) $redis->zcard($queueKeyPrefix . ':reserved');

                if (($pendingSize + $delayedSize + $reservedSize) > 0) {
                    Log::info("Coda '{$queueName}' non vuota (pending={$pendingSize}, delayed={$delayedSize}, reserved={$reservedSize})");
                    return false;
                }

                // Horizon processing (job in esecuzione)
                $processingSize = (int) $redis->zcard($horizonPrefix . 'processing');
                if ($processingSize > 0) {
                    $processingData = $redis->zrange($horizonPrefix . 'processing', 0, -1);
                    foreach ($processingData as $jobData) {
                        $job = json_decode($jobData, true);
                        if (isset($job['queue']) && $job['queue'] === $queueName) {
                            Log::info("Coda '{$queueName}' ha job in processing su Horizon");
                            return false;
                        }
                    }
                }

                return true;
            };

            // Primo controllo
            if (! $checkQueues()) {
                return false;
            }

            // Breve attesa e secondo controllo per mitigare race conditions
            usleep(1500000); // 1.5 secondi

            if (! $checkQueues()) {
                return false;
            }

            Log::info("Coda '{$queueName}' è vuota (doppio check)");
            return true;
        } catch (\Exception $e) {
            Log::error("Errore nel controllo della coda '{$queueName}': " . $e->getMessage());
            // In caso di errore, assumiamo che la coda non sia vuota per sicurezza
            return false;
        }
    }
}
