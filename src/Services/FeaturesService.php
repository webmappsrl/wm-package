<?php

namespace Wm\WmPackage\Services;

/**
 * Fonte di verita' sui domini opzionali del package.
 *
 * Un dominio e' un insieme di stub di migration, comandi e impostazioni che un
 * consumer riceve solo se lo attiva esplicitamente. A dominio spento il package
 * si comporta come se il dominio non esistesse.
 *
 * Metodi statici e senza stato, come RolesAndPermissionsService: leggono solo
 * config('wm-package.features').
 *
 * @see docs/resources/OptionalDomains.md
 */
class FeaturesService
{
    /**
     * Domini dichiarati in configurazione, accesi o spenti che siano.
     *
     * @return array<int, string>
     */
    public static function declaredDomains(): array
    {
        $features = config('wm-package.features');

        return is_array($features) ? array_keys($features) : [];
    }

    public static function isEnabled(string $domain): bool
    {
        return (bool) config("wm-package.features.{$domain}.enabled", false);
    }

    /**
     * @return array<int, string>
     */
    public static function enabledDomains(): array
    {
        return array_values(array_filter(
            self::declaredDomains(),
            fn (string $domain) => self::isEnabled($domain),
        ));
    }
}
