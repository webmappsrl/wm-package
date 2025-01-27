<?php

namespace Wm\WmPackage\Services;

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\Layer;

class LayerService extends BaseService
{
    public function getLayerMaxRank(Layer $layer)
    {
        return DB::select(DB::raw('SELECT max(rank) from layers'))[0]->max;
    }
}
