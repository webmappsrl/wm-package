<?php

use Wm\WmPackage\Models\Media;
use Wm\WmPackage\Support\PathGenerator\WmfePathGenerator;

return [
    /*
     * The fully qualified class name of the media model.
     */
    'media_model' => Media::class,
    /*
     * The disk name to use for media storage.
     */
    'disk_name' => env('MEDIA_DISK', 'wmfe'),
    /*
     * The path generator to use for media storage.
     */
    'path_generator' => WmfePathGenerator::class,

    'queue_conversions_after_database_commit' => true,

    'max_file_size' => 1024 * 1024 * 20,
];
