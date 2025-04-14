<?php

namespace Wm\WmPackage\Services\Models\App;

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\BaseService;

abstract class AppBaseService extends BaseService
{
    public function __construct(protected App $app) {}
}
