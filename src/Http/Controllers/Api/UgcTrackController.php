<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Models\UgcTrack;

class UgcTrackController extends Controller
{
    protected function getModelIstance(): UgcTrack
    {
        return new UgcTrack;
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
