<?php

namespace Wm\WmPackage\Support\PathGenerator;

use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Services\StorageService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

class WmfePathGenerator implements PathGenerator
{
    /**
     * Get the path for the given media, relative to the root storage path.
     */
    public function getPath(Media $media): string
    {
        $storageService = StorageService::make();
        $appId = $this->getAppIdFromMedia($media);
        $basePath = $storageService->getShardBasePath($appId);

        return 'geohub/conf/' . $basePath . 'media/' . $media->id;
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

    /**
     * Try to extract the app_id from the media item or its related model
     * 
     * @param Media $media
     * @return int|null
     */
    protected function getAppIdFromMedia(Media $media): ?int
    {
        if (!empty($media->app_id)) {
            return $media->app_id;
        }

        // Try to get app_id as a property of the model
        if ($media->model && isset($media->model->app_id)) {
            return $media->model->app_id;
        }

        return null;
    }
}
