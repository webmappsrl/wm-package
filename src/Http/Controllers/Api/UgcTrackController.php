<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use App\Models\UgcTrack as ModelsUgcTrack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wm\WmPackage\Http\Controllers\Api\Abstracts\UgcController;
use Wm\WmPackage\Models\UgcTrack;

class UgcTrackController extends UgcController
{
    protected function getModelIstance(): UgcTrack
    {
        return new UgcTrack;
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function update(Request $request, UgcTrack $track): JsonResponse
    {
        return parent::_update($request, $track);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function destroy(UgcTrack $track): JsonResponse
    {
        return parent::_destroy($track);
    }

    //osm2cai api for geojson download
    //TODO: update when osm2cai will use wm-package UGCs
    public function downloadGeojson($ids)
    {
        $featureCollection = ['type' => 'FeatureCollection', 'features' => []];

        $ids = explode(',', $ids);
        $tracks = ModelsUgcTrack::whereIn('id', $ids)->get();

        foreach ($tracks as $track) {
            $feature = $track->getEmptyGeojson();
            $feature['properties'] = $track->getJsonProperties();
            $featureCollection['features'][] = $feature;
        }

        $headers = [
            'Content-type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="ugc_tracks.geojson"',
        ];

        return response()->json($featureCollection, 200, $headers);
    }
}
