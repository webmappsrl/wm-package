<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Models\OverlayLayer;

class OverlayLayerObserver extends AbstractObserver
{
    /**
     * Handle the OverlayLayer "updating" event.
     *
     * @return void
     */
    public function updating(OverlayLayer $overlay)
    {
        if ($overlay->isDirty('default') && $overlay->default) {
            $overlayLayers = $overlay->app->overlayLayers;
            if ($overlayLayers->count() > 1) {
                foreach ($overlayLayers as $item) {
                    if ($item->id != $overlay->id) {
                        $item->default = false;
                    }
                }
            }
        }
    }
}
