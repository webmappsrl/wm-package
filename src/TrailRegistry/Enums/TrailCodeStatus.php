<?php

namespace Wm\WmPackage\TrailRegistry\Enums;

/**
 * Stato di un codice nel registro.
 *
 * Non esiste uno stato «in conflitto». Un sentiero la cui posizione è già di
 * un altro non entra affatto nel registro: il registro contiene solo codici
 * realmente portati da qualcuno, e il caso irrisolto vive come anomalia
 * `codice_gia_assegnato`, dove il sentiero che quel numero lo porta è scritto
 * in chiaro invece di dover essere ricostruito cercando il codice.
 *
 * `Released` è l'unico stato che lascia una riga in tabella senza occupare
 * la posizione: serve perché un numero liberato resti tracciato e sia
 * riassegnabile.
 */
enum TrailCodeStatus: string
{
    case Reserved = 'reserved';
    case Assigned = 'assigned';
    case Released = 'released';

    /**
     * Gli stati che occupano un codice. Devono coincidere con la clausola
     * WHERE dell'indice unico parziale in database/migrations/trail_registry.
     *
     * @return array<int, self>
     */
    public static function active(): array
    {
        return [self::Reserved, self::Assigned];
    }
}
