<?php

namespace Wm\WmPackage\TrailRegistry\Exceptions;

use RuntimeException;

/**
 * Qualcun altro ha cambiato lo stato mentre lo stavi cambiando (oc:8989).
 *
 * Chi scrive dichiara da quale stato parte; se nel frattempo non e' piu'
 * quello, la scrittura non si applica su una base vecchia: si solleva questa,
 * e chi l'ha chiamata rilegge e decide. E' il caso dei due operatori che
 * aprono la stessa scheda, e quello dell'import che gira mentre qualcuno
 * registra a mano.
 */
class WalkabilityConflictException extends RuntimeException
{
    public static function for(string $expected, ?string $actual): self
    {
        return new self(sprintf(
            'Lo stato di partenza dichiarato era «%s», ma adesso è «%s».',
            $expected,
            $actual ?? 'nessuno',
        ));
    }
}
