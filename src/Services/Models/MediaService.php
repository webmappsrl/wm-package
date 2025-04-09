<?php

namespace Wm\WmPackage\Services\Models;


use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\Services\BaseService;
use Wm\WmPackage\Services\StorageService;
use Exception;
use Intervention\Image\Facades\Image;

class MediaService extends BaseService
{
    public function updateDataChain(Media $model)
    {

        // $chain = [
        //     new UpdateMedia($model), // it updates: geometry(if available on exif), thumbnails and url
        //     new UpdateModelWithGeometryTaxonomyWhere($model), // it relates where taxonomy terms to the Media model based on geometry attribute
        // ];

        // Bus::chain($chain)
        //     ->catch(function (Throwable $e) {
        //         // A job within the chain has failed...
        //         Log::error($e->getMessage());
        //     })->dispatch();
    }

    public function thumbnail(Media $model, $size): string
    {
        $thumbnails = json_decode($model->thumbnails, true);
        $result = substr($model->url, 0, 4) === 'http' ? $model->url : StorageService::make()->getPublicPath($model->url);
        if (isset($thumbnails[$size])) {
            $result = $thumbnails[$size];
        }

        return $result;
    }

    /**
     * Get a feature collection with the related media
     */
    public function getAssociatedMedia(GeometryModel $model)
    {

        $result = [
            'type' => 'FeatureCollection',
            'features' => [],
        ];
        foreach ($model->getMedia() as $media) {
            $result['features'][] = $media->getGeojson();
        }

        return $result;
    }

    /**
     * Return a mapped array with all the useful exif of the image
     * Copied (and updated) from geomixer
     *
     * @param  Media  $mediaModel  the path of the image
     * @return array|false the array in geojson format or empty or false if coordinates aren't present
     *
     * @throws Exception
     */
    public function getMediaExifCoordinatesAsGeojson(Media $mediaModel): array|false
    {
        $imagePath = $mediaModel->getAbsolutePath();

        // https://github.com/Intervention/image-laravel
        // https://image.intervention.io/v3/basics/meta-information
        $data = Image::make($imagePath)->exif();

        if (isset($data['GPSLatitude']) && isset($data['GPSLongitude'])) {
            try {

                // Calculate Latitude with degrees, minutes and seconds

                $latDegrees = $data['GPSLatitude'][0];
                $latDegrees = explode('/', $latDegrees);
                $latDegrees = ($latDegrees[0] / $latDegrees[1]);

                $latMinutes = $data['GPSLatitude'][1];
                $latMinutes = explode('/', $latMinutes);
                $latMinutes = (($latMinutes[0] / $latMinutes[1]) / 60);

                $latSeconds = $data['GPSLatitude'][2];
                $latSeconds = explode('/', $latSeconds);
                $latSeconds = (($latSeconds[0] / $latSeconds[1]) / 3600);

                // Calculate Longitude with degrees, minutes and seconds

                $lonDegrees = $data['GPSLongitude'][0];
                $lonDegrees = explode('/', $lonDegrees);
                $lonDegrees = ($lonDegrees[0] / $lonDegrees[1]);

                $lonMinutes = $data['GPSLongitude'][1];
                $lonMinutes = explode('/', $lonMinutes);
                $lonMinutes = (($lonMinutes[0] / $lonMinutes[1]) / 60);

                $lonSeconds = $data['GPSLongitude'][2];
                $lonSeconds = explode('/', $lonSeconds);
                $lonSeconds = (($lonSeconds[0] / $lonSeconds[1]) / 3600);

                $imgLatitude = $latDegrees + $latMinutes + $latSeconds;
                $imgLongitude = $lonDegrees + $lonMinutes + $lonSeconds;

                $coordinates = [$imgLongitude, $imgLatitude];

                return [
                    'type' => 'Point',
                    'coordinates' => $coordinates,
                ];
            } catch (Exception $e) {
                Log::error('getMediaExifCoordinatesAsGeojson on media id ' . $mediaModel->id . ': invalid Coordinates present');
            }
        }

        return false;
    }

    public function getThumbnailSizes()
    {
        return config('wm-package.services.image.thumbnail_sizes');
    }

    public function getSizesUrls(Media $media): array
    {

        $sizes = $this->getThumbnailSizes();
        $urls = [];
        foreach ($sizes as $size) {
            $width = $size['width'];
            $height = $size['height'];
            $urls["{$width}x{$height}"] = $media->getUrl($this->getMediaConversionNameByWidthAndHeight($width, $height));
        }
        return $urls;
    }

    public function getMediaConversionNameByWidthAndHeight(int $width, int $height)
    {
        return "thumbnail_{$width}_{$height}";
    }

    public function getThumbnailUrl(Media $media)
    {
        return $media->getUrl($this->getMediaConversionNameByWidthAndHeight(400, 200));
    }
}
