<?php

namespace Wm\WmPackage\Models;

use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;
use Wm\WmPackage\Services\GeoJsonService;

class Media extends SpatieMedia
{
    /**
     * Calculate the geojson of a model with only the geometry
     */
    public function getGeojson(): array
    {
        return GeoJsonService::make()->getModelAsGeojson($this);
    }
}
