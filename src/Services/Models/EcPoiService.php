<?php

namespace Wm\WmPackage\Services\Models;

use Illuminate\Support\Collection;
use Throwable;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Models\EcPoi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\BaseService;
use Wm\WmPackage\Jobs\UpdateEcPoiDemJob;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;

class EcPoiService extends BaseService
{
    public function updateDataChain(EcPoi $model)
    {

        $chain = [

            new UpdateModelWithGeometryTaxonomyWhere($model), // it relates where taxonomy terms to the ecMedia model based on geometry attribute
            new UpdateEcPoiDemJob($model),
        ];

        Bus::chain($chain)
            ->catch(function (Throwable $e) {
                // A job within the chain has failed...
                Log::error($e->getMessage());
            })->dispatch();
    }

    public function getUpdatedAtPois(User|null $user = null): Collection
    {
        if ($user) {
            $arr = EcPoi::where('user_id', $user->id)->pluck('updated_at', 'id');
        } else {

            $arr = DB::select('select id, updated_at from ec_pois where user_id != 20548 and user_id != 17482');
            $arr = collect($arr)->pluck('updated_at', 'id');
        }
        return $arr;
    }


    /**
     * Returns the EcPoi ID associated to an external feature
     * 
     * TODO: optimize this query
     * TODO: make a method that returns the EcPoi model instance instead then update the EcPoiController
     * 
     * @param  string  $endpoint_slug
     * @param  int  $source_id
     * @return JsonResponse
     */
    public function getEcPoiIdFromSourceID($endpoint_slug, $source_id): int
    {
        $osf_id = collect(DB::select("SELECT id FROM out_source_features where endpoint_slug='$endpoint_slug' and source_id='$source_id'"))->pluck('id')->toArray();

        $ecPoi_id = collect(DB::select("select id from ec_pois where out_source_feature_id='$osf_id[0]'"))->pluck('id')->toArray();

        return $ecPoi_id[0];
    }
}
