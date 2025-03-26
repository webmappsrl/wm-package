<?php

return [
    /*
     * The disk on which to store added files and derived images by default. Choose
     * one or more of the disks you've configured in config/filesystems.php.
     */
    'disk_name' => env('MEDIA_DISK', 'wmfe'),

    /*
     * The maximum file size of an item in bytes.
     * Adding a larger file will result in an exception.
     */
    'max_file_size' => 1024 * 1024 * 10, // 10MB

    /*
     * This queue connection will be used to generate derived and responsive images.
     * Leave empty to use the default queue connection.
     */
    'queue_connection_name' => env('QUEUE_CONNECTION', 'sync'),

    /*
     * This queue will be used to generate derived and responsive images.
     * Leave empty to use the default queue.
     */
    'queue_name' => '',

    /*
     * By default all conversions will be performed on a queue.
     */
    'queue_conversions_by_default' => env('QUEUE_CONVERSIONS_BY_DEFAULT', true),

    /*
     * The fully qualified class name of the media model.
     */
    'media_model' => \Wm\WmPackage\Models\Media::class,

    /*
     * The fully qualified class name of the model used for temporary uploads.
     */
    'temporary_upload_model' => Spatie\MediaLibrary\MediaCollections\Models\TemporaryUpload::class,

    /*
     * This is the class that is responsible for naming generated files.
     */
    'file_namer' => Spatie\MediaLibrary\Support\FileNamer\DefaultFileNamer::class,

    /*
     * This is the class that is responsible for generating paths for media items.
     */
    'path_generator' => \Wm\WmPackage\Support\PathGenerator\WmfePathGenerator::class,

    /*
     * When urls to files get generated, this class will be called. Use the default
     * if your files are stored locally above the site root or on s3.
     */
    'url_generator' => Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator::class,

    /*
     * Moves media to trash instead of deleting it.
     */
    'moves_media_to_trash' => false,

    /*
     * Whether to activate versioning when urls to files get generated.
     * When activated, this attaches a ?v=xx query string to the URL.
     */
    'version_urls' => false,

    /*
     * The class that contains the strategy for determining a media file's path.
     */
    'path_generator_type' => Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator::class,

    /*
     * Here you can specify which path generator should be used for the given class.
     */
    'custom_path_generators' => [
        // Model::class => PathGenerator::class
        \Wm\WmPackage\Models\EcPoi::class => \Wm\WmPackage\Support\PathGenerator\WmfePathGenerator::class,
        \Wm\WmPackage\Models\EcTrack::class => \Wm\WmPackage\Support\PathGenerator\WmfePathGenerator::class,
        \Wm\WmPackage\Models\Layer::class => \Wm\WmPackage\Support\PathGenerator\WmfePathGenerator::class,
    ],

    /*
     * When converting Media instances to response the media library will add
     * a `loading` attribute to the `img` tag. Here you can specify which
     * value that attribute should receive.
     */
    'default_loading_attribute_value' => 'auto',

    /*
     * This is the class responsible for generating responsive images.
     * See https://docs.spatie.be/laravel-medialibrary/v9/responsive-images/generating-responsive-images
     */
    'image_generator' => Spatie\MediaLibrary\ResponsiveImages\ResponsiveImageGenerator::class,

    /*
     * The engine that should perform the image conversions.
     * Should be either `gd` or `imagick`.
     */
    'image_driver' => env('IMAGE_DRIVER', 'gd'),

    /*
     * The class that contains the strategy for determining a media file's url.
     */
    'url_generator_class' => Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator::class,

    /*
     * The class that contains the strategy for storing files.
     */
    'filesystem_driver' => Spatie\MediaLibrary\Support\FileSystem\DefaultFileSystem::class,

    /*
     * Define the disk where the media should be stored by default.
     */
    'default_filesystem' => 'wmfe',

    /*
     * Define the path where the media should be stored by default.
     */
    'default_path' => '',

    /*
     * Define the queue that should be used to perform image conversions.
     */
    'conversion_queue' => env('MEDIA_CONVERSION_QUEUE', 'default'),

    /*
     * Define the maximum number of image conversions that can be performed in parallel.
     */
    'max_concurrent_conversions' => env('MEDIA_MAX_CONCURRENT_CONVERSIONS', 1),
];
