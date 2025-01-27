<?php

namespace Wm\WmPackage\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Exception\ImageException;
use Intervention\Image\Facades\Image;

class ImageService extends BaseService
{
    /**
     * Return a mapped array with all the useful exif of the image
     * Copied (and updated) from geomixer
     *
     * @param  string  $imagePath  the path of the image
     * @return array the array with the coordinates
     *
     * @throws Exception
     */
    public function getImageExif(string $imagePath): array
    {

        if (! file_exists($imagePath)) {
            throw new Exception("The image $imagePath does not exists");
        }

        $data = Image::make($imagePath)->exif();

        if (isset($data['GPSLatitude']) && isset($data['GPSLongitude'])) {
            Log::info('getImageExif: Coordinates present');
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

                return ['coordinates' => $coordinates];
            } catch (Exception $e) {
                Log::info('getImageExif: invalid Coordinates present');

                return [];
            }
        } else {
            return [];
        }
    }

    public function getThumbnailSizes()
    {
        return config('wm-package.services.image.thumbnail_sizes');
    }

    public function getImageResizeFilePathBySize($localImagePath, array $size): string
    {
        if ($size['width'] == 0) {
            $imageResize = $this->imgResizeSingleDimension($localImagePath, $size['height'], 'height');
        } elseif ($size['height'] == 0) {
            $imageResize = $this->imgResizeSingleDimension($localImagePath, $size['width'], 'width');
        } else {
            $imageResize = $this->imgResize($localImagePath, $size['width'], $size['height']);
        }

        return $imageResize;
    }

    /**
     * Resize the given image to the specified width and height
     * Copied (and updated) from geomixer
     *
     * @param  string  $imagePath  the path of the image
     * @param  int  $dim  the new width or height
     * @param  string  $type  the width or height
     * @return string the new path image
     *
     * @throws ImageException
     */
    public function imgResizeSingleDimension(string $imagePath, int $dim, string $type): string
    {
        [$imgWidth, $imgHeight] = getimagesize($imagePath);
        if ($type == 'height') {
            if ($imgHeight < $dim) {
                throw new ImageException('The image is too small to resize ');
            }

            $img = $this->correctImageOrientation(Image::make($imagePath));
            $pathInfo = pathinfo($imagePath);
            $newPathImage = $pathInfo['dirname'].DIRECTORY_SEPARATOR.$this->resizedFileName($imagePath, $width = '', $dim);
            $img->fit(null, $dim, function ($const) {
                $const->aspectRatio();
            })->save($newPathImage);

            return $newPathImage;
        } elseif ($type == 'width') {
            if ($imgWidth < $dim) {
                throw new ImageException('The image is too small to resize ');
            }

            $img = $this->correctImageOrientation(Image::make($imagePath));
            $pathInfo = pathinfo($imagePath);
            $newPathImage = $pathInfo['dirname'].DIRECTORY_SEPARATOR.$this->resizedFileName($imagePath, $dim, $height = 0);
            $img->fit($dim, null, function ($const) {
                $const->aspectRatio();
            })->save($newPathImage);

            return $newPathImage;
        }
    }

    /**
     * Corregge l'orientamento dell'immagine basato sui dati Exif.
     * Copied (and updated) from geomixer
     *
     * @param  \Intervention\Image\Image  $img
     * @return \Intervention\Image\Image
     */
    public function correctImageOrientation($img)
    {
        $orientation = $img->exif('Orientation');
        switch ($orientation) {
            case 3:
                $img->rotate(180);
                break;
            case 6:
                $img->rotate(-90);
                break;
            case 8:
                $img->rotate(90);
                break;
        }

        return $img;
    }

    /**
     * Helper to get the filename of a resized image
     * Copied (and updated) from geomixer
     *
     * @param  string  $imagePath  absolute path of file
     * @param  int  $width  the image width
     * @param  int  $height  the image height
     */
    public function resizedFileName(string $imagePath, int $width, int $height): string
    {
        $pathInfo = pathinfo($imagePath);
        if ($width == 0) {
            return $pathInfo['filename'].'_x'.$height.'.'.$pathInfo['extension'];
        } elseif ($height == 0) {
            return $pathInfo['filename'].'_'.$width.'x.'.$pathInfo['extension'];
        } else {
            return $pathInfo['filename'].'_'.$width.'x'.$height.'.'.$pathInfo['extension'];
        }
    }

    /**
     * Resize the given image to the specified width and height
     * Copied (and updated) from geomixer
     *
     * @param  string  $imagePath  the path of the image
     * @param  int  $width  the new width
     * @param  int  $height  the new height
     * @return string the new path image
     *
     * @throws ImageException
     */
    public function imgResize(string $imagePath, int $width, int $height): string
    {
        [$imgWidth, $imgHeight] = getimagesize($imagePath);
        if ($imgWidth < $width || $imgHeight < $height) {
            throw new ImageException("The image is too small to resize - required size: $width, $height - actual size: $imgWidth, $imgHeight");
        }

        $img = $this->correctImageOrientation(Image::make($imagePath));
        $pathInfo = pathinfo($imagePath);
        $newPathImage = $pathInfo['dirname'].DIRECTORY_SEPARATOR.$this->resizedFileName($imagePath, $width, $height);
        $img->fit($width, $height, function ($const) {
            $const->aspectRatio();
        })->save($newPathImage);

        return $newPathImage;
    }
}
