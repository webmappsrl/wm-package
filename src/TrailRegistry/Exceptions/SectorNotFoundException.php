<?php

namespace Wm\WmPackage\TrailRegistry\Exceptions;

use RuntimeException;

/**
 * La geometria non ricade in alcun settore CAI, quindi non e' possibile
 * comporre un codice. Non e' un errore da nascondere: e' la risposta «non
 * posso proporre un numero», e chi chiama deve poterla riferire al
 * richiedente.
 */
class SectorNotFoundException extends RuntimeException
{
    public static function forGeometry(): self
    {
        return new self('La traccia non ricade in alcun settore del catasto.');
    }
}
