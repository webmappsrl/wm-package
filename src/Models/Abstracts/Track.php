<?php

namespace Wm\WmPackage\Models\Abstracts;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Wm\WmPackage\Models\User;

abstract class Track extends GeometryModel
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
