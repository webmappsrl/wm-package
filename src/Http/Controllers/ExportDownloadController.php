<?php

namespace Wm\WmPackage\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class ExportDownloadController extends Controller
{
    public function download($fileName)
    {
        if (!Storage::disk('public')->exists($fileName)) {
            abort(404);
        }

        $filePath = Storage::disk('public')->path($fileName);
        $mimeType = Storage::disk('public')->mimeType($fileName);

        return response()->file($filePath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename=' . $fileName
        ])->deleteFileAfterSend(true);
    }
}
