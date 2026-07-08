<?php

namespace Wm\WmPackage\Listeners;

use Laravel\Nova\Events\StartedImpersonating;
use Wm\WmPackage\Listeners\Abstracts\ImpersonationLogListener;

class LogImpersonationStarted extends ImpersonationLogListener
{
    protected function verb(): string
    {
        return 'started';
    }

    public function handle(StartedImpersonating $event): void
    {
        $this->log($event);
    }
}
