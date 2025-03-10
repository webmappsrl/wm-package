<?php

namespace Wm\WmPackage\Jobs\Import;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportEcPoiJob extends BaseImportJob
{
    /**
     * The app ID to use for the import.
     */
    protected int $appId;

    public function __construct(int $entityId, string $connection, int $appId)
    {
        parent::__construct($entityId, $connection);
        $this->appId = $appId;
    }

    protected function getModelName(): string
    {
        return config('wm-geohub-import.import_models.ec_poi.namespace');
    }

    protected function getTableName(): string
    {
        return 'ec_pois';
    }

    protected function getMapping(): array
    {
        return config('wm-geohub-import.import_mapping.ec_poi', []);
    }


    protected function transformData(array $data): array
    {
        $result = [];

        // Process basic fields
        foreach ($this->mapping['fields'] ?? [] as $target => $source) {
            if (is_array($source) && isset($source['field']) && isset($source['transformer'])) {
                // Apply transformer
                $value = $data[$source['field']] ?? null;
                $result[$target] = $this->applyTransformer($value, $source['transformer']);
            } elseif (is_string($source)) {
                // Direct mapping
                $result[$target] = $data[$source] ?? null;
            }
        }

        // Process properties
        if (isset($this->mapping['properties'])) {
            $properties = [];

            foreach ($this->mapping['properties'] as $target => $source) {
                //check if it has a transformer
                if (is_array($source) && isset($source['field']) && isset($source['transformer'])) {
                    // Apply transformer
                    $value = $data[$source['field']] ?? null;
                    $properties[$target] = $this->applyTransformer($value, $source['transformer']);
                } elseif (is_string($source)) {
                    // Direct mapping
                    $properties[$target] = $data[$source] ?? null;
                }
            }

            // Add geohub_id and geohub_synced_at to properties
            $properties['geohub_id'] = $data['id'] ?? null;
            $properties['geohub_synced_at'] = Carbon::now()->toIso8601String();

            $result['properties'] = $properties;
        }

        // Handle app_id
        $result['app_id'] = $this->appId;


        return $result;
    }

    protected function importData(array $transformedData): mixed
    {
        $logger = Log::channel('wm-package-failed-jobs');

        try {
            // Use updateOrCreate to create or update the poi
            $model = config('wm-geohub-import.import_models.ec_poi.namespace', 'EcPoi');

            if (!$model || !class_exists($model)) {
                throw new \RuntimeException("POI model class {$model} not found or not configured");
            }

            // Extract identifier fields for updateOrCreate
            $identifiers = [];
            foreach ($this->mapping['identifiers'] ?? [] as $field) {
                if (isset($transformedData[$field])) {
                    $identifiers[$field] = $transformedData[$field];
                }
            }

            if (empty($identifiers)) {
                // If no identifiers are found, use custom properties with geohub_id
                $identifiers = [
                    'properties->geohub_id' => $this->entityId
                ];
            }

            // Create or update the poi
            $poi = $model::updateOrCreate($identifiers, $transformedData);

            $logger->info("POI with ID {$this->entityId} imported successfully. Local ID: {$poi->id}");

            return $poi;
        } catch (\Exception $e) {
            $logger->error("Error importing POI with ID {$this->entityId}: " . $e->getMessage());
            throw $e;
        }
    }

    protected function processDependencies(array $data): void
    {
        //no dependencies
    }

    /**
     * Find app ID by SKU.
     */
    protected function findAppIdBySku(string $sku): ?int
    {
        try {
            // Query the apps table for the given SKU
            // Assuming sku is in a JSON column like {os: "com.example.app"}
            $app = DB::connection($this->dbConnection)
                ->table('apps')
                ->whereRaw("JSON_CONTAINS(sku, '\"{$sku}\"', '$.os')")
                ->first();

            return $app ? $app->id : null;
        } catch (\Exception $e) {
            Log::channel('wm-package-failed-jobs')
                ->error("Error finding app ID for SKU {$sku}: " . $e->getMessage());
            return null;
        }
    }
}
