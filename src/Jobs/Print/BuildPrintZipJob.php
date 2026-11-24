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
    use BuildsPrintSelectionFile {
        BuildsPrintSelectionFile::key as selectionKey;
        BuildsPrintSelectionFile::dispatchFor as selectionDispatchFor;
        BuildsPrintSelectionFile::uniqueId as selectionUniqueId;
    }
    use Dispatchable, InteractsWithQueue, Queueable;

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

    /**
     * @param  list<int>  $ids  id ordinati e senza duplicati
     * @param  string  $type  `ec-track` o `ec-poi`: di che modello sono gli id
     */
    public function __construct(public array $ids, public string $locale, public string $type = 'ec-track')
    {
        $wait = max(self::POLL_SECONDS, (int) config('wm-package.print.zip.max_wait_seconds', 1800));
        $this->tries = intdiv($wait, self::POLL_SECONDS) + 1;
        $this->uniqueFor = $wait + $this->timeout;
        $this->onQueue((string) config('wm-package.print.queue', 'print'));
    }

    public function handle(PrintService $service, StorageService $storage): void
    {
        $class = PrintService::modelClass($this->type);
        if ($class === null) {
            throw new RuntimeException("Tipo non stampabile per lo ZIP delle schede: {$this->type}.");
        }
        $tracks = $class::query()->whereIn('id', $this->ids)->orderBy('id')->get();
        if ($tracks->isEmpty()) {
            throw new RuntimeException('Nessun elemento per lo ZIP delle schede.');
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

    /**
     * Chiave dello ZIP: quella della selezione, con il tipo per i POI. Le tracce tengono la chiave di
     * prima, così uno ZIP già scritto resta valido; i POI con gli stessi id non lo condividono.
     */
    public static function key(array $ids, string $locale, string $type = 'ec-track'): string
    {
        return $type === 'ec-track'
            ? self::selectionKey($ids, $locale)
            : sha1($type.'|'.self::selectionKey($ids, $locale));
    }

    /** Segna lo stato `queued` e accoda il job (il lock di unicità evita il secondo push). */
    public static function dispatchFor(array $ids, string $locale, string $type = 'ec-track'): void
    {
        if (! preg_match(PrintService::LOCALE_PATTERN, $locale)) {
            throw new \InvalidArgumentException('Lingua non valida per il file della selezione.');
        }

        static::putSelectionStatus(static::key($ids, $locale, $type), ['status' => PrintStatus::QUEUED]);

        static::dispatch($ids, $locale, $type);
    }

    public function uniqueId(): string
    {
        return static::key($this->ids, $this->locale, $this->type);
    }

    /** Nome del file nello ZIP: slug ASCII del nome nella lingua + id. */
    public static function entryName(Model&PrintableModel $track, string $locale): string
    {
        $slug = Str::limit(Str::slug($track->printTranslation('name', $locale)), 80, '');
        $slug = trim($slug, '-');

        return ($slug !== '' ? $slug : (PrintService::typeOf($track) === 'ec-poi' ? 'poi' : 'track')).'-'.$track->getKey().'.pdf';
    }
}
