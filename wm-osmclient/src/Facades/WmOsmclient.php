<?php

namespace Wm\WmOsmclient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Wm\WmOsmclient\WmOsmclient
 */
class WmOsmclient extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Wm\WmOsmclient\WmOsmclient::class;
    }
}
