<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Wm\WmPackage\Http\Resources\TaxonomyActivityResource;
use Wm\WmPackage\Models\TaxonomyActivity;

class TaxonomyActivityController extends Controller
{
    /**
     * Get Taxonomy by ID
     *
     * @param  int  $id  the Taxonomy id
     * @return JsonResponse return the Taxonomy
     */
    public function show(TaxonomyActivity $taxonomyActivity): JsonResponse
    {
        return response()->json($taxonomyActivity, 200);
    }

    public function index()
    {
        return TaxonomyActivityResource::collection(TaxonomyActivity::all());
    }
}
