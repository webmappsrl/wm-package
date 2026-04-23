<?php

namespace Wm\WmPackage\Services;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StorageService extends BaseService
{
    public function storeTrack(int $trackId, $contents): string|false
    {
        $path = $this->getTrackPath($trackId);

        return $this->getRemoteWfeDisk()->put($path, $contents) ? $path : false;
    }

    public function storeIcons(string $contents): string|false
    {
        $path = $this->getIconsPath();

        return $this->getRemoteWfeDisk()->put($path, $contents) ? $path : false;
    }

    public function storeAppIcons(int $appId, string $contents): string|false
    {
        $path = $this->getAppIconsPath($appId);

        return $this->getRemoteWfeDisk()->put($path, $contents) ? $path : false;
    }

    public function getTrackGeojson(int $trackId, int $appId): ?string
    {
        return $this->getRemoteWfeDisk()->get($this->getTrackPath($trackId));
    }

    public function storePBF(int $appId, string $z, string $x, string $y, $pbfContent): string|false
    {
        $path = $this->getShardBasePath($appId) . "pbf/{$z}/{$x}/{$y}.pbf";

        return $this->getRemoteWfeDisk()->put($path, $pbfContent) ? $path : false;
    }

    public function storeAppConfig(int $appId, string $contents): string|false
    {
        $path = $this->getAppConfigPath($appId);
        $a = $this->getRemoteWfeDisk()->put($path, $contents);
        $b = $this->getLocalAppConfigDisk()->put($path, $contents);

        return $a && $b ? $path : false;
    }

    public function deleteTrack(int $trackId): bool
    {
        $path = $this->getTrackPath($trackId);

        if ($this->getRemoteWfeDisk()->exists($path)) {
            return $this->getRemoteWfeDisk()->delete($path);
        }

        return true;
    }

    public function getAppConfigJson(int $appId): ?string
    {
        $path = $this->getAppConfigPath($appId);

        return $this->getRemoteWfeDisk()->get($path) ?? $this->getLocalAppConfigDisk()->get($path);
    }

    public function storePois(int $appId, string $contents): string|false
    {
        $path = $this->getPoisPath($appId);
        $a = $this->getRemoteWfeDisk()->put($path, $contents);
        $b = $this->getLocalPoisDisk()->put($path, $contents);

        return $a && $b ? $path : false;
    }

    public function getPoisGeojson(int $appId): ?string
    {
        $path = $this->getPoisPath($appId);

        return $this->getRemoteWfeDisk()->get($path) ?? $this->getLocalPoisDisk()->get($path);
    }

    public function storeAppQrCode(int $appId, string $svg): string|false
    {
        $path = $this->getShardBasePath($appId) . 'qrcode/webapp-qrcode.svg';

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
    // public function storeEcMediaImage(string $imagePath): string
    // {
    //     if (! file_exists($imagePath)) {
    //         throw new Exception("The image $imagePath does not exists");
    //     }

    //     $filename = pathinfo($imagePath)['filename'].'.'.pathinfo($imagePath)['extension'];

    //     $path = 'EcMedia/'.$filename;

    //     $disk = $this->getEcMediaDisk();
    //     $disk->put($path, file_get_contents($imagePath));

    //     return $disk->url($path);
    // }

    // public function storeLocalEcMediaImage(EcMedia $ecMedia): bool
    // {
    //     return $this->getPublicDisk()->put($ecMedia->path, file_get_contents($ecMedia->url));
    // }

    // public function getLocalEcMediaImagePath(EcMedia $ecMedia): string
    // {
    //     return $this->getPublicDisk()->path($ecMedia->path);
    // }

    // public function getLocalImageUrl(string $relativePath): string|false
    // {
    //     if (! $this->getPublicDisk()->exists($relativePath)) {
    //         return false;
    //     }

    //     return $this->getPublicDisk()->url($relativePath);
    // }

    // public function deleteLocalEcMediaImage(EcMedia $ecMedia): bool
    // {
    //     return $this->getPublicDisk()->delete($ecMedia->path);
    // }

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
    // public function storeEcMediaImageResize(string $imagePath, int $width, int $height): string
    // {

    //     if (! file_exists($imagePath)) {
    //         throw new Exception("The image $imagePath does not exists");
    //     }

    //     $filename = basename($imagePath);
    //     if ($width == 0) {
    //         $cloudPath = 'EcMedia/Resize/x'.$height.DIRECTORY_SEPARATOR.$filename;
    //     } elseif ($height == 0) {
    //         $cloudPath = 'EcMedia/Resize/'.$width.'x'.DIRECTORY_SEPARATOR.$filename;
    //     } else {
    //         $cloudPath = 'EcMedia/Resize/'.$width.'x'.$height.DIRECTORY_SEPARATOR.$filename;
    //     }

    //     $this->getEcMediaDisk()->put($cloudPath, file_get_contents($imagePath));

    //     return $this->getEcMediaDisk()->url($cloudPath);
    // }

    public function storeFeatureCollection(int $appId, int $featureCollectionId, string $contents): string|false
    {
        try {
            $path = $this->getShardBasePath($appId) . "feature-collection/{$featureCollectionId}.geojson";

            $success = $this->getRemoteWfeDisk()->put($path, $contents);

            if ($success) {
                return $path;
            }

            return false;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to store feature collection: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Store a model file on wmfe disk under /{shard}/{appId}/files/{model-type}/{id}/{filename}.{ext}.
     */
    public function storeFile(Model $model, string $filename, UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = $this->buildFilePath($model, $filename, $ext);
        $disk = $this->getRemoteWfeDisk();

        // Replace atomically by removing the existing target first.
        if ($disk->exists($path)) {
            $disk->delete($path);
        }

        // Store file using put() to ensure path is created
        $disk->put($path, file_get_contents($file->getRealPath()), 'public');

        // Return direct S3 URL (filename is last segment: accessibility.pdf)
        return $disk->url($path);
    }

    /**
     * Delete a model file from wmfe disk by base filename (all extensions).
     */
    public function deleteFile(Model $model, string $filename): void
    {
        $disk = $this->getRemoteWfeDisk();
        $directory = $this->buildModelFilesDirectory($model);
        $files = $disk->files($directory);

        foreach ($files as $file) {
            if (Str::startsWith(basename($file), $filename . '.')) {
                $disk->delete($file);
            }
        }
    }

    /**
     * Delete all files in the model directory.
     */
    public function deleteModelFiles(Model $model): void
    {
        $disk = $this->getRemoteWfeDisk();
        $directory = $this->buildModelFilesDirectory($model);

        if ($disk->exists($directory)) {
            $disk->deleteDirectory($directory);
        }
    }

    /**
     * Store layer feature collection in AWS
     *
     * @param  int|null  $appId  The app ID
     * @param  int  $layerId  The layer ID
     * @param  string  $contents  The feature collection contents
     * @return string|false The stored path or false on failure
     */
    public function storeLayerFeatureCollection(?int $appId, int $layerId, string $contents): string|false
    {
        try {
            $path = $this->getShardBasePath($appId) . "layers/{$layerId}.geojson";

            $success = $this->getRemoteWfeDisk()->put($path, $contents);

            if ($success) {
                return $this->getRemoteWfeDisk()->url($path);
            }

            return false;
        } catch (Exception $e) {
            \Log::error('Failed to store layer feature collection: ' . $e->getMessage());
            throw $e;
        }
    }

    public function storeLocalElevationChartImage(int $id, array $geojson): array
    {
        $localDisk = $this->getLocalDisk();
        $localGeojsonPath = $this->getTempGeojsonForElevationChartGeneration($id);

        if (! $localDisk->exists('elevation_charts')) {
            $localDisk->makeDirectory('elevation_charts');
        }
        if (! $localDisk->exists('geojson')) {
            $localDisk->makeDirectory('geojson');
        }

        $localDestRelativePath = $this->getElevationChartLocalPath($id);

        $localDisk->put($localGeojsonPath, json_encode($geojson));

        $src = $localDisk->path($localGeojsonPath);
        $dest = $localDisk->path($localDestRelativePath);

        return ['src' => $src, 'dest' => $dest];
    }

    public function deleteLocalTempGeojsonForElavationChartImageGeneration(int $id): bool
    {
        return $this->getLocalDisk()->delete($this->getTempGeojsonForElevationChartGeneration($id));
    }

    public function storeRemoteElevationChartImage(int $id): string|false
    {
        $remoteDestRelativePath = $this->getElevationChartRemotePath($id);
        $remoteOldDestRelativePath = $this->getElevationChartRemoteOldPath($id);
        $localDestRelativePath = $this->getElevationChartLocalPath($id);

        $mediaDisk = $this->getMediaDisk();
        if ($mediaDisk->exists($remoteDestRelativePath)) {
            if ($mediaDisk->exists($remoteOldDestRelativePath)) {
                $mediaDisk->delete($remoteOldDestRelativePath);
            }
            $mediaDisk->move($remoteDestRelativePath, $remoteOldDestRelativePath);
        }
        try {
            $mediaDisk->writeStream($remoteDestRelativePath, $this->getLocalDisk()->readStream($localDestRelativePath));
        } catch (Exception $e) {
            Log::warning('The elevation chart image could not be written');
            if ($mediaDisk->exists($remoteOldDestRelativePath)) {
                $mediaDisk->move($remoteOldDestRelativePath, $remoteDestRelativePath);
            }
        }

        if ($mediaDisk->exists($remoteOldDestRelativePath)) {
            $mediaDisk->delete($remoteOldDestRelativePath);
        }

        return $mediaDisk->path($remoteDestRelativePath);
    }

    //
    // PATHS
    // TODO: move all paths here
    //

    private function getElevationChartLocalPath(int $id): string
    {
        return "elevation_charts/{$id}.svg";
    }

    private function getTempGeojsonForElevationChartGeneration(int $id): string
    {
        return "geojson/{$id}.geojson";
    }

    private function getElevationChartRemotePath(int $id): string
    {
        return $this->getShardBasePath() . "elevation_charts/ec_tracks/{$id}.svg";
    }

    private function getElevationChartRemoteOldPath(int $id): string
    {
        return $this->getShardBasePath() . "elevation_charts/ec_tracks/{$id}_old.svg";
    }

    private function getPoisPath(int $appId): string
    {
        return $this->getShardBasePath($appId) . 'pois.geojson';
    }

    private function getTrackPath(int $trackId): string
    {
        return $this->getShardBasePath() . "tracks/{$trackId}.json";
    }

    private function getIconsPath(): string
    {
        return $this->getShardBasePath() . 'json/icons.json';
    }

    private function getAppIconsPath(int $appId): string
    {
        return $this->getShardBasePath($appId) . 'icons.json';
    }

    private function getAppConfigPath(int $appId): string
    {
        return $this->getShardBasePath($appId) . 'config.json';
    }

    public function getAppConfigUrl(int $appId): string
    {
        return $this->getRemoteWfeDisk()->url($this->getAppConfigPath($appId));
    }

    public function getAppIconsUrl(int $appId): string
    {
        return $this->getRemoteWfeDisk()->url($this->getAppIconsPath($appId));
    }

    public function getGlobalIconsUrl(): string
    {
        return $this->getRemoteWfeDisk()->url($this->getIconsPath());
    }

    public function getAppPoisUrl(int $appId): string
    {
        return $this->getRemoteWfeDisk()->url($this->getShardBasePath($appId) . 'pois.geojson');
    }

    //
    // PUBLIC GETTERS
    //

    private function buildModelFilesDirectory(Model $model): string
    {
        $type = Str::kebab(class_basename($model));
        $id = $model->getKey();

        return $this->getShardBasePath((int) $model->app_id) . "files/{$type}/{$id}";
    }

    private function buildFilePath(Model $model, string $filename, string $ext): string
    {
        return $this->buildModelFilesDirectory($model) . "/{$filename}.{$ext}";
    }

    public function getPublicPath(string $path): string
    {
        return $this->getPublicDisk()->path($path);
    }

    public function getMediaDisk(): Filesystem
    {
        return $this->getDisk('wmfe');
    }

    public function getPublicDisk(): Filesystem
    {
        return $this->getDisk('public');
    }

    public function getWmDumpsDisk(): Filesystem
    {
        return $this->getDisk('wmdumps');
    }

    public function getLocalDisk(): Filesystem
    {
        return $this->getDisk('local');
    }

    public function getBackupsDisk(): Filesystem
    {
        return $this->getDisk('backups');
    }

    //
    // PRIVATE GETTERS
    //

    private function getLocalPoisDisk(): Filesystem
    {
        return $this->getDisk('pois');
    }

    private function getRemoteWfeDisk(): Filesystem
    {
        return $this->getDisk('wmfe');
    }

    private function getLocalAppConfigDisk(): Filesystem
    {
        return $this->getDisk('conf');
    }

    private function getDisk($disk): Filesystem
    {
        try {
            return Storage::disk($disk);
        } catch (Exception $e) {
            \Log::error("Failed to get disk {$disk}: " . $e->getMessage());
            throw $e;
        }
    }

    private function getShardName(): string
    {
        return config('wm-package.shard_name', 'webmapp');
    }

    public function getShardBasePath(?int $appId = null)
    {
        $basePath = '/' . $this->getShardName() . '/';
        if (is_int($appId)) {
            $basePath .= $appId . '/';
        }

        return $basePath;
    }
}
