<?php

namespace Wm\WmPackage\Models\Abstracts;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Wm\WmPackage\Traits\HasPackageFactory;

abstract class Point extends GeometryModel
{
    use HasPackageFactory;
}
