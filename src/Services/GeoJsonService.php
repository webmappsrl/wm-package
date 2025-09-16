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

            // Verifica che il modello abbia una geometria valida
            if (!$model->geometry || empty($model->geometry)) {
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
            //TODO: manca taxonomy where?
            $taxonomy = [
                'activity' => $taxonomyActivities,
                'theme' => $model->taxonomyThemes()->pluck('taxonomy_themes.id')->toArray(),
                'when' => $model->taxonomyWhens()->pluck('taxonomy_whens.id')->toArray(),
                'poi_type' => isset($taxonomyPoiTypes[0]) ? $taxonomyPoiTypes[0] : null,
                'poi_types' => $taxonomyPoiTypes,
            ];
            $properties['taxonomy'] = $taxonomy;

            $geom = GeometryComputationService::make()->getModelGeometryAsGeojson($model);

            if (!$geom) {
                return null;
            }

            $decodedGeom = json_decode($geom, true);

            if (!$decodedGeom) {
                return null;
            }

            return [
                'type' => 'Feature',
                // remove empty arrays from properties
                'properties' => $this->removeInvalidProperties($properties),
                'geometry' => $decodedGeom,
            ];
        } catch (\Exception $e) {
            \Log::warning("Errore nel generare GeoJSON per modello ID {$model->id}: " . $e->getMessage());
            return null;
        }
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
