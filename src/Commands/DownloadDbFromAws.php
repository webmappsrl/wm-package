<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Wm\WmPackage\Services\StorageService;

class DownloadDbFromAws extends Command
{
    protected $signature = 'db:download_from_aws {appName}';
    protected $description = 'Download the most recent dump from AWS and import it into the local database. The appName is the name of the application contained in the maphub bucket.';

    public function handle()
    {
        $appName = $this->argument('appName');
        $directory = 'maphub/' . $appName;

        $storageService = app(StorageService::class);
        $disk = $storageService->getWmDumpsDisk();

        $files = $disk->files($directory);

        if (empty($files)) {
            $this->error("No dumps found in AWS path: {$directory}");
            return 1;
        }

        usort($files, function ($a, $b) use ($disk) {
            return $disk->lastModified($b) <=> $disk->lastModified($a);
        });

        $mostRecentFile = $files[0];
        $dumpContent = $storageService->getDbDumpFromAws($mostRecentFile);

        if (!$dumpContent) {
            $this->error("Unable to read dump: {$mostRecentFile}");
            return 1;
        }

        $localDirectory = storage_path('app/backups');
        if (!is_dir($localDirectory)) {
            mkdir($localDirectory, 0755, true);
        }

        $localPath = $localDirectory . '/' . basename($mostRecentFile);
        file_put_contents($localPath, $dumpContent);

        $this->info("Dump downloaded successfully: {$localPath}");

        if (!$this->confirm('Do you want to import the downloaded database dump?')) {
            $this->info('Database import skipped.');
            return 0;
        }

        $this->info("Preparing to import into local database...");

        // Droppa il database esistente per evitare conflitti (drop di tutte le tabelle, viste, ecc.)
        $this->info("Wiping current database...");
        Artisan::call('db:wipe');
        $this->info("Database wiped successfully.");

        // Importa il dump nel database locale
        $connectionName = config('database.default');
        $connection = config("database.connections.{$connectionName}");

        $host         = $connection['host'];
        $port         = $connection['port'] ?? 5432;
        $username     = $connection['username'];
        $databaseName = $connection['database'];
        $password     = $connection['password'];

        // Imposta la variabile d'ambiente per psql
        putenv("PGPASSWORD={$password}");

        // Comando per decomprimere e importare il dump
        $command = "gunzip -c " . escapeshellarg($localPath) .
            " | psql -h " . escapeshellarg($host) .
            " -p " . escapeshellarg($port) .
            " -U " . escapeshellarg($username) .
            " " . escapeshellarg($databaseName);

        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            $this->error("Error during database import. Command: {$command}");
            return 1;
        }

        $this->info("Database successfully imported from dump: {$localPath}");

        return 0;
    }
}
