<?php

namespace Wm\WmPackage\Models;

use Wm\WmPackage\Observers\UgcObserver;
use Wm\WmPackage\Services\GeoJsonService;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

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
