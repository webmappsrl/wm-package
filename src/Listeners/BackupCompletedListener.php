<?php

namespace Wm\WmPackage\Listeners;

use Illuminate\Support\Str;
use Spatie\Backup\Events\BackupWasSuccessful;

class BackupCompletedListener
{
    public function handle(BackupWasSuccessful $event)
    {

        // get the latest backup
        $lastBackup = $event->backupDestination->newestBackup();
        if (! $lastBackup) {
            return;
        }

        // check if the backup name contains "only_db" means it's a database backup
        if (! Str::contains($lastBackup->path(), 'only_db')) {
            return;
        }

        // get the backup disk
        $disk = $event->backupDestination->disk();

        // path to the last_dump.sql.gz file on the backup disk
        $lastDumpPath = config('app.name').'/last_dump.zip';

        $stream = $disk->readStream($lastBackup->path());

        $disk->put($lastDumpPath, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}
