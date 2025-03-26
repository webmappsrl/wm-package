<?php

namespace Wm\WmPackage\Services\Import\Traits;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Services\StorageService;

trait HandlesEcMediaImport
{
    /**
     * Process the entire EC Media import
     *
     * @param array $data The original data from Geohub
     */
    public function processEcMediaImport(array $data): void
    {
        // Transform the data and get the related model info
        $transformedData = $this->transformEcMediaData($data, []);

        // Find the related model
        $relatedModel = app($transformedData['model_type'])->find($transformedData['model_id']);

        if (!$relatedModel) {
            throw new \Exception("Related model not found: {$transformedData['model_type']} with ID {$transformedData['model_id']}");
        }

        // Get the URL and prepare it
        $url = $transformedData['url'];
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $url = 'https://geohub.webmapp.it/storage/' . ltrim($url, '/');
        }

        // Add the media to the related model using Spatie Media Library
        $mediaItem = $relatedModel->addMediaFromUrl($url)
            ->withCustomProperties($transformedData['custom_properties'])
            ->toMediaCollection($relatedModel::MEDIA_COLLECTION_NAME);

        // Process thumbnails if they exist
        if (isset($data['thumbnails']) && !empty($data['thumbnails'])) {
            $thumbnails = json_decode($data['thumbnails'], true);
            foreach ($thumbnails as $size => $thumbnailUrl) {
                if (!filter_var($thumbnailUrl, FILTER_VALIDATE_URL)) {
                    $thumbnailUrl = 'https://geohub.webmapp.it/storage/' . ltrim($thumbnailUrl, '/');
                }

                // Add the thumbnail as a conversion
                $mediaItem->addMediaFromUrl($thumbnailUrl)
                    ->withCustomProperties(['size' => $size])
                    ->toMediaCollection($relatedModel::MEDIA_COLLECTION_NAME . '_' . $size);
            }
        }
    }

    /**
     * Transform media data with specific logic for handling URLs
     *
     * @param array $data The media data to transform
     */
    public function transformEcMediaData(array $data): array
    {
        // Find the related model (EcPoi, EcTrack, Layer)
        $relatedModel = $this->findAndValidateRelatedModel($data);

        if (!isset($data['url']) || empty($data['url'])) {
            throw new \Exception("No URL found for EC Media: {$data['id']}. Skipping media import.");
        }

        // Prepare custom properties
        $customProperties = [
            'geohub_id' => $data['id'],
            'geohub_synced_at' => now()->toIso8601String(),
            'name' => json_decode($data['name'], true),
            'description' => json_decode($data['description'] ?? '{}', true),
            'url' => $data['url'],
        ];

        return [
            'model_type' => $relatedModel['model_type'],
            'model_id' => $relatedModel['model_id'],
            'url' => $data['url'],
            'custom_properties' => $customProperties
        ];
    }

    /**
     * Find and validate the related model for the media
     *
     * @param array $data Original media data
     * @return array Related model data
     * @throws \Exception If no related model is found
     */
    private function findAndValidateRelatedModel(array $data): array
    {
        $relatedModel = $this->findEcMediaRelatedModel($data);

        if (!$relatedModel) {
            throw new \Exception("No related model found for EC Media: {$data['id']}. Skipping media import.");
        }

        return $relatedModel;
    }

    /**
     * Find the related model for an EC Media before importing it
     * 
     * @param array $data The original data from Geohub
     * @return array|null Array with model_type and model_id if a relation is found, null otherwise
     */
    public function findEcMediaRelatedModel(array $data): ?array
    {
        $mediaId = $data['id'];

        // Define the relationships to check
        $relations = $this->importMapping['ec_media']['relations'];

        // Check each relationship
        foreach ($relations as $relation) {
            $association = $this->dbConnection
                ->table($relation['pivot_table'])
                ->where('ec_media_id', $mediaId)
                ->first();

            if ($association) {
                $model = $relation['model']::where('properties->geohub_id', $association->{$relation['key']})->first();

                if ($model) {
                    return [
                        'model_type' => get_class($model),
                        'model_id' => $model->id,
                        'model_app_id' => $model->app_id
                    ];
                }
            }
        }

        return null;
    }

    // /**
    //  * Process all EC Media dependencies
    //  *
    //  * @param array $data The original data from Geohub
    //  * @param Model $model The imported media model
    //  */
    // public function processEcMediaDependencies(array $data, Model $model): void
    // {
    //     // Get the related model instance based on model_type and model_id
    //     $relatedModel = app($model->model_type)->find($model->model_id);

    //     if (!$relatedModel) {
    //         throw new \Exception("Related model not found: {$model->model_type} with ID {$model->model_id}");
    //     }

    //     // Get the URL from the model
    //     $url = $model->url;

    //     // If the URL is a local Geohub path, convert it to full URL
    //     if (!filter_var($url, FILTER_VALIDATE_URL)) {
    //         $url = self::GEOHUB_URL . 'storage/' . ltrim($url, '/');
    //     }

    //     try {
    //         // Add the media to the related model using Spatie Media Library
    //         $mediaItem = $relatedModel->addMediaFromUrl($url)
    //             ->withCustomProperties($model->custom_properties)
    //             ->toMediaCollection($relatedModel::MEDIA_COLLECTION_NAME);

    //         // Process thumbnails if they exist
    //         if (isset($data['thumbnails']) && !empty($data['thumbnails'])) {
    //             $thumbnails = json_decode($data['thumbnails'], true);
    //             foreach ($thumbnails as $size => $thumbnailUrl) {
    //                 if (!filter_var($thumbnailUrl, FILTER_VALIDATE_URL)) {
    //                     $thumbnailUrl = self::GEOHUB_URL . 'storage/' . ltrim($thumbnailUrl, '/');
    //                 }

    //                 // Add the thumbnail as a conversion
    //                 $mediaItem->addMediaFromUrl($thumbnailUrl)
    //                     ->withCustomProperties(['size' => $size])
    //                     ->toMediaCollection($relatedModel::MEDIA_COLLECTION_NAME . '_' . $size);
    //             }
    //         }
    //     } catch (\Exception $e) {
    //         $this->logger->error('Failed to process media: ' . $e->getMessage());
    //         throw $e;
    //     }
    // }

    /**
     * Associate media to model using Spatie Media Library
     *
     * @param Model $media The media model
     * @param Model $model The model to associate with
     */
    protected function associateMediaToModel(Model $media, Model $model): void
    {
        if (method_exists($model, 'addMedia')) {
            if (isset($media->temp_file_path) && file_exists($media->temp_file_path)) {
                $model->addMedia($media->temp_file_path)
                    ->withCustomProperties($media->custom_properties)
                    ->toMediaCollection();
            } else if (isset($media->url) && !empty($media->url)) {
                $model->addMediaFromUrl($media->url)
                    ->withCustomProperties($media->custom_properties)
                    ->toMediaCollection();
            }
        }
    }

    /**
     * Upload media file to AWS
     *
     * @param Model $model The media model with temp_file_path
     */
    protected function uploadMediaToAws(Model $model): void
    {
        if (!file_exists($model->temp_file_path)) {
            return;
        }

        try {
            $fileName = basename($model->temp_file_path);
            $storageService = \Wm\WmPackage\Services\StorageService::make();

            $url = $storageService->storeEcMediaFile(
                $model->temp_file_path,
                "{$model->id}_{$fileName}",
                $model->app_id
            );

            if ($url) {
                $model->url = $url;
                $model->save();

                @unlink($model->temp_file_path);
                unset($model->temp_file_path);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to upload media to AWS: ' . $e->getMessage());
        }
    }
}
