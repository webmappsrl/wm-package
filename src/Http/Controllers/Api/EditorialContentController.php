<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Models\Abstracts\GeometryModel;

class EditorialContentController extends Controller
{
    /**
     * Calculate the model class name of a ugc from its type
     *
     * @param  int  $id  the model id
     * @return GeometryModel the model class name
     *
     * @throws Exception
     */
    private function _getEcModelFromType($id): GeometryModel
    {
        $type = explode('/', request()->path());
        switch ($type) {
            case 'poi':
                $modelClass = "\Wm\WmPackage\Models\EcPoi";
                break;
            case 'track':
                $modelClass = "\Wm\WmPackage\Models\EcTrack";
                break;
            case 'media':
                $modelClass = "\Wm\WmPackage\Models\Media";
                break;
            default:
                throw new Exception("Invalid type ' . $type . '. Available types: poi, track, media", 400);
        }

        $model = $modelClass::find($id);
        if (is_null($model)) {
            throw new Exception('Not found', 404);
        }

        return $model;
    }

    /**
     * Get Ec image by ID
     *
     * @param  int  $id  the Ec id
     */
    public function getEcImage(int $id)
    {
        try {
            $ec = $this->_getEcModelFromType($id);
        } catch (Exception $e) {
            return response()->json(['code' => $e->getCode(), 'error' => $e->getMessage()], $e->getCode());
        }

        // https://wmptest.s3.eu-central-1.amazonaws.com/Media/2.jpg

        $pathInfo = pathinfo($ec->path);
        if (substr($ec->url, 0, 4) === 'http') {

            return response()->streamDownload(function () use ($ec) {
                file_get_contents($ec->url);
            }, 'name.'.$pathInfo['extension']);
        } else {
            // Scaricare risorsa locale
            $filename = 'name';
            if (isset($pathInfo['extension'])) {
                $filename = 'name.'.$pathInfo['extension'];
            }

            return Storage::disk('public')->download($ec->url, $filename);
        }
    }

    /**
     * Return EcTrack JSON.
     */
    public function viewEcGeojson(int $id, array $headers = []): JsonResponse
    {
        try {
            $ec = $this->_getEcModelFromType($id);
        } catch (Exception $e) {
            return response()->json(['code' => $e->getCode(), 'error' => $e->getMessage()], $e->getCode());
        }

        if (! empty($headers)) {
            $headers = $this->createDownloadFileName($ec, $headers);
        }

        return response()->json($ec->getGeojson(), 200, $headers);
    }

    /**
     * @return mixed
     */
    public function viewEcGpx(int $id, array $headers = [])
    {

        try {
            $ec = $this->_getEcModelFromType($id);
        } catch (Exception $e) {
            return response()->json(['code' => $e->getCode(), 'error' => $e->getMessage()], $e->getCode());
        }
        $content = $ec->getGpx();
        $headers = $this->createDownloadFileName($ec, $headers);

        return response()->gpx($content, 200, $headers);
    }

    /**
     * @return mixed
     */
    public function viewEcKml(int $id, array $headers = [])
    {

        try {
            $ec = $this->_getEcModelFromType($id);
        } catch (Exception $e) {
            return response()->json(['code' => $e->getCode(), 'error' => $e->getMessage()], $e->getCode());
        }

        $content = $ec->getKml();
        $headers = $this->createDownloadFileName($ec, $headers);

        return response()->kml($content, 200, $headers);
    }

    public function downloadEcGeojson(int $id): JsonResponse
    {
        $headers['Content-Type'] = 'application/vnd.api+json';
        $headers['Content-Disposition'] = 'attachment; filename="'.$id.'.geojson"';

        return $this->viewEcGeojson($id, $headers);
    }

    /**
     * @return mixed
     */
    public function downloadEcGpx(int $id)
    {
        $headers['Content-Type'] = 'application/xml';
        $headers['Content-Disposition'] = 'attachment; filename="'.$id.'.gpx"';

        return $this->viewEcGpx($id, $headers);
    }

    /**
     * @return mixed
     */
    public function downloadEcKml(int $id)
    {
        $headers['Content-Type'] = 'application/xml';
        $headers['Content-Disposition'] = 'attachment; filename="'.$id.'.kml"';

        return $this->viewEcKml($id, $headers);
    }

    /**
     * @param  Model  $ec
     * @param  array  $headers
     * @return array
     */
    public function createDownloadFileName($ec, $headers)
    {
        $fileName = '';
        if ($ec->ref) {
            $fileName = $ec->ref;
        }
        if (empty($ec->ref) && $ec->name) {
            $fileName = $ec->name;
        }
        if ($fileName) {
            $originalFileName = $headers['Content-Disposition'];
            $extension = trim(pathinfo($originalFileName, PATHINFO_EXTENSION), '"');

            $headers['Content-Disposition'] = 'attachment; filename="'.$fileName.'.'.$extension.'"';
        }

        return $headers;
    }
}
