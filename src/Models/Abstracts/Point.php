<?php

namespace Wm\WmPackage\Models\Abstracts;

use Illuminate\Database\Eloquent\Factories\HasFactory;

abstract class Point extends GeometryModel
{
    use HasFactory;
}
