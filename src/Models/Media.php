<?php

namespace Wm\WmPackage\Models;

use Wm\WmPackage\Observers\UgcObserver;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

class Media extends SpatieMedia
{

    protected static function booted()
    {
        Media::observe(UgcObserver::class);
    }
}
