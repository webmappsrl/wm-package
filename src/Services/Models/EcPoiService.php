<?php

namespace Wm\WmPackage\Services\Models;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Wm\WmPackage\Jobs\UpdateEcPoiAwsJob;
use Wm\WmPackage\Jobs\UpdateEcPoiDemJob;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\BaseService;

class EcPoiService extends BaseService
{
    public function updateDataChain(EcPoi $model)
    {
        $chain = [];

        if ($model->wasChanged('geometry')) {
            $chain[] = new UpdateModelWithGeometryTaxonomyWhere($model); // it relates where taxonomy terms to the media model based on geometry attribute
            $chain[] = new UpdateEcPoiDemJob($model);
        }

        $chain[] = new UpdateEcPoiAwsJob($model);

        Bus::chain($chain)
            ->catch(function (Throwable $e) {
                // A job within the chain has failed...
                Log::error($e->getMessage());
            })->dispatch();
    }

    public function getUpdatedAtPois(?int $app_id = null): Collection
    {
        if ($app_id) {
            $arr = EcPoi::where('app_id', $app_id)->pluck('updated_at', 'id');
        } else {
            $arr = DB::select('select id, updated_at from ec_pois');
            $arr = collect($arr)->pluck('updated_at', 'id');
        }

        return $arr;
    }

    public static function getAssociatedEcPois(GeometryModel $model)
    {
        $result = [
            'type' => 'FeatureCollection',
            'features' => [],
        ];
        foreach ($model->ecPois as $poi) {
            $result['features'][] = $poi->getGeojson();
        }

        return $result;
    }
}
