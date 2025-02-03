<?php

namespace Wm\WmPackage\Models\Abstracts;

use Illuminate\Database\Eloquent\Factories\HasFactory;

abstract class Linestring extends GeometryModel
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'sku',
        'name',
        'description',
        'geometry',
    ];
}
