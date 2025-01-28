<?php

namespace Wm\WmPackage\Services;

use Exception;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\EcMedia;

class StorageService extends BaseService
{
    public function storeTrack(int $trackId, $contents): string|false
    {
        $path = $this->getTrackPath($trackId);

        return $this->getWmFeTracksDisk()->put($path, $contents) ? $path : false;
    }

    public function getTrackGeojson(int $trackId): ?string
    {

        return $this->getWmFeTracksDisk()->get($this->getTrackPath($trackId));
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
        $path = $this->getPoisPath($appId);
        $a = $this->getRemotePoisDisk()->put($path, $contents);
        $b = $this->getLocalPoisDisk()->put($path, $contents);

        return $a && $b ? $path : false;
    }

    public function getPoisGeojson(int $appId): string|false
    {
        $path = $this->getPoisPath($appId);

        return $this->getRemotePoisDisk()->get($path) ?? $this->getLocalPoisDisk()->get($path);
    }

    public function storeAppQrCode(int $appId, string $svg): string|false
    {
        $path = "qrcode/{$appId}/webapp-qrcode.svg";

        return $this->getPublicDisk()->put($path, $svg) ? $path : false;
    }

    /**
     * Upload an existing image to the s3 bucket
     * Copied (and updated) from geomixer
     *
     * @param  string  $imagePath  the path of the image to upload
     * @return string the uploaded image url
     *
     * @throws Exception
     */
    public function storeEcMediaImage(string $imagePath): string
    {
        if (! file_exists($imagePath)) {
            throw new Exception("The image $imagePath does not exists");
        }

        $filename = pathinfo($imagePath)['filename'].'.'.pathinfo($imagePath)['extension'];

        $path = 'EcMedia/'.$filename;

        $disk = $this->getEcMediaDisk();
        $disk->put($path, file_get_contents($imagePath));

        return $disk->url($path);
    }

    public function storeLocalEcMediaImage(EcMedia $ecMedia): bool
    {
        return $this->getPublicDisk()->put($ecMedia->path, file_get_contents($ecMedia->url));
    }

    public function getLocalEcMediaImagePath(EcMedia $ecMedia): string
    {
        return $this->getPublicDisk()->path($ecMedia->path);
    }

    public function getLocalImageUrl(string $relativePath): string|false
    {
        if (! $this->getPublicDisk()->exists($relativePath)) {
            return false;
        }

        return $this->getPublicDisk()->url($relativePath);
    }

    public function deleteLocalEcMediaImage(EcMedia $ecMedia): bool
    {
        return $this->getPublicDisk()->delete($ecMedia->path);
    }

    /**
     * Upload an already resized image to the s3 bucket
     *
     * @param  string  $imagePath  the resized image
     * @param  int  $width  the image width
     * @param  int  $height  the image height
     * @return string the uploaded image url
     *
     * @throws Exception
     */
    public function storeEcMediaImageResize(string $imagePath, int $width, int $height): string
    {

        if (! file_exists($imagePath)) {
            throw new Exception("The image $imagePath does not exists");
        }

        $filename = basename($imagePath);
        if ($width == 0) {
            $cloudPath = 'EcMedia/Resize/x'.$height.DIRECTORY_SEPARATOR.$filename;
        } elseif ($height == 0) {
            $cloudPath = 'EcMedia/Resize/'.$width.'x'.DIRECTORY_SEPARATOR.$filename;
        } else {
            $cloudPath = 'EcMedia/Resize/'.$width.'x'.$height.DIRECTORY_SEPARATOR.$filename;
        }

        $this->getEcMediaDisk()->put($cloudPath, file_get_contents($imagePath));

        return $this->getEcMediaDisk()->url($cloudPath);
    }

    //
    // PATHS
    // TODO: move all paths here
    //
    private function getPoisPath(int $appId)
    {
        return "{$appId}.geojson";
    }

    private function getTrackPath(int $trackId)
    {
        return "{$trackId}.json";
    }

    //
    // PUBLIC GETTERS
    //

    public function getPublicPath(string $path): string
    {
        return $this->getPublicDisk()->path($path);
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
