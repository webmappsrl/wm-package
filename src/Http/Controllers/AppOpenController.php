<?php

namespace Wm\WmPackage\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\AppOpenService;

/**
 * Link unico dell'App (oc:8803): App Store su iOS, tentativo di aprire l'app
 * su Android con Google Play come ripiego, webapp sugli altri dispositivi; pagina con i
 * pulsanti quando manca il link dello store del dispositivo.
 */
class AppOpenController extends Controller
{
    // L'inglese è in testa perché getPreferredLanguage() ripiega sulla prima lingua, sia senza
    // header Accept-Language sia quando nessuna di quelle del browser è fra queste.
    private const LOCALES = ['en', 'it', 'fr', 'es', 'de'];

    /**
     * `$app` è una stringa: whereNumber() accetta anche numeri oltre PHP_INT_MAX, che con un
     * parametro int, o arrivati a Postgres come bigint, darebbero un 500 invece del 404.
     */
    public function __invoke(Request $request, string $app, AppOpenService $service): Response
    {
        $id = filter_var($app, FILTER_VALIDATE_INT);
        $model = $id === false ? null : App::find($id);
        if (! $model) {
            abort(404);
        }

        $platform = $service->detectPlatform($request->userAgent());
        $iosLink = $model->ios_store_link ?: null;
        $androidLink = $model->android_store_link ?: null;

        if (! $model->hasStoreLinks()) {
            $mode = AppOpenService::MODE_UNAVAILABLE;
        } elseif ($platform === AppOpenService::PLATFORM_IOS && $iosLink) {
            return $this->withNoCacheHeaders(redirect()->away($iosLink));
        } elseif ($platform === AppOpenService::PLATFORM_ANDROID && $androidLink) {
            $mode = AppOpenService::MODE_ANDROID;
        } elseif ($platform === AppOpenService::PLATFORM_OTHER) {
            // Non un 302: l'iPad si presenta come Mac e lo distingue solo lo script della pagina.
            $mode = AppOpenService::MODE_WEBAPP;
        } else {
            $mode = AppOpenService::MODE_CHOICE;
        }

        $locale = (string) $request->getPreferredLanguage(self::LOCALES);
        app()->setLocale($locale);

        return $this->withNoCacheHeaders(response()->view('wm-package::app-open', [
            'mode' => $mode,
            'locale' => $locale,
            'appName' => (string) $model->name,
            'platform' => $platform,
            'iosLink' => $iosLink,
            'androidLink' => $androidLink,
            'intentUrl' => $mode === AppOpenService::MODE_ANDROID ? $service->androidIntentUrl($model) : null,
            'webappUrl' => $model->getWebappUrl(),
            'androidFallbackDelayMs' => AppOpenService::ANDROID_FALLBACK_DELAY_MS,
        ]));
    }

    /**
     * La risposta dipende dal dispositivo: nessuna cache condivisa deve riusarla.
     */
    private function withNoCacheHeaders(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Vary', 'User-Agent');

        return $response;
    }
}
