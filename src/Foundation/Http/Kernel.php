<?php

namespace Wm\WmPackage\Foundation\Http;

use Wm\WmPackage\Foundation\Bootstrap\LoadConfiguration;

class Kernel extends \Illuminate\Foundation\Http\Kernel
{
    protected $bootstrappers = [
        \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
        //\Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
        LoadConfiguration::class,
        \Illuminate\Foundation\Bootstrap\HandleExceptions::class,
        \Illuminate\Foundation\Bootstrap\RegisterFacades::class,
        \Illuminate\Foundation\Bootstrap\RegisterProviders::class,
        \Illuminate\Foundation\Bootstrap\BootProviders::class,
    ];

}
