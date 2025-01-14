<?php

namespace Wm\WmPackage\Models\Abstracts;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Services\GeometryComputationService;

abstract class GeometryModel extends Model
{
    //
    // FROM GEOHUB App\Traits\GeometryFeatureTrait
    //

    /**
     * Calculate the geojson of a model with only the geometry
     */
    public function getEmptyGeojson(): ?array
    {
        $properties = $this->properties ?? [];
        $geom = GeometryComputationService::make()->getModelGeometryAsGeojson($this);

        if (isset($geom)) {
            return [
                'type' => 'Feature',
                'properties' => $properties,
                'geometry' => json_decode($geom, true),
            ];
        } else {
            return [
                'type' => 'Feature',
                'properties' => $properties,
                'geometry' => null,
            ];
        }
    }

    /**
     * Calculate the kml on a model with geometry
     */
    public function getKml(): ?string
    {
        return GeometryComputationService::make()->getModelGeometryAsKml($this);
    }

    /**
     * Calculate the gpx on a model with geometry
     *
     * @return mixed|null
     */
    public function getGpx()
    {
        return GeometryComputationService::make()->getModelGeometryAsGpx($this);
    }

    /**
     * Return a feature collection with the related UGC features
     */
    public function getRelatedUgcGeojson(): array
    {
        return GeometryComputationService::make()->getRelatedUgcGeojson($this);
    }
}
