<?php

namespace Wm\WmPackage\Services;

use Illuminate\Support\Facades\Storage;

class CloudStorageService extends BaseService
{


    public function storeTrack($where, $what)
    {
        return Storage::disk('wmfetracks')->put($where, $what);
    }
}
