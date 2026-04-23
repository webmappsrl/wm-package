<?php

namespace Wm\WmPackage\Listeners;

use Wm\WmPackage\Events\OrderListReorderedEvent;
use Wm\WmPackage\Jobs\Pbf\DispatcherAppPbfsDebouncedJob;
use Wm\WmPackage\Models\Layer;

class OrderListReorderedListener
{
    public function handle(OrderListReorderedEvent $event): void
    {
        if (
            $event->modelClass === Layer::class
            && $event->orderColumn === 'rank'
            && $event->scopeColumn === 'app_id'
            && is_numeric($event->scopeValue)
        ) {
            DispatcherAppPbfsDebouncedJob::dispatch((int) $event->scopeValue)
                ->delay(now()->addMinutes(5));
        }
    }
}
