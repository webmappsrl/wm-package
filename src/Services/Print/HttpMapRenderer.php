<?php

namespace Wm\WmPackage\Services\Print;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Exceptions\PrintCompilationException;
use Wm\WmPackage\Services\Print\Contracts\MapRenderer;

/**
 * Scheletro del client del servizio mappe: POST di `toArray()` a `map_service.url`.
 *
 * TODO oc:9011: autenticazione, dimensioni e formato di ritorno vanno completati col contratto di Giuseppe.
 */
class HttpMapRenderer implements MapRenderer
{
    public function render(MapRenderRequest $request): string
    {
        $url = config('wm-package.features.print.map_service.url');
        if (! $url) {
            throw new PrintCompilationException('Servizio mappe non configurato (WM_PRINT_MAP_SERVICE_URL).');
        }

        $http = Http::timeout((int) config('wm-package.features.print.map_service.timeout', 60));
        if ($token = config('wm-package.features.print.map_service.token')) {
            $http = $http->withToken($token);
        }

        try {
            $response = $http->post($url, $request->toArray());
        } catch (ConnectionException $e) {
            throw new PrintCompilationException('Servizio mappe non raggiungibile o in timeout: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new PrintCompilationException('Il servizio mappe ha risposto con errore HTTP '.$response->status().'.');
        }

        return $response->body();
    }
}
