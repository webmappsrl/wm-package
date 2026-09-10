<?php

namespace Wm\WmPackage\TrailRegistry\Exceptions;

use RuntimeException;

/**
 * Il numero scelto a mano dal gestore (replaceNumber()) e' gia' occupato.
 *
 * Senza questa eccezione, la violazione dell'indice unico parziale si
 * propagherebbe come una QueryException grezza: il gestore non potrebbe
 * distinguere «il numero e' gia' preso» da un guasto qualunque del database.
 * Il comportamento resta lo stesso — la sostituzione fallisce, non dirotta
 * mai su un numero diverso — cambia solo che diventa spiegabile a chi la
 * riceve.
 */
class NumberOccupiedException extends RuntimeException
{
    public static function forNumber(string $fullCode, int $number, string $variant): self
    {
        $code = $variant === '0'
            ? sprintf('%s%02d', $fullCode, $number)
            : sprintf('%s%02d%s', $fullCode, $number, $variant);

        return new self("Il numero {$code} e' gia' occupato.");
    }
}
