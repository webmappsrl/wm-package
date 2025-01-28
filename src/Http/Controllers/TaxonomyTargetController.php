<?php

namespace Wm\WmPackage\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Wm\WmPackage\Models\TaxonomyTarget;

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
