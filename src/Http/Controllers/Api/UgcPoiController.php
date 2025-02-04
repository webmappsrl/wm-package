<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Http\Controllers\Api\Abstracts\UgcController;


class UgcPoiController extends UgcController
{
    protected function getModelIstance(): UgcPoi
    {
        return new UgcPoi();
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function update(Request $request, UgcPoi $ugcPoi): Response
    {
        return parent::_update($request, $ugcPoi);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function destroy(UgcPoi $ugcPoi): Response
    {
        return parent::_destroy($ugcPoi);
    }
}
