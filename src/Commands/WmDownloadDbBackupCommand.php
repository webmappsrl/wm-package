<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class WmDownloadDbBackupCommand extends Command
{
    protected $signature = 'wm:download-db-backup {--file= : Specify a direct backup file path on wmdumps disk to restore from.} {--latest : Restore the latest available DB backup (default behavior if no --file is specified)}';

    protected $description = 'Downloads a database dump (.zip) from wmdumps and extracts the .sql.gz file from db-dumps/ to storage/db-dumps/last_dump.sql.gz for later import. Only runs if the environment is not production.';

    public function handle(): int
    {
        if ($this->isProduction()) {
            $this->error('This command cannot be run in the production environment.');

            return Command::FAILURE;
        }

        $this->info('Starting database backup download and extraction process...');

        $localTempStorage = Storage::disk('local');
        $tempBaseDir = 'temp';
        $tempExtractDir = $tempBaseDir.'/sql_extract';
        $zipBackupFilename = 'latest_db_dump_for_restore.sql.zip';
        $localZipBackupPath = $tempBaseDir.'/'.$zipBackupFilename;
        $outputSqlGzFilename = 'last_dump.sql.gz';
        $outputSqlGzStorageDir = 'db-dumps';
        $outputSqlGzStoragePath = $outputSqlGzStorageDir.'/'.$outputSqlGzFilename;

        // Absolute path for storage/db-dumps/last_dump.sql.gz (not storage/app/db-dumps)
        $absoluteOutputSqlGzPath = base_path('storage/'.$outputSqlGzStoragePath);

        try {
            $this->prepareTempDirectories($localTempStorage, $tempBaseDir, $tempExtractDir);

            $backupDisk = Storage::disk('wmdumps');
            $remoteBackupPath = $this->resolveBackupPath($backupDisk);

            if ($remoteBackupPath === null) {
                return Command::FAILURE;
            }

            if (! $this->downloadBackup($backupDisk, $remoteBackupPath, $localTempStorage, $localZipBackupPath)) {
                return Command::FAILURE;
            }

            if (! $this->extractBackup($localTempStorage, $localZipBackupPath, $tempExtractDir)) {
                return Command::FAILURE;
            }

            $sqlGzPath = $this->findSqlGzFile($localTempStorage, $tempExtractDir);

            if ($sqlGzPath === null) {
                return Command::FAILURE;
            }

            if (! $this->moveSqlGzToStorage($sqlGzPath, $absoluteOutputSqlGzPath)) {
                return Command::FAILURE;
            }

            $this->info('Database download and extraction process completed successfully!');
            $this->comment("You can now run the command 'bash wm-package/src/scripts/restore_db.sh' to wipe and import 'storage/{$outputSqlGzStoragePath}' into your Docker container. Make sure to run the script from the project root outside of Docker.");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('An error occurred during the download/extraction process: '.$e->getMessage());
            Log::error('WmDownloadDbBackupCommand failed: '.$e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        } finally {
            $this->cleanup($localTempStorage ?? null, $localZipBackupPath ?? '', $tempExtractDir ?? '');
        }
    }

    private function isProduction(): bool
    {
        return App::environment('production');
    }

    private function prepareTempDirectories($storage, string $baseDir, string $extractDir): void
    {
        if (! $storage->exists($baseDir)) {
            $storage->makeDirectory($baseDir);
        }
        $storage->deleteDirectory($extractDir);
        $storage->makeDirectory($extractDir);
    }

    private function resolveBackupPath($backupDisk): ?string
    {
        $fileOption = $this->option('file');
        if ($fileOption) {
            if (! $backupDisk->exists($fileOption) || ! str_ends_with($fileOption, '.zip')) {
                $this->error("Specified backup file '{$fileOption}' not found or is not a .zip file on wmdumps disk.");
                Log::error("Specified backup file '{$fileOption}' not found or not a .zip on wmdumps disk.");

                return null;
            }
            $this->info("Using specified backup file: {$fileOption}");

            return $fileOption;
        }

        $this->info('Searching for the latest database backup (.zip) on wmdumps disk...');
        $backupDirName = preg_replace('/dev$/', '', Config::get('backup.backup.name', Config::get('app.name')));
        if (empty($backupDirName)) {
            $this->error('Backup directory name (app name) could not be determined from config(\'backup.backup.name\') or config(\'app.name\').');

            return null;
        }

        $allFiles = $backupDisk->files($backupDirName);
        $dbBackups = array_filter($allFiles, static function ($file) {
            return preg_match('/only_db_\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}\.zip$/', basename($file));
        });

        if (empty($dbBackups)) {
            $this->error("No database backups found in '{$backupDirName}' on wmdumps disk matching the pattern 'only_db_*.zip'.");
            Log::error("No .zip database backups found in '{$backupDirName}' on wmdumps disk.");

            return null;
        }

        usort($dbBackups, static fn ($a, $b) => strcmp(basename($b), basename($a)));
        $latest = $dbBackups[0];
        $this->info("Latest database backup found: {$latest}");

        return $latest;
    }

    private function downloadBackup($backupDisk, string $remotePath, $localStorage, string $localPath): bool
    {
        $this->info("Downloading '{$remotePath}' to '{$localStorage->path($localPath)}'...");
        $fileContents = $backupDisk->get($remotePath);
        if ($fileContents === null) {
            $this->error("Failed to read database backup '{$remotePath}' from wmdumps disk.");
            Log::error("Failed to read database backup '{$remotePath}' from wmdumps disk.");

            return false;
        }
        $localStorage->put($localPath, $fileContents);
        $this->info('Database backup downloaded successfully.');

        return true;
    }

    private function extractBackup($localStorage, string $localZipPath, string $extractDir): bool
    {
        $this->info("Extracting SQL dump from '{$localStorage->path($localZipPath)}' to '{$localStorage->path($extractDir)}'...");
        $unzipCommand = sprintf(
            'unzip -qo %s -d %s',
            escapeshellarg($localStorage->path($localZipPath)),
            escapeshellarg($localStorage->path($extractDir))
        );

        $process = Process::fromShellCommandline($unzipCommand);
        $process->setTimeout(3600);
        $process->setIdleTimeout(60);

        try {
            $process->mustRun(function ($type, $buffer) {
                if ($type === Process::ERR) {
                    $this->warn(trim($buffer));
                    Log::warning('Zip extraction (stderr): '.trim($buffer));
                }
            });
            $this->info('Archive extracted successfully.');

            return true;
        } catch (ProcessFailedException $exception) {
            $this->error('Zip extraction failed: '.$exception->getMessage());
            Log::error('Zip extraction process failed: '.$exception->getMessage());

            return false;
        }
    }

    private function findSqlGzFile($localStorage, string $extractDir): ?string
    {
        $dbDumpsDir = $localStorage->path($extractDir.'/db-dumps');
        $finder = new Finder;
        $finder->files()->in($dbDumpsDir)->name('*.sql.gz')->sortByModifiedTime()->reverseSorting();

        if (! $finder->hasResults()) {
            $this->error("No .sql.gz file found in the extracted archive at '{$dbDumpsDir}'.");
            Log::error('No .sql.gz file found in extracted archive.', ['extract_path' => $dbDumpsDir]);

            return null;
        }

        foreach ($finder as $file) {
            $sqlGzFile = $file;
            break;
        }

        $extractedSqlGzPath = $sqlGzFile->getRealPath();
        $this->info("Found SQL GZ file: {$extractedSqlGzPath}");

        return $extractedSqlGzPath;
    }

    /**
     * Move the .sql.gz file to storage/db-dumps/last_dump.sql.gz (not storage/app/db-dumps).
     */
    private function moveSqlGzToStorage(string $from, string $absoluteToPath): bool
    {
        $storageDir = dirname($absoluteToPath);

        if (! is_dir($storageDir)) {
            if (! mkdir($storageDir, 0777, true) && ! is_dir($storageDir)) {
                $this->error("Failed to create directory: {$storageDir}");
                Log::error('Failed to create directory for SQL GZ file.', ['dir' => $storageDir]);

                return false;
            }
        }

        // Remove if already exists
        if (file_exists($absoluteToPath)) {
            unlink($absoluteToPath);
        }

        // Move the file into storage/db-dumps/last_dump.sql.gz
        if (File::move($from, $absoluteToPath)) {
            $this->info("Database dump successfully moved to: {$absoluteToPath}");

            return true;
        }
        $this->error("Failed to move SQL GZ file from '{$from}' to '{$absoluteToPath}'.");
        Log::error('Failed to move SQL GZ file.', ['from' => $from, 'to' => $absoluteToPath]);

        return false;
    }

    private function cleanup($localStorage, string $zipPath, string $extractDir): void
    {
        if (! $localStorage) {
            return;
        }
        $this->info('Cleaning up temporary files...');
        $localStorage->delete($zipPath);
        $localStorage->deleteDirectory($extractDir);
        $this->info('Cleanup complete.');
    }
}
