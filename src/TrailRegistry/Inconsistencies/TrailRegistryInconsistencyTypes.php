<?php

namespace Wm\WmPackage\TrailRegistry\Inconsistencies;

/**
 * Il registro dei tipi di incongruenza catastale dichiarati da uno shard
 * (oc:8989).
 *
 * A differenza delle anomalie, qui il package non ha tipi propri: che cosa non
 * torna fra due informazioni dipende da quali informazioni quel catasto
 * pubblica, e le sa solo lo shard. Si dichiarano in
 * `wm-package.features.trail_registry.inconsistency_types`, chiave => classe
 * che implementa InconsistencyTypeDefinition.
 */
class TrailRegistryInconsistencyTypes
{
    public static function definition(string $type): ?InconsistencyTypeDefinition
    {
        $class = self::shardTypes()[$type] ?? null;

        return $class !== null ? app($class) : null;
    }

    /**
     * L'etichetta del tipo, o la chiave stessa se nessuno lo dichiara: una
     * lista che mostra una chiave grezza si legge male, una che esplode non si
     * legge affatto.
     */
    public static function label(string $type): string
    {
        return self::definition($type)?->label() ?? $type;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_values(array_keys(self::shardTypes()));
    }

    public static function isKnown(string $type): bool
    {
        return in_array($type, self::values(), true);
    }

    /** @return array<string, class-string<InconsistencyTypeDefinition>> */
    private static function shardTypes(): array
    {
        return (array) config('wm-package.features.trail_registry.inconsistency_types', []);
    }
}
