<?php

namespace Wm\WmPackage\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Enums\JobStatus;

/**
 * This code creates a class called HoquCallerJob that extends the Model class.
 * It represents a job of Caller instance
 */
class HoquCallerJob extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        //only some statuses are allowed
        'status' => JobStatus::class,
    ];

    protected $fillable = [
        'job_id',
        'class',
        'feature_id',
        'field_to_update',
        'status',
    ];
}
