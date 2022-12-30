<?php

namespace Wm\WmPackage\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Wm\WmPackage\WmPackage
 */
class HoquClient extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Wm\WmPackage\Http\HoquClient::class;
    }
}
