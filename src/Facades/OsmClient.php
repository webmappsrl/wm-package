<?php

namespace Wm\WmPackage\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Wm\WmPackage\WmPackage
 */
class OsmClient extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Wm\WmPackage\Http\OsmClient::class;
    }
}
