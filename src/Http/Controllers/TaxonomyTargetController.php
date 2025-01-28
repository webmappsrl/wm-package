<?php

namespace Wm\WmPackage\Http\Controllers;

use Wm\WmPackage\Models\TaxonomyTarget;
use Illuminate\Http\JsonResponse;

class TaxonomyTargetController extends Controller
{

    /**
     * Get Taxonomy by ID
     *
     * @param  int  $id  the Taxonomy id
     * @return JsonResponse return the Taxonomy
     */
    public function show(TaxonomyTarget $taxonomyTarget): JsonResponse
    {
        return response()->json($taxonomyTarget, 200);
    }
}
