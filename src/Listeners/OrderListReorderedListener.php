<?php

namespace Wm\WmPackage\Listeners;

use Wm\WmPackage\Events\OrderListReordered;
use Wm\WmPackage\Jobs\Pbf\RegenerateAppPbfsDebouncedJob;
use Wm\WmPackage\Models\Layer;

class OrderListReorderedListener
{
    public function handle(OrderListReordered $event): void
    {
        if (
            $event->modelClass === Layer::class
            && $event->orderColumn === 'rank'
            && $event->scopeColumn === 'app_id'
            && is_numeric($event->scopeValue)
        ) {
            RegenerateAppPbfsDebouncedJob::dispatch((int) $event->scopeValue)
                ->delay(now()->addMinutes(5));
        }
    }
}

