<?php

namespace Wm\WmPackage\Http\Controllers;

use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\StorageService;

class DownloadDbController extends Controller
{
    public function download()
    {
        if (! auth()->check() || ! auth()->user()->hasRole('Administrator')) {
            abort(403, 'Unauthorized action.');
        }

        $storageService = app(StorageService::class);
        $backupsDisk = $storageService->getBackupsDisk();

        $filename = 'last_dump.sql.gz';

        if (! $backupsDisk->exists($filename)) {
            Log::error("DownloadDbController: File '{$filename}' not found on disk '{$backupsDisk->getConfig()['driver']}'. Aborting with 404.");
            abort(404, 'Database dump file not found on the specified disk.');
        }

        return response()->download($backupsDisk->path($filename));
    }
}
