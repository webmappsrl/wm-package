<?php

namespace Wm\WmPackage\Services;

abstract class BaseService
{
    public static function make(): static
    {
        return app()->make(static::class);
    }
}
