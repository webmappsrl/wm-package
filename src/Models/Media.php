<?php

namespace Wm\WmPackage\Models;

use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;
use Wm\WmPackage\Observers\UgcObserver;
use Wm\WmPackage\Traits\HasPackageFactory;

class Media extends SpatieMedia
{
    use HasPackageFactory;

    protected $casts = [
        'manipulations' => 'array',
        'custom_properties' => 'array',
        'generated_conversions' => 'array',
        'responsive_images' => 'array',
    ];

    // temporary disabled for factories (at the moment we do not have author relationship setup)

    // protected static function booted()
    // {
    //     Media::observe(UgcObserver::class);
    // }

    /**
     * Calculate the geojson of a model with only the geometry
     */
    public function getGeojson(): array
    {
        return GeoJsonService::make()->getModelAsGeojson($this);
    }
}
