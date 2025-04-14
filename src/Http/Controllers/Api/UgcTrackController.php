<?php

namespace Wm\WmPackage\Http\Controllers\Api;

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
}
