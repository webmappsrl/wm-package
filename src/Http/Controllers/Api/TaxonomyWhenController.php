<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Models\TaxonomyWhen;

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
