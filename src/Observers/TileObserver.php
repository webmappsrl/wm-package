<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\Tile;

class TileObserver extends AbstractObserver
{
    public function saved(Tile $tile): void
    {
        $apps = $tile->apps()->get();

        if ($apps->isEmpty()) {
            return;
        }

        foreach ($apps as $app) {
            UpdateAppConfigJob::dispatch($app->id);
        }
    }
}

