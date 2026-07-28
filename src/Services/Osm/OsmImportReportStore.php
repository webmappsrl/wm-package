<?php

declare(strict_types=1);

namespace Wm\WmPackage\Services\Osm;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Stores the OSM import report payload for the HTML summary page (one-time token in cache).
 */
final class OsmImportReportStore
{
    public const TTL_MINUTES = 15;

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function put(array $payload, int $userId): string
    {
        $token = Str::random(48);
        Cache::put(self::cacheKey($token), [
            'user_id' => $userId,
            'payload' => $payload,
        ], now()->addMinutes(self::TTL_MINUTES));

        return $token;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $token, int $userId): ?array
    {
        $row = Cache::get(self::cacheKey($token));
        if (! is_array($row) || ! isset($row['user_id'], $row['payload'])) {
            return null;
        }
        if ((int) $row['user_id'] !== $userId) {
            return null;
        }

        return is_array($row['payload']) ? $row['payload'] : null;
    }

    private static function cacheKey(string $token): string
    {
        return 'osm_import_report:'.$token;
    }
}
