<?php

declare(strict_types=1);

namespace Wm\WmPackage\Http\Resources\TrackVersions;

use Wm\WmPackage\Enums\WmfeTrackVersion;
use Wm\WmPackage\Models\EcTrack;

/**
 * Dalla forma corrente a quella senza numero: rimette le tre forme vecchie delle tassonomie
 * (oc:8997).
 *
 * È un **modulo di versione** nel senso di R4 dell'overview: tiene tutto e solo ciò che distingue
 * la versione senza numero da quella che la segue, e si cancella intero il giorno che nessuno
 * shard la scrive più. Non va chiamata da nessuno che non sia `EcTrackPropertiesBuilder::build()`.
 *
 * `taxonomy` e `taxonomyWheres` tornano come il modello le produce; `taxonomy_where` e
 * `taxonomyWheres` passano poi dal filtro delle località mostrate per l'app, che è lo stesso che
 * `applyTaxonomyWhereDisplay()` applicava quando quel codice stava nel percorso condiviso. Una
 * chiave che la traccia non ha non si aggiunge: una chiave vuota comparsa dal nulla sarebbe una
 * modifica di forma del file che le app installate leggono.
 */
class LegacyTrackPropertiesTransformation
{
    /**
     * La versione che questa trasformazione produce.
     */
    public static function appliesTo(): WmfeTrackVersion
    {
        return WmfeTrackVersion::Legacy;
    }

    /**
     * La versione da cui parte: quella che la segue nella catena. Dichiararla permette a
     * `build()` di accorgersi del passo mancante quando nascerà una versione in mezzo.
     */
    public static function appliesFrom(): WmfeTrackVersion
    {
        return WmfeTrackVersion::V1;
    }

    /**
     * @param  array<string, mixed>  $current  le properties della versione che segue questa
     * @param  array<string, mixed>  $rawContext  le chiavi vecchie come il modello le produce
     * @return array<string, mixed>
     */
    public static function apply(array $current, array $rawContext, EcTrack $track): array
    {
        $properties = $current;

        foreach ($rawContext as $key => $value) {
            $properties[$key] = $value;
        }

        return $track->applyTaxonomyWhereDisplay($properties);
    }
}
