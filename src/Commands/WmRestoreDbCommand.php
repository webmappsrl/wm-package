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

            // Always close all database connections before restore
            $this->info('Closing all database connections...');
            $this->closeAllConnections($dbConfig, true);

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

    /**
     * Close all database connections (Laravel and PostgreSQL)
     *
     * @param  bool  $restoreConnectionLimit  Whether to restore connection limit after closing connections
     */
    private function closeAllConnections(array $dbConfig, bool $restoreConnectionLimit = true): void
    {
        $dbName = $dbConfig['database'];
        $dbUser = $dbConfig['username'];
        $dbHost = $dbConfig['host'];
        $dbPassword = $dbConfig['password'] ?? '';

        // Close all Laravel database connections first
        try {
            DB::disconnect('pgsql');
            // Also try to disconnect all connections
            foreach (array_keys(config('database.connections')) as $connection) {
                try {
                    DB::disconnect($connection);
                } catch (\Exception $e) {
                    // Ignore errors when disconnecting
                }
            }
        } catch (\Exception $e) {
            // Ignore errors when disconnecting
        }

        // Terminate all active PostgreSQL connections to the database
        $this->info("Terminating all active connections to database '{$dbName}'...");

        // First, prevent new connections
        $limitConnectionsCmd = sprintf(
            'PGPASSWORD=%s psql -h %s -U %s -d postgres -c "ALTER DATABASE \"%s\" WITH CONNECTION LIMIT 0;"',
            escapeshellarg($dbPassword),
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            addcslashes($dbName, '"')
        );

        $process = Process::fromShellCommandline($limitConnectionsCmd);
        $process->run();
        if (!$process->isSuccessful()) {
            $this->warn('Failed to set connection limit: '.$process->getErrorOutput());
        }
        
        // Wait a moment for the limit to take effect
        sleep(1);

        // Then terminate all existing connections - use proper SQL string quoting
        $dropConnectionsCmd = sprintf(
            'PGPASSWORD=%s psql -h %s -U %s -d postgres -c "SELECT pg_terminate_backend(pg_stat_activity.pid) FROM pg_stat_activity WHERE pg_stat_activity.datname = \'%s\' AND pg_stat_activity.pid <> pg_backend_pid();"',
            escapeshellarg($dbPassword),
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            addcslashes($dbName, '\'')
        );

        // Try multiple times to ensure all connections are closed
        for ($i = 0; $i < 15; $i++) {
            $this->info("Termination attempt " . ($i + 1) . "/15...");
            $process = Process::fromShellCommandline($dropConnectionsCmd);
            $process->run();
            
            if ($process->isSuccessful()) {
                $output = trim($process->getOutput());
                if (!empty($output)) {
                    $this->line("Terminated: {$output}");
                }
            } else {
                $this->warn('Termination command output: '.$process->getOutput());
                $this->warn('Termination command error: '.$process->getErrorOutput());
            }
            
            sleep(2);

            // Check if there are still active connections
            $checkConnectionsCmd = sprintf(
                'PGPASSWORD=%s psql -h %s -U %s -d postgres -t -c "SELECT COUNT(*) FROM pg_stat_activity WHERE datname = \'%s\' AND pid <> pg_backend_pid();"',
                escapeshellarg($dbPassword),
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                addcslashes($dbName, '\'')
            );

            $checkProcess = Process::fromShellCommandline($checkConnectionsCmd);
            $checkProcess->run();
            $activeConnections = trim($checkProcess->getOutput());
            
            // Also show which connections are still active (for debugging)
            if ($activeConnections !== '0' && $activeConnections !== '') {
                $showConnectionsCmd = sprintf(
                    'PGPASSWORD=%s psql -h %s -U %s -d postgres -c "SELECT pid, usename, application_name, state, query FROM pg_stat_activity WHERE datname = \'%s\' AND pid <> pg_backend_pid() LIMIT 5;"',
                    escapeshellarg($dbPassword),
                    escapeshellarg($dbHost),
                    escapeshellarg($dbUser),
                    addcslashes($dbName, '\'')
                );
                $showProcess = Process::fromShellCommandline($showConnectionsCmd);
                $showProcess->run();
                $this->warn("Active connections details:\n".$showProcess->getOutput());
            }

            if ($activeConnections === '0' || $activeConnections === '') {
                $this->info('All connections terminated.');
                break;
            }

            $this->warn("Still {$activeConnections} active connection(s), retrying...");
        }

        // Final check
        $finalCheckCmd = sprintf(
            'PGPASSWORD=%s psql -h %s -U %s -d postgres -t -c "SELECT COUNT(*) FROM pg_stat_activity WHERE datname = \'%s\' AND pid <> pg_backend_pid();"',
            escapeshellarg($dbPassword),
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            addcslashes($dbName, '\'')
        );

        $finalCheckProcess = Process::fromShellCommandline($finalCheckCmd);
        $finalCheckProcess->run();
        $finalActiveConnections = trim($finalCheckProcess->getOutput());

        if ($finalActiveConnections !== '0' && $finalActiveConnections !== '') {
            // Force terminate with a more aggressive query (including our own connection if needed)
            $this->warn('Force terminating remaining connections (including our own if needed)...');
            $forceTerminateCmd = sprintf(
                'PGPASSWORD=%s psql -h %s -U %s -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = \'%s\';"',
                escapeshellarg($dbPassword),
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                addcslashes($dbName, '\'')
            );
            $forceProcess = Process::fromShellCommandline($forceTerminateCmd);
            $forceProcess->run();
            sleep(3);
            
            // Verify all connections are closed
            $verifyCmd = sprintf(
                'PGPASSWORD=%s psql -h %s -U %s -d postgres -t -c "SELECT COUNT(*) FROM pg_stat_activity WHERE datname = \'%s\';"',
                escapeshellarg($dbPassword),
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                addcslashes($dbName, '\'')
            );
            $verifyProcess = Process::fromShellCommandline($verifyCmd);
            $verifyProcess->run();
            $remainingConnections = trim($verifyProcess->getOutput());
            
            if ($remainingConnections !== '0' && $remainingConnections !== '') {
                $this->error("Warning: Still {$remainingConnections} connection(s) active. This may cause issues.");
            }
        }

        // Restore connection limit only if requested
        if ($restoreConnectionLimit) {
            $restoreConnectionsCmd = sprintf(
                'PGPASSWORD=%s psql -h %s -U %s -d postgres -c "ALTER DATABASE \"%s\" WITH CONNECTION LIMIT -1;"',
                escapeshellarg($dbPassword),
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                addcslashes($dbName, '"')
            );

            $process = Process::fromShellCommandline($restoreConnectionsCmd);
            $process->run(); // Ignore errors
        }
    }

    private function wipeDatabase(array $dbConfig): void
    {
        $dbName = $dbConfig['database'];
        $dbUser = $dbConfig['username'];
        $dbHost = $dbConfig['host'];
        $dbPassword = $dbConfig['password'] ?? '';

        // Use direct psql connection with the configured host
        // Note: All connections should already be closed by closeAllConnections()
        // But we close them again here to be absolutely sure before DROP
        // Don't restore connection limit yet - we'll do it after creating the new database
        $this->info("Wiping database via PostgreSQL connection to {$dbHost}...");
        $this->info('Ensuring all connections are closed before dropping database...');
        $this->closeAllConnections($dbConfig, false);

        // Drop database - try with FORCE first (PostgreSQL 13+), fallback to regular DROP
        $this->info('Dropping database...');
        
        // First, try to get PostgreSQL version to see if FORCE is available
        $versionCmd = sprintf(
            'PGPASSWORD=%s psql -h %s -U %s -d postgres -t -c "SELECT version();"',
            escapeshellarg($dbPassword),
            escapeshellarg($dbHost),
            escapeshellarg($dbUser)
        );
        
        $versionProcess = Process::fromShellCommandline($versionCmd);
        $versionProcess->run();
        $versionOutput = $versionProcess->getOutput();
        $useForce = strpos($versionOutput, 'PostgreSQL 1') !== false && (int)substr($versionOutput, strpos($versionOutput, 'PostgreSQL ') + 11, 2) >= 13;
        
        if ($useForce) {
            $this->info('Using DROP DATABASE ... WITH (FORCE) (PostgreSQL 13+)...');
            $dropDbCmd = sprintf(
                'PGPASSWORD=%s psql -h %s -U %s -d postgres -c "DROP DATABASE IF EXISTS \"%s\" WITH (FORCE);"',
                escapeshellarg($dbPassword),
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                addcslashes($dbName, '"')
            );
        } else {
            // For older PostgreSQL versions, try one more time to close connections
            $this->warn('PostgreSQL < 13 detected. Attempting one final connection termination...');
            $finalTerminateCmd = sprintf(
                'PGPASSWORD=%s psql -h %s -U %s -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = \'%s\';"',
                escapeshellarg($dbPassword),
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                addcslashes($dbName, '\'')
            );
            $finalTerminateProcess = Process::fromShellCommandline($finalTerminateCmd);
            $finalTerminateProcess->run();
            sleep(3);
            
            $dropDbCmd = sprintf(
                'PGPASSWORD=%s psql -h %s -U %s -d postgres -c "DROP DATABASE IF EXISTS \"%s\";"',
                escapeshellarg($dbPassword),
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                addcslashes($dbName, '"')
            );
        }

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

        // Restore connection limit after creating the database
        $this->info('Restoring connection limit...');
        $restoreConnectionsCmd = sprintf(
            'PGPASSWORD=%s psql -h %s -U %s -d postgres -c "ALTER DATABASE \"%s\" WITH CONNECTION LIMIT -1;"',
            escapeshellarg($dbPassword),
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            addcslashes($dbName, '"')
        );

        $process = Process::fromShellCommandline($restoreConnectionsCmd);
        $process->run(); // Ignore errors
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
