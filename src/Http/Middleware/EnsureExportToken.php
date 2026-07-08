<?php

namespace Wm\WmPackage\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureExportToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('wm-package.export.token');

        if (empty($expected)) {
            // Export non configurato su questa istanza: disabilitato by design.
            abort(403, 'Export is not configured on this instance.');
        }

        if (! hash_equals((string) $expected, (string) $request->bearerToken())) {
            abort(401, 'Invalid export token.');
        }

        return $next($request);
    }
}
