<?php

namespace Wm\WmPackage\Models\Abstracts;

use Wm\WmPackage\Traits\HasPackageFactory;

abstract class Linestring extends GeometryModel
{
    use HasPackageFactory;
}
