<?php

namespace Wm\WmPackage\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Facades\WmLogger;

class Handler extends ExceptionHandler
{
    public function report(Throwable $exception)
    {
        //if failed job, log to geohub-import channel
        if ($this->isPackageException($exception)) {
            WmLogger::exceptions()->error($exception->getMessage(), ['exception' => $exception]);
        }

        parent::report($exception);
    }

    public function render($request, Throwable $exception)
    {
        return parent::render($request, $exception);
    }

    private function isPackageException(Throwable $exception)
    {
        return strpos(get_class($exception), 'Wm\\WmPackage\\') === 0;
    }
}
