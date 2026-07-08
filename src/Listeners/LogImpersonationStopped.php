<?php

namespace Wm\WmPackage\Listeners;

use Laravel\Nova\Events\StoppedImpersonating;
use Wm\WmPackage\Listeners\Abstracts\ImpersonationLogListener;

class LogImpersonationStopped extends ImpersonationLogListener
{
    protected function verb(): string
    {
        return 'stopped';
    }

    public function handle(StoppedImpersonating $event): void
    {
        $this->log($event);
    }
}
