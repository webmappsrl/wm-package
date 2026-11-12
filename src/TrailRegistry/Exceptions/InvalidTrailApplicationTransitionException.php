<?php

namespace Wm\WmPackage\TrailRegistry\Exceptions;

use RuntimeException;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;

/**
 * L'istanza non e' nello stato di partenza richiesto dalla transizione
 * tentata.
 *
 * Oggi la solleva `confirmCompilation()` per un'istanza gia' respinta o
 * approvata: la compilazione non si chiude su una pratica che l'istruttoria
 * ha gia' decisa. Lo stato di partenza e' un dato dell'eccezione, cosi' chi la
 * cattura puo' rispondere senza leggerne il messaggio.
 */
class InvalidTrailApplicationTransitionException extends RuntimeException
{
    public function __construct(
        public readonly TrailApplicationStatus $from,
        string $action,
    ) {
        parent::__construct(
            "Impossibile {$action} un'istanza nello stato '{$from->value}'."
        );
    }

    public static function forAction(TrailApplicationStatus $from, string $action): self
    {
        return new self($from, $action);
    }
}
