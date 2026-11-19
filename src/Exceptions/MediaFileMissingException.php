<?php

namespace Wm\WmPackage\Exceptions;

use RuntimeException;

/**
 * Il file di un media non esiste sul suo disco.
 *
 * Serve a chi copia un media con `Media::copy()` di Spatie: con il file mancante
 * `copy()` non dà errore e crea un media da 0 byte (oc:8967), quindi l'assenza va
 * controllata prima e segnalata con questa eccezione.
 */
class MediaFileMissingException extends RuntimeException
{
    public function __construct(public readonly int $mediaId, string $path)
    {
        parent::__construct("File del media {$mediaId} non trovato sullo storage: {$path}");
    }
}
