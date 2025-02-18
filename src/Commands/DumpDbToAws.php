<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Spatie\DbDumper\Compressors\GzipCompressor;
use Spatie\DbDumper\Databases\PostgreSql;
use Wm\WmPackage\Services\StorageService;

class DumpDbToAws extends Command
{
    protected $signature = 'db:dump_to_aws';
    protected $description = 'Create a dump of the database and upload it to AWS';

    public function handle()
    {
        $connectionName = config('database.default');
        $connection = config("database.connections.{$connectionName}");

        $timestamp = now()->format('Y_m_d');
        $fileName = "dump_{$timestamp}.sql.gz";
        $backupsPath = storage_path('app/backups');
        if (!file_exists($backupsPath)) {
            mkdir($backupsPath, 0755, true);
        }
        $localPath = "{$backupsPath}/{$fileName}";

        try {
            PostgreSql::create()
                ->setDumpBinaryPath('/usr/bin')
                ->setDbName($connection['database'])
                ->setUserName($connection['username'])
                ->setPassword($connection['password'])
                ->setHost($connection['host'])
                ->setPort($connection['port'] ?? 5432)
                ->useCompressor(new GzipCompressor())
                ->dumpToFile($localPath);
        } catch (\Exception $e) {
            $this->error("Error creating the database dump: " . $e->getMessage());
            return 1;
        }

        $this->info("Dump created locally: {$localPath}");

        $dumpFileContent = file_get_contents($localPath);
        if (!$dumpFileContent) {
            $this->error("Unable to read the generated dump");
            return 1;
        }

        $remotePath = 'maphub/' . config('app.name', 'wmdumps') . '/' . $fileName;

        try {
            $storageService = app(StorageService::class);
            $storageService->storeDbDumpToAws($remotePath, $dumpFileContent);
            // Delete local dump file after successful upload
            if (file_exists($localPath)) {
                unlink($localPath);
            }
        } catch (\Exception $e) {
            $this->error("Error uploading the database dump to AWS: " . $e->getMessage());
            return 1;
        }

        $this->info("Dump uploaded correctly to AWS: {$remotePath}");

        try {
            $storageService->cleanOldDumpsFromAws('maphub/' . config('app.name', 'camminiditalia'));
        } catch (\Exception $e) {
            $this->error("Error cleaning old dumps from AWS: " . $e->getMessage());
        }

        return 0;
    }
}
