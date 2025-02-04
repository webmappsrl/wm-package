<?php

namespace Wm\WmPackage\Models;

use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;
use Wm\WmPackage\Observers\UgcObserver;

class Media extends SpatieMedia
{
    protected static function booted()
    {
        Media::observe(UgcObserver::class);
    }
}
