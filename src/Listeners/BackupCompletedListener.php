<?php

namespace Wm\WmPackage\Listeners;

use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\StorageService;
use Spatie\Backup\Events\BackupWasSuccessful;

class BackupCompletedListener
{
    public function handle(BackupWasSuccessful $event)
    {
        $lastBackup = $event->backupDestination->newestBackup();
        $localPath = storage_path('app/backups/last_dump.sql.gz');

        if (!file_exists(dirname($localPath))) {
            mkdir(dirname($localPath), 0755, true);
        }

        $disk = $event->backupDestination->disk();
        $backupPath = $event->backupDestination->backupName() . '/' . $lastBackup->path();

        $stream = $disk->readStream($backupPath);
        file_put_contents($localPath, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}
