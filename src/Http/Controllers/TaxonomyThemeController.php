<?php

namespace Wm\WmPackage\Http\Controllers;

use Wm\WmPackage\Models\TaxonomyTheme;
use Illuminate\Http\JsonResponse;

class TaxonomyThemeController extends Controller
{

    /**
     * Get Taxonomy by ID
     *
     * @param  int  $id  the Taxonomy id
     * @return JsonResponse return the Taxonomy
     */
    public function show(TaxonomyTheme $taxonomyTheme): JsonResponse
    {
        return response()->json($taxonomyTheme, 200);
    }

    /**
     * Get All TaxonomyThemes
     *
     * @return JsonResponse return all TaxonomyThemes
     */
    public function index(): JsonResponse
    {
        $taxonomyThemes = TaxonomyTheme::select('id', 'name', 'identifier')->get();
        return response()->json($taxonomyThemes, 200);
    }
}
