<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class WmRestoreDbCommand extends Command
{
    protected $signature = 'wm:restore-db {--file=last_dump.sql.gz : The dump file to restore (relative to storage/backups)} {--wipe : Wipe database before restore (default: true)} {--no-wipe : Skip wiping database before restore}';

    protected $description = 'Restores a database dump from storage/backups directory. Uses Laravel database configuration.';

    public function handle(): int
    {
        $this->info('Starting database restore process...');

        $dumpFileName = $this->option('file');
        $backupsDisk = Storage::disk('backups');
        $dumpGzPath = $backupsDisk->path($dumpFileName);

        // Check if dump file exists
        if (! $backupsDisk->exists($dumpFileName)) {
            $this->error("Dump file '{$dumpFileName}' not found in storage/backups directory.");
            $this->comment("Expected path: {$dumpGzPath}");

            return Command::FAILURE;
        }

        $this->info("Found dump file: {$dumpGzPath}");

        // Determine if we should wipe the database
        // --no-wipe takes precedence over --wipe
        $wipeDatabase = ! $this->option('no-wipe');

        try {
            // Get database config to determine if we're in Docker
            $dbConfig = config('database.connections.pgsql');
            $dbHost = $dbConfig['host'];

            // Wipe database if requested
            if ($wipeDatabase) {
                $this->info('Wiping database...');
                $this->wipeDatabase($dbConfig);
                $this->info('Database wiped successfully.');
            } else {
                $this->warn('Skipping database wipe (--no-wipe specified).');
            }

            // Decompress and import
            $this->info('Decompressing and importing dump...');
            $this->importDump($dumpGzPath);

            $this->info('Database restore completed successfully!');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('An error occurred during the restore process: '.$e->getMessage());
            Log::error('WmRestoreDbCommand failed: '.$e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    private function wipeDatabase(array $dbConfig): void
    {
        $dbName = $dbConfig['database'];
        $dbUser = $dbConfig['username'];
        $dbHost = $dbConfig['host'];
        $dbPassword = $dbConfig['password'] ?? '';

        // Close all Laravel database connections first
        try {
            DB::disconnect('pgsql');
        } catch (\Exception $e) {
            // Ignore errors when disconnecting
        }

        // Use direct psql connection with the configured host
        $this->info("Wiping database via PostgreSQL connection to {$dbHost}...");

        // Terminate all active connections to the database
        $this->info('Terminating active connections...');

        // First, prevent new connections
        $limitConnectionsCmd = sprintf(
            'PGPASSWORD=%s psql -h %s -U %s -d postgres -c "ALTER DATABASE \"%s\" WITH CONNECTION LIMIT 0;"',
            escapeshellarg($dbPassword),
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            addcslashes($dbName, '"')
        );

        $process = Process::fromShellCommandline($limitConnectionsCmd);
        $process->run(); // Ignore errors

        // Then terminate all existing connections - use proper SQL string quoting
        $dropConnectionsCmd = sprintf(
            'PGPASSWORD=%s psql -h %s -U %s -d postgres -c "SELECT pg_terminate_backend(pg_stat_activity.pid) FROM pg_stat_activity WHERE pg_stat_activity.datname = %s AND pg_stat_activity.pid <> pg_backend_pid();"',
            escapeshellarg($dbPassword),
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            escapeshellarg($dbName)
        );

        // Try multiple times to ensure all connections are closed
        for ($i = 0; $i < 5; $i++) {
            $process = Process::fromShellCommandline($dropConnectionsCmd);
            $process->run(); // Ignore errors
            sleep(1);
            
            // Check if there are still active connections
            $checkConnectionsCmd = sprintf(
                'PGPASSWORD=%s psql -h %s -U %s -d postgres -t -c "SELECT COUNT(*) FROM pg_stat_activity WHERE datname = %s AND pid <> pg_backend_pid();"',
                escapeshellarg($dbPassword),
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbName)
            );
            
            $checkProcess = Process::fromShellCommandline($checkConnectionsCmd);
            $checkProcess->run();
            $activeConnections = trim($checkProcess->getOutput());
            
            if ($activeConnections === '0' || $activeConnections === '') {
                $this->info('All connections terminated.');
                break;
            }
            
            $this->warn("Still {$activeConnections} active connection(s), retrying...");
        }

        // Final check before dropping
        $finalCheckCmd = sprintf(
            'PGPASSWORD=%s psql -h %s -U %s -d postgres -t -c "SELECT COUNT(*) FROM pg_stat_activity WHERE datname = %s AND pid <> pg_backend_pid();"',
            escapeshellarg($dbPassword),
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            escapeshellarg($dbName)
        );
        
        $finalCheckProcess = Process::fromShellCommandline($finalCheckCmd);
        $finalCheckProcess->run();
        $finalActiveConnections = trim($finalCheckProcess->getOutput());
        
        if ($finalActiveConnections !== '0' && $finalActiveConnections !== '') {
            // Force terminate with a more aggressive query
            $this->warn("Force terminating remaining connections...");
            $forceTerminateCmd = sprintf(
                'PGPASSWORD=%s psql -h %s -U %s -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = %s;"',
                escapeshellarg($dbPassword),
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbName)
            );
            $forceProcess = Process::fromShellCommandline($forceTerminateCmd);
            $forceProcess->run();
            sleep(2);
        }

        // Drop database - use identifier quoting for database name
        $this->info('Dropping database...');
        $dropDbCmd = sprintf(
            'PGPASSWORD=%s psql -h %s -U %s -d postgres -c "DROP DATABASE IF EXISTS \"%s\";"',
            escapeshellarg($dbPassword),
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            addcslashes($dbName, '"')
        );

        $process = Process::fromShellCommandline($dropDbCmd);
        $process->mustRun();

        // Create database - use identifier quoting for database name
        $this->info('Creating database...');
        $createDbCmd = sprintf(
            'PGPASSWORD=%s psql -h %s -U %s -d postgres -c "CREATE DATABASE \"%s\" OWNER \"%s\";"',
            escapeshellarg($dbPassword),
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            addcslashes($dbName, '"'),
            addcslashes($dbUser, '"')
        );

        $process = Process::fromShellCommandline($createDbCmd);
        $process->mustRun();
    }

    private function importDump(string $dumpGzPath): void
    {
        $dbConfig = config('database.connections.pgsql');
        $dbName = $dbConfig['database'];
        $dbUser = $dbConfig['username'];
        $dbHost = $dbConfig['host'];
        $dbPort = $dbConfig['port'] ?? 5432;
        $dbPassword = $dbConfig['password'] ?? '';

        // Use direct psql connection with the configured host
        $this->info("Importing via connection to {$dbHost}:{$dbPort}");

        // Set PGPASSWORD as environment variable and run the command
        $env = [];
        if ($dbPassword) {
            $env['PGPASSWORD'] = $dbPassword;
        }

        $psqlCommand = sprintf(
            'gunzip -c %s | psql -h %s -p %s -U %s -d %s',
            escapeshellarg($dumpGzPath),
            escapeshellarg($dbHost),
            escapeshellarg($dbPort),
            escapeshellarg($dbUser),
            escapeshellarg($dbName)
        );

        $process = Process::fromShellCommandline($psqlCommand);
        $process->setTimeout(3600); // 1 hour timeout for large dumps
        $process->setIdleTimeout(300); // 5 minutes idle timeout

        // Set environment variables
        if (! empty($env)) {
            $process->setEnv($env);
        }

        try {
            $process->mustRun(function ($type, $buffer) {
                if ($type === Process::ERR) {
                    $this->warn(trim($buffer));
                } else {
                    $this->line(trim($buffer));
                }
            });
            $this->info('Dump imported successfully.');
        } catch (ProcessFailedException $exception) {
            $this->error('Import failed: '.$exception->getMessage());
            throw $exception;
        }
    }
}
