<?php

namespace Wm\WmPackage\TrailRegistry\Anomalies;

use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;

/**
 * Il registro dei tipi di anomalia dichiarati da uno shard, accanto a quelli
 * fissi del catasto. Uno shard li dichiara in `wm-package.features
 * .trail_registry.anomaly_types` (chiave => classe che implementa
 * AnomalyTypeDefinition); il package non li conosce a priori.
 */
class TrailRegistryAnomalyTypes
{
    public static function definition(string $type): ?AnomalyTypeDefinition
    {
        $class = self::shardTypes()[$type] ?? null;

        return $class !== null ? app($class) : null;
    }

    public static function label(string $type): string
    {
        return self::definition($type)?->label() ?? $type;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_values(array_unique([
            ...TrailRegistryAnomalyType::values(),
            ...array_keys(self::shardTypes()),
        ]));
    }

    public static function isKnown(string $type): bool
    {
        return in_array($type, self::values(), true);
    }

    /** @return array<string, class-string<AnomalyTypeDefinition>> */
    private static function shardTypes(): array
    {
        return (array) config('wm-package.features.trail_registry.anomaly_types', []);
    }
}
