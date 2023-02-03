<?php

namespace Wm\WmPackage\Model;

use Wm\WmPackage\Enums\JobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * This code creates a class called HoquProcessorJob that extends the Model class.
 * It represents a job of Processor instance
 */
class HoquProcessorJob extends Model
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
        'output',
        'status'
    ];
}
