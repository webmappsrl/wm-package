<?php

namespace Wm\WmPackage\Helpers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Wm\WmPackage\Services\StorageService;

class GlobalFileHelper
{
    /**
     * Ottiene il percorso base per i file JSON
     */
    private static function getBasePathByType(string $fileType = 'icons'): string
    {
        $storageService = app(StorageService::class);
        return $storageService->getShardBasePath() . 'json/';
    }

    /**
     * Ottiene il contenuto di un file JSON globale
     */
    public static function getJsonContent($filename, $fileType = 'icons', $useCache = true)
    {
        $cacheKey = "file_{$fileType}_{$filename}";
        
        if ($useCache && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }
        
        $filePath = self::getBasePathByType($fileType) . $filename;
        
        if (!Storage::disk('wmfe')->exists($filePath)) {
            return null;
        }
        
        $content = Storage::disk('wmfe')->get($filePath);
        $jsonData = json_decode($content, true);
        
        if ($useCache && $jsonData !== null) {
            // Cache per 1 ora
            Cache::put($cacheKey, $jsonData, now()->addHour());
        }
        
        return $jsonData;
    }
    
    /**
     * Ottiene tutti i file disponibili per un tipo specifico
     */
    public static function getAvailableFiles($fileType = 'icons')
    {
        $filePath = self::getBasePathByType($fileType);
        if (!Storage::disk('wmfe')->exists($filePath)) {
            return [];
        }
        
        $files = Storage::disk('wmfe')->files($filePath);
        $fileList = [];
        
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'json' && 
                !str_ends_with($file, '.meta.json')) {
                $fileList[] = [
                    'filename' => basename($file),
                    'path' => $file,
                    'size' => Storage::disk('wmfe')->size($file),
                    'modified' => Storage::disk('wmfe')->lastModified($file)
                ];
            }
        }
        
        return $fileList;
    }
    
    /**
     * Verifica se un file esiste per un tipo specifico
     */
    public static function fileExists($filename, $fileType = 'icons')
    {
        return Storage::disk('wmfe')->exists(self::getBasePathByType($fileType) . $filename);
    }
    

    
    /**
     * Pulisce la cache di un file specifico
     */
    public static function clearCache($filename, $fileType = 'icons')
    {
        Cache::forget("file_{$fileType}_{$filename}");
    }
    
    /**
     * Pulisce tutta la cache per un tipo specifico di file
     */
    public static function clearAllCache($fileType = 'icons')
    {
        $files = self::getAvailableFiles($fileType);
        foreach ($files as $file) {
            Cache::forget("file_{$fileType}_{$file['filename']}");
        }
    }
}
