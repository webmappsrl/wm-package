<?php

namespace Wm\WmPackage\Models\Abstracts;

use Illuminate\Database\Eloquent\Factories\HasFactory;

abstract class Linestring extends GeometryModel
{
    use HasPackageFactory;
}
