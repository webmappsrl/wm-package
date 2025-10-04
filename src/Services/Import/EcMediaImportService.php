<?php

namespace Wm\WmPackage\Services\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\Media;

class EcMediaImportService extends GeohubImportService
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Process the entire EC Media import
     *
     * @param  array  $data  The original data from Geohub
     * @param  Expression  $geometry  The geometry of the related model
     */
    public function processEcMediaImport(array $data, Expression $geometry): void
    {
        $transformedData = $this->transformEcMediaData($data);

        // Convert Expression to WKT before assigning to custom properties
        try {
            $sqlGeometry = $geometry->getValue(DB::getQueryGrammar());

            $wktGeometry = DB::selectOne("SELECT ST_AsText(ST_Force3D($sqlGeometry)) as geom")->geom;

            $transformedData['custom_properties']['geometry'] = $wktGeometry;
        } catch (\Exception $e) {
            // If conversion fails, store an empty object to avoid array to string conversion errors
            $transformedData['custom_properties']['geometry'] = '{}';
        }

        // Get the URL and prepare it
        $url = $transformedData['url'];
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            $url = config('wm-package.clients.geohub.host') . '/storage/' . ltrim($url, '/');

            // validate if the url returns an image content type
            $contentType = get_headers($url, 1)[0];
            if (strpos($contentType, 'image') === false) {
                throw new \Exception("The URL {$url} does not return an image content type. Skipping media import.");
            }
        }

        $nameJson = json_decode($data['name'], true);
        $fileName = is_array($nameJson) ? ($nameJson['it'] ?? reset($nameJson)) : $data['name'];

        $fileName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $fileName);

        foreach ($transformedData['models'] as $model) {
            $relatedModel = $model['model_type']::find($model['model_id']);
            if (! $relatedModel) {
                throw new \Exception("Related model not found: {$model['model_type']} with ID {$model['model_id']}");
            }

            // Check if media with the same geohub_id already exists in the collection
            $existingMedia = $relatedModel->getMedia('default')
                // the "." instead of "->" is needed because here we are in a MediaCollection
                ->where('custom_properties.geohub_id', $transformedData['custom_properties']['geohub_id'])
                ->first();

            if ($existingMedia) {
                if (! $this->mediaIsAlreadyUpToDate($existingMedia, $transformedData)) {
                    $existingMedia->updateQuietly([
                        'custom_properties' => $transformedData['custom_properties'],
                        'order_column' => $model['order_column'] ?? $existingMedia->order_column,
                    ]);
                }

                continue; // Skip adding new media since we updated the existing one
            }

            $mediaItem = $relatedModel->addMediaFromUrl($url)
                ->usingName($fileName)
                ->usingFileName($fileName)
                ->withCustomProperties($transformedData['custom_properties'])
                ->toMediaCollection('default', config('wm-media-library.disk_name'));

            // Remove geometry from custom properties and update the media item
            $customProperties = $mediaItem->custom_properties;

            $mediaItem->updateQuietly([
                'custom_properties' => $customProperties,
                'order_column' => $model['order_column'] ?? $mediaItem->order_column,
            ]);
        }
    }

    private function mediaIsAlreadyUpToDate(Media $media, array $transformedData): bool
    {
        // https://www.php.net/manual/en/language.operators.array.php
        return $media->custom_properties == $transformedData['custom_properties']
            && $media->order_column == $transformedData['order_column'];
    }

    /**
     * Transform media data with specific logic for handling URLs
     *
     * @param  array  $data  The media data to transform
     */
    public function transformEcMediaData(array $data): array
    {
        // Find the related model (EcPoi, EcTrack, Layer)
        $relatedModels = $this->findAndValidateRelatedModels($data);

        if (! isset($data['url']) || empty($data['url'])) {
            throw new \Exception("No URL found for EC Media: {$data['id']}. Skipping media import.");
        }

        // Prepare custom properties
        $customProperties = [
            'geohub_id' => $data['id'],
            'geohub_synced_at' => now()->toIso8601String(),
            'name' => json_decode($data['name'], true),
            'description' => json_decode($data['description'] ?? '{}', true),
            'rank' => $data['rank'],
        ];

        return [
            'models' => $relatedModels,
            'url' => $data['url'],
            'custom_properties' => $customProperties,
            'order_column' => $relatedModel['order_column'] ?? null,
        ];
    }

    /**
     * Find and validate the related model for the media
     *
     * @param  array  $data  Original media data
     * @return array Related model data
     *
     * @throws \Exception If no related model is found
     */
    private function findAndValidateRelatedModels(array $data): array
    {
        $relatedModels = $this->findEcMediaRelatedModels($data);

        if (empty($relatedModels)) {
            throw new \Exception("No related model found for EC Media: {$data['id']}. Skipping media import.");
        }

        return $relatedModels;
    }

    /**
     * Find the related model for an EC Media before importing it
     *
     * @param  array  $data  The original data from Geohub
     * @return array|null Array with model_type and model_id if a relation is found, null otherwise
     */
    public function findEcMediaRelatedModels(array $data): array
    {
        $mediaId = $data['id'];
        $models = [];

        // Define the relationships to check
        $relations = $this->importMapping['ec_media']['relations'];

        // Check each relationship
        // TODO: this should be a while
        foreach ($relations as $relatedTableName => $relation) {
            $featuredImageModels = collect(); // Initialize as empty collection
            try {
                // check if the media is a feature image
                $featuredImageModels = $this->dbConnection
                    ->table($relatedTableName)
                    ->where('feature_image', $mediaId)
                    ->get();
            } catch (\Exception $e) {
                // If the related table does not have a properties column, skip the check
                $featuredImageModels = collect(); // Ensure it's still a collection
            }

            // handle features image
            $featureImagedModelsIds = [];
            foreach ($featuredImageModels as $featuredImageModel) {
                $relatedId = $featuredImageModel->id;
                $model = $relation['model']::where('properties->geohub_id', $relatedId)->first();
                if ($model && $model instanceof Model) {
                    $models[] = $this->getImportMediaData($model, true);
                    $featureImagedModelsIds[] = $model->id;
                } else {
                    // Log when model is not found for debugging
                    \Log::warning("Model not found for featured image", [
                        'related_id' => $relatedId,
                        'model_class' => $relation['model'],
                        'media_id' => $mediaId
                    ]);
                }
            }

            // handle other relations
            $pivotRelation = $this->dbConnection
                ->table($relation['pivot_table'])
                ->where('ec_media_id', $mediaId)
                ->get();

            foreach ($pivotRelation as $pivot) {
                $relatedId = $pivot->{$relation['key']};
                $model = $relation['model']::where('properties->geohub_id', $relatedId)->first();
                if ($model && $model instanceof Model) {
                    // dont import media in gallery if they already are feature image
                    if (! in_array($model->id, $featureImagedModelsIds)) {
                        $models[] = $this->getImportMediaData($model, false);
                    }
                } else {
                    // Log when model is not found for debugging
                    \Log::warning("Model not found for pivot relation", [
                        'related_id' => $relatedId,
                        'model_class' => $relation['model'],
                        'media_id' => $mediaId,
                        'pivot_key' => $relation['key']
                    ]);
                }
            }
        }

        return $models;
    }

    private function getImportMediaData(Model $model, bool $featuredImageModel = false): array
    {
        // Additional safety check to prevent null values
        if ($model === null) {
            throw new \InvalidArgumentException('Model cannot be null in getImportMediaData method');
        }

        $data = [
            'model_type' => get_class($model),
            'model_id' => $model->id,
        ];
        if ($featuredImageModel) {
            // https://spatie.be/docs/laravel-medialibrary/v11/advanced-usage/ordering-media
            // the feature image will be the first media
            $data['order_column'] = 0;
        }

        return $data;
    }
}
