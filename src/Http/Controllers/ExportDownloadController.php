<?php

namespace Wm\WmPackage\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Services\StorageService;

class ExportDownloadController extends Controller
{
    public function download($fileName)
    {
        $storageService = StorageService::make();
        $publicDisk = $storageService->getPublicDisk();
        if (! $publicDisk->exists($fileName)) {
            abort(404);
        }

        $filePath = $publicDisk->path($fileName);
        $mimeType = $publicDisk->mimeType($fileName);

        return response()->file($filePath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename=' . $fileName,
        ])->deleteFileAfterSend(true);
    }
}
