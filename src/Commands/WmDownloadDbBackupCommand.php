<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class WmDownloadDbBackupCommand extends Command
{
    protected $signature = 'wm:download-db-backup {--file= : Specify a direct backup file path on wmdumps disk to restore from.} {--latest : Restore the latest available DB backup (default behavior if no --file is specified)} {--s3 : Force use of real AWS S3 instead of MinIO by removing endpoint configuration}';

    protected $description = 'Downloads a database dump (.zip) from wmdumps, extracts the .sql.gz file, and saves it to the \'backups\' disk as last_dump.sql.gz.';

    public function handle(): int
    {
        $this->info('Starting database backup download and extraction process...');

        $localTempStorage = Storage::disk('local'); // For temporary operations
        $backupStorageDisk = Storage::disk('backups'); // Target disk for the final .sql.gz

        $tempBaseDir = 'temp_db_download'; // More specific temp dir name
        $tempExtractDir = $tempBaseDir . '/sql_extract';
        $zipBackupFilename = 'latest_db_dump_for_restore.sql.zip';
        $localZipBackupPath = $tempBaseDir . '/' . $zipBackupFilename;

        $outputSqlGzFilenameOnBackupDisk = 'last_dump.sql.gz'; // Filename on the 'backups' disk

        try {
            $this->prepareTempDirectories($localTempStorage, $tempBaseDir, $tempExtractDir);

            // If --s3 option is passed, force use of real AWS S3 by removing endpoint
            if ($this->option('s3')) {
                $wmdumpsConfig = config('filesystems.disks.wmdumps', []);
                $wmdumpsConfig['endpoint'] = null;
                // Use AWS_DUMPS_DEFAULT_REGION if set, otherwise AWS_DEFAULT_REGION
                $wmdumpsConfig['region'] = env('AWS_DUMPS_DEFAULT_REGION', env('AWS_DEFAULT_REGION', 'us-east-1'));
                Config::set('filesystems.disks.wmdumps', $wmdumpsConfig);
                // Force Laravel to recreate the disk with new config
                app()->forgetInstance('filesystem.disk.wmdumps');
                $this->info('Using real AWS S3 (endpoint removed, region: ' . $wmdumpsConfig['region'] . ')');
            }

            $remoteBackupDisk = Storage::disk('wmdumps');
            $remoteBackupPath = $this->resolveBackupPath($remoteBackupDisk);

            if ($remoteBackupPath === null) {
                return Command::FAILURE;
            }

            if (! $this->downloadBackup($remoteBackupDisk, $remoteBackupPath, $localTempStorage, $localZipBackupPath)) {
                return Command::FAILURE;
            }

            if (! $this->extractBackup($localTempStorage, $localZipBackupPath, $tempExtractDir)) {
                return Command::FAILURE;
            }

            $extractedSqlGzPath = $this->findSqlGzFile($localTempStorage, $tempExtractDir);

            if ($extractedSqlGzPath === null) {
                return Command::FAILURE;
            }

            // Pass the target disk and desired filename to moveSqlGzToStorage
            if (! $this->moveSqlGzToBackupDisk($extractedSqlGzPath, $backupStorageDisk, $outputSqlGzFilenameOnBackupDisk)) {
                return Command::FAILURE;
            }

            $this->info('Database download and extraction process completed successfully!');
            $finalPathOnBackupDisk = $backupStorageDisk->path($outputSqlGzFilenameOnBackupDisk);
            $this->comment("The extracted .sql.gz has been saved to the 'backups' disk at: {$outputSqlGzFilenameOnBackupDisk} (Resolves to: {$finalPathOnBackupDisk})");
            $this->comment("You can now use this file (e.g., 'storage/backups/{$outputSqlGzFilenameOnBackupDisk}') for your restore script wm-package/src/Scripts/restore_db.sh");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('An error occurred during the download/extraction process: ' . $e->getMessage());
            Log::error('WmDownloadDbBackupCommand failed: ' . $e->getMessage(), [
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
        $this->info("Preparing temporary directories: base='{$storage->path($baseDir)}', extract='{$storage->path($extractDir)}'");
        if (! $storage->exists($baseDir)) {
            $storage->makeDirectory($baseDir);
        }
        // Ensure the extract directory is clean and exists
        if ($storage->exists($extractDir)) {
            $storage->deleteDirectory($extractDir);
        }
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
        // Use app.name as the primary source for the backup directory (folder name on S3 for spatie backup)
        $backupDirName = Config::get('app.name', 'default_app_name');
        // Fallback to backup.backup.name if app.name is not set, and remove dev/local/uat suffix if present
        if ($backupDirName === 'default_app_name' || empty($backupDirName)) {
            $backupDirName = Config::get('backup.backup.name');
        }
        $backupDirName = preg_replace('/(dev|local|uat)$/', '', $backupDirName);

        if (empty($backupDirName)) {
            $this->error('Backup directory name (app name) could not be determined from config(\'app.name\') or config(\'backup.backup.name\').');

            return null;
        }
        $this->info("Searching in wmdumps disk under directory: {$backupDirName}");

        $allFiles = $backupDisk->files($backupDirName);
        $dbBackups = array_filter($allFiles, static function ($file) {
            // The file path from $backupDisk->files($backupDirName) might be like "appName/only_db_....zip"
            return preg_match('/only_db_\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}\.zip$/', basename($file));
        });

        if (empty($dbBackups)) {
            $this->error("No database backups found in '{$backupDirName}' on wmdumps disk matching the pattern 'only_db_*.zip'.");
            Log::error("No .zip database backups found in '{$backupDirName}' on wmdumps disk.", ['searched_directory' => $backupDirName]);

            return null;
        }

        // Sort by the full path as returned by S3 (which includes directory) to ensure correct order if multiple files exist
        usort($dbBackups, static fn($a, $b) => strcmp($b, $a)); // Sorts descending Z-A
        $latest = $dbBackups[0];
        $this->info("Latest database backup found: {$latest}");

        return $latest;
    }

    private function downloadBackup($backupDisk, string $remotePath, $localStorage, string $localPath): bool
    {
        $this->info("Downloading '{$remotePath}' from wmdumps to temporary path '{$localStorage->path($localPath)}'...");
        $fileContents = $backupDisk->get($remotePath);
        if ($fileContents === null) {
            $this->error("Failed to read database backup '{$remotePath}' from wmdumps disk.");
            Log::error("Failed to read database backup '{$remotePath}' from wmdumps disk.");

            return false;
        }
        $localStorage->put($localPath, $fileContents);
        $this->info('Database backup downloaded successfully to temp path.');

        return true;
    }

    private function extractBackup($localStorage, string $localZipPath, string $extractDir): bool
    {
        $this->info("Extracting SQL dump from '{$localStorage->path($localZipPath)}' to temporary directory '{$localStorage->path($extractDir)}'...");
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
                    Log::warning('Zip extraction (stderr): ' . trim($buffer));
                }
            });
            $this->info('Archive extracted successfully to temp directory.');

            return true;
        } catch (ProcessFailedException $exception) {
            $this->error('Zip extraction failed: ' . $exception->getMessage());
            Log::error('Zip extraction process failed: ' . $exception->getMessage());

            return false;
        }
    }

    private function findSqlGzFile($localStorage, string $extractDir): ?string
    {
        // Path to where `unzip` extracts the `db-dumps` folder from the archive
        $dbDumpsInExtractDir = $localStorage->path($extractDir . '/db-dumps');
        $this->info("Searching for .sql.gz file in extracted path: {$dbDumpsInExtractDir}");

        $finder = new Finder;
        // Ensure the directory exists before searching
        if (! is_dir($dbDumpsInExtractDir)) {
            $this->error("The directory 'db-dumps' was not found inside the extracted archive at path: {$dbDumpsInExtractDir}. Archive structure might be unexpected.");
            Log::error("Directory 'db-dumps' not found in extracted archive.", ['expected_db_dumps_path' => $dbDumpsInExtractDir]);

            return null;
        }

        $finder->files()->in($dbDumpsInExtractDir)->name('*.sql.gz')->sortByModifiedTime()->reverseSorting();

        if (! $finder->hasResults()) {
            $this->error("No .sql.gz file found in the extracted archive at '{$dbDumpsInExtractDir}'.");
            Log::error('No .sql.gz file found in extracted archive.', ['extract_path' => $dbDumpsInExtractDir]);

            return null;
        }

        // Get the first result (latest by modified time due to reverseSorting)
        $sqlGzFile = null;
        foreach ($finder as $file) {
            $sqlGzFile = $file; // Assigns the SplFileInfo object
            break;
        }

        $extractedSqlGzPath = $sqlGzFile->getRealPath(); // Path to the .sql.gz file in the temp extract directory
        $this->info("Found SQL GZ file: {$extractedSqlGzPath}");

        return $extractedSqlGzPath;
    }

    /**
     * Move the .sql.gz file to the target backup disk.
     */
    private function moveSqlGzToBackupDisk(string $sourceExtractedSqlGzPath, $targetBackupDisk, string $targetFilenameOnDisk): bool
    {
        $this->info("Attempting to move extracted SQL GZ from '{$sourceExtractedSqlGzPath}' to disk '{$targetBackupDisk->getConfig()['driver']}:{$targetBackupDisk->path('')}' as '{$targetFilenameOnDisk}'...");

        // Delete if already exists on the target disk
        if ($targetBackupDisk->exists($targetFilenameOnDisk)) {
            $this->warn("File '{$targetFilenameOnDisk}' already exists on disk '{$targetBackupDisk->getConfig()['driver']}'. Deleting old version.");
            $targetBackupDisk->delete($targetFilenameOnDisk);
        }

        // Stream the file to the target disk
        $fileStream = fopen($sourceExtractedSqlGzPath, 'r');
        if (! $fileStream) {
            $this->error("Failed to open stream for source file: {$sourceExtractedSqlGzPath}");
            Log::error('Failed to open stream for source SQL GZ file.', ['path' => $sourceExtractedSqlGzPath]);

            return false;
        }

        if ($targetBackupDisk->put($targetFilenameOnDisk, $fileStream)) {
            if (is_resource($fileStream)) {
                fclose($fileStream);
            }
            $this->info("Database dump successfully saved to disk '{$targetBackupDisk->getConfig()['driver']}' as '{$targetFilenameOnDisk}'.");

            return true;
        }

        if (is_resource($fileStream)) {
            fclose($fileStream);
        }
        $this->error("Failed to save SQL GZ file to disk '{$targetBackupDisk->getConfig()['driver']}' as '{$targetFilenameOnDisk}'.");
        Log::error('Failed to save SQL GZ file to target disk.', ['target_disk' => $targetBackupDisk->getConfig()['driver'], 'target_filename' => $targetFilenameOnDisk]);

        return false;
    }

    private function cleanup($localStorage, string $zipPath, string $extractDir): void
    {
        if (! $localStorage) {
            return;
        }
        $this->info('Cleaning up temporary files from local storage...');
        if ($localStorage->exists($zipPath)) {
            $localStorage->delete($zipPath);
        }
        if ($localStorage->exists($extractDir)) {
            $localStorage->deleteDirectory($extractDir);
        }
        $this->info('Temporary files cleanup complete.');
    }
}
