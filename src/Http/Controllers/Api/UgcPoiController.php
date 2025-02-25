<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Wm\WmPackage\Http\Controllers\Api\Abstracts\UgcController;
use Wm\WmPackage\Models\UgcPoi;

class UgcPoiController extends UgcController
{
    protected function getModelIstance(): UgcPoi
    {
        return new UgcPoi;
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function update(Request $request, UgcPoi $poi): Response
    {
        return parent::_update($request, $poi);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function destroy(UgcPoi $poi): Response
    {
        return parent::_destroy($poi);
    }
}
