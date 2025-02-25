<?php


// for the full configuration see: https://spatie.be/docs/laravel-backup/v8/installation-and-setup
return [

    'backup' => [

        'source' => [
            'databases' => [
                'pgsql',
            ],
        ],

        'database_dump_compressor' => \Spatie\DbDumper\Compressors\GzipCompressor::class,

        'destination' => [
            'disks' => [
                'wmdumps',
            ],
        ],
    ],

    'cleanup' => [

        'strategy' => \Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy::class,

        'default_strategy' => [
            'keep_all_backups_for_days' => env('BACKUP_KEEP_ALL_FOR_DAYS', 7),

            'keep_daily_backups_for_days' => env('BACKUP_KEEP_DAILY_FOR_DAYS', 30),

            'keep_weekly_backups_for_weeks' => env('BACKUP_KEEP_WEEKLY_FOR_WEEKS', 4),

            'keep_monthly_backups_for_months' => env('BACKUP_KEEP_MONTHLY_FOR_MONTHS', 0),

            'keep_yearly_backups_for_years' => env('BACKUP_KEEP_YEARLY_FOR_YEARS', 0),

            'delete_oldest_backups_when_using_more_megabytes_than' => env('BACKUP_MAX_SIZE_MB', 5000),
        ],

        'tries' => 1,

        'retry_delay' => 0,
    ],
];
