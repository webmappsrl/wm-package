<?php

return [
    /*
     * The fully qualified class name of the media model.
     */
    'media_model' => Wm\WmPackage\Models\Media::class,
    /*
     * The disk name to use for media storage.
     */
    'disk_name' => env('MEDIA_DISK', 'wmfe'),
    /*
     * The path generator to use for media storage.
     */
    'path_generator' => \Wm\WmPackage\Support\PathGenerator\WmfePathGenerator::class,

    'queue_conversions_after_database_commit' => false,
];
