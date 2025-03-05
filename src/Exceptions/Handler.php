<?php

namespace Wm\WmPackage\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Throwable;
use Wm\WmPackage\Facades\WmLogger;

class Handler extends ExceptionHandler
{
    public function report(Throwable $exception)
    {
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
