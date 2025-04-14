<?php

namespace Wm\WmPackage\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    public function register(): void
    {
        parent::register();
        $this->reportable(function (Throwable $e) {

            if (app()->bound('sentry') && $this->shouldReport($e) && $this->isWmPackageException($e)) {
                app('sentry')->captureException($e);
            }
        });
    }

    private function isWmPackageException(Throwable $exception)
    {
        return str_contains($exception->getTraceAsString(), 'Wm\\WmPackage\\');
    }
}
