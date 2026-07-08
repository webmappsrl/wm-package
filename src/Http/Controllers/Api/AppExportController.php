<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Illuminate\Http\Request;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Http\Resources\AppExportResource;
use Wm\WmPackage\Models\App;

class AppExportController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'updated_after' => ['sometimes', 'date'],
        ]);

        $query = App::query()->with('author')->orderBy('id');

        if (isset($validated['updated_after'])) {
            $query->where('updated_at', '>', $validated['updated_after']);
        }

        return AppExportResource::collection($query->paginate(50));
    }

    public function show(App $app)
    {
        $app->load('author');

        return new AppExportResource($app);
    }
}
