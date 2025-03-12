<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\LayerService;

class LayerAPIController extends Controller
{
    public function __construct(private LayerService $layerService) {}
    public function index()
    {
        foreach (Layer::all()->toArray() as $layer) {
            //TODO: move to a resource
            unset($layer['taxonomy_themes']);
            unset($layer['taxonomy_wheres']);
            unset($layer['taxonomy_activities']);
            //TODO: check performances then evaluate a count method into the service witha a cache
            $layers['ec_poi'] = $this->layerService->getRelatedEcPois($layer, true);
            $layers['ec_tracks'] = $this->layerService->getRelatedEcTracks($layer, true);
        }

        return $layers;
    }
}
