<?php

namespace Wm\WmPackage\Commands;

use Spatie\Backup\Config\Config;
use Spatie\Backup\Commands\BackupCommand;

class WmBackupCommand extends BackupCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'wm-backup:run {--filename=} {--only-db} {--db-name=*} {--only-files} {--only-to-disk=} {--disable-notifications} {--timeout=} {--tries=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Custom backup command extended from spatie/laravel-backup which add a prefix to the backup file name based on the type of backup';

    public function __construct(Config $config)
    {
        parent::__construct($config);
    }


    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $filenamePrefix = '';
        switch (true) {
            case $this->option('only-db'):
                $filenamePrefix = 'only_db_';
                break;
            case $this->option('only-files'):
                $filenamePrefix = 'only_files_';
                break;
            default:
                $filenamePrefix = '';
                break;
        }

        // Modify the filename prefix in the backup config
        if (property_exists($this->config, 'backup')) {
            if (!isset($this->config->backup->destination->filenamePrefix) || empty($this->config->backup->destination->filenamePrefix)) {
                $this->config->backup->destination->filenamePrefix = $filenamePrefix;
            }
        }

        return parent::handle();
    }
}
