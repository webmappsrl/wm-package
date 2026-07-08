<?php

namespace Wm\WmPackage\Listeners\Abstracts;

use Illuminate\Support\Facades\Log;

abstract class ImpersonationLogListener
{
    abstract protected function verb(): string;

    protected function log(object $event): void
    {
        Log::info("Nova impersonation {$this->verb()}", [
            'impersonator_id' => $event->impersonator->getAuthIdentifier(),
            'impersonator_email' => $event->impersonator->email ?? null,
            'impersonated_id' => $event->impersonated->getAuthIdentifier(),
            'impersonated_email' => $event->impersonated->email ?? null,
        ]);
    }
}
