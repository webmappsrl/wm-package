<?php

namespace Wm\WmPackage\Services\Models;

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Services\BaseService;

class OutSourceFeatureService extends BaseService
{
    /**
     * Returns the Model ID associated to an external feature
     *
     * TODO: optimize this query
     * TODO: make a method that returns the model model instance instead then update the all usages
     *
     * @param  string  $endpoint_slug
     * @param  int  $source_id
     * @return JsonResponse
     */
    public function getModelIdFromOutSourceFeature($endpoint_slug, $source_id, string $geometryModelClass): int
    {
        $osf_id = collect(DB::select("SELECT id FROM out_source_features where endpoint_slug='$endpoint_slug' and source_id='$source_id'"))->pluck('id')->toArray();

        $table = (new $geometryModelClass)->geTable();
        $modelId = collect(DB::select("select id from $table where out_source_feature_id='$osf_id[0]'"))->pluck('id')->toArray();

        return $modelId[0];
    }
}
