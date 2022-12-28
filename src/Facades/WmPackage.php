<?php

namespace Wm\WmPackage\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Wm\WmPackage\WmPackage
 */
class WmPackage extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Wm\WmPackage\WmPackage::class;
    }
}
