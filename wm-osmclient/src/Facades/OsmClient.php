<?php

namespace Wm\WmOsmclient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Wm\WmOsmclient\WmOsmclient
 */
class OsmClient extends Facade
{
    /**
     * Undocumented function
     *
     * @return \Wm\WmOsmclient\Http\OsmClient
     */
    protected static function getFacadeAccessor()
    {
        return \Wm\WmOsmclient\Http\OsmClient::class;
    }
}
