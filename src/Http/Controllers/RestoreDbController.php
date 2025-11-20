<?php

namespace Wm\WmPackage\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class RestoreDbController extends Controller
{
    /**
     * Show the restore confirmation page
     */
    public function show(): View|RedirectResponse
    {
        Log::info('RestoreDbController@show called', [
            'url' => request()->url(),
            'user' => auth()->user()?->id,
        ]);

        // Authentication is already checked by 'auth' middleware, but we verify role
        if (! auth()->user()->hasRole('Administrator')) {
            abort(403, 'Unauthorized action.');
        }

        // Restore is only allowed in non-production environments
        if (App::environment('production')) {
            return redirect()->back()->with('error', 'Database restore is not allowed in production environment.');
        }

        // Check if dump file exists
        $backupsDisk = Storage::disk('backups');
        $filename = 'last_dump.sql.gz';
        $dumpExists = $backupsDisk->exists($filename);

        return view('wm-package::restore-db', [
            'dumpExists' => $dumpExists,
            'filename' => $filename,
        ]);
    }

    public function restore(Request $request): JsonResponse|RedirectResponse
    {
        // Authentication is already checked by 'auth' middleware, but we verify role
        if (! auth()->user()->hasRole('Administrator')) {
            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json(['error' => 'Unauthorized action.'], 403);
            }
            abort(403, 'Unauthorized action.');
        }

        // Restore is only allowed in non-production environments
        if (App::environment('production')) {
            return response()->json([
                'error' => 'Database restore is not allowed in production environment.',
            ], 403);
        }

        // Check if dump file exists
        $backupsDisk = Storage::disk('backups');
        $filename = 'last_dump.sql.gz';

        if (! $backupsDisk->exists($filename)) {
            Log::error("RestoreDbController: File '{$filename}' not found on backups disk.");

            return response()->json([
                'error' => 'Database dump file not found.',
                'message' => "File '{$filename}' not found in storage/backups directory.",
            ], 404);
        }

        try {
            // Close all Laravel database connections first
            Log::info('RestoreDbController: Closing all Laravel database connections...');
            try {
                foreach (array_keys(config('database.connections')) as $connection) {
                    try {
                        DB::disconnect($connection);
                    } catch (\Exception $e) {
                        // Ignore errors when disconnecting
                    }
                }
                Log::info('RestoreDbController: All Laravel connections closed.');
            } catch (\Throwable $e) {
                Log::warning('RestoreDbController: Error closing Laravel connections: '.$e->getMessage());
            }

            // Run the restore command (it will handle closing all PostgreSQL connections)
            $exitCode = Artisan::call('wm:restore-db', [
                '--no-wipe' => $request->boolean('no_wipe', false),
            ]);

            if ($exitCode === 0) {
                $output = Artisan::output();
                Log::info('RestoreDbController: Database restore completed successfully.');

                // Run migrations after successful restore
                Log::info('RestoreDbController: Running migrations after restore...');
                $migrateExitCode = Artisan::call('migrate', ['--force' => true]);
                $migrateOutput = Artisan::output();

                if ($migrateExitCode === 0) {
                    Log::info('RestoreDbController: Migrations completed successfully.');
                    $output .= "\n\nMigrations:\n".$migrateOutput;
                } else {
                    Log::error('RestoreDbController: Migrations failed after restore.', [
                        'exit_code' => $migrateExitCode,
                        'output' => $migrateOutput,
                    ]);
                    $output .= "\n\nMigrations failed:\n".$migrateOutput;
                }

                // If request expects JSON, return JSON response
                if ($request->expectsJson() || $request->wantsJson()) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Database restore completed successfully.'.($migrateExitCode === 0 ? ' Migrations applied.' : ' Migrations failed - check logs.'),
                        'output' => $output,
                    ]);
                }

                // Otherwise redirect back with success message
                $message = 'Database restore completed successfully.';
                if ($migrateExitCode === 0) {
                    $message .= ' Migrations applied.';
                } else {
                    $message .= ' Migrations failed - check logs.';
                }

                return redirect()->back()->with('success', $message);
            } else {
                $output = Artisan::output();
                Log::error('RestoreDbController: Database restore failed.', ['exit_code' => $exitCode, 'output' => $output]);

                if ($request->expectsJson() || $request->wantsJson()) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Database restore failed.',
                        'output' => $output,
                    ], 500);
                }

                return redirect()->back()->with('error', 'Database restore failed. Check logs for details.');
            }
        } catch (\Throwable $e) {
            Log::error('RestoreDbController: Exception during restore.', [
                'exception' => $e,
                'message' => $e->getMessage(),
            ]);

            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => 'An error occurred during restore: '.$e->getMessage(),
                ], 500);
            }

            return redirect()->back()->with('error', 'An error occurred during restore: '.$e->getMessage());
        }
    }
}
