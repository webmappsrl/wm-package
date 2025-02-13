<?php

namespace Wm\WmPackage\Services;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Models\Abstracts\GeometryModel;

class GeoJsonService extends BaseService
{

    public function getModelAsGeojson(Model $model)
    {
        $properties = $model->properties ?? [];
        $geom = GeometryComputationService::make()->getModelGeometryAsGeojson($model);

        $decodedGeom = isset($geom) ? json_decode($geom, true) : null;

        return [
            'type' => 'Feature',
            'properties' => $properties,
            'geometry' => $decodedGeom,
        ];
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
