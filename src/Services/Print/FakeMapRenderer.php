<?php

namespace Wm\WmPackage\Services\Print;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\Print\Contracts\MapRenderer;

/**
 * Mock in attesa del servizio reale (oc:9011): logga la richiesta e restituisce un PNG segnaposto.
 */
class FakeMapRenderer implements MapRenderer
{
    public function render(MapRenderRequest $request): string
    {
        Log::channel('print')->info('Richiesta mappa (mock, nessuna chiamata HTTP)', $request->toArray());

        return File::get(dirname(__DIR__, 3).'/resources/print/map-placeholder.png');
    }
}
