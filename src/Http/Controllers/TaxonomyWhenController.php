<?php

namespace Wm\WmPackage\Http\Controllers;

use Wm\WmPackage\Models\TaxonomyWhen;
use Illuminate\Http\JsonResponse;

class TaxonomyWhenController extends Controller
{
    /**
     * Get Taxonomy by ID
     *
     * @param  int  $id  the Taxonomy id
     * @return JsonResponse return the Taxonomy
     */
    public function show(TaxonomyWhen $taxonomyWhen): JsonResponse
    {
        return response()->json($taxonomyWhen, 200);
    }
}
