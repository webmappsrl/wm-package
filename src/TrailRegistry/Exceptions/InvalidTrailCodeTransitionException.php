<?php

namespace Wm\WmPackage\TrailRegistry\Exceptions;

use RuntimeException;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;

/**
 * Il codice non e' nello stato di partenza richiesto dall'azione tentata.
 *
 * confirm()/release()/replaceNumber() cambiano stato solo se partono da uno
 * stato ammesso per quell'azione: senza questa guardia, confermare un codice
 * gia' liberato lo riporterebbe ad assigned, sostituire il numero di un
 * codice gia' assegnato staccherebbe il sentiero dal suo codice, e liberare
 * due volte lo stesso codice scriverebbe un passaggio released -> released
 * nella storia. L'unica altra difesa sarebbe l'indice unico parziale sulle
 * quattro colonne del codice — pensato per un altro scopo (l'unicita' del
 * numero, non la validita' della transizione) — che si manifesterebbe come
 * un errore SQL opaco invece che come un rifiuto motivato.
 */
class InvalidTrailCodeTransitionException extends RuntimeException
{
    /**
     * @param  array<int, TrailCodeStatus>  $allowed
     */
    public static function forTransition(TrailCodeStatus $from, string $action, array $allowed): self
    {
        $allowedList = implode(', ', array_map(fn (TrailCodeStatus $s) => $s->value, $allowed));

        return new self(
            "Impossibile {$action} un codice nello stato '{$from->value}': ammesso solo da [{$allowedList}]."
        );
    }
}
