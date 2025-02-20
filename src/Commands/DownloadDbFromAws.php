<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Wm\WmPackage\Services\StorageService;

class DownloadDbFromAws extends Command
{
    protected $signature = 'db:download_from_aws {appName}';

    protected $description = 'Download the most recent dump from AWS and import it into the local database. The appName is the name of the application contained in the wmdumps bucket.';

    public function handle()
    {
        $appName = $this->argument('appName');

        $storageService = app(StorageService::class);
        $disk = $storageService->getWmDumpsDisk();

        $files = $disk->files($appName);

        if (empty($files)) {
            $this->error("No dumps found in AWS path: wmdumps/{$appName}");

            return 1;
        }

        usort($files, function ($a, $b) use ($disk) {
            return $disk->lastModified($b) <=> $disk->lastModified($a);
        });

        $mostRecentFile = $files[0];
        $dumpContent = $storageService->getDbDumpFromAws($mostRecentFile);

        if (! $dumpContent) {
            $this->error("Unable to read dump: {$mostRecentFile}");

            return 1;
        }

        $localDirectory = storage_path('app/backups');
        if (! is_dir($localDirectory)) {
            mkdir($localDirectory, 0755, true);
        }

        $localPath = $localDirectory.'/'.basename($mostRecentFile);
        file_put_contents($localPath, $dumpContent);

        $this->info("Dump downloaded successfully: {$localPath}");

        if (! $this->confirm('Do you want to import the downloaded database dump?')) {
            $this->info('Database import skipped.');

            return 0;
        }

        $this->info('Preparing to import into local database...');

        // Drop the existing database to avoid conflicts (drop all tables, views, etc.)
        $this->info('Wiping current database...');
        Artisan::call('db:wipe');
        $this->info('Database wiped successfully.');

        // Import the dump into the local database
        $connectionName = config('database.default');
        $connection = config("database.connections.{$connectionName}");

        $host = $connection['host'];
        $port = $connection['port'] ?? 5432;
        $username = $connection['username'];
        $databaseName = $connection['database'];
        $password = $connection['password'];

        // Set the environment variable for psql
        putenv("PGPASSWORD={$password}");

        // Command to decompress and import the dump
        $command = 'gunzip -c '.escapeshellarg($localPath).
            ' | psql -h '.escapeshellarg($host).
            ' -p '.escapeshellarg($port).
            ' -U '.escapeshellarg($username).
            ' '.escapeshellarg($databaseName);

        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            $this->error("Error during database import. Command: {$command}");

            return 1;
        }

        $this->info("Database successfully imported from dump: {$localPath}");

        return 0;
    }
}
