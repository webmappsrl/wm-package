<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Http\Resources\UgcTrackCollection;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcMedia;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Traits\FeatureCollectionTrait;

class UgcTrackController extends Controller
{
    protected function getModelIstance(): UgcTrack
    {
        return new UgcTrack();
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function update(Request $request, UgcTrack $ugcTrack): Response
    {
        return parent::_update($request, $ugcTrack);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function destroy(UgcTrack $ugcTrack): Response
    {
        return parent::_destroy($ugcTrack);
    }
}
