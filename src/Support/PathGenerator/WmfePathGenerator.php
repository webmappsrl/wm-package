<?php

namespace Wm\WmPackage\Support\PathGenerator;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;
use Wm\WmPackage\Services\StorageService;

class WmfePathGenerator implements PathGenerator
{
    /**
     * Get the path for the given media, relative to the root storage path.
     */
    public function getPath(Media $media): string
    {
        $storageService = StorageService::make();
        $appId = $media->model->app_id ?? null;

        // Build the path following the StorageService convention
        return $storageService->getShardBasePath($appId) . 'media/' . $media->id;
    }

    /**
     * Get the path for conversions of the given media, relative to the root storage path.
     */
    public function getPathForConversions(Media $media): string
    {
        return $this->getPath($media) . '/conversions';
    }

    /**
     * Get the path for responsive images of the given media, relative to the root storage path.
     */
    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getPath($media) . '/responsive-images';
    }
}
