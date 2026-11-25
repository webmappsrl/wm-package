<?php

namespace Wm\WmPackage\Exceptions;

/**
 * Il servizio delle mappe di stampa (oc:9011) è occupato o lento: `429`, `503`, `504` o timeout della
 * chiamata. Non è un difetto della richiesta: il Job si rimette in coda dopo `$retryAfter` secondi
 * invece di fallire (il servizio ha due slot di rendering e risponde così sotto carico).
 */
class MapServiceBusyException extends PrintCompilationException
{
    public function __construct(string $message, public readonly int $retryAfter = 30, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
