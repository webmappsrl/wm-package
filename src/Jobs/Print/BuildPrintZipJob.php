<?php

namespace Wm\WmPackage\Jobs\Print;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Wm\WmPackage\Jobs\Print\Concerns\BuildsPrintSelectionFile;
use Wm\WmPackage\Services\Print\Contracts\PrintableModel;
use Wm\WmPackage\Services\Print\PrintService;
use Wm\WmPackage\Services\Print\PrintStatus;
use Wm\WmPackage\Services\StorageService;
use ZipArchive;

/**
 * Costruisce lo ZIP delle schede di più tracce in una lingua (oc:8982).
 *
 * Job breve che assembla: per ogni scheda non fresca accoda `GeneratePrintPdfJob` (unico per traccia e
 * lingua, quindi ZIP concorrenti condividono il lavoro) e si rilascia ogni 15 s finché sono tutte pronte;
 * allora scarica i PDF in una cartella locale, costruisce lo ZIP e lo carica. Basta una scheda
 * che fallisce perché l'intero ZIP fallisca (messaggio generico all'utente, dettaglio nel log).
 * Unico per selezione e lingua (lock su Redis). Lo ZIP vive su wmfe in `{shard}/print-tmp/zip/` e lo
 * toglie `wm:print-cleanup`.
 */
class BuildPrintZipJob implements ShouldBeUnique, ShouldQueue
{
    use BuildsPrintSelectionFile, Dispatchable, InteractsWithQueue, Queueable;

    /** Sottocartella di `print-tmp` su wmfe, sotto la base dello shard (vedi BuildsPrintSelectionFile). */
    public const SUBDIRECTORY = 'zip';

    public const EXTENSION = 'zip';

    /** Pausa fra un controllo e l'altro delle schede in generazione, in secondi. */
    public const POLL_SECONDS = 15;

    /** Tentativi: ogni attesa è un `release()` e conta come tentativo (vedi `max_wait_seconds`). */
    public int $tries;

    /** Le attese sono release(), non eccezioni: la prima eccezione e' terminale e va dritta a failed(). */
    public int $maxExceptions = 1;

    /** Il lock di unicità copre tutta l'attesa. */
    public int $uniqueFor;

    /** Ogni esecuzione è breve: sotto `retry_after` della coda (660 s su forestas). */
    public int $timeout = 300;

    /** @param  list<int>  $ids  id ordinati e senza duplicati */
    public function __construct(public array $ids, public string $locale)
    {
        $wait = max(self::POLL_SECONDS, (int) config('wm-package.features.print.zip.max_wait_seconds', 1800));
        $this->tries = intdiv($wait, self::POLL_SECONDS) + 1;
        $this->uniqueFor = $wait + $this->timeout;
        $this->onQueue((string) config('wm-package.features.print.queue', 'print'));
    }

    public function handle(PrintService $service, StorageService $storage): void
    {
        $class = config('wm-package.ec_track_model');
        $tracks = $class::query()->whereIn('id', $this->ids)->orderBy('id')->get();
        if ($tracks->isEmpty()) {
            throw new RuntimeException('Nessuna traccia per lo ZIP delle schede.');
        }
        foreach ($tracks as $track) {
            if (! $track instanceof Model || ! $track instanceof PrintableModel) {
                throw new RuntimeException('Il modello traccia non è stampabile.');
            }
        }

        $first = $this->attempts() <= 1;
        $pending = false;
        foreach ($tracks as $track) {
            if ($service->isFresh($track, $this->locale)) {
                continue;
            }
            $pending = true;
            $status = PrintStatus::get($track, $this->locale);
            if (! $first && ($status['status'] ?? null) === PrintStatus::FAILED) {
                throw new RuntimeException("Generazione della scheda non riuscita per la traccia {$track->getKey()}.");
            }
            // Al primo giro si riparte da zero (un vecchio failed non conta); dopo, se lo stato è sparito
            // o è `done` ma la scheda non è più fresca (traccia modificata durante l'attesa). `queued` resta com'è.
            if ($first || $status === null || $status['status'] === PrintStatus::DONE) {
                GeneratePrintPdfJob::dispatchFor($track, $this->locale);
            }
        }

        if ($pending) {
            $this->release(self::POLL_SECONDS);

            return;
        }

        $this->assemble($tracks, $service, $storage);
    }

    /** @param  iterable<Model&PrintableModel>  $tracks */
    private function assemble(iterable $tracks, PrintService $service, StorageService $storage): void
    {
        $key = $this->uniqueId();
        $workDir = storage_path('app/print-tmp/zip-'.Str::uuid());
        File::ensureDirectoryExists($workDir);

        try {
            $zipFile = $workDir.'/sheets.zip';
            $zip = new ZipArchive;
            if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
                throw new RuntimeException('Creazione dello ZIP non riuscita.');
            }

            // Ogni PDF passa da wmfe a un file locale e si aggiunge con addFile(): niente PDF in memoria.
            foreach ($tracks as $track) {
                $source = $service->stream($track, $this->locale);
                if ($source === null) {
                    throw new RuntimeException("PDF mancante per la traccia {$track->getKey()}.");
                }
                $local = $workDir.'/'.$track->getKey().'.pdf';
                $target = fopen($local, 'wb');
                if ($target === false) {
                    fclose($source);
                    throw new RuntimeException("Impossibile scrivere il file locale {$local}.");
                }
                try {
                    stream_copy_to_stream($source, $target);
                } finally {
                    fclose($target);
                    fclose($source);
                }
                $zip->addFile($local, self::entryName($track, $this->locale));
            }

            if (! $zip->close()) {
                throw new RuntimeException('Chiusura dello ZIP non riuscita.');
            }

            $disk = $storage->getMediaDisk();
            $path = self::path($key, $this->locale);
            $handle = fopen($zipFile, 'rb');
            if ($handle === false) {
                throw new RuntimeException("Impossibile leggere lo ZIP locale {$zipFile}.");
            }
            try {
                if ($disk->put($path, $handle, 'public') === false) {
                    throw new RuntimeException("Scrittura su wmfe non riuscita: {$path}");
                }
            } finally {
                fclose($handle);
            }

            PrintStatus::putZip($key, ['status' => PrintStatus::DONE, 'url' => $disk->url($path)]);
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    protected static function putSelectionStatus(string $key, array $payload): void
    {
        PrintStatus::putZip($key, $payload);
    }

    protected static function failureLogMessage(): string
    {
        return 'Costruzione ZIP delle schede non riuscita';
    }

    /** Nome del file nello ZIP: slug ASCII del nome nella lingua + id. */
    public static function entryName(Model&PrintableModel $track, string $locale): string
    {
        $slug = Str::limit(Str::slug($track->printTranslation('name', $locale)), 80, '');
        $slug = trim($slug, '-');

        return ($slug !== '' ? $slug : 'track').'-'.$track->getKey().'.pdf';
    }
}
