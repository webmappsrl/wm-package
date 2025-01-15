<?php

namespace Wm\WmPackage\Services;

use Illuminate\Support\Facades\Storage;

class CloudStorageService extends BaseService
{


    public function storeTrack($path, $contents)
    {
        return Storage::disk('wmfetracks')->put($path, $contents);
    }
}
