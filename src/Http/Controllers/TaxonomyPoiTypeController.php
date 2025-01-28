<?php

namespace Wm\WmPackage\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Http\Resources\TaxonomyPoiTypeResource;

class TaxonomyPoiTypeController extends Controller
{

    /**
     * Get Taxonomy by ID
     *
     * @param  int  $id  the Taxonomy id
     * @return JsonResponse return the Taxonomy
     */
    public function show(TaxonomyPoiType $taxonomyPoiType): JsonResponse
    {
        return response()->json($taxonomyPoiType, 200);
    }

    public function index()
    {
        return TaxonomyPoiTypeResource::collection(TaxonomyPoiType::all());
    }
}
