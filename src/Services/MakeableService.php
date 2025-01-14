<?php

namespace Wm\WmPackage\Services;

abstract class MakeableService
{
    public static function make(): static
    {
        return app()->make(static::class);
    }
}
