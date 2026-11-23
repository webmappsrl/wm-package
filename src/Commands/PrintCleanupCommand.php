<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Wm\WmPackage\Services\Print\PrintService;
use Wm\WmPackage\Services\StorageService;

/**
 * Cancella i temporanei della stampa PDF più vecchi di 24 ore (oc:8982):
 *
 * - su wmfe, tutto ciò che sta sotto `{shard}/print-tmp/` (ZIP delle schede e mappe d'insieme) di
 *   **questo** shard: wmfe è condiviso, e la pulizia non tocca i file degli altri shard;
 * - in locale, ogni cartella sotto `storage/app/print-tmp/` (cartelle di lavoro di ZIP, mappe
 *   d'insieme, foto della scheda e del compilatore) rimasta orfana da un Job morto a metà.
 */
class PrintCleanupCommand extends Command
{
    /** Età massima dei temporanei, in secondi. */
    public const MAX_AGE = 86400;

    protected $signature = 'wm:print-cleanup';

    protected $description = 'Cancella i temporanei della stampa PDF (ZIP, mappe di insieme, cartelle di lavoro) più vecchi di 24 ore';

    public function handle(StorageService $storage): int
    {
        $disk = $storage->getMediaDisk();
        $limit = time() - self::MAX_AGE;
        $deleted = 0;

        foreach ($disk->allFiles(PrintService::remoteTmpDirectory()) as $file) {
            if ($disk->lastModified($file) < $limit) {
                $disk->delete($file);
                $deleted++;
            }
        }

        $local = storage_path('app/print-tmp');
        foreach (is_dir($local) ? File::directories($local) : [] as $dir) {
            if (filemtime($dir) < $limit && File::deleteDirectory($dir)) {
                $deleted++;
            }
        }

        $this->info("File temporanei cancellati: {$deleted}");

        return self::SUCCESS;
    }
}
