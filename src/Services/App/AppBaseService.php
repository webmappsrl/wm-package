<?php

namespace Wm\WmPackage\Services\App;

use Wm\WmPackage\Models\App;

abstract class AppBaseService
{
    public function __construct(protected App $app) {}
}
