<?php

namespace Wm\WmPackage\Facades;

use Illuminate\Support\Facades\Facade;
use Wm\WmPackage\WmPackage;

/**
 * @see WmPackage
 */
class OsmClient extends Facade
{
    /**
     * Undocumented function
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return \Wm\WmPackage\Http\Clients\OsmClient::class;
    }
}
