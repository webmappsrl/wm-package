<?php

namespace Wm\WmPackage\TrailRegistry\Anomalies;

use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;
use Wm\WmPackage\TrailRegistry\Inconsistencies\TrailRegistryInconsistencyTypes;

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

    /**
     * L'etichetta di un tipo, da qualunque registro venga.
     *
     * Le incongruenze vivono nella stessa tabella delle anomalie, distinte dal
     * `source`, ma i loro tipi li dichiara un altro registro: chi legge una riga
     * non sa da quale dei due viene, e senza questo ripiego la colonna «Tipo»
     * mostrerebbe la chiave grezza (oc:8989).
     */
    public static function label(string $type): string
    {
        return self::definition($type)?->label()
            ?? TrailRegistryInconsistencyTypes::definition($type)?->label()
            ?? $type;
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
