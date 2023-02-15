<?php

namespace Wm\WmPackage\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Wm\WmPackage\WmPackage
 */
class ProcessorClient extends Facade
{
    /**
     * Undocumented function
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return \Wm\WmPackage\Http\ProcessorClient::class;
    }
}
