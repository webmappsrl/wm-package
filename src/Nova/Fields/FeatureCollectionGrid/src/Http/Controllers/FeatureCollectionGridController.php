<?php

namespace Wm\WmPackage\Nova\Fields\FeatureCollectionGrid\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FeatureCollectionGridController
{
    /**
     * Get GeoJSON FeatureCollection for the resource
     *
     * @param  Request  $request
     * @param  string  $resourceName
     * @param  int  $resourceId
     * @return JsonResponse
     */
    public function getGeojson(Request $request, string $resourceName, int $resourceId): JsonResponse
    {
        try {
            $resource = $this->findResource($resourceName, $resourceId);

            if (! $resource) {
                return response()->json([
                    'error' => 'Resource not found',
                ], 404);
            }

            // Get GeoJSON source method from request
            $geojsonSource = $request->query('geojson_source');

            if (! $geojsonSource) {
                return response()->json([
                    'error' => 'GeoJSON source method not specified',
                ], 400);
            }

            // Call the method or callback to get GeoJSON
            $geojson = $this->getGeojsonFromSource($resource, $geojsonSource);

            if (! $geojson) {
                return response()->json([
                    'type' => 'FeatureCollection',
                    'features' => [],
                ]);
            }

            // Ensure it's a FeatureCollection
            if (isset($geojson['type']) && $geojson['type'] === 'FeatureCollection') {
                return response()->json($geojson);
            }

            // If it's a single feature, wrap it
            if (isset($geojson['type']) && $geojson['type'] === 'Feature') {
                return response()->json([
                    'type' => 'FeatureCollection',
                    'features' => [$geojson],
                ]);
            }

            return response()->json([
                'type' => 'FeatureCollection',
                'features' => [],
            ]);
        } catch (\Exception $e) {
            Log::error('FeatureCollectionGridController::getGeojson error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Errore interno del server: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get paginated list of features from GeoJSON
     *
     * @param  Request  $request
     * @param  string  $resourceName
     * @param  int  $resourceId
     * @return JsonResponse
     */
    public function getFeatures(Request $request, string $resourceName, int $resourceId): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'geojson_source' => 'required|string',
                'page' => 'integer|min:1',
                'per_page' => 'integer|min:1|max:100',
                'search' => 'string|nullable',
                'view_mode' => 'string|in:details,edit',
            ]);

            $resource = $this->findResource($resourceName, $resourceId);

            if (! $resource) {
                return response()->json([
                    'error' => 'Resource not found',
                ], 404);
            }

            $page = $validatedData['page'] ?? 1;
            $perPage = $validatedData['per_page'] ?? 50;
            $search = $validatedData['search'] ?? '';
            $viewMode = $validatedData['view_mode'] ?? 'edit';

            // Get GeoJSON
            $geojson = $this->getGeojsonFromSource($resource, $validatedData['geojson_source']);

            if (! $geojson || ! isset($geojson['features'])) {
                return response()->json([
                    'features' => [],
                    'pagination' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => $perPage,
                        'total' => 0,
                    ],
                ]);
            }

            $features = $geojson['features'];

            // Filter by search if provided
            if ($search) {
                $features = array_filter($features, function ($feature) use ($search) {
                    $name = $feature['properties']['name'] ?? '';
                    return stripos($name, $search) !== false;
                });
            }

            // Extract feature data for grid
            $gridData = array_map(function ($feature) {
                return [
                    'id' => $feature['properties']['model_id'] ?? $feature['properties']['id'] ?? null,
                    'name' => $feature['properties']['name'] ?? 'N/A',
                    'model_type' => $feature['properties']['model_type'] ?? null,
                ];
            }, $features);

            // Paginate
            $total = count($gridData);
            $offset = ($page - 1) * $perPage;
            $paginatedData = array_slice($gridData, $offset, $perPage);

            return response()->json([
                'features' => array_values($paginatedData),
                'pagination' => [
                    'current_page' => $page,
                    'last_page' => (int) ceil($total / $perPage),
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('FeatureCollectionGridController::getFeatures error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Errore interno del server: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync relations based on selected feature IDs
     *
     * @param  Request  $request
     * @param  string  $resourceName
     * @param  int  $resourceId
     * @return JsonResponse
     */
    public function sync(Request $request, string $resourceName, int $resourceId): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'selected_feature_ids' => 'required|array',
                'selected_feature_ids.*' => 'integer',
                'relations_to_sync' => 'required|array',
                'relations_to_sync.*' => 'string',
            ]);

            $resource = $this->findResource($resourceName, $resourceId);

            if (! $resource) {
                return response()->json([
                    'error' => 'Resource not found',
                ], 404);
            }

            $selectedFeatureIds = $validatedData['selected_feature_ids'];
            $relationsToSync = $validatedData['relations_to_sync'];

            // Get GeoJSON to map feature IDs to model types and IDs
            $geojsonSource = $request->query('geojson_source');
            if ($geojsonSource) {
                $geojson = $this->getGeojsonFromSource($resource, $geojsonSource);
                $featureMap = $this->buildFeatureMap($geojson);
            } else {
                $featureMap = [];
            }

            // Group selected IDs by model type
            $groupedIds = [];
            foreach ($selectedFeatureIds as $featureId) {
                if (isset($featureMap[$featureId])) {
                    $modelType = $featureMap[$featureId]['model_type'];
                    $modelId = $featureMap[$featureId]['model_id'];
                    if (! isset($groupedIds[$modelType])) {
                        $groupedIds[$modelType] = [];
                    }
                    $groupedIds[$modelType][] = $modelId;
                }
            }

            // Sync each relation
            foreach ($relationsToSync as $relationName) {
                if (method_exists($resource, $relationName)) {
                    // Determine model class from relation
                    $relation = $resource->{$relationName}();
                    $relatedModelClass = get_class($relation->getRelated());

                    // Get model type from class name
                    $modelType = class_basename($relatedModelClass);
                    $modelTypeKey = $this->getModelTypeKey($modelType);

                    // Get IDs for this relation (use model_id directly from selectedFeatureIds if featureMap is empty)
                    if (empty($featureMap)) {
                        // Fallback: assume selectedFeatureIds are model IDs directly
                        $idsToSync = $selectedFeatureIds;
                    } else {
                        $idsToSync = $groupedIds[$modelTypeKey] ?? [];
                    }

                    // Sync the relation
                    $resource->{$relationName}()->sync($idsToSync);
                }
            }

            return response()->json([
                'message' => 'Features sincronizzate con successo',
            ], 200);
        } catch (\Exception $e) {
            Log::error('FeatureCollectionGridController::sync error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Errore interno del server: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get widget view for the map
     *
     * @param  Request  $request
     * @param  string  $resourceName
     * @param  int  $resourceId
     * @return \Illuminate\View\View
     */
    public function widget(Request $request, string $resourceName, int $resourceId)
    {
        // Get GeoJSON source from request or use default
        $geojsonSource = $request->query('geojson_source');

        // Build GeoJSON URL
        $geojsonUrl = url("/nova-vendor/feature-collection-grid/geojson/{$resourceName}/{$resourceId}");
        if ($geojsonSource) {
            $geojsonUrl .= '?geojson_source=' . urlencode($geojsonSource);
        }

        return view('nova.fields.feature-collection-grid::feature-collection-map', [
            'geojsonUrl' => $geojsonUrl,
            'model' => $resourceName,
        ]);
    }

    /**
     * Find resource by name and ID
     *
     * @param  string  $resourceName
     * @param  int  $resourceId
     * @return mixed|null
     */
    protected function findResource(string $resourceName, int $resourceId)
    {
        // Convert kebab-case to StudlyCase
        $className = \Illuminate\Support\Str::studly($resourceName);

        // Try App\Models namespace first
        $modelClass = "\\App\\Models\\{$className}";

        if (! class_exists($modelClass)) {
            // Try Wm\WmPackage\Models namespace
            $modelClass = "\\Wm\\WmPackage\\Models\\{$className}";
        }

        if (! class_exists($modelClass)) {
            return null;
        }

        return $modelClass::find($resourceId);
    }

    /**
     * Get GeoJSON from source (method or callback)
     *
     * @param  mixed  $resource
     * @param  string|callable  $source
     * @return array|null
     */
    protected function getGeojsonFromSource($resource, $source): ?array
    {
        if (is_callable($source)) {
            return $source($resource);
        }

        if (is_string($source) && method_exists($resource, $source)) {
            return $resource->{$source}();
        }

        return null;
    }

    /**
     * Build a map of feature IDs to model type and model ID
     *
     * @param  array|null  $geojson
     * @return array
     */
    protected function buildFeatureMap(?array $geojson): array
    {
        $map = [];

        if (! $geojson || ! isset($geojson['features'])) {
            return $map;
        }

        foreach ($geojson['features'] as $feature) {
            $properties = $feature['properties'] ?? [];
            $featureId = $properties['model_id'] ?? $properties['id'] ?? null;
            $modelType = $properties['model_type'] ?? null;
            $modelId = $properties['model_id'] ?? $properties['id'] ?? null;

            if ($featureId && $modelType && $modelId) {
                $map[$featureId] = [
                    'model_type' => $this->getModelTypeKey($modelType),
                    'model_id' => $modelId,
                ];
            }
        }

        return $map;
    }

    /**
     * Get model type key from class name or type
     *
     * @param  string  $modelType
     * @return string
     */
    protected function getModelTypeKey(string $modelType): string
    {
        // Normalize model type (e.g., "UgcPoi" -> "UgcPoi", "App\Models\UgcPoi" -> "UgcPoi")
        $modelType = class_basename($modelType);

        return $modelType;
    }
}
