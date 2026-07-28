<?php

declare(strict_types=1);

namespace Wm\WmPackage\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Laravel\Nova\Nova;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Wm\WmPackage\Services\Osm\OsmImportReportStore;

class OsmImportReportController extends Controller
{
    /**
     * OSM import summary page (same browser tab; no external popup).
     */
    public function show(Request $request, string $token): View
    {
        $user = $request->user();
        if ($user === null) {
            throw new NotFoundHttpException;
        }

        $payload = OsmImportReportStore::get($token, (int) $user->id);
        if ($payload === null) {
            throw new NotFoundHttpException;
        }

        return view('wm-package::osm-import-report', [
            'report' => $payload,
            'backUrl' => Nova::url('/resources/ec-pois'),
            'ttlMinutes' => OsmImportReportStore::TTL_MINUTES,
        ]);
    }
}
