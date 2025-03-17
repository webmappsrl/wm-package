<?php

namespace Wm\WmPackage\Commands;

use Spatie\Backup\Commands\BackupCommand;
use Spatie\Backup\Config\Config;

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


    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->buildConfig();
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
            if (! isset($this->config->backup->destination->filenamePrefix) || empty($this->config->backup->destination->filenamePrefix)) {
                $this->config->backup->destination->filenamePrefix = $filenamePrefix;
            }
        }

        return parent::handle();
    }
}
