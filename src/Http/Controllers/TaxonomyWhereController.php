<?php

namespace Wm\WmPackage\Http\Controllers;

use Wm\WmPackage\Models\TaxonomyWhere;
use Illuminate\Http\JsonResponse;

class TaxonomyWhereController extends Controller
{

    /**
     * Get Taxonomy by ID as geoJson
     *
     * @param  int  $id  the Taxonomy id
     * @return JsonResponse return the Taxonomy geoJson
     */
    public function getGeoJson(TaxonomyWhere $taxonomyWhere): JsonResponse
    {
        return response()->json($taxonomyWhere->getGeojson(), 200);
    }

    /**
     * Get Taxonomy by ID
     *
     * @param  int  $id  the Taxonomy id
     * @return JsonResponse return the Taxonomy
     */
    public function show(TaxonomyWhere $taxonomyWhere): JsonResponse
    {
        return response()->json($taxonomyWhere, 200);
    }

    public function index()
    {
        return TaxonomyWhere::all()->pluck('updated_at', 'id')->toArray();
    }
}
