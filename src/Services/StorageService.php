<?php

namespace Wm\WmPackage\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

class StorageService extends BaseService
{
    public function storeTrack(int $trackId, $contents): string|false
    {
        $path = "{$trackId}.json";

        return $this->getWmFeTracksDisk()->put($path, $contents) ? $path : false;
    }

    public function storePBF(int $appId, string $z, string $x, string $y, $pbfContent): string|false
    {
        $path = "{$appId}/{$z}/{$x}/{$y}.pbf";

        return $this->getPbfDisk()->put($path, $pbfContent) ? $path : false;
    }

    public function storeAppConfig(int $appId, string $contents): string|false
    {
        $path = "{$appId}.json";
        $a = $this->getRemoteAppConfigDisk()->put($path, $contents);
        $b = $this->getLocalAppConfigDisk()->put($path, $contents);

        return $a && $b ? $path : false;
    }

    public function storePois(int $appId, string $contents): string|false
    {
        $path = "{$appId}.geojson";
        $a = $this->getRemotePoisDisk()->put($path, $contents);
        $b = $this->getLocalPoisDisk()->put($path, $contents);

        return $a && $b ? $path : false;
    }

    public function storeAppQrCode(int $appId, string $svg): string|false
    {
        $path = "qrcode/{$appId}/webapp-qrcode.svg";

        return $this->getPublicDisk()->put($path, $svg) ? $path : false;
    }

    //
    // PRIVATE GETTERS
    //
    private function getLocalPoisDisk(): Filesystem
    {
        return $this->getDisk('pois');
    }

    private function getRemotePoisDisk(): Filesystem
    {
        return $this->getDisk('wmfepois');
    }

    private function getLocalAppConfigDisk(): Filesystem
    {
        return $this->getDisk('conf');
    }

    private function getRemoteAppConfigDisk(): Filesystem
    {
        return $this->getDisk('wmfeconf');
    }

    private function getPbfDisk(): Filesystem
    {
        return $this->getDisk('s3-wmpbf');
    }

    private function getWmFeTracksDisk(): Filesystem
    {
        return $this->getDisk('wmfetracks');
    }

    private function getEcMediaDisk(): Filesystem
    {
        return $this->getDisk('s3');
    }

    private function getPublicDisk(): Filesystem
    {
        return $this->getDisk('public');
    }

    private function getLocalDisk(): Filesystem
    {
        return $this->getDisk('local');
    }

    private function getDisk($disk): Filesystem
    {
        return Storage::disk($disk);
    }
}
