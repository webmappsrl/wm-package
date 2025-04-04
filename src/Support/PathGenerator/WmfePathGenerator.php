<?php

namespace Wm\WmPackage\Support\PathGenerator;

use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;
use Wm\WmPackage\Services\StorageService;

class WmfePathGenerator extends DefaultPathGenerator implements PathGenerator
{
    /**
     * Get the path for the given media, relative to the root storage path.
     */
    public function getPath(Media $media): string
    {
        $storageService = StorageService::make();

        $basePath = $storageService->getShardBasePath($media->app_id);

        return $basePath . 'media/' . $this->getBasePath($media) . '/';
    }
}
