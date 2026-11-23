<?php

namespace Wm\WmPackage\Services\Print\Contracts;

use Wm\WmPackage\Services\Print\MapRenderRequest;

/**
 * Renderizza la mappa di una scheda (sentiero, punto o mappa d'insieme).
 */
interface MapRenderer
{
    /**
     * @return string contenuto binario del PNG
     */
    public function render(MapRenderRequest $request): string;
}
