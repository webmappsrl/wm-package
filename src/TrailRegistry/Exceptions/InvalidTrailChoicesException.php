<?php

namespace Wm\WmPackage\TrailRegistry\Exceptions;

use InvalidArgumentException;

/**
 * Le scelte dell'operatore in approvazione (attivita', difficolta', attivita'
 * primaria) non si possono scrivere sul sentiero: un termine non esiste a
 * database, o la primaria non e' fra le attivita' confermate.
 *
 * A differenza di un avviso nel log, l'eccezione ferma l'approvazione: la
 * transazione torna indietro e l'istanza resta in istruttoria, invece di
 * produrre un sentiero senza quanto l'operatore aveva confermato (oc:9038).
 */
class InvalidTrailChoicesException extends InvalidArgumentException
{
    public static function missingTerm(string $identifier): self
    {
        return new self("Il termine \"{$identifier}\" non esiste in taxonomy_terms: l'approvazione non puo' scriverlo sul sentiero.");
    }

    public static function missingActivity(string $identifier): self
    {
        return new self("L'attivita' \"{$identifier}\" non esiste in taxonomy_activities: l'approvazione non puo' scriverla sul sentiero.");
    }

    public static function primaryNotConfirmed(string $primary): self
    {
        return new self("L'attivita' primaria \"{$primary}\" non e' fra le attivita' confermate.");
    }
}
