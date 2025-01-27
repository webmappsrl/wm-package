<?php

namespace Wm\WmPackage\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Wm\WmPackage\Models\EcMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Facades\Image;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\ImageService;
use Wm\WmPackage\Services\StorageService;

/**
 * Updates EcMedia: geometry, thumbnails and url
 */
class UpdateEcMedia implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(protected EcMedia $ecMedia) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(ImageService $imageService, StorageService $storageService, GeometryComputationService $geometryComputationService)
    {

        $thumbnailList = [];

        $localImage = strpos($this->ecMedia->url, 'http') !== 0;
        if (! $localImage) {
            $storageService->storeLocalEcMediaImage($this->ecMedia);
        }

        $localImagePath = $storageService->getLocalEcMediaImagePath($this->ecMedia);

        $exif = $imageService->getImageExif($localImagePath);

        if (isset($exif['coordinates'])) {
            $geojson = [
                'type' => 'Point',
                'coordinates' => [$exif['coordinates'][0], $exif['coordinates'][1]],
            ];
            // updating ecmedia geometry based on exif coordinates
            $this->ecMedia->geometry = $geometryComputationService->get2dGeometryFromGeojsonRAW(json_encode($geojson));
        }

        if ($localImage) {
            $imageCloudUrl = $storageService->storeEcMediaImage($localImagePath);
            if (is_null($imageCloudUrl)) {
                throw new Exception('Missing mandatory parameter: URL');
            }
            $this->ecMedia->url = $imageCloudUrl;
        }

        $sizes = $imageService->getThumbnailSizes();

        foreach ($sizes as $size) {
            try {

                $imageResize = $imageService->getImageResizeFilePathBySize($localImagePath, $size);

                if (file_exists($imageResize)) {
                    $thumbnailUrl = $storageService->storeEcMediaImageResize($imageResize, $size['width'], $size['height']);
                    if ($size['width'] == 0) {
                        $key = 'x' . $size['height'];
                    } elseif ($size['height'] == 0) {
                        $key = $size['width'] . 'x';
                    } else {
                        $key = $size['width'] . 'x' . $size['height'];
                    }

                    $thumbnailList[$key] = $thumbnailUrl;
                }
            } catch (Exception $e) {
                Log::warning($e->getMessage());
            }
        }

        $this->ecMedia->thumbnails = $thumbnailList;
        // persists changes on the database
        $this->ecMedia->saveQuietly();

        $storageService->deleteLocalEcMediaImage($this->ecMedia->path);
    }
}
