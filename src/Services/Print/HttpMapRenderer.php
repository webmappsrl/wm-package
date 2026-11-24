<?php

namespace Wm\WmPackage\Services\Print;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Exceptions\MapServiceBusyException;
use Wm\WmPackage\Exceptions\PrintCompilationException;
use Wm\WmPackage\Services\Print\Contracts\MapRenderer;

/**
 * Client del servizio delle mappe di stampa su wm-tiles (oc:9011): `POST {url}` con il token dello
 * shard, il PNG torna nel corpo. Documentazione e schema: `/v1/print/docs` e `/v1/print/openapi.json`
 * sullo stesso host.
 *
 * `429`, `503`, `504` e un timeout sono il servizio sotto carico: `MapServiceBusyException` con il
 * `Retry-After`, e il Job si rimette in coda. Ogni altro errore (`400`, `401`, `413`) è un difetto della
 * richiesta o della configurazione e non si riprova.
 */
class HttpMapRenderer implements MapRenderer
{
    /** Attesa se il servizio non manda `Retry-After` (`x-wm-limits.retryAfterSeconds`). */
    public const DEFAULT_RETRY_AFTER = 30;

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    public function render(MapRenderRequest $request): string
    {
        $config = (array) config('wm-package.print.map_service', []);
        $url = $config['url'] ?? null;
        if (! $url) {
            throw new PrintCompilationException('Servizio mappe non configurato (WM_PRINT_MAP_SERVICE_URL).');
        }
        $token = $config['token'] ?? null;
        if (! $token) {
            throw new PrintCompilationException('Token del servizio mappe non configurato (WM_PRINT_MAP_SERVICE_TOKEN).');
        }

        $body = $request->toServiceBody(
            (int) ($config['dpi'] ?? 300),
            (string) ($config['style_id'] ?? 'webmapp'),
            (bool) ($config['map_info'] ?? true),
            $request->maxZoom ?? (isset($config['max_zoom']) ? (float) $config['max_zoom'] : null),
            $request->paddingMm ?? (isset($config['padding_mm']) ? (float) $config['padding_mm'] : null),
        );

        try {
            $response = Http::timeout((int) ($config['timeout'] ?? 60))
                ->withToken((string) $token)
                ->accept('image/png')
                ->post((string) $url, $body);
        } catch (ConnectionException $e) {
            throw new MapServiceBusyException('Servizio mappe non raggiungibile o in timeout: '.$e->getMessage(), self::DEFAULT_RETRY_AFTER, $e);
        }

        if (in_array($response->status(), [429, 503, 504], true)) {
            $retryAfter = (int) $response->header('Retry-After');

            throw new MapServiceBusyException(
                'Servizio mappe occupato (HTTP '.$response->status().').',
                $retryAfter > 0 ? $retryAfter : self::DEFAULT_RETRY_AFTER
            );
        }
        if ($response->failed()) {
            // Il messaggio del servizio nomina il campo, mai il suo contenuto: si può loggare.
            $error = (string) ($response->json('error') ?? '');

            throw new PrintCompilationException('Il servizio mappe ha risposto con errore HTTP '.$response->status().($error !== '' ? ': '.$error : '').'.');
        }

        $png = $response->body();
        if (! str_starts_with($png, self::PNG_SIGNATURE)) {
            throw new PrintCompilationException('Il servizio mappe non ha restituito un PNG.');
        }

        return $png;
    }
}
