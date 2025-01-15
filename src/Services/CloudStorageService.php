<?php

namespace Wm\WmPackage\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

class CloudStorageService extends BaseService
{
    public function storeTrack($path, $contents): bool
    {
        return $this->getWmFeTracksDisk()->put($path, $contents);
    }

    /**
     * Memorizza il PBF generato.
     */
    public function storePBF(string $relativePath, $pbfContent)
    {
        return $this->getDisk('s3-wmpbf')->put($relativePath, $pbfContent);
    }

    //
    // GETTERS
    //

    public function getWmFeTracksDisk(): Filesystem
    {
        return $this->getDisk('wmfetracks');
    }

    public function getEcMediaDisk(): Filesystem
    {
        return $this->getDisk('s3');
    }

    public function getLocalDisk(): Filesystem
    {
        return $this->getDisk('local');
    }

    protected function getDisk($disk): Filesystem
    {
        return Storage::disk($disk);
    }
}
