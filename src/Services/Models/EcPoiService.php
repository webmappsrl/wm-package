<?php

namespace Wm\WmPackage\Services\Models;

use Throwable;
use Wm\WmPackage\Models\EcPoi;
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
}
