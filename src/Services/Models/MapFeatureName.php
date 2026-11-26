<?php

namespace Wm\WmPackage\Services\Models;

/**
 * Il nome da mostrare nel tooltip di una feature delle mappe Nova, letto dalla colonna `name`
 * (JSON delle traduzioni) presa con SQL puro: l'italiano, altrimenti la prima lingua presente,
 * altrimenti il fallback.
 */
class MapFeatureName
{
    public static function fromJson(?string $nameJson, string $fallback = 'Nome non disponibile'): string
    {
        $names = json_decode((string) $nameJson, true);

        if (! is_array($names) || $names === []) {
            return $fallback;
        }

        return $names['it'] ?? reset($names);
    }
}
