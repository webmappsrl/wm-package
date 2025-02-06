<?php

namespace Wm\WmPackage\Http\Controllers;

use Illuminate\Http\Request;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\GeometryComputationService;

class ImportController extends Controller
{
    // TODO: security leak, use a middleware to check if the user is authenticated
    public function importGeojson(Request $request)
    {
        if (! $request->geojson) {
            return redirect('/import?error-import=no-file');
        }
        $features = (file_get_contents($request->geojson));
        $features = json_decode($features);
        if ($features->type == 'Feature') {
            return redirect('/import?error-import=no-collection');
        } else {
            return view('ImportPreview', ['features' => $features]);
        }
    }

    // TODO: security leak, use a middleware to check if the user is authenticated
    public function saveImport(Request $request)
    {

        $features = json_decode($request->features);
        foreach ($features->features as $feature) {
            $geometryTracks = json_encode($feature->geometry);
            $name = 'ecTrack_'.date('Y-m-d');
            if (isset($feature->properties->name)) {
                $name = $feature->properties->name;
            }

            EcTrack::create([
                'name' => $name,
                'geometry' => GeometryComputationService::make()->get2dGeometryFromGeojsonRAW($geometryTracks),
                'import_method' => 'massive_import',
            ]);
        }

        return redirect('/resources/ec-tracks?success-import=1');
    }
}
