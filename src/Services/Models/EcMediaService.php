<?php

namespace Wm\WmPackage\Services\Models;

use Throwable;
use Wm\WmPackage\Models\EcMedia;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Jobs\UpdateEcMedia;
use Wm\WmPackage\Services\BaseService;
use Wm\WmPackage\Services\StorageService;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;

class EcMediaService extends BaseService
{
    public function updateDataChain(EcMedia $model)
    {

        $chain = [
            new UpdateEcMedia($model), // it updates: geometry(if available on exif), thumbnails and url
            new UpdateModelWithGeometryTaxonomyWhere($model), // it relates where taxonomy terms to the ecMedia model based on geometry attribute
        ];

        Bus::chain($chain)
            ->catch(function (Throwable $e) {
                // A job within the chain has failed...
                Log::error($e->getMessage());
            })->dispatch();
    }

    public function thumbnail($size, EcMedia $model): string
    {
        $thumbnails = json_decode($model->thumbnails, true);
        $result = substr($model->url, 0, 4) === 'http' ? $model->url : StorageService::make()->getPublicPath($model->url);
        if (isset($thumbnails[$size])) {
            $result = $thumbnails[$size];
        }

        return $result;
    }

    /**
     * Return json to be used in features API.
     */
    public function getJson($allData = true, EcMedia $model): array
    {
        $array = $model->toArray();
        $toSave = ['id', 'name', 'url', 'description'];
        $thumbnailSize = '400x200';

        foreach ($array as $key => $property) {
            if (! in_array($key, $toSave)) {
                unset($array[$key]);
            }
        }

        if (isset($array['description'])) {
            $array['caption'] = $array['description'];
        }
        unset($array['description']);

        if (! empty($model->thumbnail($thumbnailSize))) {
            $array['thumbnail'] = $model->thumbnail($thumbnailSize);
        }
        $array['api_url'] = route('api.ec.media.geojson', ['id' => $model->id], true);
        if ($allData) {
            $array['sizes'] = json_decode($model->thumbnails, true);
        }

        return $array;
    }

    /**
     * Create a geojson from the ec track
     */
    public function getGeojson(EcMedia $model): ?array
    {
        $feature = $model->getEmptyGeojson();
        if (isset($feature['properties'])) {
            $feature['properties'] = $model->getJson();

            return $feature;
        } else {
            return [
                'type' => 'Feature',
                'properties' => $model->getJson(),
                'coordinates' => [],
            ];
        }
    }
}
