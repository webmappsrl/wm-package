<?php

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

];
