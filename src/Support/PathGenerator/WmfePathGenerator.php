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
    protected function getBasePath(Media $media): string
    {
        $storageService = StorageService::make();

        $shardPrefix = $storageService->getShardBasePath($media->app_id);

        return $shardPrefix.'media/'.parent::getBasePath($media);
    }
}
