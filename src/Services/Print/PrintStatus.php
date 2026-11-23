<?php

namespace Wm\WmPackage\Services\Print;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Stato della generazione di una scheda PDF, letto dalla pagina di attesa (oc:8982).
 *
 * Vive in cache per un'ora sotto `print:{morphClass}:{id}:{locale}`. Payload:
 * `{status: queued|done|failed, url?: string, message?: string}`.
 */
class PrintStatus
{
    public const QUEUED = 'queued';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /** Durata dello stato in cache, in secondi. */
    public const TTL = 3600;

    public static function key(Model $model, string $locale): string
    {
        return 'print:'.$model->getMorphClass().':'.$model->getKey().':'.$locale;
    }

    /** @return array{status: string, url?: string, message?: string}|null */
    public static function get(Model $model, string $locale): ?array
    {
        $value = Cache::get(self::key($model, $locale));

        return is_array($value) ? $value : null;
    }

    public static function markQueued(Model $model, string $locale): void
    {
        self::put($model, $locale, ['status' => self::QUEUED]);
    }

    public static function markDone(Model $model, string $locale, string $url): void
    {
        self::put($model, $locale, ['status' => self::DONE, 'url' => $url]);
    }

    public static function markFailed(Model $model, string $locale, string $message): void
    {
        self::put($model, $locale, ['status' => self::FAILED, 'message' => $message]);
    }

    private static function put(Model $model, string $locale, array $payload): void
    {
        Cache::put(self::key($model, $locale), $payload, self::TTL);
    }

    /**
     * Stato dello ZIP di più schede, sotto `print-zip:{chiave}` (chiave = BuildPrintZipJob::key()).
     */
    public static function zipCacheKey(string $zipKey): string
    {
        return 'print-zip:'.$zipKey;
    }

    /** @return array{status: string, url?: string, message?: string}|null */
    public static function getZip(string $zipKey): ?array
    {
        $value = Cache::get(self::zipCacheKey($zipKey));

        return is_array($value) ? $value : null;
    }

    /** @param  array{status: string, url?: string, message?: string}  $payload */
    public static function putZip(string $zipKey, array $payload): void
    {
        Cache::put(self::zipCacheKey($zipKey), $payload, self::TTL);
    }

    /**
     * Stato della mappa d'insieme, sotto `print-overview:{chiave}` (stessa chiave dello ZIP, prefisso diverso).
     */
    public static function overviewCacheKey(string $key): string
    {
        return 'print-overview:'.$key;
    }

    /** @return array{status: string, url?: string, message?: string}|null */
    public static function getOverview(string $key): ?array
    {
        $value = Cache::get(self::overviewCacheKey($key));

        return is_array($value) ? $value : null;
    }

    /** @param  array{status: string, url?: string, message?: string}  $payload */
    public static function putOverview(string $key, array $payload): void
    {
        Cache::put(self::overviewCacheKey($key), $payload, self::TTL);
    }
}
