<?php

namespace Wm\WmPackage\Services;

use Wm\WmPackage\Models\App;

/**
 * Decide dove mandare chi apre il link unico di un'App (oc:8803):
 * piattaforma dallo user agent, package Android e URL `intent://` che tenta di aprire
 * l'app installata, con Google Play come ripiego.
 */
class AppOpenService
{
    public const PLATFORM_IOS = 'ios';

    public const PLATFORM_ANDROID = 'android';

    public const PLATFORM_OTHER = 'other';

    /** Le quattro pagine possibili di AppOpenController, oltre al 302 verso l'App Store. */
    public const MODE_ANDROID = 'android';

    public const MODE_WEBAPP = 'webapp';

    public const MODE_CHOICE = 'choice';

    public const MODE_UNAVAILABLE = 'unavailable';

    /** Dopo quanto la pagina Android, ancora in primo piano, ripiega su Google Play. */
    public const ANDROID_FALLBACK_DELAY_MS = 1500;

    private const ANDROID_PACKAGE_PATTERN = '/^[A-Za-z0-9_.]+$/';

    /**
     * Riconosce iOS (iPhone, iPad, iPod) e Android dallo user agent; tutto il resto,
     * compreso uno user agent assente, è 'other'.
     */
    public function detectPlatform(?string $userAgent): string
    {
        if (empty($userAgent)) {
            return self::PLATFORM_OTHER;
        }

        if (preg_match('/iPhone|iPad|iPod/', $userAgent)) {
            return self::PLATFORM_IOS;
        }

        if (str_contains($userAgent, 'Android')) {
            return self::PLATFORM_ANDROID;
        }

        return self::PLATFORM_OTHER;
    }

    /**
     * Package Android: il parametro `id` del link a Google Play, altrimenti lo `sku`.
     * Restituisce null se il valore non è un package valido, perché finisce dentro
     * l'URL `intent://` e un carattere come `;` vi aggiungerebbe parametri.
     */
    public function androidPackage(App $app): ?string
    {
        $package = null;

        $query = parse_url((string) $app->android_store_link, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $params);
            $package = is_string($params['id'] ?? null) ? $params['id'] : null;
        }

        $package = (string) ($package ?: $app->sku);

        if (! preg_match(self::ANDROID_PACKAGE_PATTERN, $package)) {
            return null;
        }

        return $package;
    }

    /**
     * URL `intent://` sul dominio dei deep link dell'App: colpisce l'intent-filter
     * BROWSABLE dei deep link, se la build lo dichiara, e Chrome va su Google Play se
     * l'app non c'è. Null senza link a Google Play o senza un package valido.
     */
    public function androidIntentUrl(App $app): ?string
    {
        if (empty($app->android_store_link)) {
            return null;
        }

        $package = $this->androidPackage($app);
        if ($package === null) {
            return null;
        }

        return 'intent://'.$app->getDeepLinkDomain().'/#Intent;scheme=https;package='.$package
            .';S.browser_fallback_url='.rawurlencode($app->android_store_link).';end';
    }
}
