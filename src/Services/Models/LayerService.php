<?php

namespace Wm\WmPackage\Services;

use Wm\WmPackage\Models\Layer;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Services\BaseService;

class LayerService extends BaseService
{
    public function getLayerMaxRank(Layer $layer)
    {
        return DB::select(DB::raw('SELECT max(rank) from layers'))[0]->max;
    }
}
