<?php

namespace Wm\WmPackage\Services\App;

use Wm\WmPackage\Models\App;

abstract class AppBaseService
{

    function __construct(protected App $app) {}
}
