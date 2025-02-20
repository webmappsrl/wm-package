<?php

namespace Wm\WmPackage\Models;

use Wm\WmPackage\Observers\UgcObserver;
use Wm\WmPackage\Services\GeoJsonService;
use Wm\WmPackage\Traits\OwnedByUserModel;
use Wm\WmPackage\Traits\HasPackageFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;
use Wm\WmPackage\Observers\MediaObserver;

class Media extends SpatieMedia
{
    use HasPackageFactory, OwnedByUserModel;

    protected $casts = [
        'manipulations' => 'array',
        'custom_properties' => 'array',
        'generated_conversions' => 'array',
        'responsive_images' => 'array',
    ];

    protected static function booted()
    {
        Media::observe(MediaObserver::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Calculate the geojson of a model with only the geometry
     */
    public function getGeojson(): array
    {
        return GeoJsonService::make()->getModelAsGeojson($this);
    }
}
