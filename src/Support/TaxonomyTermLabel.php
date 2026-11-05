<?php

namespace Wm\WmPackage\Support;

use Wm\WmPackage\Models\TaxonomyTerm;

/**
 * Etichetta leggibile di un termine o di una tassonomia (oc:8910): il nome nella lingua
 * corrente, e nient'altro.
 *
 * Il nome è un jsonb traducibile, e i dati importati da Sardegna Sentieri hanno solo la chiave
 * `it`: senza fallback un'interfaccia in inglese mostrerebbe righe vuote, che è il problema da
 * cui nasce questa classe. L'identifier si mostra solo quando non c'è nessun nome in nessuna
 * lingua, perché una riga senza etichetta non si può scegliere né cercare.
 */
class TaxonomyTermLabel
{
    public static function for(TaxonomyTerm $term): string
    {
        $name = self::nameOf($term);

        return $name !== '' ? $name : (string) $term->identifier;
    }

    /**
     * Il nome nella lingua corrente; se manca, la prima traduzione presente; poi stringa vuota.
     */
    public static function nameOf(TaxonomyTerm $term): string
    {
        $name = $term->getTranslation('name', app()->getLocale(), true);
        if ($name === '' || $name === null) {
            $name = collect($term->getTranslations('name'))->filter()->first();
        }

        return (string) $name;
    }
}
