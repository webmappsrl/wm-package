<?php

namespace Wm\WmPackage\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HoquCallerJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_id',
        'class',
        'feature_id',
        'field_to_update'
    ];
}
