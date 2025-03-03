<?php

namespace Wm\WmPackage\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Facades\Image;
use Wm\WmPackage\Models\Media;

class MediaService extends BaseService
{
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
                Log::error('getMediaExifCoordinatesAsGeojson on media id '.$mediaModel->id.': invalid Coordinates present');
            }
        }

        return false;
    }

    public function getThumbnailSizes()
    {
        return config('wm-package.services.image.thumbnail_sizes');
    }
}
