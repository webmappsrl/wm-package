<?php

namespace Wm\WmPackage\TrailRegistry\Exceptions;

use RuntimeException;

/**
 * Nel settore non ci sono piu' posizioni libere nello spazio numero x
 * variante.
 *
 * Solleva invece di restituire vuoto: un null senza spiegazione si propaga
 * fino al consumer come risposta non gestita, mentre «settore ZNUB5 esaurito»
 * e' un'informazione che il gestore del catasto deve avere comunque.
 */
class SectorExhaustedException extends RuntimeException
{
    public static function forFullCode(string $fullCode): self
    {
        return new self("Settore {$fullCode}: nessun numero libero.");
    }
}
