<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Wm\WmPackage\Http\Controllers\Api\Abstracts\UgcController;
use Wm\WmPackage\Models\UgcPoi;

class UgcPoiController extends UgcController
{
    protected function getModelIstance(?Request $request = null): UgcPoi
    {
        if (! $request) {
            return new UgcPoi();
        }
        $uuid = Arr::get($request->only('properties', []), 'uuid', null);
        if (! $uuid) {
            return new UgcPoi;
        }
        $uuidModel = UgcPoi::where('properties->uuid', $uuid)->first();

        return $uuidModel ?: new UgcPoi;

    }

    /**
     * Show the form for editing the specified resource.
     */
    public function update(Request $request, UgcPoi $poi): JsonResponse
    {
        return parent::_update($request, $poi);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function destroy(UgcPoi $poi): JsonResponse
    {
        return parent::_destroy($poi);
    }

    // osm2cai api for geojson download
    // TODO: update when osm2cai will use wm-package UGCs
    public function downloadGeojson($ids)
    {
        $featureCollection = ['type' => 'FeatureCollection', 'features' => []];

        $ids = explode(',', $ids);
        $pois = UgcPoi::whereIn('id', $ids)->get();

        foreach ($pois as $poi) {
            $feature = $poi->getEmptyGeojson();
            $feature['properties'] = $poi->getJsonProperties();
            $featureCollection['features'][] = $feature;
        }

        $headers = [
            'Content-type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="ugc_pois.geojson"',
        ];

        return response()->json($featureCollection, 200, $headers);
    }
}
