<?php

namespace Wm\WmPackage\Services\Import;

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
     * @param  string  $geometry  The geometry of the related model
     */
    public function processEcMediaImport(array $data, string $geometry): void
    {
        $transformedData = $this->transformEcMediaData($data);
        $transformedData['custom_properties']['geometry'] = $geometry;

        $relatedModel = $transformedData['model_type']::find($transformedData['model_id']);

        if (! $relatedModel) {
            throw new \Exception("Related model not found: {$transformedData['model_type']} with ID {$transformedData['model_id']}");
        }

        // Get the URL and prepare it
        $url = $transformedData['url'];
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            $url = 'https://geohub.webmapp.it/storage/'.ltrim($url, '/');

            // validate if the url returns an image content type
            $contentType = get_headers($url, 1)[0];
            if (strpos($contentType, 'image') === false) {
                throw new \Exception("The URL {$url} does not return an image content type. Skipping media import.");
            }
        }

        // Check if media with the same geohub_id already exists in the collection
        $existingMedia = $relatedModel->getMedia('default')
            ->where('custom_properties->geohub_id', $transformedData['custom_properties']['geohub_id'])
            ->first();

        if ($existingMedia) {
            unset($transformedData['custom_properties']['geometry']);
            $existingMedia->update([
                'custom_properties' => $transformedData['custom_properties'],
            ]);

            return; // Skip adding new media since we updated the existing one
        }

        $nameJson = json_decode($data['name'], true);
        $fileName = is_array($nameJson) ? ($nameJson['it'] ?? reset($nameJson)) : $data['name'];

        $fileName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $fileName);

        $mediaItem = $relatedModel->addMediaFromUrl($url)
            ->usingName($fileName)
            ->usingFileName($fileName)
            ->withCustomProperties($transformedData['custom_properties'])
            ->toMediaCollection('default', config('wm-media-library.disk_name'));

        unset($mediaItem->custom_properties['geometry']);
    }

    /**
     * Transform media data with specific logic for handling URLs
     *
     * @param  array  $data  The media data to transform
     */
    public function transformEcMediaData(array $data): array
    {
        // Find the related model (EcPoi, EcTrack, Layer)
        $relatedModel = $this->findAndValidateRelatedModel($data);

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
            'model_type' => $relatedModel['model_type'],
            'model_id' => $relatedModel['model_id'],
            'url' => $data['url'],
            'custom_properties' => $customProperties,
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
    private function findAndValidateRelatedModel(array $data): array
    {
        $relatedModel = $this->findEcMediaRelatedModel($data);

        if (! $relatedModel) {
            throw new \Exception("No related model found for EC Media: {$data['id']}. Skipping media import.");
        }

        return $relatedModel;
    }

    /**
     * Find the related model for an EC Media before importing it
     *
     * @param  array  $data  The original data from Geohub
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
                        'model_app_id' => $model->app_id,
                    ];
                }
            }
        }

        return null;
    }
}
