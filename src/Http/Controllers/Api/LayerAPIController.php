<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Http\Controllers\Controller;

class LayerAPIController extends Controller
{
    public function index()
    {
        foreach (Layer::all()->toArray() as $layer) {
            unset($layer['taxonomy_themes']);
            unset($layer['taxonomy_wheres']);
            unset($layer['taxonomy_activities']);
            $layers[] = $layer;
        }

        return $layers;
    }
}
