<?php

namespace Wm\WmPackage\Services;

use Illuminate\Database\Eloquent\Model;

class GeoJsonService extends BaseService
{
    public function getModelAsGeojson(Model $model)
    {
        try {
            $properties = is_array($model->properties) ? $model->properties : [];
            $properties['id'] = $model->id;

            // Merge accessibility columns (if present) into GeoJSON properties.
            // and exposed flatly in `properties`.
            $properties = [
                ...$properties,
            ];

            // Verifica che il modello abbia una geometria valida
            if (! $model->geometry || empty($model->geometry)) {
                return null;
            }

            // Aggiungo le tassonomie se presenti
            $taxonomyPoiTypes = [];
            if ($model->taxonomyPoiTypes) {
                foreach ($model->taxonomyPoiTypes as $taxonomyPoiType) {
                    $taxonomyPoiTypes[] = $taxonomyPoiType->getJson();
                }
            }
            $taxonomyActivities = [];
            if ($model->taxonomyActivities) {
                foreach ($model->taxonomyActivities as $taxonomyActivity) {
                    $taxonomyActivities[] = $taxonomyActivity->getJson();
                }
            }
            $taxonomyWhens = [];
            if ($model->taxonomyWhens) {
                $taxonomyWhens = $model->taxonomyWhens()->pluck('taxonomy_whens.id')->toArray();
            }

            $taxonomy = [
                'activity' => $taxonomyActivities,
                'when' => $taxonomyWhens,
                'poi_type' => isset($taxonomyPoiTypes[0]) ? $taxonomyPoiTypes[0] : null,
                'poi_types' => $taxonomyPoiTypes,
            ];
            $properties['taxonomy'] = $taxonomy;

            if (method_exists($model, 'getOrderedTaxonomyWheres')) {
                $properties['taxonomyWheres'] = $model->getOrderedTaxonomyWheres();
            }

            // Rimuovo da properties campi non desiderati nel geojson
            unset($properties['media']);

            // Aggiungo la feature_image se presente
            $firstMedia = $model->getMedia()->first();
            if ($firstMedia) {
                $properties['feature_image'] = [
                    'id' => $firstMedia->id,
                    'name' => $firstMedia->custom_properties['name'] ?? ['it' => $firstMedia->name],
                    'url' => $firstMedia->getUrl(),
                    'caption' => $firstMedia->custom_properties['caption'] ?? null,
                    'thumbnail' => $firstMedia->getUrl('thumbnail_400_200'),
                    'api_url' => route('default.api.media.geojson', $firstMedia->id),
                ];
            }

            $allMedia = $model->getMedia();
            if ($allMedia) {
                $imageGallery = [];
                foreach ($allMedia as $media) {
                    $imageGallery[] = [
                        'id' => $media->id,
                        'name' => $media->custom_properties['name'] ?? ['it' => $media->name],
                        'url' => $media->getUrl(),
                        'caption' => $media->custom_properties['caption'] ?? null,
                        'thumbnail' => $media->getUrl('thumbnail_400_200'),
                        'api_url' => route('default.api.media.geojson', $media->id),
                    ];
                }
                $properties['image_gallery'] = $imageGallery;
            }

            $geom = GeometryComputationService::make()->getModelGeometryAsGeojson($model);

            if (! $geom) {
                return null;
            }

            $decodedGeom = json_decode($geom, true);

            if (! $decodedGeom) {
                return null;
            }
            $properties['created_at'] = $model->created_at;
            $properties['updated_at'] = $model->updated_at;

            return [
                'type' => 'Feature',
                // remove empty arrays from properties
                'properties' => $this->removeInvalidProperties($properties),
                'geometry' => $decodedGeom,
            ];
        } catch (\Exception $e) {
            \Log::warning("Errore nel generare GeoJSON per modello ID {$model->id}: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Wrap a single GeoJSON Feature into a FeatureCollection.
     *
     * If the feature is null/falsy, returns an empty FeatureCollection.
     */
    public function wrapAsFeatureCollection(?array $feature): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => $feature ? [$feature] : [],
        ];
    }

    /**
     * Crea una FeatureCollection a partire da una lista/collection di modelli che espongono getGeojson().
     *
     * @param  iterable<int, object>  $models
     * @return array{type: string, features: array<int, array<string, mixed>>}
     */
    public function modelsToFeatureCollection(iterable $models): array
    {
        $features = [];

        foreach ($models as $model) {
            if (! is_object($model) || ! method_exists($model, 'getGeojson')) {
                continue;
            }

            $feature = $model->getGeojson();
            if (is_array($feature)) {
                $features[] = $feature;
            }
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }

    public function removeInvalidProperties(array $properties): array
    {
        return array_filter($properties, fn ($e) => ! is_array($e)
            || count(array_filter($e)) !== 0);
    }

    public function isGeojson($string)
    {
        $gj = json_decode($string);

        return isset($gj->type);
    }

    public function isGeojsonFeature($string)
    {
        $gj = json_decode($string);
        if (isset($gj->type) && $gj->type == 'Feature') {
            return true;
        }

        return false;
    }

    public function isGeojsonFeatureCollection($string)
    {
        $gj = json_decode($string);
        if (isset($gj->type) && $gj->type == 'FeatureCollection') {
            return true;
        }

        return false;
    }

    public function convertCollectionToFirstFeature($string)
    {
        if (self::isGeojsonFeature($string)) {
            return $string;
        } elseif (self::isGeojsonFeatureCollection($string)) {
            $gj = json_decode($string);
            if (isset($gj->features) && is_array($gj->features) && count($gj->features) > 0) {
                return json_encode($gj->features[0]);
            }
        }
    }

    public function isGeojsonFeaturePolygon($string)
    {
        $gj = json_decode($string);
        if (
            isset($gj->type) &&
            $gj->type == 'Feature' &&
            isset($gj->geometry) &&
            isset($gj->geometry->type) &&
            $gj->geometry->type == 'Polygon'
        ) {
            return true;
        }

        return false;
    }

    public function isGeojsonFeatureMultiPolygon($string)
    {
        $gj = json_decode($string);
        if (
            isset($gj->type) &&
            $gj->type == 'Feature' &&
            isset($gj->geometry) &&
            isset($gj->geometry->type) &&
            $gj->geometry->type == 'MultiPolygon'
        ) {
            return true;
        }

        return false;
    }

    public function convertPolygonToMultiPolygon($string)
    {
        if (self::isGeojsonFeatureMultiPolygon($string)) {
            return $string;
        } elseif (self::isGeojsonFeaturePolygon($string)) {
            $gj = json_decode($string);
            $new_coords = [$gj->geometry->coordinates];
            $gj->geometry->type = 'MultiPolygon';
            $gj->geometry->coordinates = $new_coords;

            return json_encode($gj);
        }
    }
}
