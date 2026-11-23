<?php

namespace Wm\WmPackage\Jobs\Print\Concerns;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;
use Wm\WmPackage\Jobs\Print\GeneratePrintPdfJob;
use Wm\WmPackage\Services\Print\PrintService;
use Wm\WmPackage\Services\Print\PrintStatus;

/**
 * Parti comuni dei Job che producono un file da una selezione di tracce (ZIP delle schede e mappa
 * d'insieme, oc:8982): chiave della selezione, percorso su wmfe, dispatch con lo stato `queued`,
 * lock di unicità e fallimento.
 *
 * La classe che usa il trait dichiara `public const SUBDIRECTORY` (sottocartella di `print-tmp` su
 * wmfe) e `public const EXTENSION`, ha le proprietà `$ids` e `$locale` e implementa i due metodi
 * astratti qui sotto. Niente costanti nel trait: sotto PHP 8.2 non sono ammesse.
 */
trait BuildsPrintSelectionFile
{
    /** Scrive lo stato della selezione nella cache letta dalla pagina d'attesa. */
    abstract protected static function putSelectionStatus(string $key, array $payload): void;

    /** Messaggio del log `print` quando il Job fallisce. */
    abstract protected static function failureLogMessage(): string;

    /**
     * Chiave della selezione: sha1 dello shard, degli id ordinati e della lingua. Lo shard entra
     * nella chiave perché `wmfe` è condiviso: due shard con gli stessi id non condividono il file.
     */
    public static function key(array $ids, string $locale): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return sha1(PrintService::shardName().'|'.implode(',', $ids).'|'.$locale);
    }

    /** Cartella dei file di questo Job su wmfe, sotto la base dello shard. */
    public static function directory(): string
    {
        return PrintService::remoteTmpDirectory().'/'.static::SUBDIRECTORY;
    }

    /** Percorso del file su wmfe. */
    public static function path(string $key, string $locale): string
    {
        return static::directory().'/'.$key.'-'.$locale.'.'.static::EXTENSION;
    }

    /** Segna lo stato `queued` e accoda il job (il lock di unicità evita il secondo push). */
    public static function dispatchFor(array $ids, string $locale): void
    {
        if (! preg_match(PrintService::LOCALE_PATTERN, $locale)) {
            throw new InvalidArgumentException('Lingua non valida per il file della selezione.');
        }

        static::putSelectionStatus(static::key($ids, $locale), ['status' => PrintStatus::QUEUED]);

        static::dispatch($ids, $locale);
    }

    public function uniqueId(): string
    {
        return static::key($this->ids, $this->locale);
    }

    /**
     * Lock di unicità su Redis e non sullo store di default: con `CACHE_STORE=database` un lock
     * preso dentro una transazione (Action Nova) fa fallire la transazione con `25P02` se la riga
     * esiste già (oc:8564, come `BuildAppPoisGeojsonJob`).
     */
    public function uniqueVia(): Repository
    {
        return Cache::store('redis');
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('print')->error(static::failureLogMessage(), [
            'ids' => $this->ids,
            'locale' => $this->locale,
            'exception' => $exception ? $exception::class.': '.$exception->getMessage() : null,
        ]);

        static::putSelectionStatus($this->uniqueId(), [
            'status' => PrintStatus::FAILED,
            'message' => GeneratePrintPdfJob::userMessage($exception, $this->locale),
        ]);
    }
}
