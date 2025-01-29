<?php

namespace Wm\WmPackage\Services\Models;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;
use Wm\WmPackage\Jobs\UpdateEcMedia;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Models\EcMedia;
use Wm\WmPackage\Services\BaseService;
use Wm\WmPackage\Services\StorageService;

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

    public function thumbnail(EcMedia $model, $size): string
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
    public function getAssociatedEcMedia(GeometryModel $model)
    {

        $result = [
            'type' => 'FeatureCollection',
            'features' => [],
        ];
        foreach ($model->ecMedia as $media) {
            $result['features'][] = $media->getGeojson();
        }

        return $result;
    }
}
