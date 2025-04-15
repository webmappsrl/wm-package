<?php

namespace Wm\WmPackage\Services\Import;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

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

            $wktGeometry = DB::selectOne("SELECT ST_AsText($sqlGeometry) as geom")->geom;

            $transformedData['custom_properties']['geometry'] = $wktGeometry;
        } catch (\Exception $e) {
            // If conversion fails, store an empty object to avoid array to string conversion errors
            $transformedData['custom_properties']['geometry'] = '{}';
        }

        $relatedModel = $transformedData['model_type']::find($transformedData['model_id']);

        if (! $relatedModel) {
            throw new \Exception("Related model not found: {$transformedData['model_type']} with ID {$transformedData['model_id']}");
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

        // Check if media with the same geohub_id already exists in the collection
        $existingMedia = $relatedModel->getMedia('default')
            // the "." instead of "->" is needed because here we are in a MediaCollection
            ->where('custom_properties.geohub_id', $transformedData['custom_properties']['geohub_id'])
            ->first();

        if ($existingMedia) {
            $existingMedia->update([
                'custom_properties' => $transformedData['custom_properties'],
                'order_column' => $transformedData['order_column'] ?? $existingMedia->order_column,
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

        // Remove geometry from custom properties and update the media item
        $customProperties = $mediaItem->custom_properties;
        unset($customProperties['geometry']);
        $mediaItem->updateQuietly([
            'custom_properties' => $customProperties,
            'order_column' => $transformedData['order_column'] ?? $mediaItem->order_column,
        ]);
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
        //TODO: this should be a while
        foreach ($relations as $relatedTableName => $relation) {
            $relatedId = null;

            //check if there is a relation on the pivot table
            $pivotRelation = $this->dbConnection
                ->table($relation['pivot_table'])
                ->where('ec_media_id', $mediaId)
                ->first();
            if ($pivotRelation) {
                $relatedId = $pivotRelation->{$relation['key']};
            }

            try {
                // check if the media is a feature image
                $featuredImageModel = $this->dbConnection
                    ->table($relatedTableName);

                if ($relatedId)
                    $featuredImageModel->where('id', $relatedId);

                $featuredImageModel = $featuredImageModel->where('feature_image', $mediaId)->first();
                //now we now that this media is a feature image

                //this is needed because on geohub sometimes the relation between model and media is only on feature_image column
                // some times instead is in the pivot table ... awesome!
                if ($featuredImageModel) {
                    $relatedId = $featuredImageModel->id;
                }
            } catch (\Exception $e) {
                // If the related table does not have a properties column, skip the check
                $featuredImageModel = false;
            }



            $model = $relation['model']::where('properties->geohub_id', $relatedId)->first();

            if ($model) {
                $data = [
                    'model_type' => get_class($model),
                    'model_id' => $model->id,
                    'model_app_id' => $model->app_id,
                ];
                if ($featuredImageModel) {
                    // https://spatie.be/docs/laravel-medialibrary/v11/advanced-usage/ordering-media
                    // the feature image will be the first media
                    $data['order_column'] = 0;
                }
                return $data;
            }
        }

        return null;
    }
}
