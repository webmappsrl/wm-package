<?php

namespace Wm\WmPackage\TrailRegistry;

use InvalidArgumentException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCodeEvent;

/**
 * Il punto unico da cui il dominio sa quale classe usare per ogni modello.
 *
 * I modelli si richiamano fra loro (un'istanza ha dei codici, un codice ha
 * degli eventi): senza questo punto una sottoclasse dello shard verrebbe
 * scavalcata da ogni relazione scritta con il nome della classe del package.
 */
class TrailRegistryClasses
{
    private const DEFAULTS = [
        'code' => TrailRegistryCode::class,
        'event' => TrailRegistryCodeEvent::class,
        'application' => TrailApplication::class,
        'anomaly' => TrailRegistryAnomaly::class,
    ];

    /** @return class-string<TrailRegistryCode> */
    public static function code(): string
    {
        /** @var class-string<TrailRegistryCode> $class */
        $class = self::resolve('code');

        return $class;
    }

    /** @return class-string<TrailRegistryCodeEvent> */
    public static function event(): string
    {
        /** @var class-string<TrailRegistryCodeEvent> $class */
        $class = self::resolve('event');

        return $class;
    }

    /** @return class-string<TrailApplication> */
    public static function application(): string
    {
        /** @var class-string<TrailApplication> $class */
        $class = self::resolve('application');

        return $class;
    }

    /** @return class-string<TrailRegistryAnomaly> */
    public static function anomaly(): string
    {
        /** @var class-string<TrailRegistryAnomaly> $class */
        $class = self::resolve('anomaly');

        return $class;
    }

    /**
     * Fallisce subito su una classe sbagliata: tornare in silenzio a quella
     * del package e' esattamente il difetto che questo punto deve togliere.
     */
    public static function assertValid(): void
    {
        foreach (self::DEFAULTS as $key => $base) {
            $class = self::resolve($key);

            if (! class_exists($class)) {
                throw new InvalidArgumentException(
                    "wm-package.features.trail_registry.models.{$key}: la classe {$class} non esiste."
                );
            }

            if ($class !== $base && ! is_subclass_of($class, $base)) {
                throw new InvalidArgumentException(
                    "wm-package.features.trail_registry.models.{$key}: {$class} deve estendere {$base}."
                );
            }
        }
    }

    private static function resolve(string $key): string
    {
        return (string) config("wm-package.features.trail_registry.models.{$key}", self::DEFAULTS[$key]);
    }
}
